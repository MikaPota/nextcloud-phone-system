<?php

declare(strict_types=1);

namespace OCA\PhoneSystem\Controller;

use OCA\PhoneSystem\AppInfo\Application;
use OCA\PhoneSystem\Service\Mappings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Mints a one-time PBX login for the signed-in user's extension, as the Odoo app does
 * (pilot_configuration._mint_browser_session). The password digest never reaches the browser.
 */
class SessionController extends Controller {
	public function __construct(
		IRequest $request,
		private IUserSession $users,
		private Mappings $mappings,
		private LoggerInterface $logger,
		private IL10N $l,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function mint(): JSONResponse {
		$uid = $this->users->getUser()?->getUID() ?? '';
		$tenant = $this->mappings->tenant();
		$extension = $this->mappings->extensionOf($uid);
		$digest = $extension === null ? null : $this->mappings->digestOf($uid);
		if ($tenant === '' || $extension === null || $digest === null) {
			return new JSONResponse(['error' => $this->l->t('No extension is assigned to you. Ask an administrator.')], Http::STATUS_CONFLICT);
		}
		try {
			$challenge = $this->mappings->challenge($extension);
		} catch (\Throwable $error) {
			$this->logger->warning('Phone System: password challenge failed', ['exception' => $error]);
			return new JSONResponse(['error' => $this->l->t('The phone system could not be reached.')], Http::STATUS_BAD_GATEWAY);
		}
		return new JSONResponse([
			'origin' => $this->mappings->origin($tenant),
			'tenant' => $tenant,
			'extension' => $extension,
			'directory' => array_values(array_unique(array_values($this->mappings->extensions()))),
			'login' => [
				'method' => 'login-password',
				'username' => $extension,
				'id' => $challenge['id'],
				'hash' => $this->mappings->proof($extension, $digest, $challenge['challenge']),
			],
		]);
	}
}
