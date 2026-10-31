<?php

declare(strict_types=1);

namespace OCA\PaperlessForwarder\Listener;

use OCA\PaperlessForwarder\Service\ForwarderConfig;
use OCA\PaperlessForwarder\Service\PathFilter;
use OCA\PaperlessForwarder\Service\StateStore;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\AbstractNodeEvent;
use OCP\Files\Events\Node\AbstractNodesEvent;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

/** @template-implements IEventListener<AbstractNodeEvent|AbstractNodesEvent> */
final class FileEventListener implements IEventListener {
	public function __construct(
		private ForwarderConfig $config,
		private PathFilter $filter,
		private StateStore $store,
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$this->config->isLive()) {
			return;
		}
		if ($event instanceof AbstractNodeEvent) {
			$node = $event->getNode();
		} elseif ($event instanceof AbstractNodesEvent) {
			$node = $event->getTarget();
		} else {
			return;
		}
		if (!$node instanceof File) {
			return;
		}
		$owner = $node->getOwner();
		$userId = $owner?->getUID() ?? '';
		if ($userId === '' || $userId !== $this->config->userId()) {
			return;
		}
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$relativePath = $userFolder->getRelativePath($node->getPath());
		if (!is_string($relativePath)) {
			return;
		}
		if (!$this->filter->eligible($node, $relativePath)) {
			return;
		}
		try {
			$this->store->queue($node, $userId, '/' . trim($relativePath, '/'));
		} catch (\OCP\DB\Exception $exception) {
			$this->logger->error('Could not queue a Nextcloud file for Paperless forwarding', [
				'app' => ForwarderConfig::class,
				'fileId' => $node->getId(),
				'exception' => $exception,
			]);
		}
	}
}
