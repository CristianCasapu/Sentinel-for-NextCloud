<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\Command;

use OCA\Sentinel\Service\Baseline as BaselineService;
use OCA\Sentinel\Service\Journal;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Take, compare and approve the record of what the installation's files look
 * like.
 *
 * The natural home for this is the command line: it is what an update script
 * calls after it has finished, so that a deliberate upgrade approves itself and
 * the next unexplained change is the only one that raises an alarm.
 */
class Baseline extends Command {
	public function __construct(
		private BaselineService $baseline,
		private Journal $journal,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('sentinel:baseline')
			->setDescription('Record or check what the files of this installation look like')
			->addArgument('action', InputArgument::OPTIONAL, 'status, take, compare, accept or forget', 'status')
			->addArgument('paths', InputArgument::IS_ARRAY, 'Paths to accept, for the accept action')
			->addOption('note', null, InputOption::VALUE_REQUIRED, 'Why these files are allowed to differ')
			->addOption('all', null, InputOption::VALUE_NONE, 'Accept every current difference')
			->addOption('json', null, InputOption::VALUE_NONE, 'Print the result as JSON')
			->addOption('limit', null, InputOption::VALUE_REQUIRED, 'How many differences to print', '50');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$action = (string)$input->getArgument('action');
		$who = 'occ';

		$result = match ($action) {
			'status' => $this->baseline->status(),
			'compare' => $this->baseline->compare(),
			'take' => $this->take($who),
			'accept' => $this->accept($input, $who),
			'forget' => $this->forget(),
			default => null,
		};

		if ($result === null) {
			$output->writeln('<error>Unknown action. Use status, take, compare, accept or forget.</error>');
			return 1;
		}

		if ($input->getOption('json')) {
			$output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
			return ((int)($result['changed'] ?? 0) + (int)($result['removed'] ?? 0)) > 0 ? 1 : 0;
		}

		return $this->report($result, (int)$input->getOption('limit'), $output);
	}

	/**
	 * Anybody who can run this has a shell on the server, so recording it is
	 * not about catching them — it is so that "when did the baseline last
	 * change, and why" has an answer six months from now.
	 *
	 * @return array<string, mixed>
	 */
	private function take(string $who): array {
		$result = $this->baseline->take($who);
		$this->journal->record(
			'sentinel_baseline_taken',
			Journal::WARNING,
			'A new baseline was taken of ' . $result['files'] . ' files, from the command line.',
			subject: null,
			actor: $who,
			address: null,
			detail: $result,
			quiet: 0,
		);
		return $result + $this->baseline->status();
	}

	/** @return array<string, mixed> */
	private function accept(InputInterface $input, string $who): array {
		$note = $input->getOption('note');
		$note = is_string($note) && $note !== '' ? $note : null;

		if ($input->getOption('all')) {
			$status = $this->baseline->status();
			$paths = array_column($status['differences'], 'path');
		} else {
			$paths = (array)$input->getArgument('paths');
		}

		return $this->baseline->acknowledge($paths, $who, $note);
	}

	/** @return array<string, mixed> */
	private function forget(): array {
		$this->baseline->forget();
		return $this->baseline->status();
	}

	/** @param array<string, mixed> $result */
	private function report(array $result, int $limit, OutputInterface $output): int {
		if (!$result['taken']) {
			$output->writeln('No baseline has been taken. Run <options=bold>occ sentinel:baseline take</> once you are satisfied the installation is correct.');
			return 0;
		}

		$output->writeln(sprintf(
			'%d files recorded%s.',
			$result['files'],
			$result['takenAt'] > 0 ? ', approved ' . date('j M Y H:i', (int)$result['takenAt']) . ' by ' . $result['takenBy'] : '',
		));

		if (isset($result['accepted'])) {
			$output->writeln(sprintf('Accepted %d, dropped %d that are gone.', $result['accepted'], $result['forgotten']));
		}

		$moved = (int)$result['changed'] + (int)$result['added'] + (int)$result['removed'];
		if ($result['comparedAt'] === 0) {
			$output->writeln('Not compared yet. Run <options=bold>occ sentinel:baseline compare</>.');
			return 0;
		}

		if ($moved === 0) {
			$output->writeln('<info>Nothing has changed since.</info>');
			return 0;
		}

		$output->writeln(sprintf(
			'<comment>%d changed, %d new, %d gone</comment> (walked %d files in %ss)',
			$result['changed'],
			$result['added'],
			$result['removed'],
			$result['walked'],
			$result['took'],
		));
		$output->writeln('');

		foreach (array_slice($result['differences'], 0, max(1, $limit)) as $difference) {
			$mark = match ($difference['state']) {
				'changed' => '<error>changed</error>',
				'removed' => '<error>gone   </error>',
				default => '<comment>new    </comment>',
			};
			$output->writeln('  ' . $mark . ' ' . $difference['path']);
		}
		if (count($result['differences']) > $limit) {
			$output->writeln(sprintf('  … and %d more', count($result['differences']) - $limit));
		}
		if ($result['truncated']) {
			$output->writeln('  <comment>The list was cut short; there are more differences than are kept.</comment>');
		}

		$output->writeln('');
		$output->writeln('Accept what you meant to do with <options=bold>occ sentinel:baseline accept --all --note "why"</>.');

		return ((int)$result['changed'] + (int)$result['removed']) > 0 ? 1 : 0;
	}
}
