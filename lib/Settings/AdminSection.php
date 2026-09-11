<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection {
	public function __construct(
		private IL10N $l,
		private IURLGenerator $urls,
	) {
	}

	public function getID(): string {
		return 'sentinel';
	}

	public function getName(): string {
		return $this->l->t('Sentinel');
	}

	/** Just under Security, which is where somebody looking for this would go first. */
	public function getPriority(): int {
		return 51;
	}

	public function getIcon(): string {
		return $this->urls->imagePath('sentinel', 'app-dark.svg');
	}
}
