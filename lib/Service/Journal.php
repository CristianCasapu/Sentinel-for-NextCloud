<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\Service;

use OCA\Sentinel\Db\Event;
use OCA\Sentinel\Db\EventMapper;
use OCP\IGroupManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Where things that happened are written down, and who gets told.
 *
 * The hard part of a security log is not writing to it. It is that a log which
 * says the same thing every fifteen minutes stops being read, and a log nobody
 * reads is the same as no log at all — except that it costs disk and gives a
 * false sense of being watched. So the same standing situation is recorded once
 * and then left alone until it has been quiet for a while.
 */
class Journal {
	public const NOTICE = 'notice';
	public const WARNING = 'warning';
	public const ALARM = 'alarm';

	private const RANK = [self::NOTICE => 0, self::WARNING => 1, self::ALARM => 2];

	public function __construct(
		private EventMapper $events,
		private Settings $settings,
		private INotificationManager $notifications,
		private IGroupManager $groups,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Write something down, unless the same thing was written down recently.
	 *
	 * @param array<string, mixed> $detail
	 * @param int $quiet Seconds during which an identical event is not repeated.
	 */
	public function record(
		string $kind,
		string $severity,
		string $summary,
		?string $subject = null,
		?string $actor = null,
		?string $address = null,
		array $detail = [],
		int $quiet = 21600,
	): ?Event {
		try {
			if ($quiet > 0 && $this->events->saidRecently($kind, $subject, $quiet)) {
				return null;
			}

			$event = new Event();
			$event->setKind($kind);
			$event->setSeverity(isset(self::RANK[$severity]) ? $severity : self::NOTICE);
			$event->setSummary(mb_substr($summary, 0, 1000));
			$event->setSubject($subject === null ? null : mb_substr($subject, 0, 255));
			$event->setActor($actor === null ? null : mb_substr($actor, 0, 64));
			$event->setAddress($address === null ? null : mb_substr($address, 0, 64));
			$event->setDetail($detail === [] ? null : json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
			$event->setOccurred(time());
			$event->setSeen(0);

			$stored = $this->events->insert($event);
			$this->tell($stored);
			return $stored;
		} catch (\Throwable $e) {
			// Failing to write the journal must never break whatever was
			// happening when it failed. A sign-in that works is worth more than
			// a complete record of sign-ins.
			$this->logger->warning('Sentinel could not record an event', ['exception' => $e, 'kind' => $kind]);
			return null;
		}
	}

	/** Put it in front of the administrators, if it is the kind of thing worth interrupting them for. */
	private function tell(Event $event): void {
		if (!$this->settings->notifyAdmins()) {
			return;
		}
		$floor = self::RANK[$this->settings->notifyFrom()] ?? 1;
		if ((self::RANK[$event->getSeverity()] ?? 0) < $floor) {
			return;
		}

		$admins = $this->groups->get('admin')?->getUsers() ?? [];
		foreach ($admins as $admin) {
			try {
				$notification = $this->notifications->createNotification();
				$notification->setApp('sentinel')
					->setUser($admin->getUID())
					->setDateTime(new \DateTime('@' . $event->getOccurred()))
					->setObject('event', (string)$event->getId())
					->setSubject($event->getKind(), [
						'summary' => $event->getSummary(),
						'subject' => $event->getSubject() ?? '',
						'severity' => $event->getSeverity(),
					]);
				$this->notifications->notify($notification);
			} catch (\Throwable $e) {
				$this->logger->debug('Sentinel could not notify an administrator', ['exception' => $e]);
			}
		}
	}

	/** @return array<string, mixed> */
	public function page(int $limit, int $offset, string $severity): array {
		$rows = $this->events->recent($limit, $offset, $severity);
		return [
			'events' => array_map(static fn (Event $e) => $e->asPayload(), $rows),
			'unseen' => $this->events->unseen(),
			'total' => $this->events->total(),
			'offset' => $offset,
			'limit' => $limit,
		];
	}

	public function markAllSeen(): void {
		$this->events->markAllSeen();
	}

	public function unseen(): int {
		try {
			return $this->events->unseen();
		} catch (\Throwable) {
			return 0;
		}
	}

	/** Leave nothing behind that nobody will ever read. */
	public function prune(): int {
		try {
			return $this->events->prune($this->settings->retainSeconds());
		} catch (\Throwable $e) {
			$this->logger->warning('Sentinel could not prune its journal', ['exception' => $e]);
			return 0;
		}
	}
}
