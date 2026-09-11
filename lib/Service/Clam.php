<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\Service;

use Psr\Log\LoggerInterface;

/**
 * Asking ClamAV about a specific file, at a specific moment.
 *
 * Not a virus scanner for the server — Nextcloud has files_antivirus for that,
 * and scanning every upload is a different job with a different cost. This is
 * the narrow thing Sentinel needs: when something has already looked wrong for
 * another reason, put the handful of files that caused the suspicion in front
 * of a scanner and see whether it recognises them.
 *
 * It talks to clamd directly over its socket. Shelling out to clamscan would
 * load the entire signature database for every file, which takes a quarter of a
 * minute and a gigabyte of memory; clamd already has it loaded.
 */
class Clam {
	private const CHUNK = 65536;

	public function __construct(
		private Settings $settings,
		private LoggerInterface $logger,
	) {
	}

	/** Is there anything at the other end of the socket? */
	public function available(): bool {
		if (!$this->settings->clamEnabled()) {
			return false;
		}
		return $this->ping() !== null;
	}

	/**
	 * What clamd says about itself: version and how old its signatures are.
	 *
	 * A scanner running on last year's signatures is worse than none, because
	 * it answers "clean" with the same confidence either way.
	 *
	 * @return array<string, mixed>
	 */
	public function state(): array {
		if (!$this->settings->clamEnabled()) {
			return ['enabled' => false, 'reachable' => false, 'reason' => 'switched off'];
		}

		$socket = $this->settings->clamSocket();
		if (!file_exists($socket)) {
			return [
				'enabled' => true,
				'reachable' => false,
				'socket' => $socket,
				'reason' => 'no socket at that path',
			];
		}

		$version = $this->ping();
		if ($version === null) {
			return ['enabled' => true, 'reachable' => false, 'socket' => $socket, 'reason' => 'nothing answered'];
		}

		// "ClamAV 1.0.7/27321/Mon Sep 8 09:12:03 2026"
		$parts = explode('/', $version);
		$signatureDate = isset($parts[2]) ? strtotime(trim($parts[2])) : false;

		return [
			'enabled' => true,
			'reachable' => true,
			'socket' => $socket,
			'version' => trim($parts[0] ?? $version),
			'signatures' => isset($parts[1]) ? (int)$parts[1] : 0,
			'signaturesAt' => $signatureDate === false ? 0 : $signatureDate,
			'signatureAgeDays' => $signatureDate === false ? null : (int)floor((time() - $signatureDate) / 86400),
		];
	}

	/**
	 * Scan one stream.
	 *
	 * @param resource $stream
	 * @return array{scanned: bool, infected: bool, name: string}
	 */
	public function scanStream($stream, int $maxBytes): array {
		$socket = $this->connect();
		if ($socket === null) {
			return ['scanned' => false, 'infected' => false, 'name' => ''];
		}

		try {
			fwrite($socket, "zINSTREAM\0");
			$sent = 0;
			while (!feof($stream) && $sent < $maxBytes) {
				$chunk = fread($stream, min(self::CHUNK, $maxBytes - $sent));
				if ($chunk === false || $chunk === '') {
					break;
				}
				$sent += strlen($chunk);
				fwrite($socket, pack('N', strlen($chunk)) . $chunk);
			}
			// A zero-length chunk is how INSTREAM says "that is all of it".
			fwrite($socket, pack('N', 0));

			$answer = trim((string)stream_get_contents($socket));
			return $this->readAnswer($answer);
		} catch (\Throwable $e) {
			$this->logger->warning('Sentinel could not scan a stream with ClamAV', ['exception' => $e]);
			return ['scanned' => false, 'infected' => false, 'name' => ''];
		} finally {
			fclose($socket);
		}
	}

	/**
	 * Scan a file on the local disk.
	 *
	 * @return array{scanned: bool, infected: bool, name: string}
	 */
	public function scanPath(string $path): array {
		if (!is_readable($path)) {
			return ['scanned' => false, 'infected' => false, 'name' => ''];
		}
		$handle = @fopen($path, 'rb');
		if (!is_resource($handle)) {
			return ['scanned' => false, 'infected' => false, 'name' => ''];
		}
		try {
			return $this->scanStream($handle, $this->settings->clamMaxBytes());
		} finally {
			fclose($handle);
		}
	}

	/** @return array{scanned: bool, infected: bool, name: string} */
	private function readAnswer(string $answer): array {
		// "stream: OK" or "stream: Eicar-Test-Signature FOUND" or "... ERROR"
		$answer = rtrim($answer, "\0");
		if ($answer === '' || str_ends_with($answer, 'ERROR')) {
			return ['scanned' => false, 'infected' => false, 'name' => $answer];
		}
		if (str_ends_with($answer, 'FOUND')) {
			$name = trim(substr($answer, strpos($answer, ':') + 1));
			$name = trim(substr($name, 0, -strlen('FOUND')));
			return ['scanned' => true, 'infected' => true, 'name' => $name];
		}
		return ['scanned' => true, 'infected' => false, 'name' => ''];
	}

	private function ping(): ?string {
		$socket = $this->connect();
		if ($socket === null) {
			return null;
		}
		try {
			fwrite($socket, "zVERSION\0");
			$answer = trim((string)stream_get_contents($socket));
			return $answer === '' ? null : rtrim($answer, "\0");
		} catch (\Throwable) {
			return null;
		} finally {
			fclose($socket);
		}
	}

	/** @return resource|null */
	private function connect() {
		$path = $this->settings->clamSocket();
		if ($path === '') {
			return null;
		}

		$target = str_contains($path, ':') && !str_starts_with($path, '/')
			? 'tcp://' . $path
			: 'unix://' . $path;

		$socket = @stream_socket_client($target, $code, $error, 5);
		if (!is_resource($socket)) {
			return null;
		}
		stream_set_timeout($socket, 30);
		return $socket;
	}
}
