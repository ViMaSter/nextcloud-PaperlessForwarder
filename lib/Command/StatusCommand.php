<?php

declare(strict_types=1);

namespace OCA\PaperlessForwarder\Command;

use OCA\PaperlessForwarder\Service\BreakerState;
use OCA\PaperlessForwarder\Service\StateStore;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Command\Command;

final class StatusCommand extends Command {
	public function __construct(private StateStore $store, private BreakerState $breaker) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('paperlessforwarder:status')
			->setDescription('Show Paperless forwarder configuration and file state');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$output->writeln('Mode: ' . ((getenv('PAPERLESS_FORWARDER_MODE') === 'live' && getenv('PAPERLESS_API_TOKEN')) ? 'live' : 'off'));
		$output->writeln('Scope: ' . (string)(getenv('PAPERLESS_FORWARDER_PATH') ?: '/Paperless'));
		$output->writeln('Circuit breaker: ' . ($this->breaker->isOpen() ? $this->breaker->reason() : 'closed'));
		$rows = [];
		foreach ($this->store->all() as $row) {
			$rows[] = [
				(int)$row['file_id'],
				(string)$row['path'],
				(string)$row['state'],
				(string)($row['reason'] ?? ''),
				(string)($row['task_id'] ?? ''),
			];
		}
		(new Table($output))->setHeaders(['File ID', 'Path', 'State', 'Reason', 'Task ID'])->setRows($rows)->render();
		return 0;
	}
}
