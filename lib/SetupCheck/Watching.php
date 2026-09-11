<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\SetupCheck;

use OCA\Sentinel\Service\Baseline;
use OCA\Sentinel\Service\Settings;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * Is Sentinel actually doing anything?
 *
 * An installed security app that has never been set up is worse than none at
 * all, because it looks like protection. This appears on the administration
 * overview alongside Nextcloud's own checks and says plainly whether the thing
 * is switched on.
 */
class Watching implements ISetupCheck {
	public function __construct(
		private IL10N $l,
		private Settings $settings,
		private Baseline $baseline,
	) {
	}

	public function getCategory(): string {
		return 'security';
	}

	public function getName(): string {
		return $this->l->t('Sentinel is watching');
	}

	public function run(): SetupResult {
		if (!$this->settings->watchEnabled()) {
			return SetupResult::warning($this->l->t(
				'Sentinel is installed but switched off, so nothing is being watched. Turn it on under Administration → Sentinel.',
			));
		}

		$status = $this->baseline->status();
		if (!$status['taken']) {
			return SetupResult::info($this->l->t(
				'Sentinel is watching sign-ins, shares and privileges. No file baseline has been taken yet, so changes to the installation itself are not being noticed.',
			));
		}

		$moved = (int)$status['changed'] + (int)$status['removed'];
		if ($moved > 0) {
			return SetupResult::error($this->l->n(
				'%n file of this installation differs from the approved baseline.',
				'%n files of this installation differ from the approved baseline.',
				$moved,
			));
		}

		return SetupResult::success($this->l->n(
			'Sentinel is watching, and %n file matches the approved baseline.',
			'Sentinel is watching, and all %n files match the approved baseline.',
			(int)$status['files'],
		));
	}
}
