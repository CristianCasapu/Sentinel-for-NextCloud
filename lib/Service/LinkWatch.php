<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\Service;

use OCA\Sentinel\Db\LinkUse;
use OCA\Sentinel\Db\LinkUseMapper;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * What is actually happening to the links that are deliberately open.
 *
 * A public link without a password is not a mistake to be scolded about. It is
 * the most useful thing Nextcloud does: a folder handed to somebody who does
 * not have an account and is not going to make one. Telling its owner off every
 * week for using the feature as intended teaches them to ignore the page, and
 * then the page is worth nothing on the day it matters.
 *
 * The useful question is not whether the door is open. It is whether somebody
 * unexpected has started walking through it. A link sent to two people and
 * opened from forty networks in an afternoon is the same link doing something
 * entirely different, and that is worth being told — once, at the time, with
 * the number that makes it obvious.
 *
 * Each link is judged against what that particular link normally does, not
 * against a threshold invented for all of them.
 */
class LinkWatch {
	private const NETWORKS_KEPT = 60;

	public function __construct(
		private LinkUseMapper $mapper,
		private Places $places,
		private Journal $journal,
		private Settings $settings,
		private LoggerInterface $logger,
	) {
	}

	public function today(): int {
		return (int)date('Ymd');
	}

	/**
	 * Somebody opened a link.
	 *
	 * Called on every public share page view and download, so it has to stay
	 * cheap and it has to never throw: a visitor's download failing because a
	 * counter could not be written would be a poor trade.
	 */
	public function seen(IShare $share, string $step, int $errorCode, string $address): void {
		if (!$this->settings->watchLinks()) {
			return;
		}

		try {
			$shareId = (int)$share->getId();
			if ($shareId === 0) {
				return;
			}

			$day = $this->today();
			$row = $this->mapper->find($shareId, $day);
			$fresh = $row === null;
			if ($row === null) {
				$row = new LinkUse();
				$row->setShareId($shareId);
				$row->setToken($share->getToken());
				$row->setDay($day);
			}

			$failed = $errorCode >= 400;
			if ($failed) {
				$row->setFailures($row->getFailures() + 1);
			} elseif ($step === 'download') {
				$row->setDownloads($row->getDownloads() + 1);
			} else {
				$row->setViews($row->getViews() + 1);
			}

			$network = $this->places->network($address);
			$networks = $row->networkList();
			$newNetwork = $network !== '' && !in_array($network, $networks, true);
			if ($newNetwork) {
				$networks[] = $network;
				$row->setNetworks(json_encode(array_slice($networks, -self::NETWORKS_KEPT)));
				// Counted separately from the list, which is capped: the count
				// is what the judgement uses and it must stay true past the cap.
				$row->setNetworkCount($row->getNetworkCount() + 1);
			}
			$row->setLastSeen(time());

			$saved = $fresh ? $this->mapper->insert($row) : $this->mapper->update($row);
			$this->judge($share, $saved);
		} catch (\Throwable $e) {
			$this->logger->debug('Sentinel could not record a link access', ['exception' => $e]);
		}
	}

	/** Is today unlike this link's other days? */
	private function judge(IShare $share, LinkUse $row): void {
		$floorNetworks = $this->settings->linkCrowd();
		$floorFailures = $this->settings->linkFailures();
		$factor = max(2, $this->settings->linkSurge());

		if ($row->getFailures() >= $floorFailures) {
			$this->journal->record(
				'sentinel_link_guessing',
				Journal::WARNING,
				'A password-protected link has been tried and refused ' . $row->getFailures() . ' times today.',
				subject: 'link:' . $row->getShareId(),
				actor: $share->getSharedBy(),
				address: null,
				detail: $this->describe($share, $row),
				quiet: 21600,
			);
			return;
		}

		if ($row->getNetworkCount() < $floorNetworks) {
			return;
		}

		$habit = $this->mapper->habit($row->getShareId(), $row->getDay());
		$usual = $habit['networks'];

		// A link that has always been busy is allowed to be busy. Only a link
		// doing several times what it has ever done before is news.
		if ($usual > 0 && $row->getNetworkCount() < $usual * $factor) {
			return;
		}

		$this->journal->record(
			'sentinel_link_crowd',
			Journal::WARNING,
			'A public link has been opened from ' . $row->getNetworkCount() . ' different networks today'
				. ($usual > 0 ? ', against ' . $usual . ' on its busiest day before.' : '.'),
			subject: 'link:' . $row->getShareId(),
			actor: $share->getSharedBy(),
			address: null,
			detail: $this->describe($share, $row) + ['busiestDayBefore' => $usual, 'daysKnown' => $habit['days']],
			quiet: 43200,
		);
	}

	/** @return array<string, mixed> */
	private function describe(IShare $share, LinkUse $row): array {
		return [
			'share' => $row->getShareId(),
			'target' => $share->getTarget(),
			'owner' => $share->getShareOwner(),
			'by' => $share->getSharedBy(),
			'views' => $row->getViews(),
			'downloads' => $row->getDownloads(),
			'failures' => $row->getFailures(),
			'networks' => $row->getNetworkCount(),
			'hasPassword' => $share->getPassword() !== null && $share->getPassword() !== '',
		];
	}

	/**
	 * Totals for the inventory page, over the window the settings keep.
	 *
	 * @return array<int, array{views: int, downloads: int, failures: int, networks: int, lastSeen: int}>
	 */
	public function totals(int $days = 30): array {
		try {
			$since = (int)date('Ymd', time() - ($days * 86400));
			return $this->mapper->totals($since);
		} catch (\Throwable $e) {
			$this->logger->debug('Sentinel could not read link usage', ['exception' => $e]);
			return [];
		}
	}

	public function prune(): int {
		try {
			$before = (int)date('Ymd', time() - $this->settings->retainSeconds());
			return $this->mapper->prune($before);
		} catch (\Throwable $e) {
			$this->logger->warning('Sentinel could not prune link usage', ['exception' => $e]);
			return 0;
		}
	}

	public function forget(int $shareId): void {
		try {
			$this->mapper->forgetShare($shareId);
		} catch (\Throwable $e) {
			$this->logger->debug('Sentinel could not forget a share', ['exception' => $e]);
		}
	}
}
