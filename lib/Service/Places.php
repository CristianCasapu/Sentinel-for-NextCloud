<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\Service;

use OCA\Sentinel\Db\Place;
use OCA\Sentinel\Db\PlaceMapper;
use Psr\Log\LoggerInterface;

/**
 * Where an account usually signs in from.
 *
 * Not the address — the network. A home connection is given a new address
 * every few days and a phone changes its several times an hour, so recording
 * exact addresses would make every ordinary Tuesday look like an intrusion,
 * and an alarm that goes off every Tuesday is one that gets switched off.
 *
 * Rounding to the network the address belongs to keeps the same house looking
 * like the same house, while a sign-in from a different country still looks
 * like what it is.
 */
class Places {
	public function __construct(
		private PlaceMapper $mapper,
		private Settings $settings,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Record a sighting.
	 *
	 * @return bool True if this is somewhere the account has not been seen before.
	 */
	public function sighting(string $uid, string $address): bool {
		if (!$this->settings->trackPlaces()) {
			return false;
		}
		$network = $this->network($address);
		if ($network === '') {
			return false;
		}

		try {
			$existing = $this->mapper->find($uid, $network);
			if ($existing !== null) {
				$existing->setLastSeen(time());
				$existing->setSightings($existing->getSightings() + 1);
				$this->mapper->update($existing);
				return false;
			}

			$place = new Place();
			$place->setUid($uid);
			$place->setNetwork($network);
			$place->setFirstSeen(time());
			$place->setLastSeen(time());
			$place->setSightings(1);
			$this->mapper->insert($place);

			// The first sighting ever is not news — it is the account being
			// used for the first time since this app was installed, and
			// announcing it would mean an alarm for every account on the first
			// day.
			return $this->mapper->countFor($uid) > 1;
		} catch (\Throwable $e) {
			$this->logger->debug('Sentinel could not record a place', ['exception' => $e]);
			return false;
		}
	}

	/**
	 * The network an address belongs to, written the way a person would read
	 * it.
	 */
	public function network(string $address): string {
		$address = trim($address);
		if ($address === '') {
			return '';
		}

		if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			$bits = max(8, min(32, $this->settings->placeGranularity()));
			$long = ip2long($address);
			if ($long === false) {
				return '';
			}
			$mask = $bits === 0 ? 0 : (-1 << (32 - $bits));
			return long2ip($long & $mask) . '/' . $bits;
		}

		if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
			// A single household is routinely given a /64 and often a /56, so
			// the first three groups are what stays the same as the address
			// changes underneath.
			$packed = @inet_pton($address);
			if ($packed === false) {
				return '';
			}
			$prefix = substr($packed, 0, 6) . str_repeat("\0", 10);
			$readable = @inet_ntop($prefix);
			return $readable === false ? '' : $readable . '/48';
		}

		return '';
	}

	/** @return array<int, array<string, mixed>> */
	public function forUser(string $uid): array {
		$out = [];
		foreach ($this->mapper->forUser($uid) as $place) {
			$out[] = [
				'network' => $place->getNetwork(),
				'firstSeen' => $place->getFirstSeen(),
				'lastSeen' => $place->getLastSeen(),
				'sightings' => $place->getSightings(),
			];
		}
		return $out;
	}
}
