<?php

declare(strict_types=1);

namespace OCA\PhoneSystem\AppInfo;

use OCA\PhoneSystem\Dashboard\RecentCalls;
use OCA\PhoneSystem\Listener\LoadPhoneLauncher;
use OCA\PhoneSystem\Listener\AllowPhoneConnections;
use OCA\PhoneSystem\Notification\Notifier;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'phone_system';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		// Every logged-in page gets the header button that opens or focuses the phone window.
		$context->registerEventListener(BeforeTemplateRenderedEvent::class, LoadPhoneLauncher::class);
		// The phone window talks to GCNA's relay (REST and WebSocket).
		$context->registerEventListener(AddContentSecurityPolicyEvent::class, AllowPhoneConnections::class);
		$context->registerDashboardWidget(RecentCalls::class);
		// Missed calls appear in Nextcloud's notifications.
		$context->registerNotifierService(Notifier::class);
	}

	public function boot(IBootContext $context): void {
	}
}
