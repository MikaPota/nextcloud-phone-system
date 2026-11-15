<?php

declare(strict_types=1);

namespace OCA\BizzKickR\Tests;

use OCA\BizzKickR\Service\Contacts;
use OCA\BizzKickR\Service\Gcna;
use OCA\BizzKickR\Service\People;
use OCP\Accounts\IAccount;
use OCP\Accounts\IAccountManager;
use OCP\Accounts\IAccountProperty;
use OCP\Config\IUserConfig;
use OCP\Contacts\IManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IUser;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** The app's services wired as Nextcloud would, with app and user settings kept in memory. */
abstract class AppTestCase extends TestCase {
	protected FakeGcna $gcna;
	/** @var array<string, string> "app/key" => value */
	protected array $app = [];
	/** @var array<string, string> "uid/app/key" => value */
	protected array $user = [];
	protected array $profile = [];
	protected FakeAddressBook $book;
	protected FakeAddressBook $shared;

	protected function setUp(): void {
		$this->gcna = new FakeGcna();
		$this->book = new FakeAddressBook('1', 'contacts');
		$this->shared = new FakeAddressBook('2', 'team', shared: true);
	}

	protected function appConfig(): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn ($app, $key, $default = '') => $this->app["$app/$key"] ?? $default);
		$config->method('setValueString')->willReturnCallback(function ($app, $key, $value) {
			$this->app["$app/$key"] = $value;
			return true;
		});
		$config->method('deleteKey')->willReturnCallback(function ($app, $key) {
			unset($this->app["$app/$key"]);
		});
		return $config;
	}

	protected function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn ($text, $parameters = []) => vsprintf($text, (array)$parameters));
		$l10n->method('getLanguageCode')->willReturn('de');
		return $l10n;
	}

	protected function crypto(): ICrypto {
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(fn ($plain) => 'enc:' . $plain);
		$crypto->method('decrypt')->willReturnCallback(fn ($cipher) => str_starts_with($cipher, 'enc:') ? substr($cipher, 4) : throw new \Exception('bad'));
		return $crypto;
	}

	protected function service(): Gcna {
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(fn ($uri, $options) => $this->gcna->post($uri, $options));
		$http = $this->createMock(IClientService::class);
		$http->method('newClient')->willReturn($client);
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn(str_repeat('k', 48));
		return new Gcna($this->appConfig(), $this->crypto(), $random, $http, $this->l10n(), new NullLogger());
	}

	protected function people(): People {
		$userConfig = $this->createMock(IUserConfig::class);
		$userConfig->method('getValueString')->willReturnCallback(fn ($uid, $app, $key, $default = '') => $this->user["$uid/$app/$key"] ?? $default);
		$userConfig->method('setValueString')->willReturnCallback(function ($uid, $app, $key, $value) {
			$this->user["$uid/$app/$key"] = $value;
			return true;
		});
		$userConfig->method('deleteUserConfig')->willReturnCallback(function ($uid, $app, $key) {
			unset($this->user["$uid/$app/$key"]);
		});
		$manager = $this->createMock(IManager::class);
		$manager->method('getUserAddressBooks')->willReturnCallback(fn () => [$this->book, $this->shared]);
		$accounts = $this->createMock(IAccountManager::class);
		$accounts->method('getAccount')->willReturnCallback(function () {
			$account = $this->createMock(IAccount::class);
			$account->method('getProperty')->willReturnCallback(function ($name) {
				$property = $this->createMock(IAccountProperty::class);
				$property->method('getValue')->willReturn($this->profile[$name] ?? '');
				return $property;
			});
			return $account;
		});
		return new People($this->service(), new Contacts($manager), $userConfig, $this->appConfig(), $accounts, $this->l10n(), new NullLogger());
	}

	protected function linked(): void {
		$this->app['bizzkickr/installation_id'] = 'inst-1';
		$this->app['bizzkickr/installation_token'] = 'enc:' . json_encode(['installation' => 'inst-1', 'token' => str_repeat('t', 48), 'expires' => time() + 3600]);
	}

	protected function eva(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('eva');
		$user->method('getDisplayName')->willReturn('Eva Shop');
		$user->method('getEMailAddress')->willReturn('Eva@Shop.test');
		return $user;
	}
}
