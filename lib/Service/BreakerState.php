<?php

declare(strict_types=1);

namespace OCA\PaperlessForwarder\Service;

use OCP\IConfig;

class BreakerState {
	public function __construct(private IConfig $config) {
	}

	public function isOpen(): bool {
		return $this->config->getAppValue('paperlessforwarder', 'circuit_breaker', '') !== '';
	}

	public function reason(): string {
		return $this->config->getAppValue('paperlessforwarder', 'circuit_breaker', '');
	}

	public function open(string $reason): void {
		$this->config->setAppValue('paperlessforwarder', 'circuit_breaker', $reason);
	}

	public function reset(): void {
		$this->config->deleteAppValue('paperlessforwarder', 'circuit_breaker');
	}
}
