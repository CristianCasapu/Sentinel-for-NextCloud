<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\Controller;

use OCA\Sentinel\Service\Journal;
use OCA\Sentinel\Service\Settings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\Util;

class PageController extends Controller {
	public function __construct(
		IRequest $request,
		private IInitialState $initialState,
		private Settings $settings,
		private Journal $journal,
	) {
		parent::__construct('sentinel', $request);
	}

	/**
	 * The page itself. Administrators only, which the route enforces; there is
	 * nothing here an ordinary account could act on and a great deal it should
	 * not see.
	 */
	public function index(): TemplateResponse {
		$this->initialState->provideInitialState('settings', $this->settings->all());
		$this->initialState->provideInitialState('defaults', $this->settings->defaults());
		$this->initialState->provideInitialState('unseen', $this->journal->unseen());

		Util::addScript('sentinel', 'sentinel-main');
		return new TemplateResponse('sentinel', 'main');
	}
}
