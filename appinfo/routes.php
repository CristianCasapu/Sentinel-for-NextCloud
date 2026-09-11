<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Every route here requires an administrator. None of them is public, none is
 * available to an ordinary account, and that is the single most important fact
 * about this file.
 */
return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
	],
	'ocs' => [
		['name' => 'api#posture', 'url' => '/api/v1/posture', 'verb' => 'GET'],

		['name' => 'api#events', 'url' => '/api/v1/events', 'verb' => 'GET'],
		['name' => 'api#acknowledgeEvents', 'url' => '/api/v1/events/seen', 'verb' => 'POST'],

		['name' => 'api#inventory', 'url' => '/api/v1/inventory', 'verb' => 'GET'],

		['name' => 'api#baselineStatus', 'url' => '/api/v1/baseline', 'verb' => 'GET'],
		['name' => 'api#baselineCompare', 'url' => '/api/v1/baseline/compare', 'verb' => 'POST'],
		['name' => 'api#baselineTake', 'url' => '/api/v1/baseline/take', 'verb' => 'POST'],
		['name' => 'api#baselineAcknowledge', 'url' => '/api/v1/baseline/acknowledge', 'verb' => 'POST'],
		['name' => 'api#baselineForget', 'url' => '/api/v1/baseline', 'verb' => 'DELETE'],

		['name' => 'api#expireLink', 'url' => '/api/v1/links/{id}/expire', 'verb' => 'POST'],
		['name' => 'api#closeLink', 'url' => '/api/v1/links/{id}', 'verb' => 'DELETE'],
		['name' => 'api#revokeToken', 'url' => '/api/v1/tokens/{id}', 'verb' => 'DELETE'],

		['name' => 'api#settings', 'url' => '/api/v1/settings', 'verb' => 'GET'],
		['name' => 'api#setSetting', 'url' => '/api/v1/settings/{key}', 'verb' => 'PUT'],
		['name' => 'api#resetSetting', 'url' => '/api/v1/settings/{key}', 'verb' => 'DELETE'],
	],
];
