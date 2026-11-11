<?php

declare(strict_types=1);

namespace OCA\PhoneSystem\ContactsMenu;

use OCA\PhoneSystem\AppInfo\Application;
use OCP\Contacts\ContactsMenu\IActionFactory;
use OCP\Contacts\ContactsMenu\IEntry;
use OCP\Contacts\ContactsMenu\IProvider;
use OCP\IL10N;
use OCP\IURLGenerator;

/** Adds "Call <number>" to the contacts menu for every phone number of a user or contact. */
class CallProvider implements IProvider {
	public function __construct(
		private IActionFactory $actions,
		private IURLGenerator $urls,
		private IL10N $l10n,
	) {
	}

	public function process(IEntry $entry): void {
		$numbers = $entry->getProperty('TEL');
		if (!is_array($numbers)) {
			$numbers = $numbers ? [$numbers] : [];
		}
		$icon = $this->urls->getAbsoluteURL($this->urls->imagePath(Application::APP_ID, 'app-dark.svg'));
		foreach ($numbers as $number) {
			$number = is_array($number) ? ($number['value'] ?? '') : (string)$number;
			$dial = preg_replace('/[^0-9+*#]/', '', $number);
			if ($dial === '') {
				continue;
			}
			$href = $this->urls->linkToRouteAbsolute('phone_system.page.phone', ['window' => '1', 'dial' => $dial]);
			$action = $this->actions->newLinkAction($icon, $this->l10n->t('Call %s', [$number]), $href, Application::APP_ID);
			$entry->addAction($action);
		}
	}
}
