<?php

declare(strict_types=1);

namespace OCA\PhoneSystem\Settings;

use OCA\PhoneSystem\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;
use OCP\Util;

class Admin implements ISettings {
	public function __construct(
		private IURLGenerator $urls,
	) {
	}

	public function getForm(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'admin');
		Util::addStyle(Application::APP_ID, 'phone');
		return new TemplateResponse(Application::APP_ID, 'admin', [
			'setupUrl' => $this->urls->linkToRoute('phone_system.setup.page'),
		]);
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 10;
	}
}
