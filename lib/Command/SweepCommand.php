<?php

declare(strict_types=1);

namespace OCA\PaperlessForwarder\Command;

use OCA\PaperlessForwarder\Service\Sweeper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class SweepCommand extends Command {
	public function __construct(private Sweeper $sweeper) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('paperlessforwarder:sweep')
			->setDescription('Queue eligible files from the configured Nextcloud folder')
			->addOption('list', null, InputOption::VALUE_NONE, 'List eligible files without queueing them');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$listOnly = (bool)$input->getOption('list');
		$files = $this->sweeper->sweep($listOnly);
		foreach ($files as $file) {
			$output->writeln($file['id'] . "\t" . $file['path']);
		}
		$output->writeln(count($files) . ($listOnly ? ' eligible file(s)' : ' file(s) queued or refreshed'));
		return 0;
	}
}
