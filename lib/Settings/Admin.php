<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\Settings;

use OCA\Sentinel\Service\Journal;
use OCA\Sentinel\Service\Settings as SettingsService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * The whole of Sentinel, inside Administration.
 *
 * Not only the settings. The two questions an administrator has arrive together
 * — is anything wrong, and what is watching for it — and answering them on two
 * different screens is how one of them stops being asked. So this page carries
 * the overview, the findings, the files, the inventory, the journal and the
 * settings, and it is the same console as the full-page app.
 *
 * Every number this app uses is on it and hardcoded nowhere else. What counts
 * as a stale application password on a machine used by one person is not what
 * counts on one used by forty, and neither of those is my decision to make.
 */
class Admin implements ISettings {
	public function __construct(
		private IInitialState $initialState,
		private SettingsService $settings,
		private Journal $journal,
	) {
	}

	public function getForm(): TemplateResponse {
		$this->initialState->provideInitialState('settings', $this->settings->all());
		$this->initialState->provideInitialState('defaults', $this->settings->defaults());
		$this->initialState->provideInitialState('unseen', $this->journal->unseen());

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
