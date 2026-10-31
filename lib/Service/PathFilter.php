<?php

declare(strict_types=1);

namespace OCA\PaperlessForwarder\Service;

use OCP\Files\Node;

final class PathFilter {
	public function __construct(private ForwarderConfig $config) {
	}

	public function eligible(Node $node, string $relativePath): bool {
		if ($node->getType() !== 'file' || $node->getSize() <= 0 || $node->getSize() > 268435456) {
			return false;
		}

		$name = $node->getName();
		if ($name === '' || $name[0] === '.' || str_starts_with($name, '~$')
			|| preg_match('/\.(part|tmp|crdownload|filepart)$/i', $name) === 1
			|| in_array(strtolower($name), ['thumbs.db', 'desktop.ini'], true)) {
			return false;
		}

		$prefix = trim($this->config->path(), '/');
		$relativePath = trim($relativePath, '/');
		return $relativePath === $prefix || str_starts_with($relativePath, $prefix . '/');
	}
}
