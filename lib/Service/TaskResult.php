<?php

declare(strict_types=1);

namespace OCA\PaperlessForwarder\Service;

final class TaskResult {
	/**
	 * @param array<string, mixed> $task
	 * @return array{outcome: string, duplicate_id?: int, duplicate_in_trash?: bool, status: string}
	 */
	public function classify(array $task): array {
		$status = strtoupper((string)($task['status'] ?? ''));
		$result = $task['result_data'] ?? [];
		if ($result === null) {
			$result = [];
		}
		if (is_string($result)) {
			try {
				$decoded = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
			} catch (\JsonException $exception) {
				throw new PaperlessException('Paperless task result_data was not valid JSON', 0, $exception);
			}
			$result = $decoded;
		}
		if (!is_array($result)) {
			throw new PaperlessException('Paperless task result_data had an unexpected shape');
		}
		$duplicateId = filter_var($result['duplicate_of'] ?? null, FILTER_VALIDATE_INT);
		if ($duplicateId !== false && $duplicateId > 0) {
			return [
				'outcome' => 'duplicate',
				'duplicate_id' => $duplicateId,
				'duplicate_in_trash' => (bool)($result['duplicate_in_trash'] ?? false),
				'status' => $status,
			];
		}
		if (in_array($status, ['PENDING', 'STARTED', 'RECEIVED', 'RETRY'], true)) {
			return ['outcome' => 'pending', 'status' => $status];
		}
		if ($status === 'SUCCESS') {
			return ['outcome' => 'success', 'status' => $status];
		}
		if ($status === 'FAILURE') {
			return ['outcome' => 'failure', 'status' => $status];
		}
		throw new PaperlessException('Paperless task response had an unknown status');
	}
}
