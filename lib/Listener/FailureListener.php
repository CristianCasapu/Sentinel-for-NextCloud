<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\Listener;

use OCA\Sentinel\Service\Journal;
use OCA\Sentinel\Service\Settings;
use OCP\Authentication\Events\LoginFailedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IRequest;

/**
 * A password that did not work.
 *
 * One of these is somebody's morning. Nextcloud already slows down whoever is
 * producing them, and repeating that here would serve nobody. What is worth
 * recording is the shape: many attempts against one account, or one address
 * working its way through several accounts — the second being the thing that
 * throttling alone does not make visible, because each individual account only
 * sees a couple of tries.
 *
 * @template-implements IEventListener<LoginFailedEvent>
 */
class FailureListener implements IEventListener {
	private const WINDOW = 900;
	private const ATTEMPTS_BEFORE_SPEAKING = 10;
	private const ACCOUNTS_BEFORE_SPEAKING = 3;

	public function __construct(
		private Journal $journal,
		private Settings $settings,
		private IGroupManager $groups,
		private IRequest $request,
		private ICacheFactory $caches,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof LoginFailedEvent) {
			return;
		}
		if (!$this->settings->watchEnabled()) {
			return;
		}

		$uid = $event->getUid();
		$address = $this->request->getRemoteAddress();
		$cache = $this->caches->createDistributed('sentinel_failures');

		$attempts = $this->tally($cache, 'addr:' . $address);
		$accounts = $this->accountsTried($cache, $address, $uid);

		if (count($accounts) >= self::ACCOUNTS_BEFORE_SPEAKING) {
			$this->journal->record(
				'sentinel_spraying',
				Journal::WARNING,
				'One address has tried ' . count($accounts) . ' different accounts in the last quarter of an hour.',
				subject: $address,
				actor: null,
				address: $address,
				detail: ['address' => $address, 'accounts' => array_slice($accounts, 0, 20), 'attempts' => $attempts],
				quiet: 3600,
			);
			return;
		}

		if ($attempts >= self::ATTEMPTS_BEFORE_SPEAKING) {
			$isAdmin = $uid !== '' && $this->groups->isAdmin($uid);
			$this->journal->record(
				'sentinel_guessing',
				$isAdmin ? Journal::WARNING : Journal::NOTICE,
				$isAdmin
					? $attempts . ' failed attempts against the administrator account ' . $uid . '.'
					: $attempts . ' failed sign-in attempts from one address.',
				subject: $address . ':' . $uid,
				actor: $uid !== '' ? $uid : null,
				address: $address,
				detail: ['address' => $address, 'uid' => $uid, 'attempts' => $attempts, 'administrator' => $isAdmin],
				quiet: 3600,
			);
		}
	}

	private function tally($cache, string $key): int {
		try {
			$count = (int)$cache->get($key);
			$count++;
			$cache->set($key, $count, self::WINDOW);
			return $count;
		} catch (\Throwable) {
			return 0;
		}
	}

	/** @return string[] */
	private function accountsTried($cache, string $address, string $uid): array {
		try {
			$key = 'names:' . $address;
			$seen = $cache->get($key);
			$seen = is_array($seen) ? $seen : [];
			if ($uid !== '' && !in_array($uid, $seen, true)) {
				$seen[] = $uid;
				$cache->set($key, array_slice($seen, -50), self::WINDOW);
			}
			return $seen;
		} catch (\Throwable) {
			return [];
		}
	}
}
