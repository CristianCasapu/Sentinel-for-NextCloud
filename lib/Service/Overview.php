<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\Service;

use OCA\Sentinel\Db\Event;
use OCA\Sentinel\Db\EventMapper;

/**
 * The whole picture in one answer.
 *
 * Everything this app knows is available somewhere — a check here, a table
 * there, a counter in the cache — and somewhere is not good enough. An
 * administrator opening the settings page should be able to tell, in the time
 * it takes to read one screen, whether this server is being looked after: what
 * is being watched, what is not, what has happened lately, and whether anything
 * would actually reach them if it happened at three in the morning.
 *
 * That last one is the part nobody checks. A server with every watcher running
 * and no way to reach anybody is a server with no watchers at all, and it looks
 * identical from the inside.
 */
class Overview {
	public function __construct(
		private Posture $posture,
		private Inventory $inventory,
		private Baseline $baseline,
		private Exposure $exposure,
		private LinkWatch $links,
		private ChangeWatch $changes,
		private Messenger $messenger,
		private Edr $edr,
		private Clam $clam,
		private EventMapper $events,
		private Settings $settings,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function assemble(): array {
		$report = $this->posture->report();
		$accounts = $this->inventory->accounts();
		$tokens = $this->inventory->tokens();
		$links = $this->inventory->links();
		$baseline = $this->baseline->status();
		$exposure = $this->exposure->last();
		$certificate = $this->exposure->certificate();

		$usage = $this->links->totals(30);
		$busiest = 0;
		$opened = 0;
		foreach ($usage as $numbers) {
			$opened += $numbers['views'] + $numbers['downloads'];
			$busiest = max($busiest, $numbers['networks']);
		}

		$day = $this->events->tally(time() - 86400);
		$week = $this->events->tally(time() - (7 * 86400));

		return [
			'state' => $this->worst($report),
			'counts' => $report['counts'],
			'headline' => $this->headline($report),
			'checkedAt' => $report['checkedAt'],

			// The numbers somebody would otherwise go to four pages to collect.
			'numbers' => [
				'accounts' => $accounts['total'],
				'withoutTwoFactor' => $accounts['withoutTwoFactor'],
				'administrators' => count(array_filter($accounts['accounts'], static fn (array $a) => $a['admin'])),
				'links' => $links['total'],
				'linkOpens' => $opened,
				'linkBusiestNetworks' => $busiest,
				'tokens' => $tokens['total'],
				'coldTokens' => $tokens['cold'],
				'baselineFiles' => $baseline['files'],
				'baselineChanged' => $baseline['changed'] + $baseline['removed'],
				'baselineComparedAt' => $baseline['comparedAt'],
				'exposureServed' => count($exposure['served'] ?? []),
				'exposureCheckedAt' => (int)($exposure['probedAt'] ?? 0),
				'certificateDays' => (int)($certificate['daysLeft'] ?? 0),
				'certificateKnown' => (bool)($certificate['checked'] ?? false),
			],

			// What is switched on, said plainly, because the most expensive
			// mistake with an app like this is assuming it is watching
			// something it was never told to watch.
			'watchers' => $this->watchers(),

			'events' => [
				'day' => $day,
				'week' => $week,
				'unseen' => $this->events->unseen(),
				'recent' => array_map(static fn (Event $e) => $e->asPayload(), $this->events->recent(8)),
			],

			// Would any of this actually reach a person?
			'delivery' => $this->messenger->state() + [
				'inApp' => $this->settings->notifyAdmins(),
				'inAppFrom' => $this->settings->notifyFrom(),
			],

			'busy' => $this->changes->busy(),
			'outside' => [
				'edr' => $this->edr->state(),
				'clam' => $this->clam->state(),
			],
		];
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function watchers(): array {
		$on = $this->settings->watchEnabled();
		$edr = $this->edr->state();
		$clam = $this->clam->available();
		return [
			$this->watcher('signins', 'Sign-ins from somewhere new', $on && $this->settings->placeAlert(),
				'An account appearing on a network it has never used, which for an administrator with only a password is the shape of a compromise.'),
			$this->watcher('failures', 'Passwords being guessed', $on,
				'Many attempts against one account, or one address working through several — the second being what throttling alone does not make visible.'),
			$this->watcher('privileges', 'Privileges and accounts', $on,
				'An account made an administrator, two-factor switched off, an account created or deleted.'),
			$this->watcher('apps', 'Apps being enabled or switched off', $on,
				'Enabling an app is arbitrary code running as the server. Switching off a defence is the first move against a server that is being watched.'),
			$this->watcher('config', 'Settings that decide who is trusted', $on,
				'Trusted domains, trusted proxies, forwarded-for headers. Fingerprints only; the values are never stored.'),
			$this->watcher('baseline', 'The files of the installation', $on && $this->baseline->status()['taken'],
				'Only what has moved since the last baseline you approved.'),
			$this->watcher('churn', 'Files changing very fast', $on && $this->settings->watchChurn(),
				'What ransomware on somebody\'s laptop looks like from the server\'s side.'),
			$this->watcher('links', 'How public links are used', $on && $this->settings->watchLinks(),
				'A link opened by a crowd several times larger than that link\'s own record.'),
			$this->watcher('exposure', 'What the web server hands out', $on && $this->settings->probeEnabled(),
				'Asking this server, as an anonymous visitor, for the files it must never serve.'),
			$this->watcher('processes', 'Which program is writing', (bool)($edr['alive'] ?? false),
				'A daemon outside Nextcloud, using fanotify, so that a sync client uploading encrypted files '
				. 'can be told apart from something on this server writing into the data directory.'),
			$this->watcher('scanner', 'A scanner to ask', $clam,
				'ClamAV, asked about the handful of files that already look wrong and about the binary of '
				. 'anything writing where it should not be.'),
		];
	}

	/** @return array<string, mixed> */
	private function watcher(string $id, string $name, bool $on, string $what): array {
		return ['id' => $id, 'name' => $name, 'on' => $on, 'what' => $what];
	}

	/** @param array<string, mixed> $report */
	private function worst(array $report): string {
		$counts = $report['counts'];
		if (($counts['bad'] ?? 0) > 0) {
			return Posture::BAD;
		}
		if (($counts['warn'] ?? 0) > 0) {
			return Posture::WARN;
		}
		return ($counts['note'] ?? 0) > 0 ? Posture::NOTE : Posture::GOOD;
	}

	/** @param array<string, mixed> $report */
	private function headline(array $report): string {
		$counts = $report['counts'];
		$bad = (int)($counts['bad'] ?? 0);
		$warn = (int)($counts['warn'] ?? 0);
		$note = (int)($counts['note'] ?? 0);

		if ($bad > 0) {
			return $bad === 1 ? 'One thing needs attention' : $bad . ' things need attention';
		}
		if ($warn > 0) {
			return $warn === 1 ? 'One thing is worth a look' : $warn . ' things are worth a look';
		}
		if ($note > 0) {
			return $note === 1 ? 'Nothing urgent, one thing worth knowing' : 'Nothing urgent, ' . $note . ' things worth knowing';
		}
		return 'Everything checked is in order';
	}
}
