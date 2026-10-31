<?php

declare(strict_types=1);

namespace OCA\PaperlessForwarder\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20260101000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('paperlessfwd_files')) {
			$table = $schema->createTable('paperlessfwd_files');
			$table->addColumn('file_id', 'bigint', ['notnull' => true]);
			$table->addColumn('owner_id', 'string', ['length' => 64, 'notnull' => true]);
			$table->addColumn('path', 'string', ['length' => 4000, 'notnull' => true]);
			$table->addColumn('etag', 'string', ['length' => 64, 'notnull' => true]);
			$table->addColumn('size', 'bigint', ['notnull' => true]);
			$table->addColumn('state', 'string', ['length' => 32, 'notnull' => true]);
			$table->addColumn('rounds', 'integer', ['notnull' => true, 'default' => 0]);
			$table->addColumn('attempts', 'integer', ['notnull' => true, 'default' => 0]);
			$table->addColumn('poll_attempts', 'integer', ['notnull' => true, 'default' => 0]);
			$table->addColumn('rejections', 'integer', ['notnull' => true, 'default' => 0]);
			$table->addColumn('task_id', 'string', ['length' => 64, 'notnull' => false]);
			$table->addColumn('next_attempt_at', 'bigint', ['notnull' => true, 'default' => 0]);
			$table->addColumn('reason', 'string', ['length' => 255, 'notnull' => false]);
			$table->addColumn('last_error', 'text', ['notnull' => false]);
			$table->addColumn('updated_at', 'bigint', ['notnull' => true]);
			$table->setPrimaryKey(['file_id']);
			$table->addIndex(['state', 'next_attempt_at'], 'paperlessfwd_due');
		}
		return $schema;
	}
}
