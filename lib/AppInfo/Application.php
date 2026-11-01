<?php

declare(strict_types=1);

namespace OCA\PaperlessForwarder\AppInfo;

use OCA\PaperlessForwarder\Jobs\ProcessJob;
use OCA\PaperlessForwarder\Jobs\SweepJob;
use OCA\PaperlessForwarder\Listener\FileEventListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\BackgroundJob\IJobList;

class Application extends App implements IBootstrap {
	public const APP_ID = 'paperlessforwarder';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		foreach ([NodeCreatedEvent::class, NodeWrittenEvent::class, NodeRenamedEvent::class, NodeCopiedEvent::class] as $event) {
			$context->registerEventListener($event, FileEventListener::class);
		}
	}

	public function boot(IBootContext $context): void {
		$jobs = $context->getAppContainer()->get(IJobList::class);
		if (!$jobs->has(ProcessJob::class, null)) {
			$jobs->add(ProcessJob::class);
		}
		if (!$jobs->has(SweepJob::class, null)) {
			$jobs->add(SweepJob::class);
		}
	}
}
