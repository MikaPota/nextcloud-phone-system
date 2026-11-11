<?php

declare(strict_types=1);

namespace OCA\PhoneSystem\Service;

use OCA\PhoneSystem\AppInfo\Application;
use OCA\PhoneSystem\Db\Call;
use OCA\PhoneSystem\Notification\Notifier;
use OCP\Notification\IManager;

/**
 * A missed incoming call becomes a Nextcloud notification; calling the number back
 * clears every missed-call notification from it.
 */
class MissedCalls {
	public function __construct(private IManager $notifications) {
	}

	public function recorded(Call $call): void {
		if ($call->getDirection() === 'in' && $call->getOutcome() === 'missed') {
			$this->notifications->notify($this->of($call->getUserId(), $call->getRemote())
				->setDateTime((new \DateTime())->setTimestamp($call->getStartedAt()))
				->setSubject(Notifier::MISSED, ['remote' => $call->getRemote(), 'name' => (string)$call->getRemoteName()]));
		} elseif ($call->getDirection() === 'out' && $call->getOutcome() === 'answered') {
			$this->notifications->markProcessed($this->of($call->getUserId(), $call->getRemote()));
		}
	}

	private function of(string $user, string $remote) {
		return $this->notifications->createNotification()
			->setApp(Application::APP_ID)
			->setUser($user)
			->setObject('caller', $remote);
	}
}
