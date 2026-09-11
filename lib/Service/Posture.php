<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\Service;

use OCP\App\IAppManager;
use OCP\Authentication\TwoFactorAuth\IRegistry;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * The questions nobody asks until it is too late.
 *
 * Nextcloud checks a great many things about itself, and this deliberately
 * checks none of them again. What it looks at instead is the state that
 * accumulates quietly while a server is used: an account that never turned on
 * two-factor authentication, a link shared for an afternoon three years ago
 * that is still open, an application password last used in February that would
 * still work today.
 *
 * None of those is a vulnerability. Each is a door that was opened for a reason
 * and never closed, and together they are how most installations are actually
 * lost — not through a flaw in the software.
 */
class Posture {
	public const GOOD = 'good';
	public const NOTE = 'note';
	public const WARN = 'warn';
	public const BAD = 'bad';

	public function __construct(
		private IUserManager $users,
		private IGroupManager $groups,
		private IRegistry $twoFactor,
		private IAppManager $apps,
		private IConfig $config,
		private IDBConnection $db,
		private Baseline $baseline,
		private Exposure $exposure,
		private LinkWatch $links,
		private Settings $settings,
	) {
	}

	/**
	 * Everything worth knowing, worst first.
	 *
	 * @return array<string, mixed>
	 */
	public function report(): array {
		$findings = [
			$this->twoFactorCoverage(),
			$this->administrators(),
			$this->publicLinks(),
			$this->applicationPasswords(),
			$this->fileBaseline(),
			$this->whatIsServed(),
			$this->certificate(),
			$this->ransomwareWatch(),
			$this->watchfulness(),
			$this->passwordRules(),
		];

		$rank = [self::BAD => 0, self::WARN => 1, self::NOTE => 2, self::GOOD => 3];
		usort($findings, static fn (array $a, array $b) => $rank[$a['state']] <=> $rank[$b['state']]);

		$counts = array_count_values(array_column($findings, 'state'));
		return [
			'findings' => $findings,
			'counts' => [
				'bad' => $counts[self::BAD] ?? 0,
				'warn' => $counts[self::WARN] ?? 0,
				'note' => $counts[self::NOTE] ?? 0,
				'good' => $counts[self::GOOD] ?? 0,
			],
			'checkedAt' => time(),
		];
	}

	/**
	 * Who could be signed in as by somebody holding only a password.
	 *
	 * The single most useful number on this page. A password can be guessed,
	 * reused, phished or found in somebody else's breach; a second factor is
	 * the difference between that being an inconvenience and being the end of
	 * it.
	 */
	private function twoFactorCoverage(): array {
		$without = [];
		$adminsWithout = [];
		$total = 0;

		$this->users->callForAllUsers(function ($user) use (&$without, &$adminsWithout, &$total): void {
			$total++;
			if ($this->twoFactor->getProviderStates($user) === [] || !$this->hasEnabledProvider($user)) {
				$uid = $user->getUID();
				$without[] = ['uid' => $uid, 'name' => $user->getDisplayName(), 'lastSeen' => $user->getLastLogin()];
				if ($this->groups->isAdmin($uid)) {
					$adminsWithout[] = $uid;
				}
			}
		});

		$covered = $total - count($without);
		if ($adminsWithout !== []) {
			$state = self::BAD;
			$summary = count($adminsWithout) === 1
				? 'An administrator can be signed in as with a password alone.'
				: count($adminsWithout) . ' administrators can be signed in as with a password alone.';
		} elseif ($without !== []) {
			$state = self::WARN;
			$summary = count($without) . ' of ' . $total . ' accounts have no second factor.';
		} else {
			$state = self::GOOD;
			$summary = 'Every account has a second factor.';
		}

		return $this->finding(
			'two_factor',
			'Two-factor authentication',
			$state,
			$summary,
			'A password is one secret, and secrets travel: they get reused on other sites, typed into '
			. 'convincing copies of this one, and turn up in other people\'s breaches. A second factor means '
			. 'that knowing the password is not enough.',
			'Nextcloud can require it. In Settings → Administration → Security, choose which groups must '
			. 'have two-factor authentication, and the people in them are asked to set it up at their next sign-in.',
			[
				'covered' => $covered,
				'total' => $total,
				'without' => array_slice($without, 0, 50),
				'adminsWithout' => $adminsWithout,
			],
		);
	}

	private function hasEnabledProvider($user): bool {
		foreach ($this->twoFactor->getProviderStates($user) as $enabled) {
			if ($enabled === true) {
				return true;
			}
		}
		return false;
	}

	/**
	 * How many people can change everything, and whether any of them has
	 * stopped using the account.
	 */
	private function administrators(): array {
		$admins = [];
		$group = $this->groups->get('admin');
		foreach ($group?->getUsers() ?? [] as $user) {
			$admins[] = [
				'uid' => $user->getUID(),
				'name' => $user->getDisplayName(),
				'lastSeen' => $user->getLastLogin(),
				'twoFactor' => $this->hasEnabledProvider($user),
			];
		}
		$quiet = time() - ($this->settings->dormantAdminDays() * 86400);
		$dormant = array_values(array_filter(
			$admins,
			static fn (array $a) => $a['lastSeen'] > 0 && $a['lastSeen'] < $quiet,
		));
		$never = array_values(array_filter($admins, static fn (array $a) => $a['lastSeen'] === 0));

		$state = self::GOOD;
		$summary = count($admins) === 1
			? 'One administrator.'
			: count($admins) . ' administrators.';
		if ($dormant !== [] || $never !== []) {
			$state = self::WARN;
			$summary .= ' ' . (count($dormant) + count($never)) . ' of them have not signed in for months.';
		} elseif (count($admins) > 3) {
			$state = self::NOTE;
			$summary .= ' That is more than most installations need.';
		}

		return $this->finding(
			'administrators',
			'Administrators',
			$state,
			$summary,
			'Every administrator account is a way to change anything on the server, including the other '
			. 'accounts. An administrator who has stopped using the account is the same risk as one who uses '
			. 'it daily, with nobody watching it.',
			'Keep the number small, and take the rights away from accounts that no longer need them rather '
			. 'than leaving them in case. A person who needs them back can be given them back.',
			['admins' => $admins, 'dormant' => count($dormant) + count($never)],
		);
	}

	/**
	 * The links that are open to anyone, and what is happening to them.
	 *
	 * A public link without a password is not a fault. It is the most useful
	 * thing Nextcloud does — a folder handed to somebody who has no account and
	 * is not going to make one — and telling its owner off every week for using
	 * the feature as intended is how a page like this becomes wallpaper.
	 *
	 * So this counts rather than scolds, and the judgement it does make is
	 * about behaviour: a link opened from far more networks than that link has
	 * ever been opened from is the same link doing something different, and
	 * that is worth a sentence. Anyone who does want the sterner reading can
	 * switch it on; it is off because it is an opinion, not a finding.
	 */
	private function publicLinks(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'total'))
			->selectAlias($qb->createFunction('SUM(CASE WHEN ' . $qb->getColumnName('password') . ' IS NULL THEN 1 ELSE 0 END)'), 'open')
			->selectAlias($qb->createFunction('SUM(CASE WHEN ' . $qb->getColumnName('expiration') . ' IS NULL THEN 1 ELSE 0 END)'), 'forever')
			->selectAlias($qb->func()->min('stime'), 'oldest')
			->from('share')
			->where($qb->expr()->in('share_type', $qb->createNamedParameter([3, 4], IQueryBuilder::PARAM_INT_ARRAY)));
		$result = $qb->executeQuery();
		$row = $result->fetch() ?: [];
		$result->closeCursor();

		$total = (int)($row['total'] ?? 0);
		$open = (int)($row['open'] ?? 0);
		$forever = (int)($row['forever'] ?? 0);
		$oldest = (int)($row['oldest'] ?? 0);

		$usage = $this->links->totals(30);
		$views = array_sum(array_column($usage, 'views'));
		$downloads = array_sum(array_column($usage, 'downloads'));
		$busiest = 0;
		foreach ($usage as $numbers) {
			if ($numbers['networks'] > $busiest) {
				$busiest = $numbers['networks'];
			}
		}

		if ($total === 0) {
			$summary = 'Nothing is shared by link.';
		} elseif ($views + $downloads === 0) {
			$summary = $total . ' links are open to anyone holding the address. None has been used in the last 30 days.';
		} else {
			$summary = $total . ' links open, used ' . ($views + $downloads) . ' times in the last 30 days'
				. ($busiest > 0 ? ', the busiest from ' . $busiest . ' different networks.' : '.');
		}

		$state = self::GOOD;
		if ($this->settings->judgeOpenLinks() && $open > 0) {
			$state = self::NOTE;
			$summary .= ' ' . $open . ' without a password, ' . $forever . ' that never expire.';
		}
		if (!$this->settings->watchLinks()) {
			$state = self::NOTE;
			$summary .= ' Nothing is watching how they are used.';
		}

		return $this->finding(
			'public_links',
			'Links open to anyone',
			$state,
			$summary,
			'A link is meant to be given away; that is the whole point of it. What is worth knowing is not '
			. 'that a link exists but whether it has started being used by people it was never sent to — the '
			. 'same address opened from dozens of networks in an afternoon, or a password-protected one being '
			. 'guessed at. Each link is measured against what that link normally does, so a busy link is '
			. 'allowed to be busy.',
			$total === 0
				? 'Nothing to do.'
				: 'Nothing, unless you want to. The list shows how each link is being used, and any of them '
				. 'can be given an expiry or removed there. Sentinel will say something on its own if one '
				. 'starts behaving unlike itself.',
			[
				'total' => $total,
				'withoutPassword' => $open,
				'neverExpire' => $forever,
				'oldest' => $oldest,
				'views' => $views,
				'downloads' => $downloads,
				'busiestNetworks' => $busiest,
				'watching' => $this->settings->watchLinks(),
			],
		);
	}

	/**
	 * Passwords issued to applications, which are full access and do not expire
	 * on their own.
	 */
	private function applicationPasswords(): array {
		$cutoff = time() - ($this->settings->staleTokenDays() * 86400);
		$qb = $this->db->getQueryBuilder();
		$qb->select('uid', 'name', 'last_activity', 'type', 'scope')
			->from('authtoken');
		$result = $qb->executeQuery();

		$total = 0;
		$stale = [];
		while ($row = $result->fetch()) {
			$total++;
			$last = (int)$row['last_activity'];
			if ($last > 0 && $last < $cutoff) {
				$stale[] = [
					'uid' => (string)$row['uid'],
					'name' => mb_substr((string)$row['name'], 0, 80),
					'lastUsed' => $last,
				];
			}
		}
		$result->closeCursor();

		$state = $stale === [] ? self::GOOD : self::NOTE;
		if (count($stale) > 5) {
			$state = self::WARN;
		}
		$summary = $stale === []
			? $total . ' application passwords, all in recent use.'
			: count($stale) . ' of ' . $total . ' application passwords have not been used for '
				. $this->settings->staleTokenDays() . ' days.';

		return $this->finding(
			'app_passwords',
			'Application passwords',
			$state,
			$summary,
			'Each one is a key that opens the account without a second factor, issued to a device that may '
			. 'since have been sold, reset or lost. They do not expire, and nothing removes them when the '
			. 'device stops being used.',
			'Revoke the ones nobody recognises. Anything still in use will ask to be signed in again, which '
			. 'is a small price and a good way to find out what is still out there.',
			['total' => $total, 'stale' => array_slice($stale, 0, 50)],
		);
	}

	/** Whether the installation still matches what was last approved. */
	private function fileBaseline(): array {
		$status = $this->baseline->status();
		if (!$status['taken']) {
			return $this->finding(
				'baseline',
				'The files of the installation',
				self::NOTE,
				'No baseline has been taken yet.',
				'Nextcloud checks its own files against the signatures it shipped with, which is why a patched '
				. 'installation shows a permanent warning — and a permanent warning is one nobody reads. A '
				. 'baseline records what the files look like now, once you have decided that is correct.',
				'Take a baseline. From then on, only a change since that moment is reported.',
				$status,
			);
		}

		$changed = (int)$status['changed'];
		$added = (int)$status['added'];
		$removed = (int)$status['removed'];
		$moved = $changed + $added + $removed;

		return $this->finding(
			'baseline',
			'The files of the installation',
			$moved === 0 ? self::GOOD : self::BAD,
			$moved === 0
				? 'Nothing has changed since the baseline was taken.'
				: $moved . ' files differ from the baseline: ' . $changed . ' changed, ' . $added . ' new, ' . $removed . ' gone.',
			'Nextcloud verifies its own files against the signatures it shipped with. That check cannot tell a '
			. 'deliberate patch from an intrusion, so on a patched installation it warns for ever and stops '
			. 'meaning anything. A baseline you approved once tells the two apart.',
			'Look at what differs. An update or a patch you applied should be approved, which makes it the new '
			. 'baseline. Anything you cannot account for is worth taking seriously.',
			$status,
		);
	}

	/** Whether anything is keeping a record of what happens. */
	private function watchfulness(): array {
		$auditing = $this->apps->isEnabledForUser('admin_audit');
		$suspicious = $this->apps->isEnabledForUser('suspicious_login');
		$missing = [];
		if (!$auditing) {
			$missing[] = 'admin_audit';
		}
		if (!$suspicious) {
			$missing[] = 'suspicious_login';
		}

		return $this->finding(
			'watchfulness',
			'Keeping a record',
			$missing === [] ? self::GOOD : self::NOTE,
			$missing === []
				? 'Actions are being recorded and unusual sign-ins are being looked for.'
				: 'Not installed: ' . implode(', ', $missing) . '.',
			'After something goes wrong, the only question that matters is what happened and when. Without a '
			. 'record there is no answer, and the absence is discovered at exactly the moment it is needed.',
			'Enable admin_audit to record who did what to which file, and suspicious_login to be told when a '
			. 'sign-in does not look like the account\'s usual pattern. Both ship with Nextcloud.',
			['missing' => $missing],
		);
	}

	/** What a password has to be before it is accepted. */
	private function passwordRules(): array {
		$policy = $this->apps->isEnabledForUser('password_policy');
		if (!$policy) {
			return $this->finding(
				'password_rules',
				'What counts as a password',
				self::WARN,
				'No password rules are being applied.',
				'Without rules, an account password can be anything, including one that appears in every '
				. 'password list ever published.',
				'Enable the password_policy app, which ships with Nextcloud, and set a minimum length and a '
				. 'check against known breached passwords.',
				[],
			);
		}
		$minLength = (int)$this->config->getAppValue('password_policy', 'minLength', '10');
		$breached = $this->config->getAppValue('password_policy', 'enforceHaveIBeenPwned', '1') === '1';
		$common = $this->config->getAppValue('password_policy', 'enforceNonCommonPassword', '1') === '1';

		$weak = [];
		if ($minLength < 12) {
			$weak[] = 'the minimum length is ' . $minLength;
		}
		if (!$breached) {
			$weak[] = 'passwords are not checked against known breaches';
		}
		if (!$common) {
			$weak[] = 'common passwords are allowed';
		}

		return $this->finding(
			'password_rules',
			'What counts as a password',
			$weak === [] ? self::GOOD : self::NOTE,
			$weak === [] ? 'Passwords must be long and must not be known ones.' : ucfirst(implode(', ', $weak)) . '.',
			'Length beats complexity: a long ordinary phrase is far harder to guess than a short one with a '
			. 'symbol in it. Checking against known breaches matters more than either, because the passwords '
			. 'that actually get used against a server are the ones already published.',
			'In Settings → Administration → Security, set the minimum to twelve or more and turn on both the '
			. 'common-password and breached-password checks.',
			['minLength' => $minLength, 'breachCheck' => $breached, 'commonCheck' => $common],
		);
	}

	/**
	 * What the web server actually hands out to somebody who just asks.
	 *
	 * Every other check here is a statement about what the code intends. This
	 * is the only one that asks the web server what it does, and the two part
	 * company more often than anyone expects — a rewrite rule changed during a
	 * debugging session, a virtual host copied from another site, an
	 * AllowOverride that quietly stopped the shipped .htaccess being read at
	 * all. The code is identical in every one of those cases.
	 */
	private function whatIsServed(): array {
		$last = $this->exposure->last();

		if (!$this->settings->probeEnabled()) {
			return $this->finding(
				'exposure',
				'What the server hands out',
				self::NOTE,
				'Not being checked.',
				'Nothing inside Nextcloud can tell you what the web server in front of it is willing to '
				. 'serve. Only asking it can.',
				'Turn the self-check on under Administration → Sentinel.',
				$last,
			);
		}

		if (($last['never'] ?? true) || (int)($last['probedAt'] ?? 0) === 0) {
			return $this->finding(
				'exposure',
				'What the server hands out',
				self::NOTE,
				'Not asked yet.',
				'The configuration file holds the database password. The log holds paths, names and '
				. 'sometimes tokens. An app installed from git leaves its whole history in the web root. '
				. 'None of those should be downloadable, and the only way to know is to try.',
				'Press the button, or wait for the background job.',
				$last,
			);
		}

		if (!($last['reachable'] ?? false)) {
			return $this->finding(
				'exposure',
				'What the server hands out',
				self::NOTE,
				'The server could not reach itself at ' . (string)($last['base'] ?? '?') . '.',
				'The check works by being an ordinary visitor. If this installation cannot make a request '
				. 'to its own address — a firewall, split DNS, a proxy that only listens for outside '
				. 'traffic — then the check cannot run, and its silence should not be read as good news.',
				'Check overwrite.cli.url in config.php, and that the server may open connections to itself.',
				$last,
			);
		}

		$served = $last['served'] ?? [];
		return $this->finding(
			'exposure',
			'What the server hands out',
			$served === [] ? self::GOOD : self::BAD,
			$served === []
				? 'Asked for ' . (int)($last['checked'] ?? 0) . ' files that should never be served, and was refused every time.'
				: count($served) . ' files that should never be served are being served.',
			'The configuration file holds the database password and the secret that signs every session. '
			. 'The log holds paths and names. A .git directory left behind by an app installed from source '
			. 'holds every version of everything, including whatever was committed by accident once and '
			. 'removed later.',
			$served === []
				? 'Nothing. This is the check worth re-running after any change to the web server.'
				: 'Anything listed below can be downloaded by whoever guesses the address, and some of it is '
				. 'enough to take the server. Restore the shipped .htaccess and make sure AllowOverride lets '
				. 'it be read, or block these paths in the virtual host.',
			$last,
		);
	}

	/** How long the certificate in front of this server has left. */
	private function certificate(): array {
		$certificate = $this->exposure->certificate();
		if (!($certificate['checked'] ?? false)) {
			return $this->finding(
				'certificate',
				'The certificate',
				self::NOTE,
				'Could not be read: ' . (string)($certificate['reason'] ?? 'unknown') . '.',
				'A certificate that expires takes every sync client with it, all at once, and the first '
				. 'anybody hears of it is the phone call.',
				'Not necessarily a problem — a server behind a proxy that terminates TLS elsewhere will say '
				. 'exactly this.',
				$certificate,
			);
		}

		$days = (int)$certificate['daysLeft'];
		$warn = $this->settings->certificateWarnDays();
		$state = self::GOOD;
		if ($days <= 0) {
			$state = self::BAD;
		} elseif ($days <= $warn) {
			$state = self::WARN;
		}

		return $this->finding(
			'certificate',
			'The certificate',
			$state,
			$days <= 0
				? 'The certificate for ' . (string)$certificate['host'] . ' has expired.'
				: 'Valid for another ' . $days . ' days, issued by ' . (string)$certificate['issuer'] . '.',
			'Renewal is automatic until the day it is not — a changed address, a rate limit, a renewal hook '
			. 'that stopped being run. Nothing tells you it has stopped working; the certificate simply runs '
			. 'out on a Saturday.',
			$days <= $warn
				? 'Renew it now, and check that whatever was supposed to renew it is still running.'
				: 'Nothing. This is watched so that a renewal which quietly stopped has somewhere to show up.',
			$certificate,
		);
	}

	/**
	 * Whether anything is watching for the one thing that can destroy
	 * everything without a single password being wrong.
	 */
	private function ransomwareWatch(): array {
		$on = $this->settings->watchChurn();
		$response = $this->settings->churnResponse();
		$minutes = max(1, (int)round($this->settings->churnWindow() / 60));

		return $this->finding(
			'churn',
			'Files changing very fast',
			$on ? self::GOOD : self::WARN,
			$on
				? 'Watching. More than ' . $this->settings->churnWrites() . ' files rewritten or '
					. $this->settings->churnDeletes() . ' deleted by one account in ' . $minutes . ' minutes '
					. ($response === 'lock' ? 'disables the account and ends its sessions.' : 'raises an alarm.')
				: 'Not watching.',
			'A sync client on a machine that catches ransomware does exactly what it is built to do: it '
			. 'uploads every encrypted file over the original. No password was wrong, no permission was '
			. 'exceeded, and every check that asks about passwords and permissions says the server is fine. '
			. 'The only visible thing is the rate — hundreds of files rewritten in minutes, which no person '
			. 'does by hand.',
			$on
				? ($response === 'lock'
					? 'Nothing. Bear in mind it will also stop somebody restoring a large backup into their '
					. 'folder, which is the price of a switch that acts without asking.'
					: 'Consider setting the response to disable the account rather than only report it. '
					. 'Minutes matter here, and nobody reads a notification at three in the morning.')
				: 'Turn it on under Administration → Sentinel.',
			['watching' => $on, 'response' => $response, 'windowSeconds' => $this->settings->churnWindow()],
		);
	}

	/**
	 * @param array<string, mixed> $detail
	 * @return array<string, mixed>
	 */
	private function finding(string $id, string $title, string $state, string $summary, string $why, string $fix, array $detail): array {
		return [
			'id' => $id,
			'title' => $title,
			'state' => $state,
			'summary' => $summary,
			'why' => $why,
			'fix' => $fix,
			'detail' => $detail,
		];
	}
}
