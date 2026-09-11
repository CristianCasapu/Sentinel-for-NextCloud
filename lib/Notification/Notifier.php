<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\Notification;

use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * How a finding reads when it arrives in the bell menu.
 *
 * Short enough to be read at a glance, specific enough to be worth acting on.
 * "A security event occurred" tells nobody anything; "casapu was made an
 * administrator" tells them whether to worry.
 */
class Notifier implements INotifier {
	public function __construct(
		private IFactory $l10n,
		private IURLGenerator $urls,
	) {
	}

	public function getID(): string {
		return 'sentinel';
	}

	public function getName(): string {
		return $this->l10n->get('sentinel')->t('Sentinel');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== 'sentinel') {
			throw new UnknownNotificationException();
		}

		$l = $this->l10n->get('sentinel', $languageCode);
		$parameters = $notification->getSubjectParameters();
		$summary = (string)($parameters['summary'] ?? '');
		$severity = (string)($parameters['severity'] ?? 'notice');

		$notification->setParsedSubject(match ($severity) {
			'alarm' => $l->t('Sentinel: something needs attention'),
			'warning' => $l->t('Sentinel: worth a look'),
			default => $l->t('Sentinel'),
		});
		$notification->setParsedMessage($summary !== '' ? $summary : $l->t('A security event was recorded.'));
		$notification->setLink($this->urls->linkToRouteAbsolute('sentinel.page.index') . '#events');
		$notification->setIcon($this->urls->getAbsoluteURL($this->urls->imagePath('sentinel', 'app-dark.svg')));

		return $notification;
	}
}
