<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\Service;

use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * What this server hands out to somebody who simply asks for it.
 *
 * Every check inside Nextcloud is a statement about what the code intends. This
 * is the only one that asks the web server what it actually does, by being an
 * ordinary anonymous visitor and requesting the files that must never be
 * served: the configuration with the database password in it, the log, the
 * repository that any app installed from git leaves lying in the web root.
 *
 * The distinction matters because none of those leaks are Nextcloud's doing.
 * They come from a rewrite rule that was changed during a debugging session and
 * never changed back, a virtual host copied from another site, an `AllowOverride
 * None` that quietly stopped the shipped .htaccess from being read at all. The
 * code is identical in every one of those cases, and so is every check that
 * only reads the code.
 */
class Exposure {
	/**
	 * Paths that must never come back as 200.
	 *
	 * @var string[]
	 */
	private const FORBIDDEN = [
		'/config/config.php',
		'/config/config.php.bak',
		'/config/config.php~',
		'/config/CAN_INSTALL',
		'/lib/base.php',
		'/lib/private/Server.php',
		'/3rdparty/composer.json',
		'/composer.json',
		'/console.php',
		'/occ',
		'/db_structure.xml',
		'/.git/config',
		'/.user.ini',
		'/README.md',
		'/AUTHORS',
	];

	public function __construct(
		private IClientService $clients,
		private IURLGenerator $urls,
		private IConfig $config,
		private IAppConfig $appConfig,
		private Settings $settings,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * What the last probe found, without making another one.
	 *
	 * Asking the web server thirty questions is not something a page load
	 * should do, so the page reads this and the probe happens on a schedule or
	 * when somebody presses the button.
	 *
	 * @return array<string, mixed>
	 */
	public function last(): array {
		$state = json_decode($this->appConfig->getValueString(Settings::APP, 'exposure_state', ''), true);
		if (!is_array($state)) {
			return ['probedAt' => 0, 'reachable' => false, 'checked' => 0, 'served' => [], 'never' => true];
		}
		return $state + ['never' => false];
	}

	/**
	 * Ask the server for everything it should refuse, and remember the answer.
	 *
	 * @return array<string, mixed>
	 */
	public function refresh(): array {
		$result = $this->probe();
		$this->appConfig->setValueString(
			Settings::APP,
			'exposure_state',
			json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
		);
		return $result + ['never' => false];
	}

	/**
	 * Ask the server for everything it should refuse.
	 *
	 * @return array<string, mixed>
	 */
	public function probe(): array {
		$base = rtrim($this->urls->getAbsoluteURL('/'), '/');
		$paths = array_values(array_unique(array_merge(
			self::FORBIDDEN,
			$this->repositories(),
			$this->dataDirectory(),
			array_map(static fn (string $p) => '/' . ltrim($p, '/'), $this->settings->probeExtra()),
		)));

		$client = $this->clients->newClient();
		$served = [];
		$checked = 0;
		$reachable = $this->reachable($client, $base);

		if (!$reachable) {
			return [
				'reachable' => false,
				'base' => $base,
				'checked' => 0,
				'served' => [],
				'probedAt' => time(),
			];
		}

		foreach ($paths as $path) {
			$checked++;
			$code = $this->ask($client, $base . $path);
			if ($code === 200) {
				$served[] = ['path' => $path, 'code' => $code];
			}
		}

		return [
			'reachable' => true,
			'base' => $base,
			'checked' => $checked,
			'served' => $served,
			'probedAt' => time(),
		];
	}

	/**
	 * Any app installed from git leaves its whole history in the web root, and
	 * that history is often the only place a development password or a private
	 * key still exists. Worth asking about by name rather than in general: the
	 * answer is specific and actionable.
	 *
	 * @return string[]
	 */
	private function repositories(): array {
		$paths = [];
		$root = rtrim(\OC::$SERVERROOT, '/');
		foreach ((array)@scandir($root . '/apps') as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			if (is_dir($root . '/apps/' . $entry . '/.git')) {
				$paths[] = '/apps/' . $entry . '/.git/config';
			}
		}
		return $paths;
	}

	/**
	 * Only worth asking if the data directory is inside the web root at all.
	 * On a correctly built server it is not, and then these paths mean nothing.
	 *
	 * @return string[]
	 */
	private function dataDirectory(): array {
		$root = rtrim(\OC::$SERVERROOT, '/');
		$data = rtrim((string)$this->config->getSystemValue('datadirectory', $root . '/data'), '/');
		if (!str_starts_with($data, $root . '/')) {
			return [];
		}
		$inside = substr($data, strlen($root));
		return [$inside . '/.ocdata', $inside . '/nextcloud.log', $inside . '/owncloud.log'];
	}

	private function reachable($client, string $base): bool {
		return $this->ask($client, $base . '/status.php') === 200;
	}

	private function ask($client, string $url): int {
		try {
			$response = $client->get($url, [
				'timeout' => 8,
				'connect_timeout' => 4,
				// Asking as a visitor, not as this server: no cookies, no
				// redirects followed to somewhere that would answer differently.
				'allow_redirects' => false,
				'nextcloud' => ['allow_local_address' => true],
				'headers' => ['User-Agent' => 'Nextcloud Sentinel (self-check)'],
			]);
			return $response->getStatusCode();
		} catch (\Throwable $e) {
			// A refusal is the desired outcome, and most of the ways a server
			// refuses arrive here as an exception carrying the status.
			$code = method_exists($e, 'getCode') ? (int)$e->getCode() : 0;
			if ($code === 0) {
				$this->logger->debug('Sentinel could not probe a path', ['exception' => $e, 'url' => $url]);
			}
			return $code;
		}
	}

	/**
	 * How long the certificate in front of this server has left.
	 *
	 * Nothing renews on its own for ever. A certificate that expires on a
	 * Saturday takes the sync clients of everybody who uses the server with it,
	 * and the first anybody hears of it is the phone call.
	 *
	 * @return array<string, mixed>
	 */
	public function certificate(): array {
		$base = $this->urls->getAbsoluteURL('/');
		$parts = parse_url($base);
		$host = (string)($parts['host'] ?? '');
		$scheme = (string)($parts['scheme'] ?? 'https');
		$port = (int)($parts['port'] ?? 443);

		if ($host === '' || $scheme !== 'https') {
			return ['checked' => false, 'reason' => 'not https'];
		}

		try {
			$context = stream_context_create(['ssl' => [
				'capture_peer_cert' => true,
				'verify_peer' => false,
				'verify_peer_name' => false,
				'SNI_enabled' => true,
				'peer_name' => $host,
			]]);
			$stream = @stream_socket_client(
				'ssl://' . $host . ':' . $port,
				$errno,
				$error,
				8,
				STREAM_CLIENT_CONNECT,
				$context,
			);
			if ($stream === false) {
				return ['checked' => false, 'reason' => $error ?: 'could not connect'];
			}

			$params = stream_context_get_params($stream);
			fclose($stream);
			$certificate = $params['options']['ssl']['peer_certificate'] ?? null;
			if ($certificate === null) {
				return ['checked' => false, 'reason' => 'no certificate offered'];
			}

			$parsed = openssl_x509_parse($certificate);
			if (!is_array($parsed)) {
				return ['checked' => false, 'reason' => 'certificate could not be read'];
			}

			$until = (int)($parsed['validTo_time_t'] ?? 0);
			return [
				'checked' => true,
				'host' => $host,
				'issuer' => (string)($parsed['issuer']['O'] ?? $parsed['issuer']['CN'] ?? 'unknown'),
				'subject' => (string)($parsed['subject']['CN'] ?? $host),
				'until' => $until,
				'daysLeft' => $until > 0 ? (int)floor(($until - time()) / 86400) : 0,
			];
		} catch (\Throwable $e) {
			$this->logger->debug('Sentinel could not read the certificate', ['exception' => $e]);
			return ['checked' => false, 'reason' => 'certificate could not be read'];
		}
	}
}
