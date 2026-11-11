<?php

declare(strict_types=1);

namespace OCA\PhoneSystem\Controller;

use OCA\PhoneSystem\AppInfo\Application;
use OCA\PhoneSystem\Service\Gcna;
use OCA\PhoneSystem\Service\GcnaError;
use OCA\PhoneSystem\Service\Mappings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Util;

/**
 * Administrator only: create a new phone system (GCNA checkout with email code and
 * Stripe's embedded checkout) or manage the ones this Nextcloud bought. REQ-005, 6.1.
 */
class SetupController extends Controller {
	public function __construct(
		IRequest $request,
		private Gcna $gcna,
		private Mappings $mappings,
		private IUserManager $users,
		private IUserSession $session,
		private IConfig $config,
		private IURLGenerator $urls,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoCSRFRequired]
	public function page(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'setup');
		Util::addStyle(Application::APP_ID, 'phone');
		$response = new TemplateResponse(Application::APP_ID, 'setup');
		// Stripe's embedded checkout: its script, its frames and its API.
		$policy = new ContentSecurityPolicy();
		$policy->addAllowedScriptDomain('https://js.stripe.com');
		foreach (['https://js.stripe.com', 'https://checkout.stripe.com', 'https://hooks.stripe.com'] as $frame) {
			$policy->addAllowedFrameDomain($frame);
		}
		foreach (['https://api.stripe.com', 'https://checkout.stripe.com', 'https://m.stripe.network', 'https://r.stripe.com'] as $connect) {
			$policy->addAllowedConnectDomain($connect);
		}
		$policy->addAllowedImageDomain('https://*.stripe.com');
		$response->setContentSecurityPolicy($policy);
		return $response;
	}

	private function run(callable $step): JSONResponse {
		try {
			return new JSONResponse($step());
		} catch (GcnaError|\InvalidArgumentException $error) {
			return new JSONResponse(['error' => $error->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/** Prices in euro with SEPA Direct Debit in DE, AT and CH, as for Odoo companies. */
	private function country(): array {
		$region = strtoupper((string)$this->config->getSystemValue('default_phone_region', ''));
		return preg_match('/^[A-Z]{2}$/', $region) ? ['country' => $region] : [];
	}

	public function state(): JSONResponse {
		return $this->run(fn () => [
			'linked' => $this->gcna->linked(),
			'checkout' => $this->gcna->linked() ? ['state' => 'none'] : $this->gcna->checkoutStatus(),
			'systems' => $this->gcna->systems(),
			'tenant' => $this->mappings->tenant(),
		]);
	}

	public function catalog(): JSONResponse {
		return $this->run(function () {
			$catalog = $this->gcna->catalog($this->country());
			$user = $this->session->getUser();
			$email = (string)$user->getEMailAddress();
			$host = parse_url($this->urls->getAbsoluteURL('/'), PHP_URL_HOST) ?: '';
			return [
				'catalogVersion' => $catalog['catalogVersion'] ?? '',
				'plans' => $catalog['plans'] ?? [],
				'legal' => $catalog['legal'] ?? null,
				'linked' => $this->gcna->linked(),
				'buyer' => [
					'email' => $email,
					'adminName' => $user->getDisplayName(),
					'companyName' => (string)$this->config->getAppValue('theming', 'name', ''),
					'domain' => str_contains($email, '@') ? substr($email, strpos($email, '@') + 1) : preg_replace('/^www\./', '', $host),
				],
			];
		});
	}

	public function checkout(string $planId, string $term, string $catalogVersion, string $email = '', string $adminName = '',
		string $companyName = '', string $domain = '', bool $acceptTerms = false, string $termsVersion = '', string $privacyVersion = ''): JSONResponse {
		return $this->run(function () use ($planId, $term, $catalogVersion, $email, $adminName, $companyName, $domain, $acceptTerms, $termsVersion, $privacyVersion) {
			if (!in_array($term, ['monthly', 'annual'], true) || $planId === '' || $catalogVersion === '') {
				throw new \InvalidArgumentException($this->l10n->t('Choose a plan.'));
			}
			$plan = ['planId' => $planId, 'term' => $term, 'catalogVersion' => $catalogVersion] + $this->country();
			if ($this->gcna->linked()) {
				$name = trim($companyName) !== '' ? trim($companyName) : $this->l10n->t('Phone System');
				return $this->gcna->order($plan + ['name' => $name]);
			}
			if (!$acceptTerms) {
				throw new \InvalidArgumentException($this->l10n->t('Accept the terms and privacy notice to continue.'));
			}
			if (!filter_var($email, FILTER_VALIDATE_EMAIL) || trim($adminName) === '' || trim($companyName) === '' || trim($domain) === '') {
				throw new \InvalidArgumentException($this->l10n->t('Complete your name, company, company domain and a valid email address.'));
			}
			return $this->gcna->startCheckout($plan + [
				'email' => $email, 'adminName' => trim($adminName), 'companyName' => trim($companyName),
				'domain' => strtolower(trim($domain)), 'app' => 'nextcloud', 'acceptTerms' => true, 'termsVersion' => $termsVersion, 'privacyVersion' => $privacyVersion,
			]);
		});
	}

	public function confirm(string $code): JSONResponse {
		return $this->run(function () use ($code) {
			if (!preg_match('/^[0-9]{6}$/', $code)) {
				throw new \InvalidArgumentException($this->l10n->t('Enter the six-digit verification code.'));
			}
			return $this->gcna->confirmCheckout($code);
		});
	}

	public function resend(): JSONResponse {
		return $this->run(fn () => $this->gcna->resendCode());
	}

	/** Gives the chosen Nextcloud users an extension on a bought phone system and makes it this Nextcloud's phone system. */
	public function users(string $id, array $users): JSONResponse {
		return $this->run(function () use ($id, $users) {
			$system = null;
			foreach ($this->gcna->systems() as $candidate) {
				if ($candidate['id'] === $id) {
					$system = $candidate;
				}
			}
			if ($system === null || $users === []) {
				throw new \InvalidArgumentException($this->l10n->t('Choose a phone system and at least one user.'));
			}
			$named = [];
			foreach ($users as $uid) {
				$user = $this->users->get((string)$uid);
				if ($user === null || !$user->isEnabled()) {
					throw new \InvalidArgumentException($this->l10n->t('Unknown user.'));
				}
				$named['nc-' . substr(hash('sha256', $user->getUID()), 0, 24)] = [$user->getUID(), $user->getDisplayName()];
			}
			$result = $this->gcna->syncUsers($id, array_map(fn ($u) => $u[1], $named));
			if ($result['tenant'] !== $system['hostname']) {
				throw new GcnaError($this->l10n->t('GCNA returned an invalid response. Contact your service provider.'));
			}
			$this->mappings->setTenant($system['hostname']);
			$current = $this->mappings->extensions();
			$assigned = [];
			foreach ($result['users'] as $reference => $item) {
				[$uid, $name] = $named[$reference];
				if ($item['webPassword'] !== null) {
					$this->mappings->store($uid, $item['extension'], $item['webPassword']);
				} elseif (($current[$uid] ?? null) !== $item['extension']) {
					throw new GcnaError($this->l10n->t('The phone login for %s is missing. Contact your service provider.', [$name]));
				}
				$assigned[$uid] = $item['extension'];
			}
			return ['extensions' => $assigned];
		});
	}

	public function billing(string $id): JSONResponse {
		return $this->run(fn () => ['url' => $this->gcna->billingPortal($id, $this->urls->linkToRouteAbsolute('phone_system.setup.page'))]);
	}
}
