<?php

declare(strict_types=1);

namespace OCA\PhoneSystem\Controller;

use OCA\PhoneSystem\AppInfo\Application;
use OCA\PhoneSystem\Service\Mappings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;

/** Administrator only (no NoAdminRequired): connection and user-to-extension mapping. */
class AdminController extends Controller {
	public function __construct(
		IRequest $request,
		private Mappings $mappings,
		private IUserManager $users,
		private IL10N $l10n,
		private \Psr\Log\LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	public function state(): JSONResponse {
		$extensions = $this->mappings->extensions();
		$users = [];
		$this->users->callForSeenUsers(function (IUser $user) use (&$users, $extensions) {
			$users[] = ['id' => $user->getUID(), 'name' => $user->getDisplayName(), 'extension' => $extensions[$user->getUID()] ?? null];
		});
		usort($users, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
		return new JSONResponse(['tenant' => $this->mappings->tenant(), 'users' => $users]);
	}

	public function tenant(string $tenant): JSONResponse {
		try {
			$this->mappings->setTenant($tenant);
		} catch (\InvalidArgumentException $error) {
			return new JSONResponse(['error' => $error->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		return $this->state();
	}

	public function assign(string $user, string $extension, string $password): JSONResponse {
		if ($this->users->get($user) === null) {
			return new JSONResponse(['error' => $this->l10n->t('Unknown user.')], Http::STATUS_BAD_REQUEST);
		}
		try {
			$this->mappings->assign($user, $extension, $password);
		} catch (\InvalidArgumentException $error) {
			return new JSONResponse(['error' => $error->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $error) {
			$this->logger->warning('Phone System: checking an extension login failed', ['exception' => $error]);
			return new JSONResponse(['error' => $this->l10n->t('The phone system could not be reached.')], Http::STATUS_BAD_GATEWAY);
		}
		return $this->state();
	}

	public function unassign(string $user): JSONResponse {
		$this->mappings->unassign($user);
		return $this->state();
	}
}
