<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\Listener;

use OCA\Sentinel\Service\Journal;
use OCA\Sentinel\Service\LinkWatch;
use OCA\Sentinel\Service\Settings;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IRequest;
use OCP\Share\Events\ShareCreatedEvent;
use OCP\Share\Events\ShareDeletedEvent;
use OCP\Share\IShare;

/**
 * A link was made, or removed.
 *
 * Making one is ordinary and is not announced: a public link is a feature being
 * used as intended, and a server that comments on every one of them teaches
 * people to stop reading. The setting exists for installations that want to
 * know, and it is off.
 *
 * Removing one matters for a duller reason — whatever was remembered about how
 * that link was being used should go with it.
 *
 * @template-implements IEventListener<Event>
 */
class ShareListener implements IEventListener {
	public function __construct(
		private Journal $journal,
		private LinkWatch $links,
		private Settings $settings,
		private IRequest $request,
	) {
	}

	public function handle(Event $event): void {
		if ($event instanceof ShareDeletedEvent) {
			$share = $event->getShare();
			if (in_array($share->getShareType(), [IShare::TYPE_LINK, IShare::TYPE_EMAIL], true)) {
				// Leave nothing behind for a link that no longer exists.
				$this->links->forget((int)$share->getId());
			}
			return;
		}

		if (!$event instanceof ShareCreatedEvent || !$this->settings->watchEnabled()) {
			return;
		}
		if (!$this->settings->judgeOpenLinks()) {
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
