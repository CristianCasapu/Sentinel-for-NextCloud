<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\Listener;

use OCA\Sentinel\Service\Journal;
use OCA\Sentinel\Service\Settings;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IRequest;
use OCP\Share\Events\ShareCreatedEvent;
use OCP\Share\IShare;

/**
 * A new way in was just created.
 *
 * Only links are worth mentioning. A share with a colleague is addressed to a
 * person who had to sign in to use it; a link is addressed to whoever ends up
 * holding it, which over a long enough period is everybody.
 *
 * @template-implements IEventListener<ShareCreatedEvent>
 */
class ShareListener implements IEventListener {
	public function __construct(
		private Journal $journal,
		private Settings $settings,
		private IRequest $request,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof ShareCreatedEvent || !$this->settings->watchEnabled()) {
			return;
		}

		$share = $event->getShare();
		if (!in_array($share->getShareType(), [IShare::TYPE_LINK, IShare::TYPE_EMAIL], true)) {
			return;
		}

		$hasPassword = $share->getPassword() !== null && $share->getPassword() !== '';
		$expires = $share->getExpirationDate();
		if ($hasPassword || $expires !== null) {
			// Somebody thought about it. That is the whole ask.
			return;
		}

		$target = $share->getTarget() ?: $share->getNode()?->getName() ?? '';
		$this->journal->record(
			'sentinel_open_link',
			Journal::NOTICE,
			'A link with no password and no expiry was created for ' . $target . '.',
			subject: (string)$share->getId(),
			actor: $share->getSharedBy(),
			address: $this->request->getRemoteAddress(),
			detail: [
				'share' => $share->getId(),
				'target' => $target,
				'owner' => $share->getShareOwner(),
				'by' => $share->getSharedBy(),
			],
			quiet: 0,
		);
	}
}
