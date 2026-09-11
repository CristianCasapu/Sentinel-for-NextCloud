<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Sentinel\AppInfo;

use OCA\Sentinel\Listener\FailureListener;
use OCA\Sentinel\Listener\PrivilegeListener;
use OCA\Sentinel\Listener\ShareListener;
use OCA\Sentinel\Listener\SignInListener;
use OCA\Sentinel\Notification\Notifier;
use OCA\Sentinel\SetupCheck\Watching;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Authentication\Events\LoginFailedEvent;
use OCP\Authentication\TwoFactorAuth\TwoFactorProviderForUserDisabled;
use OCP\Authentication\TwoFactorAuth\TwoFactorProviderForUserUnregistered;
use OCP\Group\Events\SubAdminAddedEvent;
use OCP\Group\Events\UserAddedEvent;
use OCP\Group\Events\UserRemovedEvent;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\IGroupManager;
use OCP\L10N\IFactory;
use OCP\Share\Events\ShareCreatedEvent;
use OCP\User\Events\UserCreatedEvent;
use OCP\User\Events\UserDeletedEvent;
use OCP\User\Events\UserLoggedInEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'sentinel';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerNotifierService(Notifier::class);
		$context->registerSetupCheck(Watching::class);

		// Who is here, and from where.
		$context->registerEventListener(UserLoggedInEvent::class, SignInListener::class);
		$context->registerEventListener(LoginFailedEvent::class, FailureListener::class);

		// Who can do what.
		$context->registerEventListener(UserAddedEvent::class, PrivilegeListener::class);
		$context->registerEventListener(UserRemovedEvent::class, PrivilegeListener::class);
		$context->registerEventListener(SubAdminAddedEvent::class, PrivilegeListener::class);
		$context->registerEventListener(UserCreatedEvent::class, PrivilegeListener::class);
		$context->registerEventListener(UserDeletedEvent::class, PrivilegeListener::class);
		$context->registerEventListener(TwoFactorProviderForUserDisabled::class, PrivilegeListener::class);
		$context->registerEventListener(TwoFactorProviderForUserUnregistered::class, PrivilegeListener::class);

		// What has just been opened to the world.
		$context->registerEventListener(ShareCreatedEvent::class, ShareListener::class);
	}

	public function boot(IBootContext $context): void {
		// The entry belongs in the menu of the people who can act on what it
		// says, and nowhere else. Everyone else would see a page that refuses
		// to load, which is worse than no entry at all.
		$context->injectFn(function (
			INavigationManager $navigation,
			IUserSession $session,
			IGroupManager $groups,
			IURLGenerator $urls,
			IFactory $l10n,
		): void {
			$user = $session->getUser();
			if ($user === null || !$groups->isAdmin($user->getUID())) {
				return;
			}
			$navigation->add(static fn (): array => [
				'id' => self::APP_ID,
				'order' => 80,
				'href' => $urls->linkToRoute('sentinel.page.index'),
				'icon' => $urls->imagePath(self::APP_ID, 'app.svg'),
				'name' => $l10n->get(self::APP_ID)->t('Sentinel'),
			]);
		});
	}
}
