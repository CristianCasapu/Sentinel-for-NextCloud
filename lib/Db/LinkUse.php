<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One public link on one day.
 *
 * @method int getShareId()
 * @method void setShareId(int $shareId)
 * @method string|null getToken()
 * @method void setToken(?string $token)
 * @method int getDay()
 * @method void setDay(int $day)
 * @method int getViews()
 * @method void setViews(int $views)
 * @method int getDownloads()
 * @method void setDownloads(int $downloads)
 * @method int getFailures()
 * @method void setFailures(int $failures)
 * @method string|null getNetworks()
 * @method void setNetworks(?string $networks)
 * @method int getNetworkCount()
 * @method void setNetworkCount(int $networkCount)
 * @method int getLastSeen()
 * @method void setLastSeen(int $lastSeen)
 */
class LinkUse extends Entity {
	protected $shareId = 0;
	protected $token = null;
	protected $day = 0;
	protected $views = 0;
	protected $downloads = 0;
	protected $failures = 0;
	protected $networks = null;
	protected $networkCount = 0;
	protected $lastSeen = 0;

	public function __construct() {
		$this->addType('shareId', 'integer');
		$this->addType('day', 'integer');
		$this->addType('views', 'integer');
		$this->addType('downloads', 'integer');
		$this->addType('failures', 'integer');
		$this->addType('networkCount', 'integer');
		$this->addType('lastSeen', 'integer');
	}

	/** @return string[] */
	public function networkList(): array {
		$decoded = json_decode((string)$this->getNetworks(), true);
		return is_array($decoded) ? $decoded : [];
	}
}
