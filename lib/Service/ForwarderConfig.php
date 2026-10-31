<?php

declare(strict_types=1);

namespace OCA\PaperlessForwarder\Service;

final class ForwarderConfig {
	public function enabled(): bool {
		return $this->isLive() && $this->token() !== '';
	}

	public function isLive(): bool {
		return $this->mode() === 'live';
	}

	public function mode(): string {
		$mode = getenv('PAPERLESS_FORWARDER_MODE');
		return $mode === 'live' ? 'live' : 'off';
	}

	public function userId(): string {
		return trim((string)(getenv('PAPERLESS_FORWARDER_USER') ?: ''));
	}

	public function path(): string {
		$path = trim((string)(getenv('PAPERLESS_FORWARDER_PATH') ?: '/Paperless'), '/');
		return $path === '' ? '/' : '/' . $path;
	}

	public function token(): string {
		return trim((string)(getenv('PAPERLESS_API_TOKEN') ?: ''));
	}

	public function paperlessUrl(): string {
		return rtrim(trim((string)(getenv('PAPERLESS_URL') ?: '')), '/');
	}
}
