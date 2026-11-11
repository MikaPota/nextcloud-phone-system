<?php

declare(strict_types=1);

namespace OCA\PhoneSystem\Tests;

use OCA\PhoneSystem\Dashboard\RecentCalls;
use OCA\PhoneSystem\Db\Call;
use OCA\PhoneSystem\Db\CallMapper;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\IDateTimeFormatter;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/** Nextcloud's dashboard renders only v2 widgets itself; a v1-only widget never stops loading. */
final class RecentCallsTest extends TestCase {
	private function widget(array $calls): RecentCalls {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(fn ($route, $params = []) => 'https://cloud.example/phone?' . http_build_query($params));
		$urls->method('imagePath')->willReturn('/img/app-dark.svg');
		$urls->method('getAbsoluteURL')->willReturnArgument(0);
		$mapper = $this->createMock(CallMapper::class);
		$mapper->method('recent')->willReturn($calls);
		$formatter = $this->createMock(IDateTimeFormatter::class);
		$formatter->method('formatDateTime')->willReturn('11/11/26, 7:44 PM');
		return new RecentCalls($l, $urls, $mapper, $formatter);
	}

	public function testOffersTheV2ApiWithItemsAndAnEmptyMessage(): void {
		$call = new Call();
		$call->setDirection('in');
		$call->setOutcome('missed');
		$call->setRemote('202');
		$call->setRemoteName('Jonas Weber');
		$call->setStartedAt(1794422647);
		$widget = $this->widget([$call]);
		$this->assertInstanceOf(IAPIWidgetV2::class, $widget);
		$items = $widget->getItemsV2('mara');
		$this->assertSame('No calls yet', $items->getEmptyContentMessage());
		$this->assertCount(1, $items->getItems());
		$item = $items->getItems()[0];
		$this->assertSame('Jonas Weber', $item->getTitle());
		$this->assertSame('↙ Missed · 11/11/26, 7:44 PM', $item->getSubtitle());
		$this->assertStringContainsString('dial=202', $item->getLink());
	}
}
