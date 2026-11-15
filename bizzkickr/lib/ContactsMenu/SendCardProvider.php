<?php

declare(strict_types=1);

namespace OCA\BizzKickR\ContactsMenu;

use OCA\BizzKickR\AppInfo\Application;
use OCA\BizzKickR\Service\People;
use OCP\Contacts\ContactsMenu\IActionFactory;
use OCP\Contacts\ContactsMenu\IEntry;
use OCP\Contacts\ContactsMenu\IProvider;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserSession;

/** "Send my BIZZKICKR card" in the contacts menu, for anyone with an email address once the user has a card. */
class SendCardProvider implements IProvider {
	public function __construct(
		private IActionFactory $actions,
		private IURLGenerator $urls,
		private IUserSession $session,
		private People $people,
		private IL10N $l10n,
	) {
	}

	public function process(IEntry $entry): void {
		$user = $this->session->getUser();
		if ($user === null || !$this->people->hasCard($user)) {
			return;
		}
		$own = strtolower((string)$user->getEMailAddress());
		$icon = $this->urls->getAbsoluteURL($this->urls->imagePath(Application::APP_ID, 'app-dark.svg'));
		foreach (array_unique(array_map('strtolower', $entry->getEMailAddresses())) as $email) {
			if ($email === '' || $email === $own) {
				continue;
			}
			$href = $this->urls->linkToRouteAbsolute('bizzkickr.page.index', ['send' => $email]);
			$entry->addAction($this->actions->newLinkAction($icon, $this->l10n->t('Send my BIZZKICKR card'), $href, Application::APP_ID));
			return;
		}
	}
}
