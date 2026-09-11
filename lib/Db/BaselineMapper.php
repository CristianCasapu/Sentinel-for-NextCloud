<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<BaselineFile> */
class BaselineMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'sentinel_baseline', BaselineFile::class);
	}

	/**
	 * The whole baseline as path => [digest, size], which is the shape the
	 * comparison wants. A full installation is tens of thousands of rows and
	 * hydrating an entity for each one costs more than the walk of the disk
	 * itself.
	 *
	 * @return array<string, array{digest: string, size: int}>
	 */
	public function digests(string $scope = ''): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('path', 'digest', 'size')->from($this->getTableName());
		if ($scope !== '') {
			$qb->where($qb->expr()->eq('scope', $qb->createNamedParameter($scope)));
		}
		$result = $qb->executeQuery();
		$out = [];
		while ($row = $result->fetch()) {
			$out[(string)$row['path']] = ['digest' => (string)$row['digest'], 'size' => (int)$row['size']];
		}
		$result->closeCursor();
		return $out;
	}

	public function byPath(string $path): ?BaselineFile {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('path', $qb->createNamedParameter($path)));
		try {
			return $this->findEntity($qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return null;
		}
	}

	public function count(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'n'))->from($this->getTableName());
		$result = $qb->executeQuery();
		$n = (int)$result->fetchOne();
		$result->closeCursor();
		return $n;
	}

	/** Replace the entire baseline in one go, in batches the database is happy with. */
	public function replaceAll(array $files, string $actor, int $now): int {
		$this->db->beginTransaction();
		try {
			$delete = $this->db->getQueryBuilder();
			$delete->delete($this->getTableName())->executeStatement();

			$written = 0;
			foreach (array_chunk($files, 500, true) as $chunk) {
				foreach ($chunk as $path => $info) {
					$insert = $this->db->getQueryBuilder();
					$insert->insert($this->getTableName())->values([
						'path' => $insert->createNamedParameter($path),
						'digest' => $insert->createNamedParameter($info['digest']),
						'size' => $insert->createNamedParameter($info['size'], IQueryBuilder::PARAM_INT),
						'scope' => $insert->createNamedParameter($info['scope'] ?? 'core'),
						'acknowledged_by' => $insert->createNamedParameter($actor),
						'acknowledged_at' => $insert->createNamedParameter($now, IQueryBuilder::PARAM_INT),
						'first_seen' => $insert->createNamedParameter($now, IQueryBuilder::PARAM_INT),
					])->executeStatement();
					$written++;
				}
			}
			$this->db->commit();
			return $written;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	public function forget(string $path): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('path', $qb->createNamedParameter($path)))
			->executeStatement();
	}

	public function clear(): void {
		$this->db->getQueryBuilder()->delete($this->getTableName())->executeStatement();
	}
}
