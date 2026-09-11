<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\Service;

use OCA\Sentinel\Db\PlaceMapper;
use OCP\Authentication\TwoFactorAuth\IRegistry;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Everything that currently grants access to something, in one list.
 *
 * A Nextcloud installation hands out access in four different places, and no
 * page shows them together: shares in the Files app, application passwords in
 * each person's own settings, sessions in the same place, group membership in
 * administration. Each of them is easy to review and none of them ever is,
 * because reviewing them means visiting four pages per account.
 *
 * So here they are together, oldest and most open first, with the means to
 * close any of them.
 */
class Inventory {
	public function __construct(
		private IDBConnection $db,
		private IShareManager $shares,
		private IUserManager $users,
		private IGroupManager $groups,
		private IRegistry $twoFactor,
		private PlaceMapper $places,
		private Settings $settings,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Links anyone can open.
	 *
	 * Read straight from the table rather than through the share manager: the
	 * manager answers questions about one user's shares at a time, and the
	 * question here is about all of them at once.
	 *
	 * @return array<string, mixed>
	 */
	public function links(int $limit = 500): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'share_type', 'uid_owner', 'uid_initiator', 'file_target', 'item_type', 'token', 'stime', 'expiration', 'password', 'permissions', 'attributes', 'label', 'note')
			->from('share')
			->where($qb->expr()->in('share_type', $qb->createNamedParameter([IShare::TYPE_LINK, IShare::TYPE_EMAIL], IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('stime', 'ASC')
			->setMaxResults($limit);
		$result = $qb->executeQuery();

		$links = [];
		$openForever = 0;
		$old = $this->settings->oldLinkDays() * 86400;
		while ($row = $result->fetch()) {
			$hasPassword = ($row['password'] ?? null) !== null && $row['password'] !== '';
			$expires = $row['expiration'] === null ? 0 : strtotime((string)$row['expiration']);
			$created = (int)$row['stime'];
			$exposed = !$hasPassword && $expires === 0;
			if ($exposed) {
				$openForever++;
			}
			$links[] = [
				'id' => (int)$row['id'],
				'type' => (int)$row['share_type'] === IShare::TYPE_EMAIL ? 'mail' : 'link',
				'owner' => (string)$row['uid_owner'],
				'createdBy' => (string)$row['uid_initiator'],
				'target' => (string)$row['file_target'],
				'itemType' => (string)$row['item_type'],
				'label' => (string)($row['label'] ?? ''),
				'created' => $created,
				'age' => max(0, time() - $created),
				'expires' => $expires ?: 0,
				'hasPassword' => $hasPassword,
				'canDownload' => $this->downloadAllowed((string)($row['attributes'] ?? '')),
				'exposed' => $exposed,
				'stale' => $expires === 0 && $created > 0 && $created < time() - $old,
			];
		}
		$result->closeCursor();

		return [
			'links' => $links,
			'total' => count($links),
			'openForever' => $openForever,
		];
	}

	/** Did somebody turn downloading off on this share, or is it wide open? */
	private function downloadAllowed(string $attributes): bool {
		if ($attributes === '') {
			return true;
		}
		$decoded = json_decode($attributes, true);
		if (!is_array($decoded)) {
			return true;
		}
		foreach ($decoded as $attribute) {
			if (($attribute['scope'] ?? '') === 'permissions' && ($attribute['key'] ?? '') === 'download') {
				return (bool)($attribute['value'] ?? $attribute['enabled'] ?? true);
			}
		}
		return true;
	}

	/**
	 * Sessions and application passwords: every key currently cut for every
	 * account.
	 *
	 * @return array<string, mixed>
	 */
	public function tokens(int $limit = 1000): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'uid', 'login_name', 'name', 'type', 'remember', 'last_activity', 'last_check', 'scope', 'password_invalid', 'version')
			->from('authtoken')
			->orderBy('last_activity', 'ASC')
			->setMaxResults($limit);
		$result = $qb->executeQuery();

		$staleAfter = $this->settings->staleTokenDays() * 86400;
		$idleAfter = $this->settings->idleSessionDays() * 86400;
		$tokens = [];
		$stale = 0;
		while ($row = $result->fetch()) {
			$isApp = (int)$row['type'] === 1;
			$last = (int)$row['last_activity'];
			$cold = $last > 0 && $last < time() - ($isApp ? $staleAfter : $idleAfter);
			if ($cold) {
				$stale++;
			}
			$tokens[] = [
				'id' => (int)$row['id'],
				'uid' => (string)$row['uid'],
				// The name a client sends is its own user-agent string and can
				// say anything at all, so it is carried as text and never as
				// markup.
				'name' => mb_substr((string)$row['name'], 0, 120),
				'kind' => $isApp ? 'application' : 'session',
				'lastUsed' => $last,
				'lastChecked' => (int)$row['last_check'],
				'passwordStale' => (int)($row['password_invalid'] ?? 0) === 1,
				'cold' => $cold,
			];
		}
		$result->closeCursor();

		return ['tokens' => $tokens, 'total' => count($tokens), 'cold' => $stale];
	}

	/**
	 * The accounts themselves: who they are, when they were last here, and what
	 * stands between a stolen password and their files.
	 *
	 * @return array<string, mixed>
	 */
	public function accounts(): array {
		$ignored = $this->settings->ignoredUids();
		$accounts = [];
		$this->users->callForAllUsers(function ($user) use (&$accounts, $ignored): void {
			$uid = $user->getUID();
			if (in_array($uid, $ignored, true)) {
				return;
			}
			$accounts[] = [
				'uid' => $uid,
				'name' => $user->getDisplayName(),
				'enabled' => $user->isEnabled(),
				'admin' => $this->groups->isAdmin($uid),
				'lastSeen' => $user->getLastLogin(),
				'twoFactor' => $this->hasSecondFactor($user),
				'backend' => $user->getBackendClassName(),
				'places' => $this->settings->trackPlaces() ? $this->places->countFor($uid) : 0,
			];
		});

		usort($accounts, static function (array $a, array $b) {
			// Whoever can do the most damage, and has the least protecting
			// them, first.
			return [$b['admin'], !$a['twoFactor']] <=> [$a['admin'], !$b['twoFactor']];
		});

		return [
			'accounts' => $accounts,
			'total' => count($accounts),
			'withoutTwoFactor' => count(array_filter($accounts, static fn (array $a) => !$a['twoFactor'])),
		];
	}

	private function hasSecondFactor($user): bool {
		try {
			foreach ($this->twoFactor->getProviderStates($user) as $enabled) {
				if ($enabled === true) {
					return true;
				}
			}
		} catch (\Throwable $e) {
			$this->logger->debug('Sentinel could not read two-factor state', ['exception' => $e]);
		}
		return false;
	}

	/**
	 * Give a link an expiry date it does not have.
	 *
	 * Through the share manager rather than the table, so that everything that
	 * normally happens when a share changes — the activity entry, the hooks
	 * other apps have registered — still happens.
	 */
	public function expireLink(int $id, int $days): bool {
		try {
			$share = $this->shares->getShareById('ocinternal:' . $id);
			$when = new \DateTime('@' . (time() + max(1, $days) * 86400));
			$when->setTime(0, 0, 0);
			$share->setExpirationDate($when);
			$this->shares->updateShare($share);
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning('Sentinel could not set an expiry on a share', ['exception' => $e, 'share' => $id]);
			return false;
		}
	}

	public function closeLink(int $id): bool {
		try {
			$this->shares->deleteShare($this->shares->getShareById('ocinternal:' . $id));
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning('Sentinel could not remove a share', ['exception' => $e, 'share' => $id]);
			return false;
		}
	}

	/**
	 * End a session or revoke an application password.
	 *
	 * Deleting the row would leave the token alive in the cache for as long as
	 * the cache keeps it, which is precisely the wrong behaviour for the one
	 * operation where "eventually" is not good enough.
	 */
	public function revokeToken(string $uid, int $id): bool {
		try {
			$provider = \OCP\Server::get(\OC\Authentication\Token\IProvider::class);
			$provider->invalidateTokenById($uid, $id);
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning('Sentinel could not revoke a token', ['exception' => $e, 'token' => $id]);
			return false;
		}
	}
}
