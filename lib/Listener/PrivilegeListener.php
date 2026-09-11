<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\Listener;

use OCA\Sentinel\Service\Journal;
use OCA\Sentinel\Service\Settings;
use OCP\Authentication\TwoFactorAuth\TwoFactorProviderForUserDisabled;
use OCP\Authentication\TwoFactorAuth\TwoFactorProviderForUserUnregistered;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\UserAddedEvent;
use OCP\Group\Events\UserRemovedEvent;
use OCP\Group\Events\SubAdminAddedEvent;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\User\Events\UserCreatedEvent;
use OCP\User\Events\UserDeletedEvent;

/**
 * Changes to who can do what.
 *
 * These are the events that matter most and happen least. An account becoming
 * an administrator, a second factor being switched off, a new account
 * appearing — each is perfectly ordinary when it was you who did it, and each
 * is the last step of an intrusion when it was not. The only way to tell the
 * two apart is to be told at the time.
 *
 * @template-implements IEventListener<Event>
 */
class PrivilegeListener implements IEventListener {
	public function __construct(
		private Journal $journal,
		private Settings $settings,
		private IUserSession $session,
		private IRequest $request,
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

		if ($event instanceof TwoFactorProviderForUserDisabled || $event instanceof TwoFactorProviderForUserUnregistered) {
			$uid = $event->getUser()->getUID();
			$this->journal->record(
				'sentinel_two_factor_off',
				Journal::ALARM,
				'Two-factor authentication was switched off for ' . $uid . '.',
				subject: $uid,
				actor: $actor,
				address: $address,
				detail: ['uid' => $uid, 'by' => $actor, 'provider' => $event->getProvider()->getId()],
				quiet: 0,
			);
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
}
