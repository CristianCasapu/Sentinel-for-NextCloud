<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\Listener;

use OCA\Sentinel\Service\Journal;
use OCA\Sentinel\Service\Settings;
use OCP\Authentication\TwoFactorAuth\TwoFactorProviderChallengeFailed;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\UserAddedEvent;
use OCP\Group\Events\UserRemovedEvent;
use OCP\Group\Events\SubAdminAddedEvent;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\User\Events\UserCreatedEvent;
use OCP\User\Events\UserDeletedEvent;

/**
 * Changes to who can do what.
 *
 * These are the events that matter most and happen least. An account becoming
 * an administrator, a new account appearing — each is perfectly ordinary when
 * it was you who did it, and each is the last step of an intrusion when it was
 * not. The only way to tell the two apart is to be told at the time.
 *
 * Two-factor authentication is deliberately not watched from here. Nextcloud's
 * events around it do not mean what their names suggest: the "disabled" event
 * fires when somebody types a wrong code, and the "unregistered" one fires
 * during an ordinary sign-in whenever the registry tidies up a provider that is
 * no longer installed. Reporting either as "two-factor was switched off" would
 * be a false alarm on a good day and a cried wolf on a bad one, so the real
 * thing is detected by comparing state in the background job instead — which
 * also catches it being turned off by occ, or in the database directly.
 *
 * What is worth having from that corner is the opposite signal: repeated wrong
 * second factors. That means somebody already has the password and is stuck at
 * the last door.
 *
 * @template-implements IEventListener<Event>
 */
class PrivilegeListener implements IEventListener {
	public function __construct(
		private Journal $journal,
		private Settings $settings,
		private IUserSession $session,
		private IRequest $request,
		private ICacheFactory $caches,
	) {
	}

	public function handle(Event $event): void {
		if (!$this->settings->watchEnabled()) {
			return;
		}

		$actor = $this->session->getUser()?->getUID();
		$address = $this->request->getRemoteAddress();

		if ($event instanceof UserAddedEvent) {
			$group = $event->getGroup()->getGID();
			if ($group !== 'admin') {
				return;
			}
			$this->journal->record(
				'sentinel_admin_granted',
				Journal::ALARM,
				$event->getUser()->getUID() . ' was made an administrator.',
				subject: $event->getUser()->getUID(),
				actor: $actor,
				address: $address,
				detail: ['uid' => $event->getUser()->getUID(), 'by' => $actor],
				quiet: 0,
			);
			return;
		}

		if ($event instanceof UserRemovedEvent) {
			if ($event->getGroup()->getGID() !== 'admin') {
				return;
			}
			$this->journal->record(
				'sentinel_admin_revoked',
				Journal::WARNING,
				$event->getUser()->getUID() . ' is no longer an administrator.',
				subject: $event->getUser()->getUID(),
				actor: $actor,
				address: $address,
				detail: ['uid' => $event->getUser()->getUID(), 'by' => $actor],
				quiet: 0,
			);
			return;
		}

		if ($event instanceof SubAdminAddedEvent) {
			$this->journal->record(
				'sentinel_subadmin_granted',
				Journal::WARNING,
				$event->getUser()->getUID() . ' can now administer the group ' . $event->getGroup()->getGID() . '.',
				subject: $event->getUser()->getUID() . '@' . $event->getGroup()->getGID(),
				actor: $actor,
				address: $address,
				detail: ['uid' => $event->getUser()->getUID(), 'group' => $event->getGroup()->getGID(), 'by' => $actor],
				quiet: 0,
			);
			return;
		}

		if ($event instanceof TwoFactorProviderChallengeFailed) {
			$this->wrongSecondFactor($event->getUser()->getUID(), $event->getProvider()->getId(), $address);
			return;
		}

		if ($event instanceof UserCreatedEvent) {
			$this->journal->record(
				'sentinel_account_created',
				Journal::WARNING,
				'A new account was created: ' . $event->getUid() . '.',
				subject: $event->getUid(),
				actor: $actor,
				address: $address,
				detail: ['uid' => $event->getUid(), 'by' => $actor],
				quiet: 0,
			);
			return;
		}

		if ($event instanceof UserDeletedEvent) {
			$this->journal->record(
				'sentinel_account_deleted',
				Journal::WARNING,
				'The account ' . $event->getUid() . ' was deleted.',
				subject: $event->getUid(),
				actor: $actor,
				address: $address,
				detail: ['uid' => $event->getUid(), 'by' => $actor],
				quiet: 0,
			);
		}
	}

	/**
	 * Somebody has the password and is being stopped by the second factor.
	 *
	 * One wrong code is a person fumbling their phone. A dozen in a quarter of
	 * an hour is the password already being in somebody else's hands, which is
	 * worth knowing long before they find a way past.
	 */
	private function wrongSecondFactor(string $uid, string $provider, string $address): void {
		try {
			$cache = $this->caches->createDistributed('sentinel_2fa');
			$key = $uid . ':' . $address;
			$count = (int)$cache->get($key) + 1;
			$cache->set($key, $count, 900);

			if ($count !== 8) {
				return;
			}

			$this->journal->record(
				'sentinel_second_factor_wrong',
				Journal::ALARM,
				'The password for ' . $uid . ' was accepted ' . $count . ' times in a quarter of an hour and the second factor was not.',
				subject: $uid . ':' . $address,
				actor: $uid,
				address: $address,
				detail: ['uid' => $uid, 'provider' => $provider, 'attempts' => $count, 'address' => $address],
				quiet: 3600,
			);
		} catch (\Throwable) {
			// Counting must never be the reason a sign-in fails.
		}
	}
}
