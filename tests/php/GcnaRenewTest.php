<?php

declare(strict_types=1);

namespace OCA\PhoneSystem\Tests;

use OCA\BizzKickR\Tests\FakeGcna;
use OCA\PhoneSystem\Service\Gcna;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** GCNA's link lasts 30 days; with fewer than 7 left, Phone System renews it on its own. */
final class GcnaRenewTest extends TestCase {
	private FakeGcna $gcna;
	private array $app = [];

	protected function setUp(): void {
		$this->gcna = new FakeGcna();
		$this->app['phone_system/installation_id'] = 'inst-1';
	}

	private function linkedFor(int $seconds): void {
		$this->app['phone_system/installation_token'] = 'enc:' . json_encode(['installation' => 'inst-1', 'account' => 'acc-1', 'token' => str_repeat('o', 48), 'expires' => time() + $seconds]);
	}

	private function service(): Gcna {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn ($app, $key, $default = '') => $this->app["$app/$key"] ?? $default);
		$config->method('setValueString')->willReturnCallback(function ($app, $key, $value) {
			$this->app["$app/$key"] = $value;
			return true;
		});
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(fn ($plain) => 'enc:' . $plain);
		$crypto->method('decrypt')->willReturnCallback(fn ($cipher) => substr($cipher, 4));
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn(str_repeat('k', 48));
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(fn ($uri, $options) => $this->gcna->post($uri, $options));
		$http = $this->createMock(IClientService::class);
		$http->method('newClient')->willReturn($client);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn ($text) => $text);
		return new Gcna($config, $crypto, $random, $http, $l10n, new NullLogger());
	}

	public function testALinkWithFewerThanSevenDaysLeftIsRenewed(): void {
		$this->linkedFor(6 * 86400);
		$this->gcna->answers['/v1/installation/renew'] = [200, ['token' => str_repeat('n', 48), 'accountId' => 'acc-1', 'installationId' => 'inst-1',
			'scopes' => ['phone-system:manage'], 'expiresAt' => gmdate('c', time() + 30 * 86400)]];
		$this->assertTrue($this->service()->renewIfDue());
		[$path, $body, $headers] = $this->gcna->calls[0];
		$this->assertSame('/v1/installation/renew', $path);
		$this->assertSame('Bearer ' . str_repeat('o', 48), $headers['Authorization']);
		$this->assertSame('inst-1', $body['installationId']);
		$this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $body['installationProof']);
		$stored = json_decode(substr($this->app['phone_system/installation_token'], 4), true);
		$this->assertSame(str_repeat('n', 48), $stored['token']);
		$this->assertGreaterThan(time() + 29 * 86400, $stored['expires']);
	}

	public function testALinkWithTimeLeftOrAlreadyExpiredIsLeftAlone(): void {
		$this->linkedFor(8 * 86400);
		$this->assertFalse($this->service()->renewIfDue());
		$this->linkedFor(-1);
		$this->assertFalse($this->service()->renewIfDue());
		$this->assertSame([], $this->gcna->calls);
	}

	public function testARefusedUnreachableOrInvalidRenewalKeepsTheStoredLink(): void {
		$this->linkedFor(3600);
		foreach ([[401, ['error' => ['code' => 'UNAUTHORIZED']]], [200, ['token' => 'short']]] as $answer) {
			$this->gcna->answers['/v1/installation/renew'] = $answer;
			$this->assertFalse($this->service()->renewIfDue());
		}
		$this->gcna->down = true;
		$this->assertFalse($this->service()->renewIfDue());
		$this->assertTrue($this->service()->linked());
		$this->assertStringContainsString(str_repeat('o', 48), $this->app['phone_system/installation_token']);
	}
}
