<?php

declare(strict_types=1);

namespace OCA\PaperlessForwarder\Service;

use GuzzleHttp\Psr7\Utils;
use OCP\Http\Client\IClientService;
use OCP\Files\File;

class PaperlessClient {
	public function __construct(private IClientService $clients, private ForwarderConfig $config) {
	}

	public function upload(File $file): string {
		$client = $this->clients->newClient();
		$stream = $file->fopen('r');
		if (!is_resource($stream)) {
			throw new PaperlessException('Nextcloud source could not be opened for reading');
		}
		try {
			$response = $client->post($this->endpoint('/api/documents/post_document/'), [
				'http_errors' => false,
				'headers' => $this->headers(),
				'multipart' => [[
					'name' => 'document',
					// Encrypted storage streams can report their larger on-disk size.
					'contents' => Utils::streamFor($stream, ['size' => (int)$file->getSize()]),
					'filename' => $file->getName(),
				]],
			]);
		} catch (\GuzzleHttp\Exception\GuzzleException $exception) {
			throw new PaperlessException('Paperless upload request failed', 0, $exception);
		} finally {
			if (is_resource($stream)) {
				fclose($stream);
			}
		}
		$status = $response->getStatusCode();
		$body = (string)$response->getBody();
		if ($status === 400) {
			throw new UploadRejected($body);
		}
		if ($status < 200 || $status >= 300) {
			throw new PaperlessException('Paperless upload returned HTTP ' . $status);
		}
		try {
			$payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $exception) {
			throw new PaperlessException('Paperless upload response was not valid JSON', 0, $exception);
		}
		$taskId = is_array($payload) ? ($payload['task_id'] ?? null) : $payload;
		if (!is_string($taskId) && !is_int($taskId)) {
			throw new PaperlessException('Paperless upload response did not contain a task id');
		}
		return (string)$taskId;
	}

	/** @return array<string, mixed> */
	public function task(string $taskId): array {
		try {
			$response = $this->clients->newClient()->get($this->endpoint('/api/tasks/?task_id=' . rawurlencode($taskId)), [
				'http_errors' => false,
				'headers' => $this->headers(),
			]);
		} catch (\GuzzleHttp\Exception\GuzzleException $exception) {
			throw new PaperlessException('Paperless task lookup failed', 0, $exception);
		}
		if ($response->getStatusCode() !== 200) {
			throw new PaperlessException('Paperless task lookup returned HTTP ' . $response->getStatusCode());
		}
		try {
			$payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $exception) {
			throw new PaperlessException('Paperless task response was not valid JSON', 0, $exception);
		}
		if (!is_array($payload)) {
			throw new PaperlessException('Paperless task response had an unexpected shape');
		}
		if (isset($payload['results'][0]) && is_array($payload['results'][0])) {
			return $payload['results'][0];
		}
		if (isset($payload[0]) && is_array($payload[0])) {
			return $payload[0];
		}
		if (isset($payload['status'])) {
			return $payload;
		}
		throw new PaperlessException('Paperless task response had an unexpected shape');
	}

	public function duplicateIsVerified(int $documentId): bool {
		try {
			$response = $this->clients->newClient()->get($this->endpoint('/api/documents/' . $documentId . '/'), [
				'http_errors' => false,
				'headers' => $this->headers(),
			]);
		} catch (\GuzzleHttp\Exception\GuzzleException $exception) {
			throw new PaperlessException('Paperless duplicate verification failed', 0, $exception);
		}
		if ($response->getStatusCode() !== 200) {
			return false;
		}
		try {
			$document = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException $exception) {
			throw new PaperlessException('Paperless document response was not valid JSON', 0, $exception);
		}
		return is_array($document) && empty($document['deleted_at']);
	}

	/** @return array<string, string> */
	private function headers(): array {
		return [
			'Accept' => 'application/json; version=10',
			'Authorization' => 'Token ' . $this->config->token(),
		];
	}

	private function endpoint(string $path): string {
		$url = $this->config->paperlessUrl();
		if ($url === '') {
			throw new PaperlessException('PAPERLESS_URL is not configured');
		}
		return $url . $path;
	}
}
