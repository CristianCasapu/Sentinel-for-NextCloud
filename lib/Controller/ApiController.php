<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\Controller;

use OCA\Sentinel\Service\Baseline;
use OCA\Sentinel\Service\ChangeWatch;
use OCA\Sentinel\Service\Exposure;
use OCA\Sentinel\Service\Inventory;
use OCA\Sentinel\Service\Journal;
use OCA\Sentinel\Service\Messenger;
use OCA\Sentinel\Service\Overview;
use OCA\Sentinel\Service\Posture;
use OCA\Sentinel\Service\Settings;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Everything the page asks for.
 *
 * Every route here is administrator-only — there is no #[NoAdminRequired]
 * anywhere in this file, and that is deliberate rather than an oversight. The
 * inventory is a list of every way into the server; it is exactly the thing an
 * intruder with an ordinary account would most like to read.
 */
class ApiController extends OCSController {
	public function __construct(
		IRequest $request,
		private Posture $posture,
		private Baseline $baseline,
		private Exposure $exposure,
		private ChangeWatch $changes,
		private Inventory $inventory,
		private Journal $journal,
		private Overview $overview,
		private Messenger $messenger,
		private Settings $settings,
		private IUserSession $session,
		private LoggerInterface $logger,
	) {
		parent::__construct('sentinel', $request);
	}

	/**
	 * The whole picture in one answer: what is watched, what is not, what has
	 * happened lately, and whether any of it would reach a person.
	 */
	public function overview(): DataResponse {
		return new DataResponse($this->overview->assemble());
	}

	/**
	 * Send a test message, so that "mail is configured" can be replaced by
	 * "mail arrived".
	 */
	public function testMail(): DataResponse {
		$sent = $this->messenger->test();
		return new DataResponse(
			['sent' => $sent, 'recipients' => array_keys($this->messenger->recipients())],
			$sent ? Http::STATUS_OK : Http::STATUS_BAD_REQUEST,
		);
	}

	/** How the installation is doing. */
	public function posture(): DataResponse {
		return new DataResponse($this->posture->report());
	}

	/** What has happened lately. */
	public function events(int $limit = 100, int $offset = 0, string $severity = ''): DataResponse {
		$limit = max(1, min(500, $limit));
		$offset = max(0, $offset);
		return new DataResponse($this->journal->page($limit, $offset, $severity));
	}

	public function acknowledgeEvents(): DataResponse {
		$this->journal->markAllSeen();
		return new DataResponse(['unseen' => 0]);
	}

	/** Links, sessions, application passwords and accounts. */
	public function inventory(): DataResponse {
		return new DataResponse([
			'links' => $this->inventory->links(),
			'tokens' => $this->inventory->tokens(),
			'accounts' => $this->inventory->accounts(),
			'busy' => $this->changes->busy(),
		]);
	}

	/** What the last self-probe found. */
	public function exposure(): DataResponse {
		return new DataResponse($this->exposure->last());
	}

	/**
	 * Ask the web server, now, what it is willing to hand out.
	 *
	 * Thirty-odd requests to itself, so it is a button rather than something
	 * the page does on load.
	 */
	public function probe(): DataResponse {
		try {
			$result = $this->exposure->refresh();
			if (($result['served'] ?? []) !== []) {
				$this->journal->record(
					'sentinel_exposed',
					Journal::ALARM,
					count($result['served']) . ' files that should never be served are being served by the web server.',
					subject: 'exposure',
					actor: $this->session->getUser()?->getUID(),
					address: $this->request->getRemoteAddress(),
					detail: ['served' => array_column($result['served'], 'path')],
					quiet: 3600,
				);
			}
			return new DataResponse($result);
		} catch (\Throwable $e) {
			$this->logger->error('Sentinel could not probe its own address', ['exception' => $e]);
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	public function baselineStatus(): DataResponse {
		return new DataResponse($this->baseline->status());
	}

	/**
	 * Walk the installation now.
	 *
	 * On a large installation this takes a while, which is why it is a
	 * deliberate action with a button rather than something the page does on
	 * load.
	 */
	public function baselineCompare(): DataResponse {
		try {
			return new DataResponse($this->baseline->compare());
		} catch (\Throwable $e) {
			$this->logger->error('Sentinel could not compare the baseline', ['exception' => $e]);
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	public function baselineTake(): DataResponse {
		try {
			$who = $this->session->getUser()?->getUID() ?? 'unknown';
			$result = $this->baseline->take($who);
			$this->journal->record(
				'sentinel_baseline_taken',
				Journal::WARNING,
				'A new baseline was taken of ' . $result['files'] . ' files.',
				subject: null,
				actor: $who,
				address: $this->request->getRemoteAddress(),
				detail: $result,
				quiet: 0,
			);
			return new DataResponse($result + $this->baseline->status());
		} catch (\Throwable $e) {
			$this->logger->error('Sentinel could not take a baseline', ['exception' => $e]);
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Accept some differences as intended.
	 *
	 * @param string[] $paths
	 */
	public function baselineAcknowledge(array $paths, string $note = ''): DataResponse {
		if ($paths === []) {
			return new DataResponse(['message' => 'Nothing to acknowledge'], Http::STATUS_BAD_REQUEST);
		}
		$who = $this->session->getUser()?->getUID() ?? 'unknown';
		$result = $this->baseline->acknowledge($paths, $who, $note === '' ? null : $note);
		$this->journal->record(
			'sentinel_baseline_acknowledged',
			Journal::NOTICE,
			$result['accepted'] . ' file changes were accepted as intended.',
			subject: null,
			actor: $who,
			address: $this->request->getRemoteAddress(),
			detail: ['accepted' => $result['accepted'], 'forgotten' => $result['forgotten'], 'note' => $note],
			quiet: 0,
		);
		return new DataResponse($result);
	}

	public function baselineForget(): DataResponse {
		$this->baseline->forget();
		return new DataResponse($this->baseline->status());
	}

	public function expireLink(int $id, int $days = 30): DataResponse {
		$done = $this->inventory->expireLink($id, $days);
		return new DataResponse(['done' => $done], $done ? Http::STATUS_OK : Http::STATUS_BAD_REQUEST);
	}

	public function closeLink(int $id): DataResponse {
		$done = $this->inventory->closeLink($id);
		return new DataResponse(['done' => $done], $done ? Http::STATUS_OK : Http::STATUS_BAD_REQUEST);
	}

	public function revokeToken(string $uid, int $id): DataResponse {
		$done = $this->inventory->revokeToken($uid, $id);
		if ($done) {
			$this->journal->record(
				'sentinel_token_revoked',
				Journal::NOTICE,
				'An application password or session belonging to ' . $uid . ' was revoked.',
				subject: $uid . ':' . $id,
				actor: $this->session->getUser()?->getUID(),
				address: $this->request->getRemoteAddress(),
				detail: ['uid' => $uid, 'token' => $id],
				quiet: 0,
			);
		}
		return new DataResponse(['done' => $done], $done ? Http::STATUS_OK : Http::STATUS_BAD_REQUEST);
	}

	public function settings(): DataResponse {
		return new DataResponse(['settings' => $this->settings->all(), 'defaults' => $this->settings->defaults()]);
	}

	public function setSetting(string $key, mixed $value): DataResponse {
		try {
			$stored = $this->settings->set($key, $value);
			return new DataResponse(['key' => $key, 'value' => $stored]);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	public function resetSetting(string $key): DataResponse {
		try {
			return new DataResponse(['key' => $key, 'value' => $this->settings->reset($key)]);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}
}
