<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\Service;

use OCP\IAppConfig;

/**
 * Every number this app uses, in one place and settable from the admin page.
 *
 * Nothing here is a constant hidden in a class three directories away. What
 * counts as a stale application password, how long a link may sit without an
 * expiry before it is worth mentioning, how often the watchers run, how much
 * of the installation the baseline covers — all of it is a judgement about a
 * particular server, and the person running that server is better placed to
 * make it than I am.
 */
class Settings {
	public const APP = 'sentinel';

	/**
	 * name => [default, kind, min, max]
	 *
	 * @var array<string, array{0: mixed, 1: string, 2?: int|float, 3?: int|float}>
	 */
	private const SCHEMA = [
		// Watching
		'watch_enabled' => [true, 'bool'],
		'watch_interval' => [900, 'int', 300, 86400],
		'notify_admins' => [true, 'bool'],
		'notify_from' => ['warning', 'enum:notice,warning,alarm'],
		'retain_days' => [180, 'int', 7, 3650],

		// What counts as too old
		'stale_token_days' => [90, 'int', 7, 3650],
		'dormant_admin_days' => [180, 'int', 30, 3650],
		'old_link_days' => [180, 'int', 1, 3650],
		'idle_session_days' => [60, 'int', 1, 3650],

		// The baseline
		'baseline_scope' => ['core,apps,3rdparty,config,themes', 'string'],
		'baseline_extensions' => ['php,js,json,html,htaccess,user.ini,sh,xml,yaml,yml', 'string'],
		'baseline_exclude' => ['/data/,/.git/,node_modules,/tests/,/vendor-bin/,.min.js.map', 'string'],
		'baseline_max_bytes' => [8388608, 'int', 65536, 268435456],
		'baseline_digest' => ['xxh128', 'enum:xxh128,sha256'],
		'baseline_auto_after_update' => [false, 'bool'],

		// New places
		'track_places' => [true, 'bool'],
		'place_alert' => [true, 'bool'],
		'place_granularity' => [24, 'int', 8, 32],

		// Posture
		'require_two_factor_admins' => [true, 'bool'],
		'ignore_uids' => ['', 'string'],
	];

	public function __construct(private IAppConfig $config) {
	}

	public function watchEnabled(): bool {
		return (bool)$this->get('watch_enabled');
	}

	public function watchInterval(): int {
		return (int)$this->get('watch_interval');
	}

	public function notifyAdmins(): bool {
		return (bool)$this->get('notify_admins');
	}

	public function notifyFrom(): string {
		return (string)$this->get('notify_from');
	}

	public function retainSeconds(): int {
		return (int)$this->get('retain_days') * 86400;
	}

	public function staleTokenDays(): int {
		return (int)$this->get('stale_token_days');
	}

	public function dormantAdminDays(): int {
		return (int)$this->get('dormant_admin_days');
	}

	public function oldLinkDays(): int {
		return (int)$this->get('old_link_days');
	}

	public function idleSessionDays(): int {
		return (int)$this->get('idle_session_days');
	}

	/** @return string[] */
	public function baselineScope(): array {
		return $this->list('baseline_scope');
	}

	/** @return string[] */
	public function baselineExtensions(): array {
		return array_map(static fn (string $e) => ltrim(strtolower($e), '.'), $this->list('baseline_extensions'));
	}

	/** @return string[] */
	public function baselineExclude(): array {
		return $this->list('baseline_exclude');
	}

	public function baselineMaxBytes(): int {
		return (int)$this->get('baseline_max_bytes');
	}

	public function baselineDigest(): string {
		return (string)$this->get('baseline_digest');
	}

	public function baselineAutoAfterUpdate(): bool {
		return (bool)$this->get('baseline_auto_after_update');
	}

	public function trackPlaces(): bool {
		return (bool)$this->get('track_places');
	}

	public function placeAlert(): bool {
		return (bool)$this->get('place_alert');
	}

	public function placeGranularity(): int {
		return (int)$this->get('place_granularity');
	}

	/** @return string[] */
	public function ignoredUids(): array {
		return $this->list('ignore_uids');
	}

	/** @return string[] */
	private function list(string $key): array {
		$raw = (string)$this->get($key);
		$parts = array_map('trim', explode(',', $raw));
		return array_values(array_filter($parts, static fn (string $p) => $p !== ''));
	}

	public function get(string $key): mixed {
		if (!isset(self::SCHEMA[$key])) {
			throw new \InvalidArgumentException('Unknown setting: ' . $key);
		}
		[$default, $kind] = self::SCHEMA[$key];
		$stored = $this->config->getValueString(self::APP, $key, '__unset__');
		if ($stored === '__unset__') {
			return $default;
		}
		return $this->coerce($key, $stored);
	}

	/** @return array<string, mixed> */
	public function all(): array {
		$out = [];
		foreach (array_keys(self::SCHEMA) as $key) {
			$out[$key] = $this->get($key);
		}
		return $out;
	}

	/** @return array<string, mixed> */
	public function defaults(): array {
		$out = [];
		foreach (self::SCHEMA as $key => $spec) {
			$out[$key] = $spec[0];
		}
		return $out;
	}

	/**
	 * Store a value, having first made it into something the rest of the app
	 * can rely on. A setting that can be set to nonsense from the admin page is
	 * a bug waiting for a quiet afternoon.
	 */
	public function set(string $key, mixed $value): mixed {
		if (!isset(self::SCHEMA[$key])) {
			throw new \InvalidArgumentException('Unknown setting: ' . $key);
		}
		$clean = $this->coerce($key, $value);
		$this->config->setValueString(self::APP, $key, $this->encode($clean));
		return $clean;
	}

	public function reset(string $key): mixed {
		if (!isset(self::SCHEMA[$key])) {
			throw new \InvalidArgumentException('Unknown setting: ' . $key);
		}
		$this->config->deleteKey(self::APP, $key);
		return self::SCHEMA[$key][0];
	}

	private function encode(mixed $value): string {
		if (is_bool($value)) {
			return $value ? '1' : '0';
		}
		return (string)$value;
	}

	private function coerce(string $key, mixed $value): mixed {
		$spec = self::SCHEMA[$key];
		$kind = $spec[1];

		if ($kind === 'bool') {
			if (is_bool($value)) {
				return $value;
			}
			return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true);
		}

		if ($kind === 'int') {
			$n = (int)$value;
			if (isset($spec[2])) {
				$n = max((int)$spec[2], $n);
			}
			if (isset($spec[3])) {
				$n = min((int)$spec[3], $n);
			}
			return $n;
		}

		if (str_starts_with($kind, 'enum:')) {
			$allowed = explode(',', substr($kind, 5));
			$v = (string)$value;
			return in_array($v, $allowed, true) ? $v : $spec[0];
		}

		return trim((string)$value);
	}
}
