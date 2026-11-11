<?php

declare(strict_types=1);

namespace OCA\PhoneSystem\Listener;

use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;

/** @template-implements IEventListener<AddContentSecurityPolicyEvent> */
class AllowPhoneConnections implements IEventListener {
	public function handle(Event $event): void {
		if (!$event instanceof AddContentSecurityPolicyEvent) {
			return;
		}
		$policy = new EmptyContentSecurityPolicy();
		// GCNA tenants are reached through the relay at gcn-alliance.com/phone/<tenant>/.
		$policy->addAllowedConnectDomain('https://gcn-alliance.com');
		$policy->addAllowedConnectDomain('wss://gcn-alliance.com');
		$event->addPolicy($policy);
	}
}
