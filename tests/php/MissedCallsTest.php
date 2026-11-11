<?php

declare(strict_types=1);

namespace OCA\PhoneSystem\Tests;

use OCA\PhoneSystem\Db\Call;
use OCA\PhoneSystem\Notification\Notifier;
use OCA\PhoneSystem\Service\MissedCalls;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;

final class MissedCallsTest extends TestCase {
	private function call(string $direction, string $outcome): Call {
		$call = new Call();
		$call->setUserId('bob');
		$call->setDirection($direction);
		$call->setRemote('+4930123456');
		$call->setRemoteName('Ada Lovelace');
		$call->setOutcome($outcome);
		$call->setStartedAt(1_794_398_000);
		$call->setDuration(0);
		return $call;
	}

	/** A notification double that records what was set on it. */
	private function notification(array &$set): INotification {
		$n = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setObject', 'setDateTime', 'setSubject'] as $method) {
			$n->method($method)->willReturnCallback(function (...$args) use ($method, &$set, &$n) {
				$set[$method] = $args;
				return $n;
			});
		}
		return $n;
	}

	public function testAMissedIncomingCallNotifiesTheUser(): void {
		$set = [];
		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willReturn($this->notification($set));
		$manager->expects($this->once())->method('notify');
		$manager->expects($this->never())->method('markProcessed');
		(new MissedCalls($manager))->recorded($this->call('in', 'missed'));
		$this->assertSame(['phone_system'], $set['setApp']);
		$this->assertSame(['bob'], $set['setUser']);
		$this->assertSame(['caller', '+4930123456'], $set['setObject']);
		$this->assertSame([Notifier::MISSED, ['remote' => '+4930123456', 'name' => 'Ada Lovelace']], $set['setSubject']);
		$this->assertSame(1_794_398_000, $set['setDateTime'][0]->getTimestamp());
	}

	public function testCallingTheNumberBackClearsItsMissedCalls(): void {
		$set = [];
		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willReturn($this->notification($set));
		$manager->expects($this->never())->method('notify');
		$manager->expects($this->once())->method('markProcessed');
		(new MissedCalls($manager))->recorded($this->call('out', 'answered'));
		$this->assertSame(['caller', '+4930123456'], $set['setObject']);
	}

	public function testOtherCallsLeaveNotificationsAlone(): void {
		$manager = $this->createMock(IManager::class);
		$manager->expects($this->never())->method('notify');
		$manager->expects($this->never())->method('markProcessed');
		$missed = new MissedCalls($manager);
		foreach ([['in', 'answered'], ['out', 'unanswered'], ['out', 'failed'], ['in', 'failed']] as [$direction, $outcome]) {
			$missed->recorded($this->call($direction, $outcome));
		}
	}
}
