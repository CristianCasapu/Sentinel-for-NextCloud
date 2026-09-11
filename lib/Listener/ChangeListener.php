<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\Listener;

use OCA\Sentinel\Service\ChangeWatch;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\FileInfo;
use OCP\Files\Node;

/**
 * Every file change on the server passes through here.
 *
 * Which means everything in this class is a path comparison and one cache
 * increment, and nothing in it is allowed to throw. A listener that makes
 * saving a file fail is worse than no listener.
 *
 * @template-implements IEventListener<Event>
 */
class ChangeListener implements IEventListener {
	public function __construct(private ChangeWatch $changes) {
	}

	public function handle(Event $event): void {
		try {
			if ($event instanceof NodeWrittenEvent) {
				$this->count($event->getNode(), 'write');
				return;
			}
			if ($event instanceof NodeDeletedEvent) {
				$this->count($event->getNode(), 'delete');
				return;
			}
			if ($event instanceof NodeRenamedEvent) {
				$this->count($event->getTarget(), 'rename');
			}
		} catch (\Throwable) {
			// Deliberately silent. The service logs what it can; this layer
			// exists only to make sure a file operation never fails because of
			// something watching it.
		}
	}

	private function count(Node $node, string $kind): void {
		if ($node->getType() !== FileInfo::TYPE_FILE) {
			return;
		}

		$path = $node->getPath();
		// Only somebody's own files. The server writes a great deal on its own
		// behalf — previews, avatars, the app data directory — and none of it
		// is a person doing anything.
		if (!preg_match('#^/([^/]+)/files/#', $path, $found)) {
			return;
		}
		$uid = $found[1];
		if ($uid === '' || str_starts_with($uid, 'appdata_') || $uid === '__groupfolders') {
			return;
		}

		// The reader is handed over rather than called: the watcher only spends
		// a read once the rate is already halfway to being interesting, and on
		// an ordinary day it never opens a single file.
		$this->changes->touched($uid, $kind, $node->getName(), $kind === 'write'
			? static function () use ($node): string {
				$handle = $node->fopen('rb');
				if (!is_resource($handle)) {
					return '';
				}
				try {
					return (string)fread($handle, 64);
				} finally {
					fclose($handle);
				}
			}
			: null);
	}
}
