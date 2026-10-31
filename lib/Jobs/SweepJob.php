<?php

declare(strict_types=1);

namespace OCA\PaperlessForwarder\Jobs;

use OCA\PaperlessForwarder\Service\Sweeper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

final class SweepJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private Sweeper $sweeper,
	) {
		parent::__construct($time);
		$this->setInterval(1800);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(false);
	}

	protected function run($argument): void {
		$this->sweeper->sweep();
	}
}
