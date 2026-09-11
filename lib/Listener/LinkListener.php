<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\Listener;

use OCA\Files_Sharing\Event\ShareLinkAccessedEvent;
use OCA\Sentinel\Service\LinkWatch;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IRequest;

/**
 * Somebody used a public link.
 *
 * @template-implements IEventListener<ShareLinkAccessedEvent>
 */
class LinkListener implements IEventListener {
	public function __construct(
		private LinkWatch $links,
		private IRequest $request,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof ShareLinkAccessedEvent) {
			return;
		}
		$this->links->seen(
			$event->getShare(),
			$event->getStep(),
			$event->getErrorCode(),
			$this->request->getRemoteAddress(),
		);
	}
}
