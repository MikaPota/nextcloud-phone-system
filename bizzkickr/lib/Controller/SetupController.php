<?php

declare(strict_types=1);

namespace OCA\BizzKickR\Controller;

use OCA\BizzKickR\AppInfo\Application;
use OCA\BizzKickR\Service\BizzKickRError;
use OCA\BizzKickR\Service\Gcna;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

/** Administrator only: the free GCNA sign-up that connects this Nextcloud (GCNA-BK-LINK-2). */
class SetupController extends Controller {
	public function __construct(
		IRequest $request,
		private Gcna $gcna,
		private IUserSession $session,
		private IAppConfig $config,
		private IURLGenerator $urls,
		private IL10N $l10n,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function run(callable $step): JSONResponse {
		try {
			return new JSONResponse($step());
		} catch (BizzKickRError $error) {
			return new JSONResponse(['error' => $error->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	public function state(): JSONResponse {
		return $this->run(function () {
			$through = $this->gcna->linkedThrough();
			if ($through !== null) {
				return ['linked' => $through];
			}
			$user = $this->session->getUser();
			$email = (string)$user->getEMailAddress();
			$host = (string)parse_url($this->urls->getAbsoluteURL('/'), PHP_URL_HOST);
			return [
				'linked' => null,
				'pending' => $this->gcna->signupPending(),
				'legal' => $this->gcna->legal(),
				'buyer' => [
					'email' => $email, 'adminName' => $user->getDisplayName(),
					'companyName' => $this->config->getValueString('theming', 'name', ''),
					'domain' => str_contains($email, '@') ? substr($email, strpos($email, '@') + 1) : preg_replace('/^www\./', '', $host),
				],
			];
		});
	}

	public function start(string $email = '', string $adminName = '', string $companyName = '', string $domain = '',
		bool $acceptTerms = false, string $termsVersion = '', string $privacyVersion = ''): JSONResponse {
		return $this->run(function () use ($email, $adminName, $companyName, $domain, $acceptTerms, $termsVersion, $privacyVersion) {
			if (!$acceptTerms) {
				throw new BizzKickRError($this->l10n->t('Accept the terms and privacy notice to continue.'));
			}
			$domain = strtolower(preg_replace('/^www\./i', '', trim($domain)));
			if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen(trim($adminName)) < 2 || mb_strlen(trim($companyName)) < 2
				|| !preg_match('/^(?=.{3,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
				throw new BizzKickRError($this->l10n->t('Complete your name, company, company domain and a valid email address.'));
			}
			return $this->gcna->startSignup([
				'email' => strtolower(trim($email)), 'adminName' => trim($adminName), 'companyName' => trim($companyName), 'domain' => $domain,
				'acceptTerms' => true, 'termsVersion' => $termsVersion, 'privacyVersion' => $privacyVersion,
			]);
		});
	}

	public function confirm(string $code = ''): JSONResponse {
		return $this->run(function () use ($code) {
			if (!preg_match('/^[0-9]{6}$/', $code)) {
				throw new BizzKickRError($this->l10n->t('Enter the six-digit verification code.'));
			}
			return $this->gcna->confirmSignup($code);
		});
	}

	public function resend(): JSONResponse {
		return $this->run(fn () => $this->gcna->resendSignup());
	}

	public function cancel(): JSONResponse {
		$this->gcna->cancelSignup();
		return new JSONResponse(['state' => 'none']);
	}
}
