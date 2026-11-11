<?php

declare(strict_types=1);

namespace OCA\PhoneSystem\Service;

use OCA\PhoneSystem\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * GCNA's purchase and provisioning API, the same contract the Odoo app uses
 * (vodia-odoo-app models/gcna_account.py): this Nextcloud is one installation with
 * an id and an HMAC proof; after checkout it holds a bearer token for its account.
 * Proof key and token are stored encrypted and never reach the browser.
 */
class Gcna {
	private const PROOF_LABEL = 'gcna-installation-proof/v1:';

	public function __construct(
		private IAppConfig $config,
		private ICrypto $crypto,
		private ISecureRandom $random,
		private IClientService $http,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
	}

	/** Server-side only (occ), like the Odoo app's GCNA_SERVICE_ORIGIN: the Stripe sandbox for tests. */
	private function origin(): string {
		$origin = $this->config->getValueString(Application::APP_ID, 'service_origin', 'https://gcn-alliance.com');
		return rtrim($origin, '/');
	}

	public function installationId(): string {
		$id = $this->config->getValueString(Application::APP_ID, 'installation_id');
		if ($id === '') {
			$id = sprintf('%s-%s-4%s-%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(2)),
				substr(bin2hex(random_bytes(2)), 1), dechex(8 + random_int(0, 3)) . substr(bin2hex(random_bytes(2)), 1), bin2hex(random_bytes(6)));
			$this->config->setValueString(Application::APP_ID, 'installation_id', $id);
		}
		return $id;
	}

	private function proof(): string {
		$stored = $this->config->getValueString(Application::APP_ID, 'installation_key');
		if ($stored === '') {
			$key = $this->random->generate(48, ISecureRandom::CHAR_ALPHANUMERIC);
			$this->config->setValueString(Application::APP_ID, 'installation_key', $this->crypto->encrypt($key), false, true);
		} else {
			$key = $this->crypto->decrypt($stored);
		}
		$mac = hash_hmac('sha256', self::PROOF_LABEL . $this->installationId(), $key, true);
		return rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
	}

	private function identity(): array {
		return ['installationId' => $this->installationId(), 'installationProof' => $this->proof()];
	}

	public function linked(): bool {
		return $this->token() !== null;
	}

	private function token(): ?string {
		$stored = $this->config->getValueString(Application::APP_ID, 'installation_token');
		if ($stored === '') {
			return null;
		}
		$token = json_decode($this->crypto->decrypt($stored), true);
		if (!is_array($token) || ($token['installation'] ?? null) !== $this->installationId() || ($token['expires'] ?? 0) <= time()) {
			return null;
		}
		return $token['token'];
	}

	private function storeToken(array $payload): void {
		$token = $payload['token'] ?? null;
		$expires = strtotime((string)($payload['expiresAt'] ?? ''));
		if (($payload['installationId'] ?? null) !== $this->installationId() || ($payload['scopes'] ?? null) !== ['phone-system:manage']
			|| !is_string($token) || !preg_match('/^[A-Za-z0-9_.~-]{32,512}$/', $token) || !$expires || $expires <= time()) {
			throw new GcnaError($this->l10n->t('GCNA returned an invalid response. Contact your service provider.'));
		}
		$this->config->setValueString(Application::APP_ID, 'installation_token', $this->crypto->encrypt(json_encode([
			'installation' => $this->installationId(), 'account' => $payload['accountId'] ?? '', 'token' => $token, 'expires' => $expires,
		])), false, true);
	}

	/** GCNA answers a customer can act on; everything else stays a generic error. */
	private function conflict(?string $code): ?string {
		return match ($code) {
			'COMPANY_EXISTS' => $this->l10n->t('This company already has an account. Its administrator must connect this Nextcloud.'),
			'ALREADY_A_USER' => $this->l10n->t('This email address already belongs to an account. Use a different address or contact your service provider.'),
			'ALREADY_LINKED' => $this->l10n->t('This Nextcloud already belongs to an account.'),
			'CATALOG_CHANGED' => $this->l10n->t('Prices changed. Review the current plans.'),
			'LEGAL_CHANGED' => $this->l10n->t('The terms or privacy notice changed. Review them again.'),
			'LEGAL_NOT_CONFIGURED' => $this->l10n->t('Purchases open once the provider has published its terms.'),
			'INVALID_CODE' => $this->l10n->t('The verification code is not correct.'),
			'TOO_MANY_ATTEMPTS' => $this->l10n->t('Too many wrong codes. Start the purchase again.'),
			'CHECKOUT_EXPIRED' => $this->l10n->t('The purchase expired. Start again.'),
			'CAPACITY_EXCEEDED' => $this->l10n->t('Your plan does not include that many users.'),
			'SYSTEM_NOT_READY' => $this->l10n->t('The phone system is not ready yet.'),
			'SYSTEM_CANCELLED' => $this->l10n->t('This phone system is cancelled.'),
			'BILLING_UNAVAILABLE' => $this->l10n->t('Subscription management is not available for this phone system.'),
			'INVALID_RETURN_URL' => $this->l10n->t('This Nextcloud must use HTTPS to manage subscriptions.'),
			'RATE_LIMITED' => $this->l10n->t('Too many attempts. Retry later.'),
			default => null,
		};
	}

	private function post(string $path, array $body, int $expected, bool $authorized = false): array {
		$headers = ['Content-Type' => 'application/json'];
		if ($authorized) {
			$token = $this->token();
			if ($token === null) {
				throw new GcnaError($this->l10n->t('This Nextcloud is not linked to a GCNA account.'));
			}
			$headers['Authorization'] = "Bearer $token";
		}
		try {
			$response = $this->http->newClient()->post($this->origin() . $path, [
				'body' => json_encode((object)$body), 'headers' => $headers,
				'timeout' => 15, 'allow_redirects' => false, 'http_errors' => false,
			]);
		} catch (\Throwable $error) {
			$this->logger->warning('Phone System: GCNA request failed', ['exception' => $error]);
			throw new GcnaError($this->l10n->t('GCNA could not be reached. Retry later or contact your service provider.'));
		}
		$status = $response->getStatusCode();
		$payload = json_decode((string)$response->getBody(), true);
		if ($authorized && in_array($status, [401, 403], true)) {
			throw new GcnaError($this->l10n->t('GCNA no longer accepts this installation. Link it again.'));
		}
		if (in_array($status, [400, 409, 422, 429], true) && ($message = $this->conflict($payload['error']['code'] ?? null)) !== null) {
			throw new GcnaError($message);
		}
		if ($status !== $expected || !is_array($payload)) {
			$this->logger->warning('Phone System: GCNA answered HTTP {status} on {path}', ['status' => $status, 'path' => $path]);
			throw new GcnaError($this->l10n->t('GCNA could not complete the request. Contact your service provider.'));
		}
		return $payload;
	}

	public function catalog(array $country): array {
		return $this->post('/v1/catalog', $country, 200);
	}

	public function startCheckout(array $values): array {
		$payload = $this->post('/v1/checkouts', $this->identity() + $values, 202);
		$this->config->setValueString(Application::APP_ID, 'checkout_id', (string)($payload['checkoutId'] ?? ''));
		return ['state' => 'verification_pending', 'expiresAt' => $payload['expiresAt'] ?? null];
	}

	private function checkoutId(): string {
		$id = $this->config->getValueString(Application::APP_ID, 'checkout_id');
		if (!preg_match('/^[A-Za-z0-9_-]{32,128}$/', $id)) {
			throw new GcnaError($this->l10n->t('The purchase expired. Start again.'));
		}
		return $id;
	}

	public function confirmCheckout(string $code): array {
		return $this->payment($this->post('/v1/checkouts/' . $this->checkoutId() . '/confirm', $this->identity() + ['code' => $code], 200));
	}

	public function resendCode(): array {
		return $this->post('/v1/checkouts/' . $this->checkoutId() . '/resend', $this->identity(), 202);
	}

	public function checkoutStatus(): array {
		if ($this->config->getValueString(Application::APP_ID, 'checkout_id') === '') {
			return ['state' => 'none'];
		}
		try {
			$payload = $this->post('/v1/checkouts/' . $this->checkoutId() . '/status', $this->identity(), 200);
		} catch (GcnaError $error) {
			if ($error->getMessage() !== $this->l10n->t('The purchase expired. Start again.')) {
				throw $error;
			}
			$this->config->deleteKey(Application::APP_ID, 'checkout_id');
			return ['state' => 'none'];
		}
		return ($payload['state'] ?? '') === 'verification_pending' ? ['state' => 'verification_pending'] : $this->payment($payload);
	}

	/** A linked account buys another phone system straight to payment. */
	public function order(array $values): array {
		return $this->payment($this->post('/v1/phone-systems/orders', $values, 200, true));
	}

	/** The Stripe client secret is for the browser's embedded checkout; the installation token never is. */
	private function payment(array $payload): array {
		if (!in_array($payload['state'] ?? '', ['payment_required', 'paid'], true)
			|| !str_starts_with((string)($payload['clientSecret'] ?? ''), 'cs_') || !str_starts_with((string)($payload['publishableKey'] ?? ''), 'pk_')) {
			throw new GcnaError($this->l10n->t('GCNA returned an invalid response. Contact your service provider.'));
		}
		if (is_array($payload['installation'] ?? null) && isset($payload['installation']['token'])) {
			$this->storeToken($payload['installation']);
		}
		return ['state' => $payload['state'], 'clientSecret' => $payload['clientSecret'], 'publishableKey' => $payload['publishableKey']];
	}

	public function systems(): array {
		if (!$this->linked()) {
			return [];
		}
		$systems = [];
		foreach ($this->post('/v1/phone-systems', [], 200, true)['systems'] ?? [] as $system) {
			if (!preg_match('/^[A-Za-z0-9_-]{8,128}$/', (string)($system['id'] ?? '')) || !preg_match('/^[a-z0-9.-]{4,253}$/', (string)($system['hostname'] ?? ''))) {
				continue;
			}
			$stages = [];
			foreach (['paid', 'provisioned', 'users'] as $name) {
				if (isset($system['stages'][$name]['state'])) {
					$stages[$name] = ['state' => $system['stages'][$name]['state'], 'message' => $this->stageMessage($system['stages'][$name]['message'] ?? null)];
				}
			}
			$systems[] = ['id' => $system['id'], 'hostname' => $system['hostname'], 'name' => $system['name'] ?? $system['code'] ?? '',
				'capacity' => (int)($system['capacity'] ?? 0), 'stages' => $stages, 'cancelled' => $system['cancelled'] ?? null];
		}
		return $systems;
	}

	/** GCNA writes setup progress in English; the known messages are shown in the user's language. */
	private function stageMessage(?string $message): ?string {
		return match ($message) {
			'Your phone system is created and its secure address is being certified. This usually takes about a minute; you can leave this page and come back.'
				=> $this->l10n->t('Your phone system is created and its secure address is being certified. This usually takes about a minute; you can leave this page and come back.'),
			'Your phone system is created and is waiting for its license. You can leave this page and come back.'
				=> $this->l10n->t('Your phone system is created and is waiting for its license. You can leave this page and come back.'),
			'The phone system could not be created yet. It is retried automatically; contact your service provider if this persists.',
			'The phone system could not be created yet. It is retried automatically; contact support if this persists.'
				=> $this->l10n->t('The phone system could not be created yet. It is retried automatically; contact your service provider if this persists.'),
			default => $message,
		};
	}

	/** @param array<string, string> $users reference => name; returns reference => [extension, webPassword?] */
	public function syncUsers(string $systemId, array $users): array {
		$payload = $this->post("/v1/phone-systems/$systemId/users", ['users' => array_map(
			fn ($reference, $name) => ['reference' => $reference, 'name' => $name], array_keys($users), array_values($users),
		)], 200, true);
		$result = [];
		foreach ($payload['users'] ?? [] as $item) {
			if (isset($users[$item['reference'] ?? '']) && preg_match('/^[0-9]{2,6}$/', (string)($item['extension'] ?? ''))) {
				$result[$item['reference']] = ['extension' => $item['extension'], 'webPassword' => $item['webPassword'] ?? null];
			}
		}
		if (count($result) !== count($users) || !is_string($payload['tenant'] ?? null)) {
			throw new GcnaError($this->l10n->t('GCNA returned an invalid response. Contact your service provider.'));
		}
		return ['tenant' => $payload['tenant'], 'users' => $result];
	}

	public function billingPortal(string $systemId, string $returnUrl): string {
		$url = $this->post("/v1/phone-systems/$systemId/billing-portal", ['returnUrl' => $returnUrl], 200, true)['url'] ?? '';
		if (!str_starts_with($url, 'https://billing.stripe.com/')) {
			throw new GcnaError($this->l10n->t('GCNA returned an invalid response. Contact your service provider.'));
		}
		return $url;
	}
}
