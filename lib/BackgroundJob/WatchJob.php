<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\BackgroundJob;

use OCA\Sentinel\Service\Baseline;
use OCA\Sentinel\Service\Edr;
use OCA\Sentinel\Service\Exposure;
use OCA\Sentinel\Service\Inventory;
use OCA\Sentinel\Service\Journal;
use OCA\Sentinel\Service\LinkWatch;
use OCA\Sentinel\Service\Posture;
use OCA\Sentinel\Service\Settings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Authentication\TwoFactorAuth\IRegistry;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * The part that runs when nobody is looking.
 *
 * A security page is only useful to somebody who visits it, and nobody visits a
 * page where everything has been fine for six months. So the findings come to
 * the administrators instead: this looks at the same things the page does, on a
 * schedule, and says something only when the answer has changed for the worse.
 */
class WatchJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private Posture $posture,
		private Baseline $baseline,
		private Exposure $exposure,
		private Edr $edr,
		private Inventory $inventory,
		private LinkWatch $links,
		private Journal $journal,
		private Settings $settings,
		private IAppConfig $config,
		private IConfig $systemConfig,
		private IUserManager $users,
		private IGroupManager $groups,
		private IRegistry $twoFactor,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval($this->settings->watchInterval());
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		if (!$this->settings->watchEnabled()) {
			return;
		}

		try {
			$this->compareFilesOccasionally();
		} catch (\Throwable $e) {
			$this->logger->error('Sentinel could not compare the installation against its baseline', ['exception' => $e]);
		}

		try {
			$this->collectFromOutside();
		} catch (\Throwable $e) {
			$this->logger->error('Sentinel could not read what the process watcher left', ['exception' => $e]);
		}

		try {
			$this->watchSecondFactors();
		} catch (\Throwable $e) {
			$this->logger->error('Sentinel could not read who has a second factor', ['exception' => $e]);
		}

		try {
			$this->watchTheConfiguration();
		} catch (\Throwable $e) {
			$this->logger->error('Sentinel could not read the configuration', ['exception' => $e]);
		}

		try {
			$this->askTheWebServerOccasionally();
		} catch (\Throwable $e) {
			$this->logger->error('Sentinel could not probe its own address', ['exception' => $e]);
		}

		try {
			$this->speakUpAboutPosture();
		} catch (\Throwable $e) {
			$this->logger->error('Sentinel could not assess the posture of the installation', ['exception' => $e]);
		}

		// Leave nothing behind that nobody will read.
		$this->journal->prune();
		$this->links->prune();
	}

	/**
	 * Whatever the daemon outside has left for us.
	 *
	 * It has already acted by the time this runs — freezing a process cannot
	 * wait a quarter of an hour for a cron job — so this is not the response.
	 * It is the record, and the way an administrator hears about it.
	 */
	private function collectFromOutside(): void {
		foreach ($this->edr->pending() as $report) {
			$uid = (string)($report['uid'] ?? '');
			$verdict = (string)($report['verdict'] ?? '');
			if ($uid === '' || $verdict === 'quiet') {
				continue;
			}

			$process = $report['process'] ?? null;
			$action = $report['action'] ?? [];
			$what = (string)($action['what'] ?? 'report');

			$summary = $verdict === 'ransomware'
				? 'The process watcher believes ' . $uid . '\'s files are being encrypted.'
				: 'The process watcher thinks something is off with ' . $uid . '\'s files.';

			$program = '';
			if (is_array($process)) {
				// The executable is the honest answer, but a process that has
				// already exited no longer has one; its name and command line
				// were captured while it was alive and are the next best thing.
				$program = (string)($process['exe'] ?? '');
				if ($program === '') {
					$program = (string)($process['name'] ?? '');
				}
			}

			if ($program !== '') {
				$summary .= ' Written by ' . $program . ' (pid ' . ($process['pid'] ?? '?') . ').';
				$line = (string)($process['cmdline'] ?? '');
				if ($line !== '') {
					$summary .= ' Command: ' . mb_substr($line, 0, 120) . '.';
				}
			} else {
				$summary .= ' Written through the web server, so the client holding the session is the thing to stop.';
			}

			if ($what === 'suspend' && ($action['pids'] ?? []) !== []) {
				$summary .= ' It has been frozen, not killed: nothing it holds is lost and you decide what happens next.';
			} elseif ($what === 'kill' && ($action['pids'] ?? []) !== []) {
				$summary .= ' It has been killed.';
			}

			$summary .= $this->carryOut($report, $uid);

			$this->journal->record(
				'sentinel_edr_' . ($verdict === 'ransomware' ? 'ransomware' : 'suspicion'),
				$verdict === 'ransomware' ? Journal::ALARM : Journal::WARNING,
				$summary,
				subject: $uid,
				actor: $uid,
				address: null,
				detail: $report,
				// Every distinct report is worth keeping; the daemon already
				// holds itself to one a minute per account.
				quiet: 0,
			);
		}
	}

	/**
	 * Do what the daemon asked for and could not do itself.
	 *
	 * It runs as root and Nextcloud does not, so it has to shell out to occ to
	 * end an account's sessions — and that can fail for reasons that have
	 * nothing to do with the attack: a wrong path in its configuration, a
	 * sandbox that will not let it run, an installation moved since. A response
	 * that silently did not happen is worse than one that was never designed,
	 * so the request travels in the report and Nextcloud honours it here if the
	 * daemon could not.
	 *
	 * Late, by up to one run of this job. Better than never, and the daemon has
	 * already frozen anything it could freeze by the time we get here.
	 *
	 * @param array<string, mixed> $report
	 */
	private function carryOut(array $report, string $uid): string {
		$request = $report['request'] ?? [];
		if (!is_array($request) || !($request['endSessions'] ?? false)) {
			return '';
		}

		$alreadyDone = str_starts_with((string)($report['sessions'] ?? ''), 'sessions ended');
		if ($alreadyDone) {
			return ' Its sessions were ended at the time.';
		}

		$ended = $this->inventory->revokeAllTokens($uid);
		$disabled = false;
		if ($ended && ($request['disable'] ?? false)) {
			$user = $this->users->get($uid);
			if ($user !== null) {
				$user->setEnabled(false);
				$disabled = true;
			}
		}

		if (!$ended) {
			return ' Its sessions could not be ended; the server log says why.';
		}
		return $disabled
			? ' Its sessions have now been ended and the account disabled.'
			: ' Its sessions have now been ended.';
	}

	/**
	 * Whether anybody's second factor has gone away.
	 *
	 * Not done with events, because Nextcloud's two-factor events do not mean
	 * what their names suggest — one of them fires when a code is typed wrongly
	 * and another during an ordinary sign-in. Comparing the actual state is
	 * duller and correct, and it also catches a second factor removed with occ
	 * or straight out of the database, which no event would have mentioned at
	 * all.
	 */
	private function watchSecondFactors(): void {
		$now = [];
		$this->users->callForAllUsers(function ($user) use (&$now): void {
			$protected = false;
			foreach ($this->twoFactor->getProviderStates($user) as $enabled) {
				if ($enabled === true) {
					$protected = true;
					break;
				}
			}
			$now[$user->getUID()] = $protected;
		});

		$before = json_decode($this->config->getValueString(Settings::APP, 'two_factor_state', ''), true);
		$this->config->setValueString(
			Settings::APP,
			'two_factor_state',
			json_encode($now, JSON_UNESCAPED_SLASHES) ?: '',
		);

		if (!is_array($before) || $before === []) {
			// Nothing to compare against on the first run, and announcing every
			// account as new would be noise on the day of installation.
			return;
		}

		foreach ($now as $uid => $protected) {
			if ($protected || !($before[$uid] ?? false)) {
				continue;
			}
			$this->journal->record(
				'sentinel_two_factor_off',
				Journal::ALARM,
				$uid . ' had a second factor and no longer has one.',
				subject: $uid,
				actor: null,
				address: null,
				detail: ['uid' => $uid, 'administrator' => $this->groups->isAdmin($uid)],
				quiet: 0,
			);
		}
	}

	/**
	 * The settings that decide who this server trusts.
	 *
	 * A changed trusted domain sends password resets somewhere else. A changed
	 * trusted proxy makes the server believe whatever an attacker puts in a
	 * header, including which address a request came from — which is precisely
	 * what the brute-force protection counts. Neither change announces itself
	 * anywhere, and both are one line in a file.
	 */
	private function watchTheConfiguration(): void {
		$watched = [
			'trusted_domains', 'trusted_proxies', 'forwarded_for_headers',
			'overwrite.cli.url', 'overwritehost', 'overwriteprotocol', 'overwritewebroot', 'overwritecondaddr',
			'datadirectory', 'skeletondirectory', 'appstoreenabled', 'appstoreurl', 'apps_paths',
			'debug', 'loglevel', 'maintenance', 'installed',
			'allow_local_remote_servers', 'auth.bruteforce.protection.enabled',
			'twofactor_enforced', 'remember_login_cookie_lifetime', 'session_lifetime',
			'lost_password_link', 'updater.server.url', 'has_internet_connection',
		];

		$now = [];
		foreach ($watched as $key) {
			$value = $this->systemConfig->getSystemValue($key, null);
			if ($value === null) {
				continue;
			}
			// The values themselves are never stored, only a fingerprint: this
			// is a security app, and keeping a second copy of the settings that
			// matter most would be an odd way to protect them.
			$now[$key] = substr(hash('sha256', json_encode($value) ?: ''), 0, 16);
		}

		$before = json_decode($this->config->getValueString(Settings::APP, 'config_state', ''), true);
		$this->config->setValueString(
			Settings::APP,
			'config_state',
			json_encode($now, JSON_UNESCAPED_SLASHES) ?: '',
		);

		if (!is_array($before) || $before === []) {
			// The first run has nothing to compare against, and announcing
			// every setting as new would be noise on the day of installation.
			return;
		}

		$moved = [];
		foreach ($now as $key => $digest) {
			if (!isset($before[$key])) {
				$moved[] = $key . ' (set)';
			} elseif ($before[$key] !== $digest) {
				$moved[] = $key;
			}
		}
		foreach ($before as $key => $digest) {
			if (!isset($now[$key])) {
				$moved[] = $key . ' (removed)';
			}
		}

		if ($moved === []) {
			return;
		}

		$this->journal->record(
			'sentinel_config_changed',
			Journal::ALARM,
			count($moved) === 1
				? 'A security setting changed: ' . $moved[0] . '.'
				: count($moved) . ' security settings changed: ' . implode(', ', array_slice($moved, 0, 6)) . '.',
			subject: 'config',
			actor: null,
			address: null,
			detail: ['changed' => $moved],
			quiet: 0,
		);
	}

	/**
	 * Ask the web server what it is willing to hand out, a few times a day.
	 *
	 * More often would be pointless — the answer only changes when somebody
	 * changes the web server — and it costs thirty requests each time.
	 */
	private function askTheWebServerOccasionally(): void {
		if (!$this->settings->probeEnabled()) {
			return;
		}
		$last = $this->exposure->last();
		if ((int)($last['probedAt'] ?? 0) > time() - 21600) {
			return;
		}

		$now = $this->exposure->refresh();
		$served = $now['served'] ?? [];
		if ($served === []) {
			return;
		}

		$this->journal->record(
			'sentinel_exposed',
			Journal::ALARM,
			count($served) . ' files that should never be served are being served by the web server.',
			subject: 'exposure',
			actor: null,
			address: null,
			detail: ['served' => array_column($served, 'path'), 'base' => $now['base'] ?? ''],
			quiet: 86400,
		);
	}

	/**
	 * Walking the whole installation is the most expensive thing this app does,
	 * so it happens once an hour at most regardless of how often the job runs.
	 */
	private function compareFilesOccasionally(): void {
		$status = $this->baseline->status();
		if (!$status['taken']) {
			return;
		}
		if ((int)$status['comparedAt'] > time() - 3600) {
			return;
		}

		$after = $this->baseline->compare();
		$moved = (int)$after['changed'] + (int)$after['added'] + (int)$after['removed'];
		if ($moved === 0) {
			return;
		}

		// The changed ones are the story. Files appearing in a cache directory
		// somebody forgot to exclude are not.
		$changed = array_values(array_filter(
			$after['differences'],
			static fn (array $d) => $d['state'] === 'changed' || $d['state'] === 'removed',
		));

		$this->journal->record(
			'sentinel_files_moved',
			$changed === [] ? Journal::WARNING : Journal::ALARM,
			$moved . ' files differ from the approved baseline.',
			subject: 'baseline',
			actor: null,
			address: null,
			detail: [
				'changed' => $after['changed'],
				'added' => $after['added'],
				'removed' => $after['removed'],
				'examples' => array_slice(array_column($changed !== [] ? $changed : $after['differences'], 'path'), 0, 20),
			],
			// Once a day while it stands. Acknowledging the changes ends it
			// properly; repeating it hourly would only teach people to ignore
			// the notification.
			quiet: 86400,
		);
	}

	/**
	 * Say something when a check that used to pass has stopped passing.
	 *
	 * The state of each check is remembered between runs, so a server that has
	 * always had one account without a second factor is not announced every day
	 * for ever; the day a second account joins it, that is news.
	 */
	private function speakUpAboutPosture(): void {
		$report = $this->posture->report();
		$previous = json_decode($this->config->getValueString(Settings::APP, 'posture_state', ''), true);
		$previous = is_array($previous) ? $previous : [];
		$current = [];

		foreach ($report['findings'] as $finding) {
			$id = (string)$finding['id'];
			$current[$id] = ['state' => $finding['state'], 'summary' => $finding['summary']];

			$was = $previous[$id]['state'] ?? null;
			$now = $finding['state'];
			if ($now === Posture::GOOD || $now === Posture::NOTE) {
				continue;
			}

			$rank = [Posture::GOOD => 0, Posture::NOTE => 1, Posture::WARN => 2, Posture::BAD => 3];
			$gotWorse = $was === null || ($rank[$now] ?? 0) > ($rank[$was] ?? 0);
			$summaryChanged = ($previous[$id]['summary'] ?? '') !== $finding['summary'];

			if (!$gotWorse && !$summaryChanged) {
				continue;
			}

			$this->journal->record(
				'sentinel_posture_' . $id,
				$now === Posture::BAD ? Journal::ALARM : Journal::WARNING,
				$finding['title'] . ': ' . $finding['summary'],
				subject: $id,
				actor: null,
				address: null,
				detail: ['finding' => $id, 'state' => $now, 'was' => $was, 'fix' => $finding['fix']],
				quiet: 86400,
			);
		}

		$this->config->setValueString(
			Settings::APP,
			'posture_state',
			json_encode($current, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
		);
	}
}
