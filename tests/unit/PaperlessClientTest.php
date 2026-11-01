<?php

declare(strict_types=1);

namespace OCA\PaperlessForwarder\Tests\Unit;

use GuzzleHttp\Psr7\MultipartStream;
use OCA\PaperlessForwarder\Service\ForwarderConfig;
use OCA\PaperlessForwarder\Service\PaperlessClient;
use OCA\PaperlessForwarder\Service\PaperlessException;
use OCP\Files\File;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;

final class PaperlessClientTest extends TestCase {
	protected function setUp(): void {
		putenv('PAPERLESS_URL=https://paperless.example.invalid');
		putenv('PAPERLESS_API_TOKEN=test-token');
	}

	public function testMultipartLengthUsesReadableSizeInsteadOfStorageStat(): void {
		self::assertTrue(stream_wrapper_register('paperless-test', InflatedStatStream::class));
		$stream = fopen('paperless-test://document', 'r');
		try {
			self::assertGreaterThan(InflatedStatStream::SIZE, fstat($stream)['size']);
			$file = $this->createMock(File::class);
			$file->method('fopen')->with('r')->willReturn($stream);
			$file->method('getName')->willReturn('large.pdf');
			$file->method('getSize')->willReturn(InflatedStatStream::SIZE);

			$response = $this->createMock(IResponse::class);
			$response->method('getStatusCode')->willReturn(200);
			$response->method('getBody')->willReturn('"upload-task"');
			$http = $this->createMock(IClient::class);
			$http->expects(self::once())->method('post')->willReturnCallback(
				function (string $url, array $options) use ($response): IResponse {
					self::assertSame('https://paperless.example.invalid/api/documents/post_document/', $url);
					$multipart = new MultipartStream($options['multipart'], 'test-boundary');
					$declaredSize = $multipart->getSize();
					$body = (string)$multipart;
					self::assertSame(strlen($body), $declaredSize);
					$content = explode("\r\n\r\n", $body, 2)[1];
					$content = explode("\r\n--test-boundary", $content, 2)[0];
					self::assertSame(InflatedStatStream::SIZE, strlen($content));
					self::assertSame(hash('sha256', str_repeat('x', InflatedStatStream::SIZE)), hash('sha256', $content));
					return $response;
				},
			);
			$clients = $this->createMock(IClientService::class);
			$clients->method('newClient')->willReturn($http);

			self::assertSame('upload-task', (new PaperlessClient($clients, new ForwarderConfig()))->upload($file));
			self::assertFalse(is_resource($stream));
		} finally {
			if (is_resource($stream)) {
				fclose($stream);
			}
			stream_wrapper_unregister('paperless-test');
		}
	}

	public function testUnreadableSourceRaisesAnExplicitForwardingError(): void {
		$file = $this->createMock(File::class);
		$file->method('fopen')->with('r')->willReturn(false);
		$http = $this->createMock(IClient::class);
		$http->expects(self::never())->method('post');
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($http);

		$this->expectException(PaperlessException::class);
		$this->expectExceptionMessage('Nextcloud source could not be opened for reading');
		(new PaperlessClient($clients, new ForwarderConfig()))->upload($file);
	}
}

final class InflatedStatStream {
	public const SIZE = 2 * 1024 * 1024;
	public mixed $context;
	private int $position = 0;

	public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
		$this->position = 0;
		return true;
	}

	public function stream_read(int $count): string {
		$count = min($count, self::SIZE - $this->position);
		$this->position += $count;
		return str_repeat('x', $count);
	}

	public function stream_eof(): bool {
		return $this->position >= self::SIZE;
	}

	public function stream_tell(): int {
		return $this->position;
	}

	public function stream_seek(int $offset, int $whence): bool {
		$position = match ($whence) {
			SEEK_SET => $offset,
			SEEK_CUR => $this->position + $offset,
			SEEK_END => self::SIZE + $offset,
			default => -1,
		};
		if ($position < 0 || $position > self::SIZE) {
			return false;
		}
		$this->position = $position;
		return true;
	}

	public function stream_stat(): array {
		return ['size' => self::SIZE + 8192];
	}
}
