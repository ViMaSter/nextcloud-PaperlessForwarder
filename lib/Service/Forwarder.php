<?php

declare(strict_types=1);

namespace OCA\PaperlessForwarder\Service;

use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

final class Forwarder {
	public function __construct(
		private ForwarderConfig $config,
		private StateStore $store,
		private PaperlessClient $paperless,
		private TaskResult $taskResult,
		private PathFilter $filter,
		private BreakerState $breaker,
		private IRootFolder $rootFolder,
		private IUserManager $userManager,
		private IAppManager $appManager,
		private LoggerInterface $logger,
	) {
	}

	public function processDue(): void {
		if (!$this->config->enabled() || $this->breaker->isOpen()) {
			return;
		}
		$deadline = microtime(true) + 20;
		foreach ($this->store->due() as $row) {
			if (microtime(true) >= $deadline) {
				break;
			}
			$this->processRow($row);
		}
	}

	/** @param array<string, mixed> $row */
	private function processRow(array $row): void {
		$fileId = (int)$row['file_id'];
		$userId = (string)$row['owner_id'];
		$user = $this->userManager->get($userId);
		if ($user === null || $userId !== $this->config->userId()) {
			$this->store->update($fileId, ['state' => 'parked', 'reason' => 'owner_unavailable']);
			return;
		}

		$userFolder = $this->rootFolder->getUserFolder($userId);
		$nodes = $userFolder->getById($fileId);
		$file = $nodes[0] ?? null;
		if (!$file instanceof File) {
			$this->store->update($fileId, ['state' => 'parked', 'reason' => 'file_missing']);
			return;
		}

		$relativePath = $userFolder->getRelativePath($file->getPath());
		if (!is_string($relativePath)) {
			$this->store->update($fileId, ['state' => 'parked', 'reason' => 'file_outside_home_storage']);
			return;
		}
		if (!$this->filter->eligible($file, $relativePath)) {
			$this->store->update($fileId, ['state' => 'skipped', 'reason' => 'file_not_eligible']);
			return;
		}
		if ($file->getEtag() !== (string)$row['etag'] || $file->getSize() !== (int)$row['size']) {
			$this->store->queue($file, $userId, '/' . trim($relativePath, '/'));
			return;
		}
		if ($file->getMtime() > time() - 120) {
			$this->store->update($fileId, ['state' => 'queued', 'next_attempt_at' => $file->getMtime() + 120]);
			return;
		}

		if ((string)$row['state'] === 'waiting') {
			$this->poll($file, $row);
			return;
		}
		$this->upload($file, $row);
	}

	/** @param array<string, mixed> $row */
	private function upload(File $file, array $row): void {
		$fileId = (int)$row['file_id'];
		$row['attempts'] = (int)$row['attempts'] + 1;
		$this->store->update($fileId, ['attempts' => $row['attempts']]);
		try {
			$taskId = $this->paperless->upload($file);
			$this->store->update($fileId, [
				'state' => 'waiting',
				'task_id' => $taskId,
				'rounds' => (int)$row['rounds'] + 1,
				'next_attempt_at' => time() + 15,
				'reason' => null,
				'last_error' => null,
			]);
		} catch (UploadRejected $exception) {
			$rejections = (int)$row['rejections'] + 1;
			if ($rejections >= 2 && strtolower((string)pathinfo($file->getName(), PATHINFO_EXTENSION)) !== 'pdf') {
				$this->trash($file, $row, 'rejected_twice');
				return;
			}
			$this->store->update($fileId, [
				'state' => $rejections >= 2 ? 'parked' : 'queued',
				'rejections' => $rejections,
				'next_attempt_at' => time() + 60,
				'reason' => $rejections >= 2 ? 'pdf_rejected_twice' : 'upload_rejected',
				'last_error' => $exception->getMessage(),
			]);
		} catch (PaperlessException $exception) {
			$this->retry($row, $exception->getMessage());
		}
	}

	/** @param array<string, mixed> $row */
	private function poll(File $file, array $row): void {
		$fileId = (int)$row['file_id'];
		$taskId = (string)($row['task_id'] ?? '');
		if ($taskId === '') {
			$this->retry($row, 'Missing Paperless task id');
			return;
		}
		try {
			$task = $this->paperless->task($taskId);
		} catch (PaperlessException $exception) {
			$this->retryWaiting($row, $exception->getMessage());
			return;
		}

		try {
			$result = $this->taskResult->classify($task);
		} catch (PaperlessException $exception) {
			$this->retryWaiting($row, $exception->getMessage());
			return;
		}
		if ($result['outcome'] === 'pending') {
			$this->store->update($fileId, ['poll_attempts' => 0, 'next_attempt_at' => time() + 15]);
			return;
		}
		if ($result['outcome'] === 'duplicate') {
			try {
				$verified = !$result['duplicate_in_trash'] && $this->paperless->duplicateIsVerified($result['duplicate_id']);
			} catch (PaperlessException $exception) {
				$this->retryWaiting($row, $exception->getMessage());
				return;
			}
			if (!$verified) {
				$this->store->update($fileId, ['state' => 'parked', 'reason' => 'duplicate_in_paperless_trash']);
				return;
			}
			$this->trash($file, $row, 'duplicate');
			return;
		}
		if ($result['outcome'] === 'success') {
			if ((int)$row['rounds'] >= 2) {
				$this->breaker->open('A second document was created for Nextcloud file ' . $fileId);
				$this->store->update($fileId, ['state' => 'parked', 'reason' => 'dedupe_not_active']);
				$this->logger->error('Paperless duplicate verification created a second document; forwarding paused', [
					'app' => 'paperlessforwarder',
					'fileId' => $fileId,
				]);
				return;
			}
			$this->store->update($fileId, [
				'state' => 'queued',
				'task_id' => null,
				'next_attempt_at' => time(),
				'reason' => null,
			]);
			return;
		}
		$this->retry($row, 'Paperless task ended with status ' . $result['status']);
	}

	/** @param array<string, mixed> $row */
	private function trash(File $file, array $row, string $reason): void {
		$fileId = (int)$row['file_id'];
		$userId = (string)$row['owner_id'];
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$relativePath = $userFolder->getRelativePath($file->getPath());
		$user = $this->userManager->get($userId);
		if ($user === null
			|| !$this->appManager->isEnabledForUser('files_trashbin', $user)
			|| !is_string($relativePath)
			|| !$this->filter->eligible($file, $relativePath)
			|| $file->getEtag() !== (string)$row['etag']
			|| $file->getSize() !== (int)$row['size']) {
			$this->store->update($fileId, ['state' => 'parked', 'reason' => 'trash_guard_failed']);
			return;
		}
		$file->delete();
		$this->store->update($fileId, ['state' => 'trashed', 'reason' => $reason, 'task_id' => null]);
	}

	/** @param array<string, mixed> $row */
	private function retry(array $row, string $reason): void {
		$attempt = max(1, (int)$row['attempts']);
		$delay = min(3600, 60 * (2 ** min(5, $attempt)));
		$this->store->update((int)$row['file_id'], [
			'state' => $attempt >= 3 ? 'parked' : 'queued',
			'task_id' => null,
			'next_attempt_at' => time() + $delay,
			'reason' => $attempt >= 3 ? 'retry_limit' : 'retrying',
			'last_error' => $reason,
		]);
		$this->logger->warning('Paperless forwarding will retry or park a file', [
			'app' => 'paperlessforwarder',
			'fileId' => (int)$row['file_id'],
			'reason' => $reason,
		]);
	}

	/** @param array<string, mixed> $row */
	private function retryWaiting(array $row, string $reason): void {
		$attempt = (int)$row['poll_attempts'] + 1;
		$this->store->update((int)$row['file_id'], [
			'state' => $attempt >= 3 ? 'parked' : 'waiting',
			'poll_attempts' => $attempt,
			'next_attempt_at' => time() + min(900, 60 * (2 ** min(4, $attempt))),
			'reason' => $attempt >= 3 ? 'task_poll_failed' : 'poll_retrying',
			'last_error' => $reason,
		]);
		$this->logger->warning('Paperless task polling will retry or park a file', [
			'app' => 'paperlessforwarder',
			'fileId' => (int)$row['file_id'],
			'reason' => $reason,
		]);
	}
}
