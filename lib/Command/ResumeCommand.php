<?php

declare(strict_types=1);

namespace OCA\PaperlessForwarder\Command;

use OCA\PaperlessForwarder\Service\BreakerState;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ResumeCommand extends Command {
	public function __construct(private BreakerState $breaker) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('paperlessforwarder:resume')
			->setDescription('Reset the circuit breaker after investigation');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$this->breaker->reset();
		$output->writeln('Paperless forwarder circuit breaker reset.');
		return 0;
	}
}
