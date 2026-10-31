<?php

declare(strict_types=1);

namespace OCA\PaperlessForwarder\Jobs;

use OCA\PaperlessForwarder\Service\Forwarder;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

final class ProcessJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private Forwarder $forwarder,
	) {
		parent::__construct($time);
		$this->setInterval(60);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		$this->forwarder->processDue();
	}
}
