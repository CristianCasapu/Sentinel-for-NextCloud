<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Event> */
class EventMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'sentinel_events', Event::class);
	}

	/** @return Event[] */
	public function recent(int $limit = 100, int $offset = 0, string $severity = ''): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->orderBy('occurred', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults($limit)
			->setFirstResult($offset);
		if ($severity !== '') {
			$qb->where($qb->expr()->eq('severity', $qb->createNamedParameter($severity)));
		}
		return $this->findEntities($qb);
	}

	public function unseen(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'n'))->from($this->getTableName())
			->where($qb->expr()->eq('seen', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$n = (int)$result->fetchOne();
		$result->closeCursor();
		return $n;
	}

	public function markAllSeen(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('seen', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('seen', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * Has this exact thing already been said recently? Watchers run on a
	 * schedule and see the same standing situation every time; saying it once
	 * is a report, saying it every ten minutes is noise that trains people to
	 * ignore the page.
	 */
	public function saidRecently(string $kind, ?string $subject, int $within): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'n'))->from($this->getTableName())
			->where($qb->expr()->eq('kind', $qb->createNamedParameter($kind)))
			->andWhere($qb->expr()->gt('occurred', $qb->createNamedParameter(time() - $within, IQueryBuilder::PARAM_INT)));
		if ($subject === null) {
			$qb->andWhere($qb->expr()->isNull('subject'));
		} else {
			$qb->andWhere($qb->expr()->eq('subject', $qb->createNamedParameter($subject)));
		}
		$result = $qb->executeQuery();
		$n = (int)$result->fetchOne();
		$result->closeCursor();
		return $n > 0;
	}

	/** Nothing is kept for ever; a record nobody will read is only a liability. */
	public function prune(int $olderThan): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->delete($this->getTableName())
			->where($qb->expr()->lt('occurred', $qb->createNamedParameter(time() - $olderThan, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	public function total(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'n'))->from($this->getTableName());
		$result = $qb->executeQuery();
		$n = (int)$result->fetchOne();
		$result->closeCursor();
		return $n;
	}
}
