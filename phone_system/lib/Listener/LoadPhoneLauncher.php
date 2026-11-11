<?php

declare(strict_types=1);

namespace OCA\PhoneSystem\Listener;

use OCA\PhoneSystem\AppInfo\Application;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/** @template-implements IEventListener<BeforeTemplateRenderedEvent> */
class LoadPhoneLauncher implements IEventListener {
	public function handle(Event $event): void {
		if ($event instanceof BeforeTemplateRenderedEvent && $event->isLoggedIn()) {
			Util::addScript(Application::APP_ID, 'launcher');
		}
	}
}
