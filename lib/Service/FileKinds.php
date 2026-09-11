<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\Service;

/**
 * Does this file still look like what its name claims?
 *
 * This is the signal that makes the difference between noticing ransomware and
 * shouting at somebody uploading a holiday. Rate alone cannot tell them apart:
 * five hundred files in five minutes is a phone finishing its backup as often
 * as it is an encryptor working through a folder.
 *
 * What no legitimate client ever does is rewrite a .jpg so that it is no longer
 * a JPEG. Ransomware does exactly that, to every file it touches, because it
 * encrypts the contents and keeps the name. Sixteen bytes off the front of a
 * sampled file answer the question, and the answer has almost no false
 * positives — which is the only property that matters in something allowed to
 * disable an account.
 */
class FileKinds {
	/**
	 * extension => list of [offset, signature] that a file of this kind may start with.
	 *
	 * Only kinds with a signature worth trusting. Anything not listed here is
	 * not evidence either way and is simply not sampled.
	 *
	 * @var array<string, array<int, array{0: int, 1: string}>>
	 */
	private const MAGIC = [
		'jpg' => [[0, "\xFF\xD8\xFF"]],
		'jpeg' => [[0, "\xFF\xD8\xFF"]],
		'png' => [[0, "\x89PNG\r\n\x1A\n"]],
		'gif' => [[0, 'GIF87a'], [0, 'GIF89a']],
		'bmp' => [[0, 'BM']],
		'webp' => [[0, 'RIFF'], [8, 'WEBP']],
		'tif' => [[0, "II\x2A\x00"], [0, "MM\x00\x2A"]],
		'tiff' => [[0, "II\x2A\x00"], [0, "MM\x00\x2A"]],
		'psd' => [[0, '8BPS']],
		'heic' => [[4, 'ftyp']],
		'heif' => [[4, 'ftyp']],
		'avif' => [[4, 'ftyp']],
		'cr2' => [[0, "II\x2A\x00"]],
		'nef' => [[0, "MM\x00\x2A"], [0, "II\x2A\x00"]],
		'arw' => [[0, "II\x2A\x00"]],
		'dng' => [[0, "II\x2A\x00"], [0, "MM\x00\x2A"]],

		'mp4' => [[4, 'ftyp']],
		'm4v' => [[4, 'ftyp']],
		'm4a' => [[4, 'ftyp']],
		'mov' => [[4, 'ftyp'], [4, 'moov'], [4, 'mdat']],
		'3gp' => [[4, 'ftyp']],
		'mkv' => [[0, "\x1A\x45\xDF\xA3"]],
		'webm' => [[0, "\x1A\x45\xDF\xA3"]],
		'avi' => [[0, 'RIFF'], [8, 'AVI ']],
		'wav' => [[0, 'RIFF'], [8, 'WAVE']],
		'flv' => [[0, 'FLV']],
		'wmv' => [[0, "\x30\x26\xB2\x75"]],
		'asf' => [[0, "\x30\x26\xB2\x75"]],
		'mpg' => [[0, "\x00\x00\x01"]],
		'mpeg' => [[0, "\x00\x00\x01"]],

		'mp3' => [[0, 'ID3'], [0, "\xFF\xFB"], [0, "\xFF\xF3"], [0, "\xFF\xF2"]],
		'flac' => [[0, 'fLaC']],
		'ogg' => [[0, 'OggS']],
		'opus' => [[0, 'OggS']],

		'pdf' => [[0, '%PDF']],
		'zip' => [[0, "PK\x03\x04"], [0, "PK\x05\x06"]],
		'docx' => [[0, "PK\x03\x04"]],
		'xlsx' => [[0, "PK\x03\x04"]],
		'pptx' => [[0, "PK\x03\x04"]],
		'odt' => [[0, "PK\x03\x04"]],
		'ods' => [[0, "PK\x03\x04"]],
		'odp' => [[0, "PK\x03\x04"]],
		'epub' => [[0, "PK\x03\x04"]],
		'doc' => [[0, "\xD0\xCF\x11\xE0"]],
		'xls' => [[0, "\xD0\xCF\x11\xE0"]],
		'ppt' => [[0, "\xD0\xCF\x11\xE0"]],
		'rtf' => [[0, '{\rtf']],
		'7z' => [[0, "7z\xBC\xAF\x27\x1C"]],
		'rar' => [[0, 'Rar!']],
		'gz' => [[0, "\x1F\x8B"]],
		'tgz' => [[0, "\x1F\x8B"]],
		'bz2' => [[0, 'BZh']],
		'xz' => [[0, "\xFD7zXZ"]],
		'sqlite' => [[0, 'SQLite format 3']],
		'exe' => [[0, 'MZ']],
		'dll' => [[0, 'MZ']],
	];

	/**
	 * Names that only ever appear because somebody is being asked for money.
	 *
	 * Matched loosely, because the wording changes with every family while the
	 * shape does not.
	 */
	private const RANSOM_NOTES = [
		'how_to_decrypt', 'how-to-decrypt', 'howtodecrypt',
		'how_to_restore', 'how-to-restore', 'howtorestore',
		'recover_your_files', 'recover-your-files', 'recoveryourfiles',
		'restore_my_files', 'restore-my-files', 'restoremyfiles',
		'your_files_are_encrypted', 'files_encrypted', 'decrypt_instruction',
		'readme_for_decrypt', 'read_me_decrypt', 'decrypt_files',
		'_openme', 'attention.txt', 'unlock_your_files',
	];

	/**
	 * Nextcloud's own server-side encryption writes every file with this
	 * header. On an installation that uses it, every file would otherwise look
	 * as though somebody hostile had encrypted it — which is true, and useless.
	 */
	private const OWN_ENCRYPTION = 'HBEGIN:';

	/** Is this the kind of name that can be checked at all? */
	public function known(string $filename): bool {
		return isset(self::MAGIC[$this->extension($filename)]);
	}

	/**
	 * Does the content match the name?
	 *
	 * Only ever called on a name this class claims to know, and only on the
	 * first few bytes, so the cost is one short read.
	 *
	 * @return bool|null true if it matches, false if it does not, null if it
	 *                   could not be judged (unreadable, too short, unknown kind)
	 */
	public function matches(string $filename, string $head): ?bool {
		$signatures = self::MAGIC[$this->extension($filename)] ?? null;
		if ($signatures === null || $head === '') {
			return null;
		}
		if (str_starts_with($head, self::OWN_ENCRYPTION)) {
			return null;
		}

		$judged = false;
		foreach ($signatures as [$offset, $signature]) {
			// A signature that sits past what was read cannot be judged, but
			// another one for the same kind still might.
			if ($offset + strlen($signature) > strlen($head)) {
				continue;
			}
			$judged = true;
			if (substr($head, $offset, strlen($signature)) === $signature) {
				return true;
			}
		}

		// An empty file, or one truncated to fewer bytes than any signature
		// needs, says nothing either way.
		return $judged ? false : null;
	}

	public function looksLikeARansomNote(string $filename): bool {
		$lower = strtolower($filename);
		if (!str_ends_with($lower, '.txt') && !str_ends_with($lower, '.html') && !str_ends_with($lower, '.hta') && !str_ends_with($lower, '.htm')) {
			return false;
		}
		foreach (self::RANSOM_NOTES as $needle) {
			if (str_contains($lower, $needle)) {
				return true;
			}
		}
		return false;
	}

	private function extension(string $filename): string {
		$dot = strrpos($filename, '.');
		return $dot === false ? '' : strtolower(substr($filename, $dot + 1));
	}
}
