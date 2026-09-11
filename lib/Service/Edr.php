<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\Service;

use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * The half of the watching that Nextcloud cannot do from inside itself.
 *
 * Nextcloud sees files changing and knows which account changed them. It cannot
 * see which *process* changed them, and that is the difference between the two
 * things that look identical from in here:
 *
 *   - a sync client faithfully uploading what ransomware did on somebody's
 *     laptop, which arrives as ordinary authenticated PHP requests, and
 *   - something on this server writing straight into the data directory, which
 *     is a compromise of the machine itself.
 *
 * A small daemon outside answers that, because it can use fanotify and see the
 * process identifier behind every write. It runs as root, Nextcloud does not,
 * and neither should be able to make the other do anything — so they are joined
 * by the dullest channel there is: the daemon writes a line of JSON into a
 * directory, and this reads it. No credentials, no socket, no port, nothing
 * that has to be kept secret, and nothing that stops working when one of them
 * is restarted.
 */
class Edr {
	private const FRESH = 300;

	public function __construct(
		private Settings $settings,
		private IAppConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Is the daemon there, and has it said anything recently?
	 *
	 * @return array<string, mixed>
	 */
	public function state(): array {
		$spool = $this->settings->edrSpool();
		if ($spool === '' || !is_dir($spool)) {
			return [
				'expected' => $this->settings->edrExpected(),
				'installed' => false,
				'alive' => false,
				'spool' => $spool,
			];
		}

		$heartbeat = $spool . '/heartbeat.json';
		$beat = $this->readOne($heartbeat);
		$at = (int)($beat['at'] ?? 0);

		return [
			'expected' => $this->settings->edrExpected(),
			'installed' => true,
			'alive' => $at > time() - self::FRESH,
			'lastBeat' => $at,
			'version' => (string)($beat['version'] ?? ''),
			'watching' => $beat['watching'] ?? [],
			'method' => (string)($beat['method'] ?? ''),
			'clam' => $beat['clam'] ?? null,
			'spool' => $spool,
		];
	}

	/**
	 * Anything the daemon has said about this account in the last few minutes.
	 *
	 * Consulted while deciding whether a burst of writing is worth an alarm, so
	 * it has to be cheap and it has to never throw.
	 *
	 * @return array<string, mixed>|null
	 */
	public function verdictFor(string $uid): ?array {
		try {
			foreach ($this->pending(false) as $report) {
				if (($report['uid'] ?? '') !== $uid) {
					continue;
				}
				if ((int)($report['at'] ?? 0) < time() - self::FRESH) {
					continue;
				}
				if (($report['verdict'] ?? '') === 'ransomware') {
					return [
						'process' => $report['process'] ?? null,
						'score' => $report['score'] ?? 0,
						'signals' => $report['signals'] ?? [],
						'action' => $report['action'] ?? 'none',
					];
				}
			}
		} catch (\Throwable $e) {
			$this->logger->debug('Sentinel could not read the process watcher', ['exception' => $e]);
		}
		return null;
	}

	/**
	 * Everything the daemon has left for us since last time, oldest first.
	 *
	 * Read and remembered rather than read and deleted. The daemon runs as root
	 * and its spool belongs to root; giving the web server permission to delete
	 * things in a root-owned directory, so that it can tidy up after a security
	 * daemon, would be a poor trade for the convenience. So Nextcloud keeps a
	 * mark of how far it has got, and the daemon sweeps its own old files.
	 *
	 * The names sort by the second they were written, which is all the ordering
	 * this needs.
	 *
	 * @param bool $consume Move the mark forward, so these are not seen again.
	 * @return array<int, array<string, mixed>>
	 */
	public function pending(bool $consume = true): array {
		$spool = $this->settings->edrSpool();
		if ($spool === '' || !is_dir($spool)) {
			return [];
		}

		$files = glob(rtrim($spool, '/') . '/report-*.json') ?: [];
		sort($files);

		$mark = $this->config->getValueString(Settings::APP, 'edr_cursor', '');
		$out = [];
		$last = $mark;

		foreach ($files as $file) {
			$name = basename($file);
			if ($mark !== '' && strcmp($name, $mark) <= 0) {
				continue;
			}
			$report = $this->readOne($file);
			if ($report !== []) {
				$out[] = $report;
			}
			$last = $name;
			if (count($out) >= 200) {
				break;
			}
		}

		if ($consume && $last !== $mark) {
			$this->config->setValueString(Settings::APP, 'edr_cursor', $last);
		}
		return $out;
	}

	/** @return array<string, mixed> */
	private function readOne(string $file): array {
		if (!is_file($file) || !is_readable($file)) {
			return [];
		}
		$raw = @file_get_contents($file, false, null, 0, 262144);
		if ($raw === false || $raw === '') {
			return [];
		}
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? $decoded : [];
	}
}
