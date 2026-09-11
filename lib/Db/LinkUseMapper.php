<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<LinkUse> */
class LinkUseMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'sentinel_link_use', LinkUse::class);
	}

	public function find(int $shareId, int $day): ?LinkUse {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('share_id', $qb->createNamedParameter($shareId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('day', $qb->createNamedParameter($day, IQueryBuilder::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * What this link normally does, so that today can be compared against it
	 * rather than against a number somebody invented.
	 *
	 * @return array{days: int, views: int, networks: int}
	 */
	public function habit(int $shareId, int $beforeDay, int $days = 30): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'n'))
			->selectAlias($qb->func()->sum('views'), 'views')
			->selectAlias($qb->func()->max('network_count'), 'networks')
			->from($this->getTableName())
			->where($qb->expr()->eq('share_id', $qb->createNamedParameter($shareId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lt('day', $qb->createNamedParameter($beforeDay, IQueryBuilder::PARAM_INT)))
			->setMaxResults($days);
		$result = $qb->executeQuery();
		$row = $result->fetch() ?: [];
		$result->closeCursor();
		return [
			'days' => (int)($row['n'] ?? 0),
			'views' => (int)($row['views'] ?? 0),
			'networks' => (int)($row['networks'] ?? 0),
		];
	}

	/**
	 * Totals per link over a window, for the inventory.
	 *
	 * @return array<int, array{views: int, downloads: int, failures: int, networks: int, lastSeen: int}>
	 */
	public function totals(int $sinceDay): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('share_id')
			->selectAlias($qb->func()->sum('views'), 'views')
			->selectAlias($qb->func()->sum('downloads'), 'downloads')
			->selectAlias($qb->func()->sum('failures'), 'failures')
			->selectAlias($qb->func()->max('network_count'), 'networks')
			->selectAlias($qb->func()->max('last_seen'), 'last_seen')
			->from($this->getTableName())
			->where($qb->expr()->gte('day', $qb->createNamedParameter($sinceDay, IQueryBuilder::PARAM_INT)))
			->groupBy('share_id');
		$result = $qb->executeQuery();
		$out = [];
		while ($row = $result->fetch()) {
			$out[(int)$row['share_id']] = [
				'views' => (int)$row['views'],
				'downloads' => (int)$row['downloads'],
				'failures' => (int)$row['failures'],
				'networks' => (int)$row['networks'],
				'lastSeen' => (int)$row['last_seen'],
			];
		}
		$result->closeCursor();
		return $out;
	}

	public function prune(int $beforeDay): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->delete($this->getTableName())
			->where($qb->expr()->lt('day', $qb->createNamedParameter($beforeDay, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/** When a share is gone, so is everything remembered about it. */
	public function forgetShare(int $shareId): int {
		$qb = $this->db->getQueryBuilder();
		return $qb->delete($this->getTableName())
			->where($qb->expr()->eq('share_id', $qb->createNamedParameter($shareId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
