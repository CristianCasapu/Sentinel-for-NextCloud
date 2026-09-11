<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\Command;

use OCA\Sentinel\Service\Exposure;
use OCA\Sentinel\Service\Inventory;
use OCA\Sentinel\Service\Posture;
use OCP\IDateTimeFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The same report the page shows, for people who live in a terminal and for
 * anything that wants to run it from cron and email the result.
 */
class Check extends Command {
	public function __construct(
		private Posture $posture,
		private Inventory $inventory,
		private Exposure $exposure,
		private IDateTimeFormatter $dates,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('sentinel:check')
			->setDescription('Report on the security posture of this installation')
			->addOption('json', null, InputOption::VALUE_NONE, 'Print the report as JSON')
			->addOption('quiet-when-clean', null, InputOption::VALUE_NONE, 'Print nothing unless something needs attention')
			->addOption('inventory', null, InputOption::VALUE_NONE, 'Also list open links, sessions and application passwords')
			->addOption('probe', null, InputOption::VALUE_NONE, 'Ask the web server first what it is willing to serve');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($input->getOption('probe')) {
			// Asked for explicitly, because it means thirty requests to the
			// server's own address and that should never be a side effect.
			$this->exposure->refresh();
		}

		$report = $this->posture->report();

		if ($input->getOption('json')) {
			if ($input->getOption('inventory')) {
				$report['inventory'] = [
					'links' => $this->inventory->links(),
					'tokens' => $this->inventory->tokens(),
					'accounts' => $this->inventory->accounts(),
				];
			}
			$output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
			return $this->verdict($report);
		}

		$needsAttention = $report['counts']['bad'] > 0 || $report['counts']['warn'] > 0;
		if ($input->getOption('quiet-when-clean') && !$needsAttention) {
			return self::SUCCESS;
		}

		foreach ($report['findings'] as $finding) {
			$mark = match ($finding['state']) {
				Posture::BAD => '<error> !! </error>',
				Posture::WARN => '<comment> !  </comment>',
				Posture::NOTE => '<comment> .  </comment>',
				default => '<info> ok </info>',
			};
			$output->writeln($mark . ' <options=bold>' . $finding['title'] . '</> — ' . $finding['summary']);
			if ($finding['state'] !== Posture::GOOD) {
				$output->writeln('      ' . $this->wrap($finding['fix'], 6));
			}
		}

		$output->writeln('');
		$output->writeln(sprintf(
			'%d need attention, %d worth noting, %d fine.',
			$report['counts']['bad'],
			$report['counts']['warn'] + $report['counts']['note'],
			$report['counts']['good'],
		));

		if ($input->getOption('inventory')) {
			$this->printInventory($output);
		}

		return $this->verdict($report);
	}

	private function printInventory(OutputInterface $output): void {
		$links = $this->inventory->links();
		$output->writeln('');
		$output->writeln('<options=bold>Links open to anyone</> (' . $links['total'] . ')');
		foreach ($links['links'] as $link) {
			$age = $link['created'] > 0 ? $this->dates->formatTimeSpan($link['created']) : 'unknown';
			$output->writeln(sprintf(
				'  %s %-40s %-12s %s%s',
				$link['exposed'] ? '<error>open</error>' : '    ',
				mb_substr($link['target'], 0, 40),
				$link['owner'],
				$age,
				$link['hasPassword'] ? ', password' : ', no password',
			));
		}

		$tokens = $this->inventory->tokens();
		$output->writeln('');
		$output->writeln('<options=bold>Sessions and application passwords</> (' . $tokens['total'] . ', ' . $tokens['cold'] . ' unused)');
		foreach ($tokens['tokens'] as $token) {
			if (!$token['cold']) {
				continue;
			}
			$output->writeln(sprintf(
				'  <comment>cold</comment> %-20s %-40s last used %s',
				$token['uid'],
				mb_substr($token['name'], 0, 40),
				$token['lastUsed'] > 0 ? $this->dates->formatTimeSpan($token['lastUsed']) : 'never',
			));
		}
	}

	/** @param array<string, mixed> $report */
	private function verdict(array $report): int {
		// An exit code somebody can act on from a cron entry: nonzero means
		// go and look.
		return $report['counts']['bad'] > 0 ? 1 : self::SUCCESS;
	}

	private function wrap(string $text, int $indent): string {
		return str_replace("\n", "\n" . str_repeat(' ', $indent), wordwrap($text, 100 - $indent));
	}
}
