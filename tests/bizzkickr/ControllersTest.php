<?php

declare(strict_types=1);

namespace OCA\BizzKickR\Tests;

use OCA\BizzKickR\ContactsMenu\SendCardProvider;
use OCA\BizzKickR\Controller\PageController;
use OCA\BizzKickR\Service\BizzKickRError;
use OCA\BizzKickR\Service\People;
use OCP\Contacts\ContactsMenu\IActionFactory;
use OCP\Contacts\ContactsMenu\IEntry;
use OCP\Contacts\ContactsMenu\ILinkAction;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

final class ControllersTest extends AppTestCase {
	private function session(): IUserSession {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->eva());
		return $session;
	}

	public function testEveryCardFieldTheUserEditedReachesBizzKickR(): void {
		$people = $this->createMock(People::class);
		$people->expects($this->once())->method('saveCard')
			->with($this->anything(), ['name' => 'Eva', 'organization' => 'Shop', 'role' => 'CEO', 'phone' => '+49', 'email' => 'e@shop.test', 'web' => 'shop.test'])
			->willReturn(['state' => 'ready']);
		$response = (new PageController($this->createMock(IRequest::class), $people, $this->session()))->saveCard('Eva', 'Shop', 'CEO', '+49', 'e@shop.test', 'shop.test');
		$this->assertSame(200, $response->getStatus());
	}

	public function testAFailureReachesThePageAsItsMessage(): void {
		$people = $this->createMock(People::class);
		$people->method('send')->willThrowException(new BizzKickRError('Create your BIZZKICKR card first.'));
		$response = (new PageController($this->createMock(IRequest::class), $people, $this->session()))->send('bob@buyer.test');
		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'Create your BIZZKICKR card first.'], $response->getData());
	}

	private function menu(bool $hasCard, array $emails): array {
		$people = $this->createMock(People::class);
		$people->method('hasCard')->willReturn($hasCard);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(fn ($route, $parameters) => "https://cloud.test/apps/bizzkickr/?send=" . $parameters['send']);
		$actions = $this->createMock(IActionFactory::class);
		$actions->method('newLinkAction')->willReturnCallback(fn ($icon, $name, $href) => $this->createConfiguredMock(ILinkAction::class, ['getHref' => $href, 'getName' => $name]));
		$entry = $this->createMock(IEntry::class);
		$entry->method('getEMailAddresses')->willReturn($emails);
		$added = [];
		$entry->method('addAction')->willReturnCallback(function ($action) use (&$added) {
			$added[] = $action->getHref();
		});
		(new SendCardProvider($actions, $urls, $this->session(), $people, $this->l10n()))->process($entry);
		return $added;
	}

	public function testTheContactsMenuOffersTheCardOnceTheUserHasOne(): void {
		$this->assertSame(['https://cloud.test/apps/bizzkickr/?send=bob@buyer.test'], $this->menu(true, ['Bob@Buyer.test', 'bob@buyer.test']));
		$this->assertSame([], $this->menu(false, ['bob@buyer.test']), 'no card, no action');
		$this->assertSame([], $this->menu(true, ['eva@shop.test']), 'not to oneself');
		$this->assertSame([], $this->menu(true, []));
	}
}
