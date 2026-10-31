<?php

declare(strict_types=1);

namespace OCA\PaperlessForwarder\Command;

use OCA\PaperlessForwarder\Service\StateStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RetryCommand extends Command {
	public function __construct(private StateStore $store) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('paperlessforwarder:retry')
			->setDescription('Requeue parked files');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$output->writeln($this->store->retryParked() . ' parked file(s) requeued.');
		return 0;
	}
}
