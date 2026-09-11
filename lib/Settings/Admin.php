<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\Settings;

use OCA\Sentinel\Service\Baseline;
use OCA\Sentinel\Service\Settings as SettingsService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * Every number this app uses, where the person running the server can change it.
 *
 * There is nothing here that is also hardcoded somewhere else. What counts as a
 * stale application password on a machine used by one person is not what counts
 * on one used by forty, and neither of those is my decision to make.
 */
class Admin implements ISettings {
	public function __construct(
		private IInitialState $initialState,
		private SettingsService $settings,
		private Baseline $baseline,
	) {
	}

	public function getForm(): TemplateResponse {
		$this->initialState->provideInitialState('settings', $this->settings->all());
		$this->initialState->provideInitialState('defaults', $this->settings->defaults());
		$this->initialState->provideInitialState('baseline', $this->baseline->status());

		Util::addScript('sentinel', 'sentinel-admin');
		return new TemplateResponse('sentinel', 'admin', [], TemplateResponse::RENDER_AS_BLANK);
	}

	public function getSection(): string {
		return 'sentinel';
	}

	public function getPriority(): int {
		return 10;
	}
}
