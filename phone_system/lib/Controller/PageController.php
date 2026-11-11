<?php

declare(strict_types=1);

namespace OCA\PhoneSystem\Controller;

use OCA\PhoneSystem\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\FeaturePolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\Util;

class PageController extends Controller {
	public function __construct(IRequest $request) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** The phone runs in its own window, so calls survive page loads in the main window. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function phone(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'phone');
		Util::addStyle(Application::APP_ID, 'phone');
		$response = new TemplateResponse(Application::APP_ID, 'phone', [], $this->request->getParam('window') === '1' ? 'base' : 'user');
		// Nextcloud denies the microphone by default; only the phone page may use it.
		$policy = new FeaturePolicy();
		$policy->addAllowedMicrophoneDomain("'self'");
		$response->setFeaturePolicy($policy);
		return $response;
	}
}
