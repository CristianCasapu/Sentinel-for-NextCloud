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
			$this->watchfulness(),
			$this->passwordRules(),
			$this->linkDefaults(),
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
	 * Links that anybody holding the address can open, and what stands between
	 * them and the files.
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

		$state = self::GOOD;
		$summary = $total === 0 ? 'Nothing is shared by link.' : $total . ' links are open to anyone holding the address.';
		if ($total > 0 && $open === $total && $forever === $total) {
			$state = self::WARN;
			$summary = 'All ' . $total . ' public links have no password and no expiry.';
		} elseif ($open > 0 || $forever > 0) {
			$state = self::NOTE;
			$summary .= ' ' . $open . ' without a password, ' . $forever . ' that never expire.';
		}
		if ($oldest > 0 && $oldest < time() - (365 * 86400) && $forever > 0) {
			$state = self::WARN;
		}

		return $this->finding(
			'public_links',
			'Links open to anyone',
			$state,
			$summary,
			'A link with no expiry outlives the reason it was made. It stays in an email thread, a chat '
			. 'history, a browser\'s address bar on a borrowed laptop — and it keeps working. Most leaks of '
			. 'this kind are not attacks; they are an address that was passed on and never stopped working.',
			'Give links an expiry by default, in Settings → Administration → Sharing, and require a password '
			. 'for new ones. The list below shows what is open now, and can be given an expiry in one go.',
			[
				'total' => $total,
				'withoutPassword' => $open,
				'neverExpire' => $forever,
				'oldest' => $oldest,
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

	/** What happens by default when somebody makes a link. */
	private function linkDefaults(): array {
		$enforcePassword = $this->config->getAppValue('core', 'shareapi_enforce_links_password', 'no') === 'yes';
		$defaultExpiry = $this->config->getAppValue('core', 'shareapi_default_expire_date', 'no') === 'yes';
		$enforceExpiry = $this->config->getAppValue('core', 'shareapi_enforce_expire_date', 'no') === 'yes';

		$weak = [];
		if (!$enforcePassword) {
			$weak[] = 'a link needs no password';
		}
		if (!$defaultExpiry) {
			$weak[] = 'a link never expires unless somebody sets a date';
		}

		return $this->finding(
			'link_defaults',
			'What a new link does by default',
			$weak === [] ? self::GOOD : self::NOTE,
			$weak === [] ? 'New links get a password and an expiry.' : ucfirst(implode(', and ', $weak)) . '.',
			'Defaults are what happens when somebody is in a hurry, which is most of the time. A setting that '
			. 'has to be remembered on every share is a setting that will be forgotten on the one that matters.',
			'In Settings → Administration → Sharing, set a default expiry for link shares and, if it suits how '
			. 'you work, require a password on them.',
			['passwordRequired' => $enforcePassword, 'expiryByDefault' => $defaultExpiry, 'expiryEnforced' => $enforceExpiry],
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
