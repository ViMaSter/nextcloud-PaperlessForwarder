<?php

declare(strict_types=1);

namespace OCA\PaperlessForwarder\Tests\Unit;

use OCA\PaperlessForwarder\Service\BreakerState;
use OCA\PaperlessForwarder\Service\Forwarder;
use OCA\PaperlessForwarder\Service\ForwarderConfig;
use OCA\PaperlessForwarder\Service\PaperlessClient;
use OCA\PaperlessForwarder\Service\PaperlessException;
use OCA\PaperlessForwarder\Service\PathFilter;
use OCA\PaperlessForwarder\Service\StateStore;
use OCA\PaperlessForwarder\Service\TaskResult;
use OCA\PaperlessForwarder\Service\UploadRejected;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Throwable;

final class ForwarderTest extends TestCase {
	protected function setUp(): void {
		putenv('PAPERLESS_FORWARDER_MODE=live');
		putenv('PAPERLESS_FORWARDER_USER=forwarder-user');
		putenv('PAPERLESS_FORWARDER_PATH=/Paperless');
		putenv('PAPERLESS_API_TOKEN=test-token');
		putenv('PAPERLESS_URL=https://paperless.example.invalid');
	}

	public function testUploadsTwiceAndTrashesOnlyAfterVerifiedDuplicate(): void {
		[$forwarder, $store, $paperless, $breaker, $file] = $this->harness();
		$paperless->uploadResults = ['first-task', 'second-task'];
		$paperless->tasks = [
			'first-task' => ['status' => 'SUCCESS'],
			'second-task' => [
				'status' => 'FAILURE',
				'result_data' => ['duplicate_of' => 42, 'duplicate_in_trash' => false],
			],
		];
		$paperless->duplicateVerified = true;
		$file->expects(self::once())->method('delete');

		$forwarder->processDue();
		self::assertSame('waiting', $store->row['state']);
		self::assertSame(1, $store->row['rounds']);
		$store->makeDue();

		$forwarder->processDue();
		self::assertSame('queued', $store->row['state']);
		$store->makeDue();

		$forwarder->processDue();
		self::assertSame('waiting', $store->row['state']);
		self::assertSame(2, $store->row['rounds']);
		$store->makeDue();

		$forwarder->processDue();
		self::assertSame('trashed', $store->row['state']);
		self::assertSame('duplicate', $store->row['reason']);
		self::assertSame([42], $paperless->verifiedDocumentIds);
		self::assertFalse($breaker->opened);
		self::assertCount(2, $paperless->uploadedFiles);
	}

	public function testParksPdfAfterTwoUploadRejectionsWithoutTrashing(): void {
		[$forwarder, $store, $paperless, , $file] = $this->harness('invoice.pdf');
		$file->expects(self::never())->method('delete');
		$this->paperlessUploadResults(
			$forwarder,
			$store,
			$paperless,
			[new UploadRejected('unsupported'), new UploadRejected('unsupported')],
		);

		self::assertSame('parked', $store->row['state']);
		self::assertSame('pdf_rejected_twice', $store->row['reason']);
		self::assertSame(2, $store->row['rejections']);
	}

	public function testTrashesNonPdfAfterTwoUploadRejections(): void {
		[$forwarder, $store, $paperless, , $file] = $this->harness('archive.docx');
		$file->expects(self::once())->method('delete');
		$this->paperlessUploadResults(
			$forwarder,
			$store,
			$paperless,
			[new UploadRejected('unsupported'), new UploadRejected('unsupported')],
		);

		self::assertSame('trashed', $store->row['state']);
		self::assertSame('rejected_twice', $store->row['reason']);
	}

	public function testKeepsSourceAfterPaperlessProcessingFailure(): void {
		[$forwarder, $store, $paperless, , $file] = $this->harness();
		$file->expects(self::never())->method('delete');
		$paperless->uploadResults = ['failed-task'];
		$paperless->tasks = ['failed-task' => ['status' => 'FAILURE']];

		$forwarder->processDue();
		$store->makeDue();
		$forwarder->processDue();

		self::assertSame('queued', $store->row['state']);
		self::assertSame('retrying', $store->row['reason']);
		self::assertNull($store->row['task_id']);
	}

	public function testKeepsSourceWhenDuplicateIsAlreadyInPaperlessTrash(): void {
		[$forwarder, $store, $paperless, , $file] = $this->harness();
		$file->expects(self::never())->method('delete');
		$paperless->uploadResults = ['first-task', 'second-task'];
		$paperless->tasks = [
			'first-task' => ['status' => 'SUCCESS'],
			'second-task' => [
				'status' => 'FAILURE',
				'result_data' => ['duplicate_of' => 42, 'duplicate_in_trash' => true],
			],
		];
		$paperless->duplicateVerified = true;
		$this->completeFirstUpload($forwarder, $store);
		$store->makeDue();
		$forwarder->processDue();
		$store->makeDue();
		$forwarder->processDue();

		self::assertSame('parked', $store->row['state']);
		self::assertSame('duplicate_in_paperless_trash', $store->row['reason']);
		self::assertSame([], $paperless->verifiedDocumentIds);
	}

	public function testOpensCircuitBreakerIfSecondUploadCreatesAnotherDocument(): void {
		[$forwarder, $store, $paperless, $breaker, $file] = $this->harness();
		$file->expects(self::never())->method('delete');
		$paperless->uploadResults = ['first-task', 'second-task'];
		$paperless->tasks = [
			'first-task' => ['status' => 'SUCCESS'],
			'second-task' => ['status' => 'SUCCESS'],
		];
		$this->completeFirstUpload($forwarder, $store);
		$store->makeDue();
		$forwarder->processDue();
		$store->makeDue();
		$forwarder->processDue();

		self::assertTrue($breaker->opened);
		self::assertSame('parked', $store->row['state']);
		self::assertSame('dedupe_not_active', $store->row['reason']);
	}

	public function testDoesNotTrashWhenTrashbinIsDisabled(): void {
		[$forwarder, $store, $paperless, , $file] = $this->harness('invoice.pdf', false);
		$file->expects(self::never())->method('delete');
		$paperless->uploadResults = ['first-task', 'second-task'];
		$paperless->tasks = [
			'first-task' => ['status' => 'SUCCESS'],
			'second-task' => [
				'status' => 'FAILURE',
				'result_data' => ['duplicate_of' => 42, 'duplicate_in_trash' => false],
			],
		];
		$paperless->duplicateVerified = true;
		$this->completeFirstUpload($forwarder, $store);
		$store->makeDue();
		$forwarder->processDue();
		$store->makeDue();
		$forwarder->processDue();

		self::assertSame('parked', $store->row['state']);
		self::assertSame('trash_guard_failed', $store->row['reason']);
	}

	public function testTaskPollRetryKeepsTaskIdAndDoesNotReupload(): void {
		[$forwarder, $store, $paperless] = $this->harness();
		$paperless->uploadResults = ['pending-task'];
		$paperless->taskErrors = ['pending-task' => new PaperlessException('temporary outage')];
		$forwarder->processDue();
		$store->makeDue();
		$forwarder->processDue();

		self::assertSame('waiting', $store->row['state']);
		self::assertSame('pending-task', $store->row['task_id']);
		self::assertSame(1, $store->row['poll_attempts']);
		self::assertCount(1, $paperless->uploadedFiles);
	}

	/**
	 * @return array{Forwarder, InMemoryStateStore, ForwarderClient, TestBreakerState, File}
	 */
	private function harness(string $name = 'invoice.pdf', bool $trashbinEnabled = true): array {
		$config = new ForwarderConfig();
		$store = new InMemoryStateStore();
		$paperless = new ForwarderClient();
		$breaker = new TestBreakerState();
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(101);
		$file->method('getName')->willReturn($name);
		$file->method('getType')->willReturn('file');
		$file->method('getSize')->willReturn(1024);
		$file->method('getEtag')->willReturn('etag-101');
		$file->method('getPath')->willReturn('/forwarder-user/files/Paperless/' . $name);
		$file->method('getMtime')->willReturn(time() - 180);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getById')->with(101)->willReturn([$file]);
		$userFolder->method('getRelativePath')->willReturn('Paperless/' . $name);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('forwarder-user')->willReturn($userFolder);

		$userManager = $this->createMock(IUserManager::class);
		$user = $this->createMock(IUser::class);
		$userManager->method('get')->with('forwarder-user')->willReturn($user);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->with('files_trashbin', $user)->willReturn($trashbinEnabled);

		$forwarder = new Forwarder(
			$config,
			$store,
			$paperless,
			new TaskResult(),
			new PathFilter($config),
			$breaker,
			$rootFolder,
			$userManager,
			$appManager,
			$this->createMock(LoggerInterface::class),
		);
		return [$forwarder, $store, $paperless, $breaker, $file];
	}

	private function completeFirstUpload(Forwarder $forwarder, InMemoryStateStore $store): void {
		$forwarder->processDue();
		$store->makeDue();
		$forwarder->processDue();
	}

	/**
	 * @param list<string|Throwable> $results
	 */
	private function paperlessUploadResults(
		Forwarder $forwarder,
		InMemoryStateStore $store,
		ForwarderClient $paperless,
		array $results,
	): void {
		$paperless->uploadResults = $results;
		$forwarder->processDue();
		$store->makeDue();
		$forwarder->processDue();
	}
}

final class InMemoryStateStore extends StateStore {
	/** @var array<string, mixed> */
	public array $row = [
		'file_id' => 101,
		'owner_id' => 'forwarder-user',
		'path' => '/Paperless/invoice.pdf',
		'etag' => 'etag-101',
		'size' => 1024,
		'state' => 'queued',
		'rounds' => 0,
		'attempts' => 0,
		'poll_attempts' => 0,
		'rejections' => 0,
		'task_id' => null,
		'next_attempt_at' => 0,
		'reason' => null,
		'last_error' => null,
	];

	public function __construct() {
	}

	/** @return list<array<string, mixed>> */
	public function due(int $limit = 10): array {
		if (!in_array($this->row['state'], ['queued', 'waiting'], true) || (int)$this->row['next_attempt_at'] > time()) {
			return [];
		}
		return [$this->row];
	}

	/** @param array<string, int|string|null> $values */
	public function update(int $fileId, array $values): void {
		$this->row = array_merge($this->row, $values);
	}

	public function queue(File $file, string $ownerId, string $path): void {
		$this->row = array_merge($this->row, [
			'owner_id' => $ownerId,
			'path' => $path,
			'etag' => $file->getEtag(),
			'size' => $file->getSize(),
			'state' => 'queued',
			'rounds' => 0,
			'attempts' => 0,
			'poll_attempts' => 0,
			'rejections' => 0,
			'task_id' => null,
			'next_attempt_at' => time(),
		]);
	}

	public function makeDue(): void {
		$this->row['next_attempt_at'] = time();
	}
}

final class ForwarderClient extends PaperlessClient {
	/** @var list<string|Throwable> */
	public array $uploadResults = [];

	/** @var array<string, array<string, mixed>> */
	public array $tasks = [];

	/** @var array<string, PaperlessException> */
	public array $taskErrors = [];

	/** @var list<File> */
	public array $uploadedFiles = [];

	/** @var list<int> */
	public array $verifiedDocumentIds = [];

	public bool $duplicateVerified = false;

	public function __construct() {
	}

	public function upload(File $file): string {
		$this->uploadedFiles[] = $file;
		$result = array_shift($this->uploadResults);
		if ($result instanceof Throwable) {
			throw $result;
		}
		if (!is_string($result)) {
			throw new PaperlessException('No test upload result was configured');
		}
		return $result;
	}

	public function task(string $taskId): array {
		if (isset($this->taskErrors[$taskId])) {
			throw $this->taskErrors[$taskId];
		}
		return $this->tasks[$taskId] ?? [];
	}

	public function duplicateIsVerified(int $documentId): bool {
		$this->verifiedDocumentIds[] = $documentId;
		return $this->duplicateVerified;
	}
}

final class TestBreakerState extends BreakerState {
	public bool $opened = false;

	public function __construct() {
	}

	public function isOpen(): bool {
		return $this->opened;
	}

	public function open(string $reason): void {
		$this->opened = true;
	}
}
