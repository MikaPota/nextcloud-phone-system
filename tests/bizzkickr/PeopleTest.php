<?php

declare(strict_types=1);

namespace OCA\BizzKickR\Tests;

use OCA\BizzKickR\Service\BizzKickRError;
use OCA\BizzKickR\Service\People;
use OCP\Accounts\IAccountManager;

final class PeopleTest extends AppTestCase {
	private const SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><path d="M0 0h1v1H0z"/></svg>';
	private const P = '/v1/bizzkickr/';

	private static function card(array $over = []): array {
		return $over + ['id' => 'c-eva', 'name' => 'Eva Shop', 'organization' => 'Shop GmbH', 'role' => 'Owner', 'phone' => '+49 30 123456',
			'email' => 'eva@shop.test', 'web' => 'https://shop.test', 'isDefault' => true,
			'link' => 'https://bizzkickr.comwize.app/k/AAAAAAAAAAAAAAAAAAAAAA', 'qrSvg' => self::SVG];
	}

	private static function row(array $over = []): array {
		return $over + ['id' => str_repeat('b', 64), 'name' => 'Bob Buyer', 'organization' => 'Buyer Ltd', 'email' => 'bob@buyer.test',
			'role' => 'CTO', 'phone' => null, 'web' => null, 'connectedAt' => '2026-11-15T09:05:00Z', 'updatedAt' => '2026-11-15T09:05:00Z'];
	}

	private function ready(): People {
		$this->linked();
		$people = $this->people();
		$this->gcna->answers[self::P . 'people'] = [202, ['state' => 'unconfirmed']];
		$people->register($this->eva());
		$this->gcna->answers[self::P . 'person'] = [200, ['state' => 'confirmed']];
		$this->gcna->answers[self::P . 'cards'] = [200, ['cards' => [self::card()]]];
		$people->refresh($this->eva());
		return $people;
	}

	public function testRegisterConfirmAndCreateTheFirstCardFromTheProfile(): void {
		$this->linked();
		$this->app['theming/name'] = 'Shop GmbH';
		$this->profile = [IAccountManager::PROPERTY_ROLE => 'Owner', IAccountManager::PROPERTY_WEBSITE => 'https://www.shop.test'];
		$people = $this->people();
		$this->assertSame('none', $people->open($this->eva())['state']);
		$this->assertSame([], $this->gcna->calls, 'nothing is sent before the user registers');

		$this->gcna->answers[self::P . 'people'] = [202, ['state' => 'unconfirmed']];
		$this->assertSame('unconfirmed', $people->register($this->eva())['state']);
		$sent = $this->gcna->last(self::P . 'people');
		$this->assertSame(['person' => 'eva@shop.test', 'lang' => 'de', 'app' => 'nextcloud', 'companyName' => 'Shop GmbH'], $sent);
		$this->assertSame('Bearer ' . str_repeat('t', 48), $this->gcna->calls[0][2]['Authorization']);

		$this->gcna->answers[self::P . 'person'] = [200, ['state' => 'confirmed']];
		$this->gcna->answers[self::P . 'cards'] = [200, ['cards' => []]];
		$this->gcna->answers[self::P . 'connections'] = [200, ['connections' => []]];
		$this->assertSame('no_card', $people->open($this->eva())['state']);

		$this->gcna->answers[self::P . 'cards/create'] = [201, ['card' => self::card()]];
		$view = $people->createCard($this->eva());
		$this->assertSame(['name' => 'Eva Shop', 'organization' => 'Shop GmbH', 'role' => 'Owner', 'phone' => '', 'email' => 'Eva@Shop.test', 'web' => 'www.shop.test'],
			$this->gcna->last(self::P . 'cards/create')['card']);
		$this->assertSame('ready', $view['state']);
		$this->assertSame('https://bizzkickr.comwize.app/k/AAAAAAAAAAAAAAAAAAAAAA', $view['card']['link']);
		$this->assertSame(self::SVG, $people->qr($this->eva()));
	}

	public function testTheCardIsSavedAndSentToAContact(): void {
		$people = $this->ready();
		$this->gcna->answers[self::P . 'cards/c-eva/update'] = [200, ['card' => self::card(['role' => 'Managing Director'])]];
		$view = $people->saveCard($this->eva(), ['name' => 'Eva Shop', 'role' => 'Managing Director']);
		$this->assertSame('Managing Director', $this->gcna->last(self::P . 'cards/c-eva/update')['card']['role']);
		$this->assertSame('Managing Director', $view['card']['role']);

		$this->gcna->answers[self::P . 'cards/c-eva/send'] = [202, ['sent' => true]];
		$people->send($this->eva(), ' Bob@Buyer.test ');
		$this->assertSame(['person' => 'eva@shop.test', 'to' => 'bob@buyer.test', 'lang' => 'de'], $this->gcna->last(self::P . 'cards/c-eva/send'));
		$this->expectException(BizzKickRError::class);
		$people->send($this->eva(), 'not an address');
	}

	public function testAMalformedCardOrUnsafeQrCodeIsNotShown(): void {
		$this->linked();
		$people = $this->people();
		$this->gcna->answers[self::P . 'people'] = [200, ['state' => 'confirmed']];
		$this->gcna->answers[self::P . 'person'] = [200, ['state' => 'confirmed']];
		$bad = [
			self::card(['link' => 'javascript:alert(1)']), self::card(['link' => 'http://plain.test/k/x']), self::card(['name' => '<b>Eva</b>']),
			self::card(['qrSvg' => '<svg><script>alert(1)</script></svg>']), self::card(['qrSvg' => '<svg onload="alert(1)"><path d=""/></svg>']),
			self::card(['qrSvg' => '<svg><image href="https://evil.test/x.png"/></svg>']), self::card(['qrSvg' => '<svg><path style="fill:url(https://x)"/></svg>']),
		];
		foreach ($bad as $card) {
			$this->gcna->answers[self::P . 'cards'] = [200, ['cards' => [$card]]];
			try {
				$people->register($this->eva());
				$this->fail('shown: ' . json_encode($card));
			} catch (BizzKickRError $error) {
				$this->assertStringContainsString('unexpectedly', $error->getMessage());
			}
		}
		$this->assertNull($people->qr($this->eva()));
	}

	public function testAConfirmedRequestBecomesAContactAndFollowsLaterChanges(): void {
		$people = $this->ready();
		$request = str_repeat('a', 64);
		$this->gcna->answers[self::P . 'requests'] = [200, ['requests' => [['id' => $request, 'name' => 'Bob Buyer', 'organization' => 'Buyer Ltd',
			'email' => 'bob@buyer.test', 'event' => 'Expo', 'requestedAt' => '2026-11-15T09:00:00Z'], ['id' => 'not-a-hash', 'name' => 'Mallory']]]];
		$this->assertSame(['Bob Buyer'], array_column($people->requests($this->eva()), 'name'), 'a malformed request is not shown');

		$this->gcna->answers[self::P . "requests/$request/accept"] = [200, ['connection' => []]];
		$this->gcna->answers[self::P . 'connections'] = [200, ['connections' => [self::row()]]];
		$this->gcna->answers[self::P . 'requests'] = [200, ['requests' => []]];
		$this->assertSame([], $people->answer($this->eva(), $request, 'accept'));
		$this->assertArrayNotHasKey('since', $this->gcna->last(self::P . 'connections'), 'the first fetch asks for everything');
		$this->assertCount(1, $this->book->cards);
		$uri = array_key_first($this->book->cards);
		$this->assertSame(['EMAIL' => ['bob@buyer.test'], 'FN' => 'Bob Buyer', 'ORG' => 'Buyer Ltd', 'TITLE' => 'CTO'], $this->book->cards[$uri]);
		$this->assertSame([], $this->shared->cards, 'a shared address book is never written');

		// Bob changes his card; a field changed here since stays as it is here.
		$this->book->cards[$uri]['TITLE'] = 'Chief Technology Officer';
		$this->gcna->answers[self::P . 'connections'] = [200, ['connections' => [self::row(['role' => 'CEO', 'organization' => 'Buyer Group', 'phone' => '+44 20 1234'])]]];
		$people->sync($this->eva());
		$this->assertArrayHasKey('since', $this->gcna->last(self::P . 'connections'), 'later fetches ask only for changes');
		$this->assertSame('Buyer Group', $this->book->cards[$uri]['ORG']);
		$this->assertSame(['+44 20 1234'], $this->book->cards[$uri]['TEL']);
		$this->assertSame('Chief Technology Officer', $this->book->cards[$uri]['TITLE']);
		$this->assertCount(1, $this->book->cards);
	}

	public function testAnExistingContactOnlyGetsItsEmptyFieldsFilledAndKeepsItsOwnValues(): void {
		$people = $this->ready();
		$this->book->createOrUpdate(['FN' => 'Robert Buyer', 'EMAIL' => ['Bob@Buyer.test'], 'TITLE' => 'Buyer']);
		$this->gcna->answers[self::P . 'connections'] = [200, ['connections' => [self::row()]]];
		$people->sync($this->eva());
		$this->assertCount(1, $this->book->cards);
		$card = reset($this->book->cards);
		$this->assertSame(['Robert Buyer', 'Buyer', 'Buyer Ltd'], [$card['FN'], $card['TITLE'], $card['ORG']]);

		$this->gcna->answers[self::P . 'connections'] = [200, ['connections' => [self::row(['name' => 'Bobby Buyer', 'role' => 'CEO'])]]];
		$people->sync($this->eva());
		$card = reset($this->book->cards);
		$this->assertSame(['Robert Buyer', 'Buyer'], [$card['FN'], $card['TITLE']], 'what was kept here before stays');
	}

	public function testAContactTheUserDeletedIsNotBroughtBack(): void {
		$people = $this->ready();
		$this->gcna->answers[self::P . 'connections'] = [200, ['connections' => [self::row()]]];
		$people->sync($this->eva());
		$this->book->cards = [];
		$this->gcna->answers[self::P . 'connections'] = [200, ['connections' => [self::row(['role' => 'CEO'])]]];
		$people->sync($this->eva());
		$this->assertSame([], $this->book->cards);
	}

	public function testAMalformedConnectionIsSkippedAndTheRestApplied(): void {
		$people = $this->ready();
		$this->gcna->answers[self::P . 'connections'] = [200, ['connections' => [
			self::row(['id' => 'x']), self::row(['id' => str_repeat('c', 64), 'email' => 'no address']), self::row(['id' => str_repeat('d', 64), 'web' => 'javascript:x']),
			self::row(['id' => str_repeat('e', 64), 'name' => '<script>']), self::row(),
		]]];
		$people->sync($this->eva());
		$this->assertCount(1, $this->book->cards);
	}

	public function testWithoutACardNothingIsSentAndOpeningTheAppSaysWhatIsWrong(): void {
		$this->linked();
		$people = $this->people();
		try {
			$people->send($this->eva(), 'bob@buyer.test');
			$this->fail('sent');
		} catch (BizzKickRError $error) {
			$this->assertStringContainsString('Register', $error->getMessage());
		}
		$this->assertSame([], $this->gcna->calls);

		$people = $this->ready();
		$this->gcna->answers[self::P . 'person'] = [409, ['error' => ['code' => 'PERSON_UNCONFIRMED']]];
		$view = $people->open($this->eva());
		$this->assertSame('ready', $view['state']);
		$this->assertStringContainsString('confirm', $view['notice']);
	}

	public function testEachUserIsTheirOwnPerson(): void {
		$people = $this->ready();
		$max = $this->createMock(\OCP\IUser::class);
		$max->method('getUID')->willReturn('max');
		$max->method('getEMailAddress')->willReturn('max@shop.test');
		$this->assertSame('none', $people->open($max)['state']);
		$this->assertNull($people->qr($max));
		$this->assertFalse($people->hasCard($max));
		$this->assertTrue($people->hasCard($this->eva()));
	}
}
