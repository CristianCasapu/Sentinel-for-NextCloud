<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A network an account has signed in from before.
 *
 * The network, not the address: a home connection changes address regularly and
 * a phone changes it constantly, so recording the exact address would make
 * every ordinary morning look like a new place.
 *
 * @method string getUid()
 * @method void setUid(string $uid)
 * @method string getNetwork()
 * @method void setNetwork(string $network)
 * @method int getFirstSeen()
 * @method void setFirstSeen(int $firstSeen)
 * @method int getLastSeen()
 * @method void setLastSeen(int $lastSeen)
 * @method int getSightings()
 * @method void setSightings(int $sightings)
 */
class Place extends Entity {
	protected $uid = '';
	protected $network = '';
	protected $firstSeen = 0;
	protected $lastSeen = 0;
	protected $sightings = 0;

	public function __construct() {
		$this->addType('firstSeen', 'integer');
		$this->addType('lastSeen', 'integer');
		$this->addType('sightings', 'integer');
	}
}
