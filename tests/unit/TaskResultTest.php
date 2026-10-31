<?php

declare(strict_types=1);

namespace OCA\PaperlessForwarder\Tests\Unit;

use OCA\PaperlessForwarder\Service\PaperlessException;
use OCA\PaperlessForwarder\Service\TaskResult;
use PHPUnit\Framework\TestCase;

final class TaskResultTest extends TestCase {
	public function testClassifiesPendingTask(): void {
		self::assertSame(['outcome' => 'pending', 'status' => 'STARTED'], (new TaskResult())->classify(['status' => 'started']));
	}

	public function testClassifiesSuccessfulTask(): void {
		self::assertSame(['outcome' => 'success', 'status' => 'SUCCESS'], (new TaskResult())->classify(['status' => 'SUCCESS']));
	}

	public function testClassifiesDuplicateTaskWithJsonResultData(): void {
		self::assertSame([
			'outcome' => 'duplicate',
			'duplicate_id' => 42,
			'duplicate_in_trash' => false,
			'status' => 'FAILURE',
		], (new TaskResult())->classify([
			'status' => 'FAILURE',
			'result_data' => '{"duplicate_of":42,"duplicate_in_trash":false}',
		]));
	}

	public function testKeepsDuplicateTrashFlag(): void {
		$result = (new TaskResult())->classify([
			'status' => 'FAILURE',
			'result_data' => ['duplicate_of' => '42', 'duplicate_in_trash' => true],
		]);
		self::assertTrue($result['duplicate_in_trash']);
	}

	public function testClassifiesProcessingFailureWithoutDuplicate(): void {
		self::assertSame(['outcome' => 'failure', 'status' => 'FAILURE'], (new TaskResult())->classify(['status' => 'FAILURE']));
	}

	public function testRejectsUnexpectedTaskShape(): void {
		$this->expectException(PaperlessException::class);
		(new TaskResult())->classify(['status' => 'mystery']);
	}

	public function testRejectsMalformedResultData(): void {
		$this->expectException(PaperlessException::class);
		(new TaskResult())->classify(['status' => 'FAILURE', 'result_data' => '{']);
	}
}
