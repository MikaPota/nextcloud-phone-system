<?php

declare(strict_types=1);

namespace OCA\PhoneSystem\Service;

use OCA\PhoneSystem\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Security\ICrypto;

/**
 * The phone system connection and which Nextcloud user owns which extension.
 * The extension password is kept only as the digest the PBX login needs, encrypted.
 */
class Mappings {
	public function __construct(
		private IAppConfig $config,
		private ICrypto $crypto,
		private IClientService $http,
		private IL10N $l10n,
	) {
	}

	public function tenant(): string {
		return $this->config->getValueString(Application::APP_ID, 'tenant');
	}

	public function setTenant(string $tenant): void {
		$tenant = strtolower(trim($tenant));
		if (!preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $tenant)) {
			throw new \InvalidArgumentException($this->l10n->t('Enter the phone system address, for example company.pbx.example.com.'));
		}
		$this->config->setValueString(Application::APP_ID, 'tenant', $tenant);
	}

	/** Browser origin: tenants on GCNA's PBX go through GCNA's relay (REQ-001 17.78). */
	public function origin(string $tenant): string {
		return str_ends_with($tenant, '.pbx.gcn-alliance.com') ? "https://gcn-alliance.com/phone/$tenant/" : "https://$tenant";
	}

	/** @return array<string, array{extension: string, digest: string}> keyed by user id */
	private function all(): array {
		$value = json_decode($this->config->getValueString(Application::APP_ID, 'mappings', '{}'), true);
		return is_array($value) ? $value : [];
	}

	/** @return array<string, string> user id => extension */
	public function extensions(): array {
		return array_map(fn (array $m) => $m['extension'], $this->all());
	}

	public function extensionOf(string $uid): ?string {
		return $this->all()[$uid]['extension'] ?? null;
	}

	public function digestOf(string $uid): ?string {
		$stored = $this->all()[$uid]['digest'] ?? null;
		return $stored === null ? null : $this->crypto->decrypt($stored);
	}

	/** Checks the login against the PBX before it is stored, so a typo fails here, not at the first call. */
	public function assign(string $uid, string $extension, string $password): void {
		if (!preg_match('/^[0-9]{2,6}$/', $extension) || $password === '') {
			throw new \InvalidArgumentException($this->l10n->t('Enter the extension number and its web password.'));
		}
		foreach ($this->all() as $other => $mapping) {
			if ($other !== $uid && $mapping['extension'] === $extension) {
				throw new \InvalidArgumentException($this->l10n->t('Extension %s is already assigned to another user.', [$extension]));
			}
		}
		$digest = md5($password);
		$this->verify($extension, $digest);
		$all = $this->all();
		$all[$uid] = ['extension' => $extension, 'digest' => $this->crypto->encrypt($digest)];
		$this->save($all);
	}

	/** A login GCNA created with the extension; GCNA is the source, so it is stored without a second check. */
	public function store(string $uid, string $extension, string $password): void {
		$all = $this->all();
		$all[$uid] = ['extension' => $extension, 'digest' => $this->crypto->encrypt(md5($password))];
		$this->save($all);
	}

	public function unassign(string $uid): void {
		$all = $this->all();
		unset($all[$uid]);
		$this->save($all);
	}

	private function save(array $all): void {
		$this->config->setValueString(Application::APP_ID, 'mappings', json_encode($all), false, true);
	}

	/** @return array{id: string, challenge: string} */
	public function challenge(string $extension): array {
		$tenant = $this->tenant();
		if ($tenant === '') {
			throw new \InvalidArgumentException($this->l10n->t('Save the phone system address first.'));
		}
		$response = $this->http->newClient()->post("https://$tenant/rest/system/login", [
			'json' => ['method' => 'get-password', 'username' => $extension],
			'timeout' => 6,
			'allow_redirects' => false,
		]);
		$challenge = json_decode((string)$response->getBody(), true);
		if (!is_array($challenge) || !is_string($challenge['id'] ?? null) || !is_string($challenge['challenge'] ?? null)
			|| !preg_match('/^[0-9a-zA-Z_-]{8,128}$/', $challenge['id']) || !preg_match('/^[0-9a-zA-Z_-]{16,128}$/', $challenge['challenge'])) {
			throw new \RuntimeException('The phone system sent an invalid sign-in challenge.');
		}
		return $challenge;
	}

	public function proof(string $extension, string $digest, string $challenge): string {
		return hash('sha256', "$extension:$digest:$challenge");
	}

	private function verify(string $extension, string $digest): void {
		$challenge = $this->challenge($extension);
		$response = $this->http->newClient()->post('https://' . $this->tenant() . '/rest/system/login', [
			'json' => ['method' => 'login-password', 'username' => $extension, 'id' => $challenge['id'],
				'hash' => $this->proof($extension, $digest, $challenge['challenge'])],
			'timeout' => 6,
			'allow_redirects' => false,
			'http_errors' => false,
		]);
		$result = json_decode((string)$response->getBody(), true);
		if (($result['success'] ?? false) !== true) {
			throw new \InvalidArgumentException($this->l10n->t('The phone system rejected the password for extension %s.', [$extension]));
		}
	}
}
