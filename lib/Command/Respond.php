<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\Command;

use OCA\Sentinel\Service\Inventory;
use OCA\Sentinel\Service\Journal;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The one thing the daemon outside cannot do for itself.
 *
 * When the writing is coming through php-fpm — which on a Nextcloud server is
 * almost always — there is no process worth stopping. php-fpm is what writes
 * everybody's files all day; killing it stops the server for every account on
 * the machine, which would be a far more reliable way to lose a day than most
 * of what is being watched for.
 *
 * What needs to stop is the *session* doing it: the sync client on the laptop
 * that has caught something. That is Nextcloud's to end, and this is how the
 * daemon asks. Through occ rather than the API, so that nothing has to keep a
 * credential on disk, and so that every invocation is visible in the process
 * list.
 */
class Respond extends Command {
	public function __construct(
		private Inventory $inventory,
		private IUserManager $users,
		private Journal $journal,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('sentinel:respond')
			->setDescription('End an account\'s sessions, and optionally disable it')
			->addOption('uid', null, InputOption::VALUE_REQUIRED, 'The account')
			->addOption('disable', null, InputOption::VALUE_NONE, 'Also disable the account')
			->addOption('reason', null, InputOption::VALUE_REQUIRED, 'What prompted this', 'the process watcher');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$uid = (string)$input->getOption('uid');
		if ($uid === '') {
			$output->writeln('<error>--uid is required</error>');
			return 1;
		}

		$user = $this->users->get($uid);
		if ($user === null) {
			$output->writeln('<error>No such account: ' . $uid . '</error>');
			return 1;
		}

		$reason = (string)$input->getOption('reason');
		$disable = (bool)$input->getOption('disable');

		$ended = $this->inventory->revokeAllTokens($uid);
		$disabled = false;
		if ($disable) {
			$user->setEnabled(false);
			$disabled = true;
		}

		$this->journal->record(
			'sentinel_responded',
			Journal::ALARM,
			$disabled
				? $uid . ' was signed out of everything and disabled, at the request of ' . $reason . '.'
				: $uid . ' was signed out of everything, at the request of ' . $reason . '.',
			subject: $uid,
			actor: 'sentinel-edr',
			address: null,
			detail: ['uid' => $uid, 'sessionsEnded' => $ended, 'disabled' => $disabled, 'reason' => $reason],
			quiet: 0,
		);

		$output->writeln(($ended ? 'sessions ended' : 'sessions could not be ended')
			. ($disabled ? ', account disabled' : ''));
		return $ended ? 0 : 1;
	}
}
