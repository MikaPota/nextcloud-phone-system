<?php

declare(strict_types=1);

namespace OCA\PhoneSystem\Controller;

use OCA\PhoneSystem\AppInfo\Application;
use OCA\PhoneSystem\Db\Call;
use OCA\PhoneSystem\Db\CallMapper;
use OCA\PhoneSystem\Service\Callers;
use OCA\PhoneSystem\Service\MissedCalls;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class CallsController extends Controller {
	public function __construct(
		IRequest $request,
		private IUserSession $session,
		private CallMapper $calls,
		private Callers $callers,
		private MissedCalls $missed,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function index(): JSONResponse {
		return new JSONResponse($this->calls->recent($this->session->getUser()->getUID()));
	}

	#[NoAdminRequired]
	public function lookup(string $remote): JSONResponse {
		return new JSONResponse(['name' => $this->callers->name($remote)]);
	}

	/** The phone window reports each call when it ends; a missed one is also a notification. */
	#[NoAdminRequired]
	public function create(string $direction, string $remote, string $outcome, int $startedAt, int $duration = 0): JSONResponse {
		if (!in_array($direction, ['in', 'out'], true) || !in_array($outcome, ['answered', 'missed', 'unanswered', 'failed'], true)
			|| !preg_match('/^\+?[0-9*#]{1,32}$/', $remote) || $duration < 0 || $duration > 86400
			|| abs($startedAt - time()) > 86400) {
			return new JSONResponse(['error' => 'Invalid call record.'], Http::STATUS_BAD_REQUEST);
		}
		$call = new Call();
		$call->setUserId($this->session->getUser()->getUID());
		$call->setDirection($direction);
		$call->setRemote($remote);
		$call->setRemoteName($this->callers->name($remote));
		$call->setOutcome($outcome);
		$call->setStartedAt($startedAt);
		$call->setDuration($duration);
		$call = $this->calls->insert($call);
		$this->missed->recorded($call);
		return new JSONResponse($call, Http::STATUS_CREATED);
	}
}
