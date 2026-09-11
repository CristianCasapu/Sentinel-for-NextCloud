<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20260911200000 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// What every file looked like when somebody last looked at it and said
		// it was fine. A change on its own means nothing — installations are
		// patched for good reasons — so what is recorded is the judgement, not
		// merely the hash.
		if (!$schema->hasTable('sentinel_baseline')) {
			$t = $schema->createTable('sentinel_baseline');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('path', Types::STRING, ['notnull' => true, 'length' => 512]);
			$t->addColumn('digest', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('size', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('scope', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => 'core']);
			// Why this file is allowed to differ from what shipped.
			$t->addColumn('note', Types::STRING, ['notnull' => false, 'length' => 1000]);
			$t->addColumn('acknowledged_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('acknowledged_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('first_seen', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['path'], 'sentinel_baseline_path');
			$t->addIndex(['scope'], 'sentinel_baseline_scope');
		}

		// Things worth being told about, kept so that "when did this start" has
		// an answer.
		if (!$schema->hasTable('sentinel_events')) {
			$t = $schema->createTable('sentinel_events');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('severity', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'notice']);
			$t->addColumn('subject', Types::STRING, ['notnull' => false, 'length' => 255]);
			$t->addColumn('actor', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('address', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('summary', Types::STRING, ['notnull' => true, 'length' => 1000]);
			$t->addColumn('detail', Types::TEXT, ['notnull' => false]);
			$t->addColumn('occurred', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('seen', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['occurred'], 'sentinel_events_when');
			$t->addIndex(['kind'], 'sentinel_events_kind');
			$t->addIndex(['seen'], 'sentinel_events_seen');
		}

		// Where each account was last seen signing in from, so that somewhere
		// new is recognisable as new.
		if (!$schema->hasTable('sentinel_places')) {
			$t = $schema->createTable('sentinel_places');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('network', Types::STRING, ['notnull' => true, 'length' => 64]);
			$t->addColumn('first_seen', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('last_seen', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('sightings', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['uid', 'network'], 'sentinel_place_uid_net');
		}

		return $schema;
	}
}
