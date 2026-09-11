<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One file as it looked when somebody last approved it.
 *
 * @method string getPath()
 * @method void setPath(string $path)
 * @method string getDigest()
 * @method void setDigest(string $digest)
 * @method int getSize()
 * @method void setSize(int $size)
 * @method string getScope()
 * @method void setScope(string $scope)
 * @method string|null getNote()
 * @method void setNote(?string $note)
 * @method string|null getAcknowledgedBy()
 * @method void setAcknowledgedBy(?string $acknowledgedBy)
 * @method int getAcknowledgedAt()
 * @method void setAcknowledgedAt(int $acknowledgedAt)
 * @method int getFirstSeen()
 * @method void setFirstSeen(int $firstSeen)
 */
class BaselineFile extends Entity {
	protected $path = '';
	protected $digest = '';
	protected $size = 0;
	protected $scope = 'core';
	protected $note = null;
	protected $acknowledgedBy = null;
	protected $acknowledgedAt = 0;
	protected $firstSeen = 0;

	public function __construct() {
		$this->addType('size', 'integer');
		$this->addType('acknowledgedAt', 'integer');
		$this->addType('firstSeen', 'integer');
	}
}
