<?php

declare(strict_types=1);

namespace OCA\BizzKickR\Tests;

use OCA\BizzKickR\Service\BizzKickRError;

final class GcnaTest extends AppTestCase {
	private function signup(): array {
		return ['email' => 'admin@shop.test', 'adminName' => 'Ada Admin', 'companyName' => 'Shop GmbH', 'domain' => 'shop.test',
			'acceptTerms' => true, 'termsVersion' => 't1', 'privacyVersion' => 'p1'];
	}

	public function testTheFreeSignUpLinksThisNextcloudAfterTheEmailedCode(): void {
		$gcna = $this->service();
		$this->assertNull($gcna->linkedThrough());
		$this->gcna->answers['/v1/signups'] = [202, ['signupId' => str_repeat('s', 40), 'expiresAt' => '2026-11-15T10:00:00Z']];
		$gcna->startSignup($this->signup());
		$sent = $this->gcna->last('/v1/signups');
		$this->assertSame('nextcloud', $sent['app']);
		$this->assertSame($gcna->installationId(), $sent['installationId']);
		$this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $sent['installationProof']);
		$this->assertTrue($gcna->signupPending());

		$path = '/v1/signups/' . str_repeat('s', 40) . '/confirm';
		$this->gcna->answers[$path] = [200, ['state' => 'linked', 'installation' => ['token' => str_repeat('T', 48), 'accountId' => 'acc-1',
			'installationId' => $gcna->installationId(), 'scopes' => ['phone-system:manage', 'bizzkickr:use'], 'expiresAt' => gmdate('c', time() + 86400)]]];
		$gcna->confirmSignup('123456');
		$this->assertSame('123456', $this->gcna->last($path)['code']);
		$this->assertSame('bizzkickr', $gcna->linkedThrough());
		$this->assertFalse($gcna->signupPending());
		$this->assertStringNotContainsString(str_repeat('T', 48), json_encode(array_diff_key($this->app, ['bizzkickr/installation_token' => 1])));
		$this->assertStringStartsWith('enc:', $this->app['bizzkickr/installation_token'], 'the token is stored encrypted');
	}

	public function testACredentialForAnotherInstallationOrWithoutBizzKickRIsNotStored(): void {
		$gcna = $this->service();
		$this->app['bizzkickr/signup_id'] = str_repeat('s', 40);
		$path = '/v1/signups/' . str_repeat('s', 40) . '/confirm';
		foreach ([['installationId' => 'someone-else', 'scopes' => ['bizzkickr:use']], ['scopes' => ['phone-system:manage']], ['scopes' => ['bizzkickr:use', 'admin']]] as $bad) {
			$this->gcna->answers[$path] = [200, ['state' => 'linked', 'installation' => $bad + ['token' => str_repeat('T', 48),
				'installationId' => $gcna->installationId(), 'expiresAt' => gmdate('c', time() + 86400)]]];
			try {
				$gcna->confirmSignup('123456');
				$this->fail('stored');
			} catch (BizzKickRError $error) {
				$this->assertStringContainsString('invalid response', $error->getMessage());
			}
			$this->assertNull($gcna->linkedThrough());
		}
	}

	public function testPhoneSystemsLinkIsUsedWhenThisNextcloudAlreadyHasOne(): void {
		$this->app['phone_system/installation_id'] = 'phone-1';
		$this->app['phone_system/installation_token'] = 'enc:' . json_encode(['installation' => 'phone-1', 'token' => str_repeat('p', 48), 'expires' => time() + 3600]);
		$gcna = $this->service();
		$this->assertSame('phone_system', $gcna->linkedThrough());
		$gcna->post('/v1/bizzkickr/person', ['person' => 'eva@shop.test'], [200], true);
		$this->assertSame('Bearer ' . str_repeat('p', 48), $this->gcna->calls[0][2]['Authorization']);
		$this->expectException(BizzKickRError::class);
		$gcna->startSignup(['email' => 'a@b.test']);
	}

	public function testAnExpiredOrForeignPhoneSystemLinkIsNotUsed(): void {
		$this->app['phone_system/installation_id'] = 'phone-1';
		$this->app['phone_system/installation_token'] = 'enc:' . json_encode(['installation' => 'phone-1', 'token' => str_repeat('p', 48), 'expires' => time() - 1]);
		$this->assertNull($this->service()->linkedThrough());
		$this->app['phone_system/installation_token'] = 'enc:' . json_encode(['installation' => 'phone-2', 'token' => str_repeat('p', 48), 'expires' => time() + 3600]);
		$this->assertNull($this->service()->linkedThrough());
	}

	public function testAnswersReachTheUserInWordsAndARevokedLinkIsNotMistaken(): void {
		$this->linked();
		$gcna = $this->service();
		$this->gcna->answers['/v1/bizzkickr/cards'] = [409, ['error' => ['code' => 'PERSON_UNCONFIRMED']]];
		$this->assertError(fn () => $gcna->post('/v1/bizzkickr/cards', [], [200], true), 'confirm');
		$this->gcna->answers['/v1/bizzkickr/cards'] = [403, ['error' => ['code' => 'TERMS_ACCEPTANCE_REQUIRED']]];
		$this->assertError(fn () => $gcna->post('/v1/bizzkickr/cards', [], [200], true), 'accept them');
		$this->gcna->answers['/v1/bizzkickr/cards'] = [401, ['error' => ['code' => 'UNAUTHORIZED']]];
		$this->assertError(fn () => $gcna->post('/v1/bizzkickr/cards', [], [200], true), 'connect it again');
		$this->gcna->answers['/v1/bizzkickr/cards'] = [500, ['error' => ['code' => 'SOMETHING']]];
		$this->assertError(fn () => $gcna->post('/v1/bizzkickr/cards', [], [200], true), 'could not complete');
		$this->gcna->down = true;
		$this->assertError(fn () => $gcna->post('/v1/bizzkickr/cards', [], [200], true), 'could not be reached');
	}

	public function testWithoutALinkNothingIsSent(): void {
		$this->assertError(fn () => $this->service()->post('/v1/bizzkickr/cards', [], [200], true), 'connect this Nextcloud');
		$this->assertSame([], $this->gcna->calls);
	}

	private function assertError(callable $call, string $words): void {
		try {
			$call();
			$this->fail('no error');
		} catch (BizzKickRError $error) {
			$this->assertStringContainsString($words, $error->getMessage());
		}
	}

	public function testItsOwnLinkIsRenewedWithFewerThanSevenDaysLeft(): void {
		$this->linked();
		$gcna = $this->service();
		$this->gcna->answers['/v1/installation/renew'] = [200, ['token' => str_repeat('N', 48), 'accountId' => 'acc-1', 'installationId' => 'inst-1',
			'scopes' => ['phone-system:manage', 'bizzkickr:use'], 'expiresAt' => gmdate('c', time() + 30 * 86400)]];
		$this->assertTrue($gcna->renewIfDue());
		[$path, $body, $headers] = $this->gcna->calls[0];
		$this->assertSame('/v1/installation/renew', $path);
		$this->assertSame('Bearer ' . str_repeat('t', 48), $headers['Authorization']);
		$this->assertSame('inst-1', $body['installationId']);
		$this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $body['installationProof']);
		$gcna->post('/v1/bizzkickr/person', [], [200], true);
		$this->assertSame('Bearer ' . str_repeat('N', 48), $this->gcna->calls[1][2]['Authorization']);
	}

	public function testALinkWithTimeLeftOrPhoneSystemsLinkIsNotRenewedHere(): void {
		$this->app['bizzkickr/installation_id'] = 'inst-1';
		$this->app['bizzkickr/installation_token'] = 'enc:' . json_encode(['installation' => 'inst-1', 'token' => str_repeat('t', 48), 'expires' => time() + 8 * 86400]);
		$this->assertFalse($this->service()->renewIfDue());
		unset($this->app['bizzkickr/installation_token']);
		$this->app['phone_system/installation_id'] = 'phone-1';
		$this->app['phone_system/installation_token'] = 'enc:' . json_encode(['installation' => 'phone-1', 'token' => str_repeat('p', 48), 'expires' => time() + 3600]);
		$this->assertFalse($this->service()->renewIfDue(), 'Phone System renews its own link');
		$this->assertSame([], $this->gcna->calls);
	}

	public function testARefusedOrUnreachableRenewalKeepsTheStoredLink(): void {
		$this->linked();
		$this->gcna->answers['/v1/installation/renew'] = [401, ['error' => ['code' => 'UNAUTHORIZED']]];
		$this->assertFalse($this->service()->renewIfDue());
		$this->gcna->down = true;
		$this->assertFalse($this->service()->renewIfDue());
		$this->assertSame('bizzkickr', $this->service()->linkedThrough());
		$this->assertStringContainsString(str_repeat('t', 48), $this->app['bizzkickr/installation_token']);
	}
}
