<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\BackgroundJob;

use OCA\Sentinel\Db\EventMapper;
use OCA\Sentinel\Service\Messenger;
use OCA\Sentinel\Service\Posture;
use OCA\Sentinel\Service\Settings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * The day in one message, once a day.
 *
 * Worth sending even when there is nothing to say. "Nothing happened" arriving
 * every morning is itself information: the morning it does not arrive is the
 * morning to go and look at why — a server that has stopped watching looks
 * exactly like a server where nothing is wrong.
 */
class DigestJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private EventMapper $events,
		private Posture $posture,
		private Messenger $messenger,
		private Settings $settings,
		private IAppConfig $config,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		// Checked hourly; sent once, at the hour that was asked for.
		$this->setInterval(3600);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		if (!$this->settings->watchEnabled() || !$this->settings->digestEnabled()) {
			return;
		}

		$now = time();
		if ((int)date('G', $now) !== $this->settings->digestHour()) {
			return;
		}

		$today = (int)date('Ymd', $now);
		if ($this->config->getValueInt(Settings::APP, 'digest_sent_day', 0) === $today) {
			return;
		}
		// Written before sending rather than after: a mail server that hangs
		// should cost one missed summary, not an hourly retry for ever.
		$this->config->setValueInt(Settings::APP, 'digest_sent_day', $today);

		try {
			$sent = $this->messenger->digest(
				$this->events->since($now - 86400),
				$this->posture->report(),
			);
			if ($sent) {
				$this->config->setValueInt(Settings::APP, 'digest_sent_at', $now);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Sentinel could not send the daily summary', ['exception' => $e]);
		}
	}
}
