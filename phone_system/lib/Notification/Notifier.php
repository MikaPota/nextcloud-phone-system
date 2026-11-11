<?php

declare(strict_types=1);

namespace OCA\PhoneSystem\Notification;

use OCA\PhoneSystem\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/** Missed calls in Nextcloud's notifications, in the user's language, with "Call back". */
class Notifier implements INotifier {
	public const MISSED = 'missed_call';

	public function __construct(
		private IFactory $l10n,
		private IURLGenerator $urls,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l10n->get(Application::APP_ID)->t('Phone System');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID || $notification->getSubject() !== self::MISSED) {
			throw new UnknownNotificationException();
		}
		$l = $this->l10n->get(Application::APP_ID, $languageCode);
		$params = $notification->getSubjectParameters();
		$remote = (string)($params['remote'] ?? '');
		$name = (string)($params['name'] ?? '');
		$callBack = $this->urls->linkToRouteAbsolute('phone_system.page.phone', ['dial' => $remote]);
		$notification->setParsedSubject($name !== '' ? $l->t('Missed call from %1$s (%2$s)', [$name, $remote]) : $l->t('Missed call from %s', [$remote]))
			->setIcon($this->urls->getAbsoluteURL($this->urls->imagePath(Application::APP_ID, 'app-dark.svg')))
			->setLink($callBack);
		$action = $notification->createAction();
		$action->setLabel('call_back')->setParsedLabel($l->t('Call back'))->setLink($callBack, 'WEB')->setPrimary(true);
		$notification->addParsedAction($action);
		return $notification;
	}
}
