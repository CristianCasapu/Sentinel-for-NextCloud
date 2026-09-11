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

class Version1001Date20260911210000 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// How each public link is actually being used.
		//
		// A link with no password is not a mistake — it is a decision, and a
		// perfectly ordinary one. What is worth knowing is not that the door is
		// open but that somebody unexpected has started walking through it: a
		// link shared with two people being opened from forty networks in an
		// afternoon is the same link doing something entirely different.
		//
		// One row per link per day, so that today can be compared against what
		// that particular link normally does rather than against a number I
		// picked out of the air.
		if (!$schema->hasTable('sentinel_link_use')) {
			$t = $schema->createTable('sentinel_link_use');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('share_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('token', Types::STRING, ['notnull' => false, 'length' => 64]);
			$t->addColumn('day', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('views', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('downloads', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('failures', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			// The networks it was opened from today, as a short list. Networks,
			// not addresses, and capped: this is for recognising a crowd, not
			// for keeping a register of who visited.
			$t->addColumn('networks', Types::TEXT, ['notnull' => false]);
			$t->addColumn('network_count', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('last_seen', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['share_id', 'day'], 'sentinel_link_share_day');
			$t->addIndex(['day'], 'sentinel_link_day');
		}

		return $schema;
	}
}
