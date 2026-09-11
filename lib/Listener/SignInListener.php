<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\Listener;

use OCA\Sentinel\Service\Journal;
use OCA\Sentinel\Service\Places;
use OCA\Sentinel\Service\Settings;
use OCP\Authentication\TwoFactorAuth\IRegistry;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\User\Events\UserLoggedInEvent;

/**
 * Somebody signed in. Was it from somewhere they have been before?
 *
 * @template-implements IEventListener<UserLoggedInEvent>
 */
class SignInListener implements IEventListener {
	public function __construct(
		private Places $places,
		private Journal $journal,
		private Settings $settings,
		private IGroupManager $groups,
		private IRegistry $twoFactor,
		private IRequest $request,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof UserLoggedInEvent) {
			return;
		}
		if (!$this->settings->watchEnabled()) {
			return;
		}

		$uid = $event->getUid();
		if (in_array($uid, $this->settings->ignoredUids(), true)) {
			return;
		}

		$address = $this->request->getRemoteAddress();
		$isNew = $this->places->sighting($uid, $address);
		if (!$isNew || !$this->settings->placeAlert()) {
			return;
		}

		$isAdmin = $this->groups->isAdmin($uid);
		$protected = $this->hasSecondFactor($event->getUser());

		// An administrator arriving somewhere new with only a password is the
		// shape of a compromise. The same account with a second factor, or an
		// ordinary account on holiday, is a note.
		$severity = ($isAdmin && !$protected) ? Journal::ALARM : Journal::NOTICE;

		$this->journal->record(
			'sentinel_new_place',
			$severity,
			$isAdmin
				? 'The administrator ' . $uid . ' signed in from a network not seen before.'
				: $uid . ' signed in from a network not seen before.',
			subject: $uid . '@' . $this->places->network($address),
			actor: $uid,
			address: $address,
			detail: [
				'uid' => $uid,
				'network' => $this->places->network($address),
				'administrator' => $isAdmin,
				'twoFactor' => $protected,
				'tokenLogin' => $event->isTokenLogin(),
			],
			// Once per network per day is plenty: somebody travelling should
			// not generate an alarm every time their laptop wakes up.
			quiet: 86400,
		);
	}

	private function hasSecondFactor($user): bool {
		try {
			foreach ($this->twoFactor->getProviderStates($user) as $enabled) {
				if ($enabled === true) {
					return true;
				}
			}
		} catch (\Throwable) {
		}
		return false;
	}
}
