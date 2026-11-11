<?php

declare(strict_types=1);

namespace OCA\PhoneSystem\Settings;

use OCA\PhoneSystem\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class Section implements IIconSection {
	public function __construct(private IL10N $l10n, private IURLGenerator $urls) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l10n->t('Phone System');
	}

	public function getPriority(): int {
		return 60;
	}

	public function getIcon(): string {
		return $this->urls->imagePath(Application::APP_ID, 'app-dark.svg');
	}
}
