<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\Service;

use OCA\Sentinel\Db\Event;
use OCP\Defaults;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

/**
 * Getting a finding in front of a person who is not looking at the screen.
 *
 * The bell inside Nextcloud is the right place for most things and the wrong
 * place for the one that matters: an administrator who is not signed in does
 * not have a bell. Whatever took the account at three in the morning will be
 * eight hours old by the time anybody opens a browser, and eight hours is the
 * difference between an incident and a disaster.
 *
 * So anything serious goes out by mail as well, to every administrator and to
 * any other address you name — the one that reaches a phone, typically. And
 * once a day there is a short summary, because "nothing happened" arriving
 * every morning is also information: the day it stops arriving, something is
 * wrong with the watching itself.
 */
class Messenger {
	private const RANK = ['notice' => 0, 'warning' => 1, 'alarm' => 2];

	public function __construct(
		private IMailer $mailer,
		private IUserManager $users,
		private IGroupManager $groups,
		private IURLGenerator $urls,
		private IFactory $l10n,
		private Defaults $theme,
		private Settings $settings,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Send one finding, if it is the kind worth interrupting somebody's evening
	 * for.
	 */
	public function announce(Event $event): void {
		if (!$this->settings->emailEnabled()) {
			return;
		}
		$floor = self::RANK[$this->settings->emailFrom()] ?? 2;
		if ((self::RANK[$event->getSeverity()] ?? 0) < $floor) {
			return;
		}

		$recipients = $this->recipients();
		if ($recipients === []) {
			return;
		}

		$l = $this->l10n->get('sentinel');
		$title = match ($event->getSeverity()) {
			'alarm' => $l->t('Sentinel: something needs attention on %s', [$this->host()]),
			'warning' => $l->t('Sentinel: worth a look on %s', [$this->host()]),
			default => $l->t('Sentinel: %s', [$this->host()]),
		};

		try {
			$template = $this->mailer->createEMailTemplate('sentinel.finding', ['summary' => $event->getSummary()]);
			$template->setSubject($title);
			$template->addHeader();
			$template->addHeading($title);
			$template->addBodyText($event->getSummary());

			$when = date('j M Y, H:i', $event->getOccurred());
			$where = trim(($event->getActor() ?? '') . ' ' . ($event->getAddress() ?? ''));
			$template->addBodyText($where === ''
				? $l->t('Recorded %s.', [$when])
				: $l->t('Recorded %1$s — %2$s.', [$when, $where]));

			$template->addBodyButton(
				$l->t('Open Sentinel'),
				$this->urls->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => 'sentinel']),
			);
			$template->addBodyText($l->t('You are receiving this because you administer this server. Which findings arrive by mail can be changed in Administration → Sentinel.'));
			$template->addFooter();

			$message = $this->mailer->createMessage();
			$message->setTo($recipients);
			$message->useTemplate($template);
			$this->mailer->send($message);
		} catch (\Throwable $e) {
			// A finding that could not be mailed is still recorded, and the
			// page will show it. Failing to send must never lose the event.
			$this->logger->warning('Sentinel could not send a finding by mail', ['exception' => $e]);
		}
	}

	/**
	 * The day in one message.
	 *
	 * @param Event[] $events
	 * @param array<string, mixed> $posture
	 */
	public function digest(array $events, array $posture): bool {
		if (!$this->settings->digestEnabled()) {
			return false;
		}
		$recipients = $this->recipients();
		if ($recipients === []) {
			return false;
		}

		$l = $this->l10n->get('sentinel');
		$counts = $posture['counts'] ?? [];
		$needing = (int)($counts['bad'] ?? 0);
		$watching = (int)($counts['warn'] ?? 0);

		$headline = match (true) {
			$needing > 0 => $l->n('%n thing needs attention', '%n things need attention', $needing),
			$watching > 0 => $l->n('%n thing is worth a look', '%n things are worth a look', $watching),
			default => $l->t('Nothing needs attention'),
		};

		try {
			$template = $this->mailer->createEMailTemplate('sentinel.digest', ['host' => $this->host()]);
			$template->setSubject($l->t('%1$s — %2$s', [$this->host(), $headline]));
			$template->addHeader();
			$template->addHeading($headline);

			if ($events === []) {
				$template->addBodyText($l->t('Nothing was recorded in the last day. Which is the best thing this message can say.'));
			} else {
				$template->addBodyText($l->n(
					'%n thing was recorded in the last day:',
					'%n things were recorded in the last day:',
					count($events),
				));
				foreach (array_slice($events, 0, 15) as $event) {
					$template->addBodyListItem(
						$event->getSummary(),
						date('H:i', $event->getOccurred()),
					);
				}
				if (count($events) > 15) {
					$template->addBodyText($l->n('And %n more.', 'And %n more.', count($events) - 15));
				}
			}

			// The standing state, not only the new things: a server where
			// nothing happened today but two accounts still have no second
			// factor is not a server where there is nothing to do.
			$outstanding = array_filter(
				$posture['findings'] ?? [],
				static fn (array $f) => $f['state'] === 'bad' || $f['state'] === 'warn',
			);
			if ($outstanding !== []) {
				$template->addBodyText($l->t('Still outstanding:'));
				foreach ($outstanding as $finding) {
					$template->addBodyListItem($finding['summary'], $finding['title']);
				}
			}

			$template->addBodyButton(
				$l->t('Open Sentinel'),
				$this->urls->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => 'sentinel']),
			);
			$template->addFooter();

			$message = $this->mailer->createMessage();
			$message->setTo($recipients);
			$message->useTemplate($template);
			$this->mailer->send($message);
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning('Sentinel could not send the daily summary', ['exception' => $e]);
			return false;
		}
	}

	/**
	 * Everybody who should hear about it: the administrators who have an
	 * address, plus anything named by hand — which is usually the address that
	 * reaches a phone rather than a mailbox on this same server.
	 *
	 * @return array<string, string> address => name
	 */
	public function recipients(): array {
		$out = [];
		foreach ($this->groups->get('admin')?->getUsers() ?? [] as $admin) {
			$address = $admin->getEMailAddress();
			if ($address !== null && $address !== '' && $this->mailer->validateMailAddress($address)) {
				$out[$address] = $admin->getDisplayName();
			}
		}
		foreach ($this->settings->emailExtra() as $address) {
			if ($this->mailer->validateMailAddress($address)) {
				$out[$address] = $address;
			}
		}
		return $out;
	}

	/**
	 * Whether a message would actually get anywhere, which the admin page says
	 * out loud: mail that was configured once and quietly stopped working is
	 * the same as no mail at all, and worse, because it looks like protection.
	 *
	 * @return array<string, mixed>
	 */
	public function state(): array {
		$recipients = $this->recipients();
		return [
			'enabled' => $this->settings->emailEnabled(),
			'from' => $this->settings->emailFrom(),
			'digest' => $this->settings->digestEnabled(),
			'digestHour' => $this->settings->digestHour(),
			'recipients' => array_keys($recipients),
			'configured' => $recipients !== [],
			'lastSent' => 0,
		];
	}

	/** A test message, so that "it is configured" can be replaced by "it arrived". */
	public function test(): bool {
		$recipients = $this->recipients();
		if ($recipients === []) {
			return false;
		}
		$l = $this->l10n->get('sentinel');
		try {
			$template = $this->mailer->createEMailTemplate('sentinel.test', []);
			$template->setSubject($l->t('Sentinel test message from %s', [$this->host()]));
			$template->addHeader();
			$template->addHeading($l->t('This is what a finding will look like'));
			$template->addBodyText($l->t('If you are reading this, Sentinel can reach you, and the address it reached you at is the one it will use when something actually happens.'));
			$template->addBodyButton(
				$l->t('Open Sentinel'),
				$this->urls->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => 'sentinel']),
			);
			$template->addFooter();

			$message = $this->mailer->createMessage();
			$message->setTo($recipients);
			$message->useTemplate($template);
			$this->mailer->send($message);
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning('Sentinel could not send a test message', ['exception' => $e]);
			return false;
		}
	}

	private function host(): string {
		$url = $this->urls->getAbsoluteURL('/');
		return (string)(parse_url($url, PHP_URL_HOST) ?: $this->theme->getName());
	}
}
