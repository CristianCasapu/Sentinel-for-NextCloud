<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\Migration;

use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use OCP\Notification\IManager as INotificationManager;

/**
 * Leave nothing behind.
 *
 * Nextcloud does not drop an app's tables when the app is removed, so an app
 * that has been uninstalled for two years can still be sitting in the database
 * holding a list of every file on the server and every network every account
 * has used. For an app whose whole subject is what accumulates unnoticed, that
 * would be a poor joke.
 */
class Uninstall implements IRepairStep {
	public function __construct(
		private IDBConnection $db,
		private IAppConfig $config,
		private INotificationManager $notifications,
	) {
	}

	public function getName(): string {
		return 'Remove everything Sentinel stored';
	}

	public function run(IOutput $output): void {
		foreach (['sentinel_baseline', 'sentinel_events', 'sentinel_places'] as $table) {
			try {
				if ($this->db->tableExists($table)) {
					$this->db->dropTable($table);
					$output->info('Dropped ' . $table);
				}
			} catch (\Throwable $e) {
				$output->warning('Could not drop ' . $table . ': ' . $e->getMessage());
			}
		}

		try {
			$this->config->deleteApp('sentinel');
			$output->info('Removed the settings');
		} catch (\Throwable $e) {
			$output->warning('Could not remove the settings: ' . $e->getMessage());
		}

		try {
			$notification = $this->notifications->createNotification();
			$notification->setApp('sentinel');
			$this->notifications->markProcessed($notification);
			$output->info('Removed pending notifications');
		} catch (\Throwable $e) {
			$output->warning('Could not remove pending notifications: ' . $e->getMessage());
		}
	}
}
