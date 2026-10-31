<?php

declare(strict_types=1);

namespace OCA\PaperlessForwarder\Command;

use OCA\PaperlessForwarder\Service\Forwarder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ProcessCommand extends Command {
	public function __construct(private Forwarder $forwarder) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('paperlessforwarder:process')
			->setDescription('Process currently due files once');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$this->forwarder->processDue();
		$output->writeln('Processed due Paperless forwarder work.');
		return 0;
	}
}
