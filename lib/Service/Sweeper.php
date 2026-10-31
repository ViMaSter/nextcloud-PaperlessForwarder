<?php

declare(strict_types=1);

namespace OCA\PaperlessForwarder\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\ILogger;

final class Sweeper {
	public function __construct(
		private ForwarderConfig $config,
		private PathFilter $filter,
		private StateStore $store,
		private IRootFolder $rootFolder,
		private ILogger $logger,
	) {
	}

	/** @return list<array{id: int, path: string}> */
	public function sweep(bool $listOnly = false): array {
		if ((!$listOnly && !$this->config->isLive()) || $this->config->userId() === '') {
			return [];
		}
		$userFolder = $this->rootFolder->getUserFolder($this->config->userId());
		try {
			$target = $userFolder->get(trim($this->config->path(), '/'));
		} catch (\OCP\Files\NotFoundException $exception) {
			$this->logger->warning('Paperless forwarder scope does not exist in Nextcloud', [
				'app' => 'paperlessforwarder',
				'path' => $this->config->path(),
			]);
			return [];
		}
		if (!$target instanceof Folder) {
			$this->logger->warning('Paperless forwarder scope is not a folder', ['app' => 'paperlessforwarder']);
			return [];
		}
		$files = [];
		$this->walk($target, $userFolder, $files, $listOnly);
		return $files;
	}

	/**
	 * @param list<array{id: int, path: string}> $files
	 */
	private function walk(Folder $folder, Folder $userFolder, array &$files, bool $listOnly): void {
		foreach ($folder->getDirectoryListing() as $node) {
			$relativePath = $userFolder->getRelativePath($node->getPath());
			if (!is_string($relativePath)) {
				continue;
			}
			if ($node instanceof Folder) {
				$this->walk($node, $userFolder, $files, $listOnly);
				continue;
			}
			if (!$node instanceof File || !$this->filter->eligible($node, $relativePath)) {
				continue;
			}
			$path = '/' . trim($relativePath, '/');
			$files[] = ['id' => $node->getId(), 'path' => $path];
			if (!$listOnly) {
				$owner = $node->getOwner();
				if ($owner !== null) {
					$this->store->queue($node, $owner->getUID(), $path);
				}
			}
		}
	}
}
