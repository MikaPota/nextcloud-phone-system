<?php

declare(strict_types=1);

namespace OCA\PhoneSystem\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000100Date20261110 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('phone_system_calls')) {
			return null;
		}
		$table = $schema->createTable('phone_system_calls');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('direction', Types::STRING, ['notnull' => true, 'length' => 8]);
		$table->addColumn('remote', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('remote_name', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('outcome', Types::STRING, ['notnull' => true, 'length' => 16]);
		$table->addColumn('started_at', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('duration', Types::INTEGER, ['notnull' => true, 'default' => 0]);
		$table->setPrimaryKey(['id']);
		$table->addIndex(['user_id', 'started_at'], 'phone_calls_user_time');
		return $schema;
	}
}
