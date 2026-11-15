<?php

declare(strict_types=1);

namespace OCA\BizzKickR\Controller;

use OCA\BizzKickR\AppInfo\Application;
use OCA\BizzKickR\Service\BizzKickRError;
use OCA\BizzKickR\Service\People;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Util;

/** Every user's own BIZZKICKR: their card, requests waiting for them, and connections. */
class PageController extends Controller {
	public function __construct(
		IRequest $request,
		private People $people,
		private IUserSession $session,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	private function user(): IUser {
		return $this->session->getUser();
	}

	private function run(callable $step): JSONResponse {
		try {
			return new JSONResponse($step($this->user()));
		} catch (BizzKickRError $error) {
			return new JSONResponse(['error' => $error->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'page');
		Util::addStyle(Application::APP_ID, 'bizzkickr');
		return new TemplateResponse(Application::APP_ID, 'page');
	}

	#[NoAdminRequired]
	public function state(): JSONResponse {
		return $this->run(fn (IUser $user) => $this->people->open($user));
	}

	#[NoAdminRequired]
	public function register(): JSONResponse {
		return $this->run(fn (IUser $user) => $this->people->register($user));
	}

	#[NoAdminRequired]
	public function refresh(): JSONResponse {
		return $this->run(fn (IUser $user) => $this->people->refresh($user));
	}

	#[NoAdminRequired]
	public function createCard(): JSONResponse {
		return $this->run(fn (IUser $user) => $this->people->createCard($user));
	}

	#[NoAdminRequired]
	public function saveCard(string $name = '', string $organization = '', string $role = '', string $phone = '', string $email = '', string $web = ''): JSONResponse {
		$card = ['name' => $name, 'organization' => $organization, 'role' => $role, 'phone' => $phone, 'email' => $email, 'web' => $web];
		return $this->run(fn (IUser $user) => $this->people->saveCard($user, $card));
	}

	/** The QR code of the user's card, shown as an image (an SVG in an img element runs nothing); People::safeSvg checked it. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function qr(): DataDisplayResponse {
		$svg = $this->people->qr($this->user());
		if ($svg === null) {
			return new DataDisplayResponse('', Http::STATUS_NOT_FOUND);
		}
		$response = new DataDisplayResponse($svg, Http::STATUS_OK, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'private, no-cache']);
		return $response;
	}

	#[NoAdminRequired]
	public function send(string $to = ''): JSONResponse {
		return $this->run(function (IUser $user) use ($to) {
			$this->people->send($user, $to);
			return ['sent' => true];
		});
	}

	#[NoAdminRequired]
	public function requests(): JSONResponse {
		return $this->run(fn (IUser $user) => ['requests' => $this->people->requests($user)]);
	}

	#[NoAdminRequired]
	public function answer(string $id, string $verb): JSONResponse {
		return $this->run(fn (IUser $user) => ['requests' => $this->people->answer($user, $id, $verb)]);
	}

	#[NoAdminRequired]
	public function sync(): JSONResponse {
		return $this->run(fn (IUser $user) => $this->people->sync($user));
	}
}
