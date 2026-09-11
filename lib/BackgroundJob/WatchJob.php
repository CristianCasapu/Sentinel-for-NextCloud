<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\BackgroundJob;

use OCA\Sentinel\Service\Baseline;
use OCA\Sentinel\Service\Journal;
use OCA\Sentinel\Service\Posture;
use OCA\Sentinel\Service\Settings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * The part that runs when nobody is looking.
 *
 * A security page is only useful to somebody who visits it, and nobody visits a
 * page where everything has been fine for six months. So the findings come to
 * the administrators instead: this looks at the same things the page does, on a
 * schedule, and says something only when the answer has changed for the worse.
 */
class WatchJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private Posture $posture,
		private Baseline $baseline,
		private Journal $journal,
		private Settings $settings,
		private IAppConfig $config,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval($this->settings->watchInterval());
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		if (!$this->settings->watchEnabled()) {
			return;
		}

		try {
			$this->compareFilesOccasionally();
		} catch (\Throwable $e) {
			$this->logger->error('Sentinel could not compare the installation against its baseline', ['exception' => $e]);
		}

		try {
			$this->speakUpAboutPosture();
		} catch (\Throwable $e) {
			$this->logger->error('Sentinel could not assess the posture of the installation', ['exception' => $e]);
		}

		// Leave nothing behind that nobody will read.
		$this->journal->prune();
	}

	/**
	 * Walking the whole installation is the most expensive thing this app does,
	 * so it happens once an hour at most regardless of how often the job runs.
	 */
	private function compareFilesOccasionally(): void {
		$status = $this->baseline->status();
		if (!$status['taken']) {
			return;
		}
		if ((int)$status['comparedAt'] > time() - 3600) {
			return;
		}

		$after = $this->baseline->compare();
		$moved = (int)$after['changed'] + (int)$after['added'] + (int)$after['removed'];
		if ($moved === 0) {
			return;
		}

		// The changed ones are the story. Files appearing in a cache directory
		// somebody forgot to exclude are not.
		$changed = array_values(array_filter(
			$after['differences'],
			static fn (array $d) => $d['state'] === 'changed' || $d['state'] === 'removed',
		));

		$this->journal->record(
			'sentinel_files_moved',
			$changed === [] ? Journal::WARNING : Journal::ALARM,
			$moved . ' files differ from the approved baseline.',
			subject: 'baseline',
			actor: null,
			address: null,
			detail: [
				'changed' => $after['changed'],
				'added' => $after['added'],
				'removed' => $after['removed'],
				'examples' => array_slice(array_column($changed !== [] ? $changed : $after['differences'], 'path'), 0, 20),
			],
			// Once a day while it stands. Acknowledging the changes ends it
			// properly; repeating it hourly would only teach people to ignore
			// the notification.
			quiet: 86400,
		);
	}

	/**
	 * Say something when a check that used to pass has stopped passing.
	 *
	 * The state of each check is remembered between runs, so a server that has
	 * always had one account without a second factor is not announced every day
	 * for ever; the day a second account joins it, that is news.
	 */
	private function speakUpAboutPosture(): void {
		$report = $this->posture->report();
		$previous = json_decode($this->config->getValueString(Settings::APP, 'posture_state', ''), true);
		$previous = is_array($previous) ? $previous : [];
		$current = [];

		foreach ($report['findings'] as $finding) {
			$id = (string)$finding['id'];
			$current[$id] = ['state' => $finding['state'], 'summary' => $finding['summary']];

			$was = $previous[$id]['state'] ?? null;
			$now = $finding['state'];
			if ($now === Posture::GOOD || $now === Posture::NOTE) {
				continue;
			}

			$rank = [Posture::GOOD => 0, Posture::NOTE => 1, Posture::WARN => 2, Posture::BAD => 3];
			$gotWorse = $was === null || ($rank[$now] ?? 0) > ($rank[$was] ?? 0);
			$summaryChanged = ($previous[$id]['summary'] ?? '') !== $finding['summary'];

			if (!$gotWorse && !$summaryChanged) {
				continue;
			}

			$this->journal->record(
				'sentinel_posture_' . $id,
				$now === Posture::BAD ? Journal::ALARM : Journal::WARNING,
				$finding['title'] . ': ' . $finding['summary'],
				subject: $id,
				actor: null,
				address: null,
				detail: ['finding' => $id, 'state' => $now, 'was' => $was, 'fix' => $finding['fix']],
				quiet: 86400,
			);
		}

		$this->config->setValueString(
			Settings::APP,
			'posture_state',
			json_encode($current, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
		);
	}
}
