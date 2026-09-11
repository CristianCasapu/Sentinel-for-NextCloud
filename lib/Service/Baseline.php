<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\Service;

use OCA\Sentinel\Db\BaselineFile;
use OCA\Sentinel\Db\BaselineMapper;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * What the installation looked like when somebody last said it was correct.
 *
 * Nextcloud already checks its own files against the signatures it shipped
 * with, and on any installation that has ever been patched — a fix applied
 * before the release that contains it, a header changed in .htaccess, a
 * workaround for a bug nobody upstream has got to yet — that check fails for
 * ever. A check that always fails is a check nobody reads, and the day
 * something is genuinely wrong it arrives as one more line in a warning that
 * has been ignored for a year.
 *
 * This takes the other approach. It records what the files look like at a
 * moment you choose, once you have decided that moment is correct, and from
 * then on reports only what has moved since. A legitimate patch is approved
 * once and becomes part of the baseline. An update is approved once. Anything
 * else is a short list of files that changed when nobody changed them, which
 * is the only list actually worth looking at.
 */
class Baseline {
	private const STATE_KEY = 'baseline_state';
	private const KEEP_IN_STATE = 500;

	public function __construct(
		private BaselineMapper $mapper,
		private Settings $settings,
		private IAppConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The last thing we know, without touching the disk.
	 *
	 * The posture page asks this on every load, and walking seventy thousand
	 * files to answer a page load would make the page the most expensive thing
	 * on the server. The walk happens on a schedule and on demand; this reads
	 * what it found.
	 *
	 * @return array<string, mixed>
	 */
	public function status(): array {
		$count = 0;
		try {
			$count = $this->mapper->count();
		} catch (\Throwable $e) {
			$this->logger->warning('Sentinel could not read the baseline', ['exception' => $e]);
		}

		$state = json_decode($this->config->getValueString(Settings::APP, self::STATE_KEY, ''), true);
		if (!is_array($state)) {
			$state = [];
		}

		return [
			'taken' => $count > 0,
			'files' => $count,
			'takenAt' => $this->config->getValueInt(Settings::APP, 'baseline_taken_at', 0),
			'takenBy' => $this->config->getValueString(Settings::APP, 'baseline_taken_by', ''),
			'comparedAt' => (int)($state['comparedAt'] ?? 0),
			'changed' => (int)($state['changed'] ?? 0),
			'added' => (int)($state['added'] ?? 0),
			'removed' => (int)($state['removed'] ?? 0),
			'walked' => (int)($state['walked'] ?? 0),
			'took' => (float)($state['took'] ?? 0),
			'differences' => $state['differences'] ?? [],
			'truncated' => (bool)($state['truncated'] ?? false),
		];
	}

	/**
	 * Record the installation as it is now, and call that correct.
	 *
	 * @return array<string, mixed>
	 */
	public function take(string $actor): array {
		$started = microtime(true);
		$files = $this->walk();
		$now = time();
		$written = $this->mapper->replaceAll($files, $actor, $now);

		$this->config->setValueInt(Settings::APP, 'baseline_taken_at', $now);
		$this->config->setValueString(Settings::APP, 'baseline_taken_by', mb_substr($actor, 0, 64));
		$this->rememberState([
			'comparedAt' => $now,
			'changed' => 0,
			'added' => 0,
			'removed' => 0,
			'walked' => count($files),
			'took' => round(microtime(true) - $started, 2),
			'differences' => [],
			'truncated' => false,
		]);

		return [
			'files' => $written,
			'took' => round(microtime(true) - $started, 2),
			'takenAt' => $now,
			'takenBy' => $actor,
		];
	}

	/**
	 * Walk the installation and say what has moved since the baseline.
	 *
	 * @return array<string, mixed>
	 */
	public function compare(): array {
		$started = microtime(true);
		$known = $this->mapper->digests();
		if ($known === []) {
			return $this->status();
		}

		$now = $this->walk();
		$differences = [];
		$changed = 0;
		$added = 0;

		foreach ($now as $path => $info) {
			if (!isset($known[$path])) {
				$added++;
				$differences[] = [
					'path' => $path,
					'state' => 'added',
					'size' => $info['size'],
					'digest' => $info['digest'],
					'modified' => $info['modified'] ?? 0,
				];
				continue;
			}
			if ($known[$path]['digest'] !== $info['digest']) {
				$changed++;
				$differences[] = [
					'path' => $path,
					'state' => 'changed',
					'size' => $info['size'],
					'wasSize' => $known[$path]['size'],
					'digest' => $info['digest'],
					'modified' => $info['modified'] ?? 0,
				];
			}
			unset($known[$path]);
		}

		$removed = 0;
		foreach ($known as $path => $info) {
			$removed++;
			$differences[] = [
				'path' => $path,
				'state' => 'removed',
				'size' => 0,
				'wasSize' => $info['size'],
				'digest' => '',
				'modified' => 0,
			];
		}

		// Changed files first: a file that exists and is different is a much
		// more interesting thing than a file that has appeared, and on an
		// installation with a cache directory inside it there can be a great
		// many of the latter.
		$order = ['changed' => 0, 'removed' => 1, 'added' => 2];
		usort($differences, static fn (array $a, array $b) => [$order[$a['state']], $a['path']] <=> [$order[$b['state']], $b['path']]);

		$truncated = count($differences) > self::KEEP_IN_STATE;
		$state = [
			'comparedAt' => time(),
			'changed' => $changed,
			'added' => $added,
			'removed' => $removed,
			'walked' => count($now),
			'took' => round(microtime(true) - $started, 2),
			'differences' => array_slice($differences, 0, self::KEEP_IN_STATE),
			'truncated' => $truncated,
		];
		$this->rememberState($state);

		return $this->status();
	}

	/**
	 * Accept some of the differences as intended.
	 *
	 * This is the whole point of the app. A patch applied deliberately is
	 * approved once, with a note saying why, and stops being an alarm for
	 * ever — which is what makes the remaining alarms mean something.
	 *
	 * @param string[] $paths
	 * @return array<string, mixed>
	 */
	public function acknowledge(array $paths, string $actor, ?string $note = null): array {
		$root = $this->root();
		$accepted = 0;
		$forgotten = 0;
		$now = time();

		foreach ($paths as $path) {
			$path = $this->tidy($path);
			if ($path === '' || !$this->within($root, $root . $path)) {
				continue;
			}
			$full = $root . $path;

			if (!is_file($full)) {
				// The file is gone and that is intended: drop it from the
				// baseline rather than leaving a row that reports it missing
				// every single time.
				$this->mapper->forget($path);
				$forgotten++;
				continue;
			}

			$digest = $this->digest($full);
			if ($digest === null) {
				continue;
			}

			$row = $this->mapper->byPath($path);
			if ($row === null) {
				$row = new BaselineFile();
				$row->setPath($path);
				$row->setScope($this->scopeOf($path));
				$row->setFirstSeen($now);
				$row->setDigest($digest);
				$row->setSize((int)filesize($full));
				$row->setNote($note === null ? null : mb_substr($note, 0, 1000));
				$row->setAcknowledgedBy(mb_substr($actor, 0, 64));
				$row->setAcknowledgedAt($now);
				$this->mapper->insert($row);
			} else {
				$row->setDigest($digest);
				$row->setSize((int)filesize($full));
				if ($note !== null) {
					$row->setNote(mb_substr($note, 0, 1000));
				}
				$row->setAcknowledgedBy(mb_substr($actor, 0, 64));
				$row->setAcknowledgedAt($now);
				$this->mapper->update($row);
			}
			$accepted++;
		}

		$this->compare();
		return ['accepted' => $accepted, 'forgotten' => $forgotten] + $this->status();
	}

	/** Throw the baseline away entirely, leaving no rows behind. */
	public function forget(): void {
		$this->mapper->clear();
		$this->config->deleteKey(Settings::APP, self::STATE_KEY);
		$this->config->deleteKey(Settings::APP, 'baseline_taken_at');
		$this->config->deleteKey(Settings::APP, 'baseline_taken_by');
	}

	/**
	 * Walk the installation once.
	 *
	 * @return array<string, array{digest: string, size: int, scope: string, modified: int}>
	 */
	private function walk(): array {
		$root = $this->root();
		$extensions = $this->settings->baselineExtensions();
		$exclude = $this->settings->baselineExclude();
		$maxBytes = $this->settings->baselineMaxBytes();
		$out = [];

		foreach ($this->roots($root) as $scope => $where) {
			// A root can be a single file: the ones at the top of the
			// installation — index.php, remote.php, .htaccess — are the ones an
			// intruder is most likely to touch, and they are not in any
			// directory of their own.
			if (is_file($where)) {
				$this->consider($out, $root, $where, basename($where), $scope, $extensions, $exclude, $maxBytes);
				continue;
			}
			if (!is_dir($where)) {
				continue;
			}
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($where, RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS),
				RecursiveIteratorIterator::LEAVES_ONLY,
				// A directory that cannot be read is a fact about permissions,
				// not a reason to abandon the walk.
				RecursiveIteratorIterator::CATCH_GET_CHILD,
			);

			foreach ($iterator as $item) {
				/** @var \SplFileInfo $item */
				if (!$item->isFile()) {
					continue;
				}
				$this->consider($out, $root, $item->getPathname(), $item->getFilename(), $scope, $extensions, $exclude, $maxBytes);
			}
		}

		ksort($out);
		return $out;
	}

	/**
	 * Weigh one file and, if it is the kind that matters, record it.
	 *
	 * @param array<string, array{digest: string, size: int, scope: string, modified: int}> $out
	 * @param string[] $extensions
	 * @param string[] $exclude
	 */
	private function consider(
		array &$out,
		string $root,
		string $full,
		string $filename,
		string $scope,
		array $extensions,
		array $exclude,
		int $maxBytes,
	): void {
		$relative = $this->tidy(substr($full, strlen($root)));
		if ($relative === '' || isset($out[$relative])) {
			return;
		}
		if ($this->excluded($relative, $exclude)) {
			return;
		}
		if (!$this->wanted($filename, $extensions)) {
			return;
		}
		$size = (int)@filesize($full);
		if ($size > $maxBytes) {
			return;
		}
		$digest = $this->digest($full);
		if ($digest === null) {
			return;
		}
		$out[$relative] = [
			'digest' => $digest,
			'size' => $size,
			// The scope a path belongs to is a fact about the path, not about
			// which walk happened to reach it first.
			'scope' => str_starts_with($scope, 'top:') ? 'core' : $scope,
			'modified' => (int)@filemtime($full),
		];
	}

	/**
	 * Which parts of the installation are covered.
	 *
	 * @return array<string, string> scope => absolute directory
	 */
	private function roots(string $root): array {
		$wanted = $this->settings->baselineScope();
		$known = [
			'core' => $root . '/lib',
			'core-ocs' => $root . '/ocs',
			'core-top' => $root,
			'apps' => $root . '/apps',
			'config' => $root . '/config',
			'themes' => $root . '/themes',
			'3rdparty' => $root . '/3rdparty',
		];

		$roots = [];
		foreach ($wanted as $scope) {
			if ($scope === 'core') {
				// The top level of the server root plus the directories that
				// are actually code, but not apps (which are their own scope)
				// and not data (which is the users' files and changes by the
				// second).
				$roots['core'] = $known['core'];
				$roots['core-ocs'] = $known['core-ocs'];
				$roots['core-core'] = $root . '/core';
				continue;
			}
			if (isset($known[$scope])) {
				$roots[$scope] = $known[$scope];
			}
		}

		// The files at the very top — index.php, .htaccess, remote.php — are
		// the ones an intruder is most likely to touch and the smallest set to
		// check, so they are covered whenever core is.
		if (isset($roots['core'])) {
			foreach ((array)@scandir($root) as $entry) {
				if ($entry === '.' || $entry === '..') {
					continue;
				}
				if (is_file($root . '/' . $entry)) {
					$roots['top:' . $entry] = $root . '/' . $entry;
				}
			}
		}

		return $roots;
	}

	private function scopeOf(string $path): string {
		if (str_starts_with($path, '/apps/')) {
			return 'apps';
		}
		if (str_starts_with($path, '/config/')) {
			return 'config';
		}
		if (str_starts_with($path, '/3rdparty/')) {
			return '3rdparty';
		}
		if (str_starts_with($path, '/themes/')) {
			return 'themes';
		}
		return 'core';
	}

	/** @param string[] $exclude */
	private function excluded(string $relative, array $exclude): bool {
		foreach ($exclude as $needle) {
			if ($needle !== '' && str_contains($relative, $needle)) {
				return true;
			}
		}
		return false;
	}

	/** @param string[] $extensions */
	private function wanted(string $filename, array $extensions): bool {
		if ($extensions === []) {
			return true;
		}
		$lower = strtolower($filename);
		foreach ($extensions as $extension) {
			// Matched against the end of the name rather than the extension
			// alone, because the interesting ones — .htaccess, .user.ini — are
			// not extensions at all.
			if (str_ends_with($lower, '.' . $extension) || $lower === $extension || $lower === '.' . $extension) {
				return true;
			}
		}
		return false;
	}

	private function digest(string $full): ?string {
		$algorithm = $this->settings->baselineDigest();
		$hash = @hash_file($algorithm, $full);
		return is_string($hash) && $hash !== '' ? $hash : null;
	}

	private function root(): string {
		return rtrim(\OC::$SERVERROOT, '/');
	}

	/** Never let a path escape the installation, however it was typed. */
	private function within(string $root, string $candidate): bool {
		$real = realpath($candidate);
		if ($real === false) {
			// A file that is meant to be gone has no real path; judge it on the
			// text, which has already been tidied.
			return str_starts_with($candidate, $root . '/');
		}
		return str_starts_with($real, realpath($root) . '/');
	}

	private function tidy(string $path): string {
		$path = str_replace('\\', '/', $path);
		$path = '/' . ltrim($path, '/');
		if (str_contains($path, '/../') || str_ends_with($path, '/..')) {
			return '';
		}
		return $path === '/' ? '' : rtrim($path, '/');
	}

	/** @param array<string, mixed> $state */
	private function rememberState(array $state): void {
		$this->config->setValueString(
			Settings::APP,
			self::STATE_KEY,
			json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
		);
	}
}
