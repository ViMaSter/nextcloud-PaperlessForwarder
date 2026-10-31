<?php

declare(strict_types=1);

namespace OCA\PaperlessForwarder\Tests\Unit;

use OCA\PaperlessForwarder\Service\ForwarderConfig;
use OCA\PaperlessForwarder\Service\PathFilter;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;

final class PathFilterTest extends TestCase {
	protected function setUp(): void {
		putenv('PAPERLESS_FORWARDER_PATH=/Paperless');
	}

	public function testAcceptsEligibleFileUnderScope(): void {
		self::assertTrue($this->filterFile('invoice.pdf', 100, 'Paperless/2026/invoice.pdf'));
	}

	public function testRejectsPathPrefixCollision(): void {
		self::assertFalse($this->filterFile('invoice.pdf', 100, 'Paperless-old/invoice.pdf'));
	}

	public function testRejectsEmptyOrOversizedFiles(): void {
		self::assertFalse($this->filterFile('empty.pdf', 0, 'Paperless/empty.pdf'));
		self::assertFalse($this->filterFile('large.pdf', 268435457, 'Paperless/large.pdf'));
	}

	public function testRejectsHiddenAndTemporaryFiles(): void {
		foreach (['.hidden.pdf', '~$draft.docx', 'partial.tmp', 'download.crdownload', 'Thumbs.db', 'desktop.ini'] as $name) {
			self::assertFalse($this->filterFile($name, 100, 'Paperless/' . $name), $name);
		}
	}

	private function filterFile(string $name, int $size, string $path): bool {
		$file = $this->createMock(File::class);
		$file->method('getType')->willReturn('file');
		$file->method('getName')->willReturn($name);
		$file->method('getSize')->willReturn($size);
		return (new PathFilter(new ForwarderConfig()))->eligible($file, $path);
	}
}
