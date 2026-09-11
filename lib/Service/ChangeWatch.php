<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\Service;

use OCP\ICacheFactory;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Somebody's files are being changed very fast.
 *
 * This is the one thing on a Nextcloud server that can destroy everything in an
 * afternoon without a single password being wrong. A laptop with a sync client
 * catches ransomware; the ransomware encrypts the synced folder; the client
 * does exactly what it is supposed to do and faithfully uploads every encrypted
 * file over the originals. Nothing was breached. Nothing looks unusual to any
 * check that asks about passwords and permissions. The files are simply gone,
 * and the versions go with them once the quota rolls over.
 *
 * The signature is unmistakable and needs no cleverness to spot: an account
 * rewriting or deleting hundreds of files in a few minutes, which no human
 * being does by hand. What it needs is to be noticed in the first ten minutes
 * rather than the next morning — so the counting happens in the cache, one
 * increment per file, and the server can be told to pull the account's plug
 * by itself if that is what you want it to do.
 */
class ChangeWatch {
	public const TELL = 'tell';
	public const LOCK = 'lock';

	public function __construct(
		private ICacheFactory $caches,
		private IUserManager $users,
		private Inventory $inventory,
		private Journal $journal,
		private Settings $settings,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * One file changed.
	 *
	 * Called for every write, delete and rename on the server, so everything
	 * here is one cache increment and an integer comparison. It must never
	 * throw: a file save failing because a counter could not be written would
	 * be a far worse outcome than a missed alarm.
	 *
	 * @param string $kind one of write, delete, rename
	 */
	public function touched(string $uid, string $kind, string $name): void {
		if ($uid === '' || !$this->settings->watchChurn()) {
			return;
		}
		if (in_array($uid, $this->settings->ignoredUids(), true)) {
			return;
		}

		try {
			$window = $this->settings->churnWindow();
			$cache = $this->caches->createDistributed('sentinel_churn');

			$destructive = $kind === 'delete' || $kind === 'write';
			$count = $this->bump($cache, $uid . ':' . $kind, $window);
			$total = $destructive ? $this->bump($cache, $uid . ':all', $window) : 0;

			// Renames are counted for the record but judged only through the
			// extension they land on: moving a folder of holiday photos is a
			// thousand renames and is nobody's emergency.
			if ($kind === 'rename') {
				$this->watchExtension($cache, $uid, $name, $window);
				return;
			}

			$limit = $kind === 'delete' ? $this->settings->churnDeletes() : $this->settings->churnWrites();
			// Exactly equal, so this fires once as the line is crossed rather
			// than on every file after it.
			if ($count === $limit) {
				$this->raise($uid, $kind, $count, $window, ['deletes' => $kind === 'delete' ? $count : null, 'total' => $total]);
			}
		} catch (\Throwable $e) {
			$this->logger->debug('Sentinel could not count a file change', ['exception' => $e]);
		}
	}

	/**
	 * Ransomware renames what it encrypts, and it renames it all to the same
	 * thing. Several hundred files arriving at one unfamiliar extension in a
	 * few minutes is that and almost nothing else.
	 */
	private function watchExtension($cache, string $uid, string $name, int $window): void {
		$dot = strrpos($name, '.');
		if ($dot === false || $dot === 0) {
			return;
		}
		$extension = strtolower(substr($name, $dot + 1));
		if ($extension === '' || strlen($extension) > 16) {
			return;
		}

		$count = $this->bump($cache, $uid . ':ext:' . $extension, $window);
		if ($count === $this->settings->churnRenames()) {
			$this->raise($uid, 'rename', $count, $window, ['extension' => $extension]);
		}
	}

	/**
	 * @param array<string, mixed> $extra
	 */
	private function raise(string $uid, string $kind, int $count, int $window, array $extra = []): void {
		$minutes = max(1, (int)round($window / 60));
		$summary = match ($kind) {
			'delete' => $uid . ' has deleted ' . $count . ' files in ' . $minutes . ' minutes.',
			'rename' => $uid . ' has renamed ' . $count . ' files to .' . ($extra['extension'] ?? '?') . ' in ' . $minutes . ' minutes.',
			default => $uid . ' has rewritten ' . $count . ' files in ' . $minutes . ' minutes.',
		};

		$acted = null;
		if ($this->settings->churnResponse() === self::LOCK) {
			$acted = $this->pullThePlug($uid);
			$summary .= $acted
				? ' The account has been disabled and its sessions ended.'
				: ' The account could not be disabled; the server log says why.';
		}

		$this->journal->record(
			'sentinel_churn',
			Journal::ALARM,
			$summary,
			subject: $uid . ':' . $kind,
			actor: $uid,
			address: null,
			detail: array_filter([
				'uid' => $uid,
				'kind' => $kind,
				'count' => $count,
				'windowSeconds' => $window,
				'response' => $this->settings->churnResponse(),
				'locked' => $acted,
			] + $extra, static fn ($v) => $v !== null),
			// Once per account per kind per hour. The situation does not become
			// more true by being said again while somebody deals with it.
			quiet: 3600,
		);
	}

	/**
	 * Stop it, now.
	 *
	 * Disabling the account ends nothing on its own — a sync client holds a
	 * token and keeps going — so the tokens go too. This is off by default and
	 * has to be asked for: a server that locks somebody out because they
	 * restored a backup into their folder is its own kind of incident.
	 */
	private function pullThePlug(string $uid): bool {
		try {
			$user = $this->users->get($uid);
			if ($user === null) {
				return false;
			}
			$user->setEnabled(false);
			$this->inventory->revokeAllTokens($uid);
			return true;
		} catch (\Throwable $e) {
			$this->logger->error('Sentinel could not disable an account it meant to stop', ['exception' => $e, 'uid' => $uid]);
			return false;
		}
	}

	private function bump($cache, string $key, int $ttl): int {
		$count = (int)$cache->get($key);
		$count++;
		$cache->set($key, $count, $ttl);
		return $count;
	}

	/**
	 * What the counters say right now, for the page. Nothing is stored on disk
	 * for this: if the window has passed, there is nothing to show.
	 *
	 * @return array<int, array{uid: string, kind: string, count: int}>
	 */
	public function busy(): array {
		$out = [];
		try {
			$cache = $this->caches->createDistributed('sentinel_churn');
			$this->users->callForAllUsers(function ($user) use ($cache, &$out): void {
				$uid = $user->getUID();
				foreach (['write', 'delete'] as $kind) {
					$count = (int)$cache->get($uid . ':' . $kind);
					if ($count > 0) {
						$out[] = ['uid' => $uid, 'kind' => $kind, 'count' => $count];
					}
				}
			});
		} catch (\Throwable $e) {
			$this->logger->debug('Sentinel could not read the change counters', ['exception' => $e]);
		}
		usort($out, static fn (array $a, array $b) => $b['count'] <=> $a['count']);
		return array_slice($out, 0, 20);
	}
}
