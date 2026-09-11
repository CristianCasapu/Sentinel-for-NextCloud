<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Something that happened and was worth recording.
 *
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string getSeverity()
 * @method void setSeverity(string $severity)
 * @method string|null getSubject()
 * @method void setSubject(?string $subject)
 * @method string|null getActor()
 * @method void setActor(?string $actor)
 * @method string|null getAddress()
 * @method void setAddress(?string $address)
 * @method string getSummary()
 * @method void setSummary(string $summary)
 * @method string|null getDetail()
 * @method void setDetail(?string $detail)
 * @method int getOccurred()
 * @method void setOccurred(int $occurred)
 * @method int getSeen()
 * @method void setSeen(int $seen)
 */
class Event extends Entity {
	protected $kind = '';
	protected $severity = 'notice';
	protected $subject = null;
	protected $actor = null;
	protected $address = null;
	protected $summary = '';
	protected $detail = null;
	protected $occurred = 0;
	protected $seen = 0;

	public function __construct() {
		$this->addType('occurred', 'integer');
		$this->addType('seen', 'integer');
	}

	/** @return array<string, mixed> */
	public function asPayload(): array {
		return [
			'id' => $this->getId(),
			'kind' => $this->getKind(),
			'severity' => $this->getSeverity(),
			'subject' => $this->getSubject(),
			'actor' => $this->getActor(),
			'address' => $this->getAddress(),
			'summary' => $this->getSummary(),
			'detail' => $this->getDetail() === null ? null : json_decode($this->getDetail(), true),
			'occurred' => $this->getOccurred(),
			'seen' => $this->getSeen() === 1,
		];
	}
}
