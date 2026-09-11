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
 * check that asks about passwords and permissions.
 *
 * Rate is how you notice it. Rate is not how you decide.
 *
 * Five hundred files in five minutes is a phone finishing its first backup at
 * least as often as it is an encryptor working through a folder, and an alarm
 * that goes off for the first is one that gets switched off before the second
 * ever arrives. So the rate only opens the question, and something else has to
 * answer it: files that no longer are what their names say they are, a wave of
 * renames onto one new extension, a ransom note, or the process watcher outside
 * saying it has seen the thing doing it.
 *
 * Without one of those, a fast day is only a fast day.
 */
class ChangeWatch {
	public const TELL = 'tell';
	public const LOCK = 'lock';

	/** Wrong files one after another, which no working system produces. */
	private const TELLING_STREAK = 10;

	public function __construct(
		private ICacheFactory $caches,
		private IUserManager $users,
		private Inventory $inventory,
		private FileKinds $kinds,
		private Edr $edr,
		private Journal $journal,
		private Settings $settings,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * One file changed.
	 *
	 * Called for every write, delete and rename on the server, so the common
	 * path is one cache increment and an integer comparison. It must never
	 * throw: a file save failing because a counter could not be written would
	 * be a far worse outcome than a missed alarm.
	 *
	 * @param string $kind one of write, delete, rename
	 * @param (callable(): string)|null $head Reads the first bytes of the file.
	 *        Called only while sampling, which only happens once the rate is
	 *        already halfway to being interesting.
	 */
	public function touched(string $uid, string $kind, string $name, ?callable $head = null): void {
		if ($uid === '' || !$this->settings->watchChurn()) {
			return;
		}
		if (in_array($uid, $this->settings->ignoredUids(), true)) {
			return;
		}

		try {
			$window = $this->settings->churnWindow();
			$cache = $this->caches->createDistributed('sentinel_churn');

			$count = $this->bump($cache, $uid . ':' . $kind, $window);
			if ($kind !== 'rename') {
				$this->bump($cache, $uid . ':all', $window);
			}

			// A ransom note is worth spotting whatever the rate is doing, and
			// costs a string comparison.
			if ($kind !== 'delete' && $this->kinds->looksLikeARansomNote($name)) {
				$this->bump($cache, $uid . ':notes', $window);
			}

			if ($kind === 'rename') {
				// Renames are judged only through the extension they land on:
				// moving a folder of holiday photos is a thousand renames and
				// is nobody's emergency.
				$this->watchExtension($cache, $uid, $name, $window);
				return;
			}

			$limit = $kind === 'delete' ? $this->settings->churnDeletes() : $this->settings->churnWrites();

			if ($kind === 'write' && $head !== null && $count * 2 >= $limit) {
				// Halfway there: start looking at what is actually being
				// written. Before that point there is nothing to decide and no
				// reason to spend a read on it.
				$this->sample($cache, $uid, $name, $head, $window, $count);
			}

			if ($count < $limit) {
				return;
			}

			// Past the line. Judge now, and then again every so often, because
			// the evidence usually arrives a little after the rate does.
			if ($count === $limit || ($count - $limit) % 25 === 0) {
				$this->judge($cache, $uid, $kind, $count, $window);
			}
		} catch (\Throwable $e) {
			$this->logger->debug('Sentinel could not count a file change', ['exception' => $e]);
		}
	}

	/**
	 * Read the front of a file and ask whether it is still what its name says.
	 *
	 * No legitimate client rewrites a .jpg so that it stops being a JPEG.
	 * Ransomware does it to every file it touches, because it encrypts the
	 * contents and keeps the name.
	 *
	 * @param callable(): string $head
	 */
	private function sample($cache, string $uid, string $name, callable $head, int $window, int $count): void {
		if (!$this->kinds->known($name)) {
			return;
		}
		$every = max(1, $this->settings->churnSampleEvery());
		if ($count % $every !== 0) {
			return;
		}

		try {
			$verdict = $this->kinds->matches($name, $head());
		} catch (\Throwable) {
			// A file that cannot be read right now is not evidence.
			return;
		}
		if ($verdict === null) {
			return;
		}

		$this->bump($cache, $uid . ':checked', $window);
		if ($verdict === false) {
			$this->bump($cache, $uid . ':mismatch', $window);
			// And how many in a row. The ratio over the whole window has a hole
			// in it: somebody who has just uploaded eight hundred holiday
			// photos and is attacked a minute later has a window where the
			// genuine files outnumber the encrypted ones, and the attack is
			// invisible precisely because the account was busy. A run of wrong
			// files, one after another, is what ransomware actually produces.
			$this->bump($cache, $uid . ':streak', $window);
		} else {
			$cache->set($uid . ':streak', 0, $window);
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
			$this->raise($uid, 'rename', $count, $window, ['extension' => $extension], [
				'renamedToOneExtension' => $count,
			]);
		}
	}

	/**
	 * The rate says something is happening. Is there anything to say it is the
	 * thing we are afraid of?
	 */
	private function judge($cache, string $uid, string $kind, int $count, int $window): void {
		$checked = (int)$cache->get($uid . ':checked');
		$mismatch = (int)$cache->get($uid . ':mismatch');
		$streak = (int)$cache->get($uid . ':streak');
		$notes = (int)$cache->get($uid . ':notes');

		$evidence = [];

		if ($notes > 0) {
			$evidence['ransomNotes'] = $notes;
		}

		$floor = $this->settings->churnMismatchFloor();
		$ratio = $checked > 0 ? (int)round(($mismatch * 100) / $checked) : 0;
		if ($mismatch >= $floor && $ratio >= $this->settings->churnMismatchRatio()) {
			$evidence['filesNoLongerWhatTheyClaim'] = $mismatch . ' of ' . $checked . ' sampled';
		} elseif ($streak >= self::TELLING_STREAK) {
			$evidence['filesNoLongerWhatTheyClaim'] = $streak . ' in a row';
		}

		// And whatever the process watcher outside has to say, if it is there.
		$outside = $this->edr->verdictFor($uid);
		if ($outside !== null) {
			$evidence['processWatch'] = $outside;
		}

		if ($evidence === []) {
			if (!$this->settings->churnRequireEvidence()) {
				$this->raise($uid, $kind, $count, $window, [], ['rateOnly' => true]);
				return;
			}
			$this->suspect($uid, $kind, $count, $window, $checked, $mismatch);
			return;
		}

		$this->raise($uid, $kind, $count, $window, [
			'sampled' => $checked,
			'mismatched' => $mismatch,
			'mismatchPercent' => $ratio,
			'longestRun' => $streak,
			'ransomNotes' => $notes,
		], $evidence);
	}

	/**
	 * Fast, but nothing says it is wrong.
	 *
	 * Recorded once a day and nothing more. Somebody moving a photo library is
	 * entitled to move a photo library, and the whole value of the alarm above
	 * depends on this not being one.
	 */
	private function suspect(string $uid, string $kind, int $count, int $window, int $checked, int $mismatch): void {
		$minutes = max(1, (int)round($window / 60));
		$this->journal->record(
			'sentinel_churn_quiet',
			Journal::NOTICE,
			$uid . ' changed ' . $count . ' files in ' . $minutes . ' minutes. Nothing about the files themselves looks wrong.',
			subject: $uid . ':' . $kind,
			actor: $uid,
			address: null,
			detail: [
				'uid' => $uid,
				'kind' => $kind,
				'count' => $count,
				'windowSeconds' => $window,
				'sampled' => $checked,
				'mismatched' => $mismatch,
				'acted' => false,
			],
			quiet: 86400,
		);
	}

	/**
	 * @param array<string, mixed> $extra
	 * @param array<string, mixed> $evidence
	 */
	private function raise(string $uid, string $kind, int $count, int $window, array $extra, array $evidence): void {
		$minutes = max(1, (int)round($window / 60));
		$summary = match ($kind) {
			'delete' => $uid . ' has deleted ' . $count . ' files in ' . $minutes . ' minutes.',
			'rename' => $uid . ' has renamed ' . $count . ' files to .' . ($extra['extension'] ?? '?') . ' in ' . $minutes . ' minutes.',
			default => $uid . ' has rewritten ' . $count . ' files in ' . $minutes . ' minutes.',
		};

		if (isset($evidence['filesNoLongerWhatTheyClaim'])) {
			$summary .= ' ' . ucfirst((string)$evidence['filesNoLongerWhatTheyClaim'])
				. ' are no longer the kind of file their name says they are.';
		}
		if (isset($evidence['ransomNotes'])) {
			$summary .= ' A ransom note was written.';
		}
		if (isset($evidence['renamedToOneExtension'])) {
			$summary .= ' All to the same new extension.';
		}
		if (isset($evidence['processWatch'])) {
			$summary .= ' The process watcher agrees.';
		}

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
				'evidence' => $evidence,
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
	 * has to be asked for.
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
	 * @return array<int, array<string, mixed>>
	 */
	public function busy(): array {
		$out = [];
		try {
			$cache = $this->caches->createDistributed('sentinel_churn');
			$this->users->callForAllUsers(function ($user) use ($cache, &$out): void {
				$uid = $user->getUID();
				$checked = (int)$cache->get($uid . ':checked');
				$mismatch = (int)$cache->get($uid . ':mismatch');
				foreach (['write', 'delete'] as $kind) {
					$count = (int)$cache->get($uid . ':' . $kind);
					if ($count > 0) {
						$out[] = [
							'uid' => $uid,
							'kind' => $kind,
							'count' => $count,
							'sampled' => $checked,
							'mismatched' => $mismatch,
						];
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
