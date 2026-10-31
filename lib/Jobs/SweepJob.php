<?php

declare(strict_types=1);

namespace OCA\PaperlessForwarder\Jobs;

use OCA\PaperlessForwarder\Service\Sweeper;
use OCP\BackgroundJob\TimedJob;
use OCP\ILogger;
use OCP\ITimeFactory;

final class SweepJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		ILogger $logger,
		private Sweeper $sweeper,
	) {
		parent::__construct($time, $logger);
		$this->setInterval(1800);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		$this->sweeper->sweep();
	}
}
