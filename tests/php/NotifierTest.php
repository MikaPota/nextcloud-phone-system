<?php

declare(strict_types=1);

namespace OCA\PhoneSystem\Tests;

use OCA\PhoneSystem\Notification\Notifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\IAction;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\TestCase;

final class NotifierTest extends TestCase {
	private function notifier(?string &$language = null): Notifier {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(fn (string $text, array $p = []) => vsprintf(str_replace(['%1$s', '%2$s'], ['%s', '%s'], $text), $p));
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturnCallback(function (string $app, ?string $lang = null) use ($l, &$language) {
			$language = $lang;
			return $l;
		});
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(fn (string $route, array $p) => "https://cloud.example/apps/phone_system/?dial=" . rawurlencode($p['dial']));
		$urls->method('imagePath')->willReturn('/apps/phone_system/img/app-dark.svg');
		$urls->method('getAbsoluteURL')->willReturnCallback(fn (string $u) => "https://cloud.example$u");
		return new Notifier($factory, $urls);
	}

	private function missed(array $params, array &$parsed): INotification {
		$n = $this->createMock(INotification::class);
		$n->method('getApp')->willReturn('phone_system');
		$n->method('getSubject')->willReturn(Notifier::MISSED);
		$n->method('getSubjectParameters')->willReturn($params);
		foreach (['setParsedSubject', 'setIcon', 'setLink'] as $m) {
			$n->method($m)->willReturnCallback(function ($v) use ($m, &$parsed, &$n) { $parsed[$m] = $v; return $n; });
		}
		$action = $this->createMock(IAction::class);
		foreach (['setLabel', 'setParsedLabel', 'setLink', 'setPrimary'] as $m) {
			$action->method($m)->willReturnCallback(function (...$v) use ($m, &$parsed, &$action) { $parsed["action.$m"] = $v; return $action; });
		}
		$n->method('createAction')->willReturn($action);
		$n->method('addParsedAction')->willReturnCallback(function () use (&$n) { return $n; });
		return $n;
	}

	public function testAMissedCallNamesTheCallerAndOffersToCallBackInTheUsersLanguage(): void {
		$parsed = [];
		$language = null;
		$this->notifier($language)->prepare($this->missed(['remote' => '+4930123456', 'name' => 'Ada Lovelace'], $parsed), 'de');
		$this->assertSame('de', $language);
		$this->assertSame('Missed call from Ada Lovelace (+4930123456)', $parsed['setParsedSubject']);
		$this->assertSame('https://cloud.example/apps/phone_system/?dial=%2B4930123456', $parsed['setLink']);
		$this->assertSame(['Call back'], $parsed['action.setParsedLabel']);
		$this->assertSame(['https://cloud.example/apps/phone_system/?dial=%2B4930123456', 'WEB'], $parsed['action.setLink']);
	}

	public function testAnUnknownCallerIsNamedByNumber(): void {
		$parsed = [];
		$this->notifier()->prepare($this->missed(['remote' => '202', 'name' => ''], $parsed), 'en');
		$this->assertSame('Missed call from 202', $parsed['setParsedSubject']);
	}

	public function testOtherAppsNotificationsAreNotOurs(): void {
		$n = $this->createMock(INotification::class);
		$n->method('getApp')->willReturn('files');
		$this->expectException(UnknownNotificationException::class);
		$this->notifier()->prepare($n, 'en');
	}
}
