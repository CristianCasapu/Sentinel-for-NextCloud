<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\Listener;

use OCA\Sentinel\Service\Journal;
use OCA\Sentinel\Service\Settings;
use OCP\App\Events\AppDisableEvent;
use OCP\App\Events\AppEnableEvent;
use OCP\App\Events\AppUpdateEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * An app was enabled, updated or switched off.
 *
 * Enabling an app is the most consequential thing anybody can do to a Nextcloud
 * installation: it is arbitrary code, running as the server, with access to
 * everybody's files. It is also completely silent — no notification, no entry
 * anywhere anyone looks. An intruder who reaches an administrator session does
 * not need an exploit after that; they need the app store.
 *
 * Switching one off matters for the opposite reason. The first move against a
 * server that is being watched is to stop it watching.
 *
 * @template-implements IEventListener<Event>
 */
class AppListener implements IEventListener {
	/**
	 * Turning any of these off is not housekeeping.
	 */
	private const GUARDS = ['sentinel', 'twofactor_totp', 'twofactor_backupcodes', 'password_policy', 'admin_audit', 'suspicious_login', 'bruteforcesettings', 'encryption'];

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

		if ($event instanceof AppEnableEvent) {
			$groups = $event->getGroupIds();
			$this->journal->record(
				'sentinel_app_enabled',
				Journal::ALARM,
				'The app ' . $event->getAppId() . ' was enabled'
					. ($groups === [] ? '.' : ' for ' . implode(', ', array_slice($groups, 0, 5)) . '.'),
				subject: $event->getAppId(),
				actor: $actor,
				address: $address,
				detail: ['app' => $event->getAppId(), 'groups' => $groups, 'by' => $actor],
				quiet: 0,
			);
			return;
		}

		if ($event instanceof AppDisableEvent) {
			$guard = in_array($event->getAppId(), self::GUARDS, true);
			$this->journal->record(
				'sentinel_app_disabled',
				$guard ? Journal::ALARM : Journal::WARNING,
				$guard
					? 'The app ' . $event->getAppId() . ', which is part of this installation\'s defences, was switched off.'
					: 'The app ' . $event->getAppId() . ' was switched off.',
				subject: $event->getAppId(),
				actor: $actor,
				address: $address,
				detail: ['app' => $event->getAppId(), 'defence' => $guard, 'by' => $actor],
				quiet: 0,
			);
			return;
		}

		if ($event instanceof AppUpdateEvent) {
			$this->journal->record(
				'sentinel_app_updated',
				Journal::NOTICE,
				'The app ' . $event->getAppId() . ' was updated.',
				subject: $event->getAppId(),
				actor: $actor,
				address: $address,
				detail: ['app' => $event->getAppId(), 'by' => $actor],
				quiet: 0,
			);
		}
	}
}
