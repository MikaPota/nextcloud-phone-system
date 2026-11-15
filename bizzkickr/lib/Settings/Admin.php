<?php

declare(strict_types=1);

namespace OCA\BizzKickR\Settings;

use OCA\BizzKickR\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

/** Administrator: connect this Nextcloud to GCN-ALLIANCE with a free sign-up (GCNA-BK-LINK-2). */
class Admin implements ISettings {
	public function getForm(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'admin');
		Util::addStyle(Application::APP_ID, 'bizzkickr');
		return new TemplateResponse(Application::APP_ID, 'admin');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 10;
	}
}
