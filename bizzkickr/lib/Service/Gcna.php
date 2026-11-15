<?php

declare(strict_types=1);

namespace OCA\BizzKickR\Service;

use OCA\BizzKickR\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * This Nextcloud's link to GCN-ALLIANCE, the reseller BIZZKICKR is booked through (GCNA REQ-006).
 *
 * Like the Phone System app, this Nextcloud is one GCNA installation with an id and an HMAC proof; the free
 * sign-up (GCNA-BK-LINK-2) gives it a bearer token for a new GCNA account. A Nextcloud whose Phone System is
 * already linked uses that link: it is the same company's account. Proof key and token are stored encrypted
 * and never reach the browser.
 */
class Gcna {
	private const PROOF_LABEL = 'gcna-installation-proof/v1:';
	private const PHONE_SYSTEM = 'phone_system';
	private const SCOPES = ['phone-system:manage', 'bizzkickr:use'];

	public function __construct(
		private IAppConfig $config,
		private ICrypto $crypto,
		private ISecureRandom $random,
		private IClientService $http,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
	}

	/** Server-side only (occ), like Phone System's service_origin: a test GCNA. */
	private function origin(): string {
		return rtrim($this->config->getValueString(Application::APP_ID, 'service_origin', 'https://gcn-alliance.com'), '/');
	}

	public function installationId(): string {
		$id = $this->config->getValueString(Application::APP_ID, 'installation_id');
		if ($id === '') {
			$hex = bin2hex(random_bytes(16));
			$id = sprintf('%s-%s-4%s-%x%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 13, 3),
				8 + random_int(0, 3), substr($hex, 17, 3), substr($hex, 20, 12));
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

	/** A stored credential of this app or of Phone System, if it is still valid. */
	private function stored(string $app, string $installation): ?string {
		$stored = $this->config->getValueString($app, 'installation_token');
		if ($stored === '' || $installation === '') {
			return null;
		}
		try {
			$token = json_decode($this->crypto->decrypt($stored), true);
		} catch (\Exception) {
			return null;
		}
		if (!is_array($token) || ($token['installation'] ?? null) !== $installation || ($token['expires'] ?? 0) <= time() || !is_string($token['token'] ?? null)) {
			return null;
		}
		return $token['token'];
	}

	/** 'bizzkickr' when this app signed up, 'phone_system' when Phone System's link is used, or null. */
	public function linkedThrough(): ?string {
		if ($this->stored(Application::APP_ID, $this->config->getValueString(Application::APP_ID, 'installation_id')) !== null) {
			return Application::APP_ID;
		}
		if ($this->stored(self::PHONE_SYSTEM, $this->config->getValueString(self::PHONE_SYSTEM, 'installation_id')) !== null) {
			return self::PHONE_SYSTEM;
		}
		return null;
	}

	private function token(): ?string {
		return $this->stored(Application::APP_ID, $this->config->getValueString(Application::APP_ID, 'installation_id'))
			?? $this->stored(self::PHONE_SYSTEM, $this->config->getValueString(self::PHONE_SYSTEM, 'installation_id'));
	}

	private function storeToken(mixed $payload): void {
		$token = is_array($payload) ? ($payload['token'] ?? null) : null;
		$scopes = is_array($payload) ? ($payload['scopes'] ?? null) : null;
		$expires = is_array($payload) ? strtotime((string)($payload['expiresAt'] ?? '')) : false;
		if (($payload['installationId'] ?? null) !== $this->installationId() || !is_array($scopes) || !in_array('bizzkickr:use', $scopes, true)
			|| array_diff($scopes, self::SCOPES) !== [] || !is_string($token) || !preg_match('/^[A-Za-z0-9_.~-]{32,512}$/', $token)
			|| !$expires || $expires <= time()) {
			throw $this->invalid();
		}
		$this->config->setValueString(Application::APP_ID, 'installation_token', $this->crypto->encrypt(json_encode([
			'installation' => $this->installationId(), 'account' => $payload['accountId'] ?? '', 'token' => $token, 'expires' => $expires,
		])), false, true);
	}

	public function invalid(): BizzKickRError {
		return new BizzKickRError($this->l10n->t('GCNA returned an invalid response. Contact your service provider.'));
	}

	/** GCNA's and BIZZKICKR's answers a person can act on; everything else stays a generic error. */
	private function message(?string $code): ?string {
		return match ($code) {
			'COMPANY_EXISTS' => $this->l10n->t('This company already has a GCNA account. Its administrator must connect this Nextcloud.'),
			'ALREADY_A_USER' => $this->l10n->t('This email address already belongs to a GCNA account. Use a different address or contact your service provider.'),
			'ALREADY_LINKED' => $this->l10n->t('This Nextcloud already belongs to a GCNA account.'),
			'LEGAL_CHANGED' => $this->l10n->t('The terms or privacy notice changed. Review them again.'),
			'LEGAL_NOT_CONFIGURED' => $this->l10n->t('Sign-up opens once the provider has published its terms.'),
			'INVALID_SIGNUP', 'INVALID_EMAIL' => $this->l10n->t('Complete your name, company, company domain and a valid email address.'),
			'CONSENT_REQUIRED' => $this->l10n->t('Accept the terms and privacy notice to continue.'),
			'INVALID_CODE' => $this->l10n->t('The verification code is not correct.'),
			'CODE_EXPIRED' => $this->l10n->t('The code expired. Request a new one.'),
			'TOO_MANY_ATTEMPTS', 'SIGNUP_EXPIRED' => $this->l10n->t('The sign-up expired. Start again.'),
			'DELIVERY_FAILED' => $this->l10n->t('The verification email could not be delivered. Retry later.'),
			'PERSON_UNKNOWN' => $this->l10n->t('Register for BIZZKICKR first.'),
			'PERSON_UNCONFIRMED' => $this->l10n->t('Open the link BIZZKICKR emailed you to confirm, then refresh.'),
			'CARDS_EXIST' => $this->l10n->t('You already have a card. Refresh to see it.'),
			'INVALID_CARD' => $this->l10n->t('Check the card: each field takes at most 100 characters, without < or >.'),
			'INVALID_INPUT' => $this->l10n->t('Check the card: a phone such as +49 30 1234567 and a web address such as example.com.'),
			'INVALID_PERSON' => $this->l10n->t('Add a valid email address to your Nextcloud account first.'),
			'INVALID_RECIPIENT' => $this->l10n->t('This contact has no valid email address.'),
			'OWN_CARD' => $this->l10n->t('This is your own address.'),
			'MAIL_OFF' => $this->l10n->t('BIZZKICKR cannot send email right now. Retry later.'),
			'NOT_FOUND' => $this->l10n->t('This no longer exists. Refresh and try again.'),
			'TOO_MANY_REQUESTS', 'RATE_LIMITED' => $this->l10n->t('Too many attempts. Retry later.'),
			'BIZZKICKR_UNAVAILABLE' => $this->l10n->t('BIZZKICKR cannot be reached right now. Retry later.'),
			default => null,
		};
	}

	/** One request to GCNA. `$expected` lists the statuses that are a success. */
	public function post(string $path, array $body, array $expected, bool $authorized = false): array {
		$headers = ['Content-Type' => 'application/json'];
		if ($authorized) {
			$token = $this->token();
			if ($token === null) {
				throw new BizzKickRError($this->l10n->t('An administrator must connect this Nextcloud to GCN-ALLIANCE first: Administration settings > BIZZKICKR.'));
			}
			$headers['Authorization'] = "Bearer $token";
		}
		try {
			$response = $this->http->newClient()->post($this->origin() . $path, [
				'body' => json_encode((object)$body), 'headers' => $headers,
				'timeout' => 15, 'allow_redirects' => false, 'http_errors' => false,
			]);
		} catch (\Throwable $error) {
			$this->logger->warning('BIZZKICKR: GCNA request failed', ['exception' => $error]);
			throw new BizzKickRError($this->l10n->t('GCNA could not be reached. Retry later or contact your service provider.'));
		}
		$status = $response->getStatusCode();
		$payload = json_decode((string)$response->getBody(), true);
		$code = is_array($payload) && is_string($payload['error']['code'] ?? null) ? $payload['error']['code'] : null;
		if ($authorized && $status === 403 && $code === 'TERMS_ACCEPTANCE_REQUIRED') {
			throw new BizzKickRError($this->l10n->t('GCNA updated its terms. A company administrator must sign in at app.gcn-alliance.com and accept them; BIZZKICKR then works again.'));
		}
		if ($authorized && in_array($status, [401, 403], true)) {
			throw new BizzKickRError($this->l10n->t('GCNA no longer accepts this Nextcloud. An administrator must connect it again: Administration settings > BIZZKICKR.'));
		}
		if (!in_array($status, $expected, true)) {
			$message = $status >= 400 && $status < 600 ? $this->message($code) : null;
			if ($message === null) {
				$this->logger->warning('BIZZKICKR: GCNA answered HTTP {status} on {path}', ['status' => $status, 'path' => $path]);
				$message = $this->l10n->t('GCNA could not complete the request. Contact your service provider.');
			}
			throw new BizzKickRError($message);
		}
		if (!is_array($payload)) {
			throw $this->invalid();
		}
		return $payload;
	}

	/** The provider's current terms and privacy notice, shown before the sign-up; null until published. */
	public function legal(): ?array {
		$legal = $this->post('/v1/catalog', [], [200])['legal'] ?? null;
		if (!is_array($legal)) {
			return null;
		}
		$url = (string)($legal['terms']['url'] ?? '');
		$sections = $legal['privacy']['sections'] ?? null;
		if (!str_starts_with($url, 'https://') || !is_string($legal['terms']['version'] ?? null) || !is_string($legal['privacy']['version'] ?? null)
			|| !is_string($legal['imprint']['entity'] ?? null) || !is_array($sections)) {
			throw $this->invalid();
		}
		$clean = [];
		foreach ($sections as $section) {
			if (!is_string($section['title'] ?? null) || !is_array($section['text'] ?? null)) {
				throw $this->invalid();
			}
			$clean[] = ['title' => $section['title'], 'text' => array_values(array_filter($section['text'], 'is_string'))];
		}
		return [
			'entity' => $legal['imprint']['entity'],
			'terms' => ['version' => $legal['terms']['version'], 'url' => $url],
			'privacy' => ['version' => $legal['privacy']['version'], 'sections' => $clean],
		];
	}

	public function signupPending(): bool {
		return $this->config->getValueString(Application::APP_ID, 'signup_id') !== '';
	}

	/** A free GCNA account (no purchase): GCNA emails a code to the administrator's address. */
	public function startSignup(array $values): array {
		if ($this->linkedThrough() !== null) {
			throw new BizzKickRError($this->message('ALREADY_LINKED'));
		}
		$payload = $this->post('/v1/signups', $this->identity() + $values + ['app' => 'nextcloud'], [202]);
		$id = (string)($payload['signupId'] ?? '');
		if (!preg_match('/^[A-Za-z0-9_-]{32,128}$/', $id)) {
			throw $this->invalid();
		}
		$this->config->setValueString(Application::APP_ID, 'signup_id', $id);
		return ['state' => 'verification_pending'];
	}

	private function signupId(): string {
		$id = $this->config->getValueString(Application::APP_ID, 'signup_id');
		if ($id === '') {
			throw new BizzKickRError($this->message('SIGNUP_EXPIRED'));
		}
		return $id;
	}

	public function confirmSignup(string $code): array {
		$id = $this->signupId();
		try {
			$payload = $this->post("/v1/signups/$id/confirm", $this->identity() + ['code' => $code], [200]);
		} catch (BizzKickRError $error) {
			if ($error->getMessage() === $this->message('SIGNUP_EXPIRED')) {
				$this->config->deleteKey(Application::APP_ID, 'signup_id');
			}
			throw $error;
		}
		if (($payload['state'] ?? null) !== 'linked') {
			throw $this->invalid();
		}
		$this->storeToken($payload['installation'] ?? null);
		$this->config->deleteKey(Application::APP_ID, 'signup_id');
		return ['state' => 'linked'];
	}

	public function resendSignup(): array {
		$this->post('/v1/signups/' . $this->signupId() . '/resend', $this->identity(), [202]);
		return ['state' => 'verification_pending'];
	}

	public function cancelSignup(): void {
		$this->config->deleteKey(Application::APP_ID, 'signup_id');
	}
}
