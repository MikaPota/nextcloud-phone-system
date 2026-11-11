<?php

declare(strict_types=1);

namespace OCA\PhoneSystem\Tests;

use OCA\PhoneSystem\Controller\CallsController;
use OCA\PhoneSystem\Db\Call;
use OCA\PhoneSystem\Db\CallMapper;
use OCA\PhoneSystem\Service\Callers;
use OCA\PhoneSystem\Service\MissedCalls;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class CallsControllerTest extends TestCase {
	private function controller(CallMapper $mapper, MissedCalls $missed): CallsController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$callers = $this->createMock(Callers::class);
		$callers->method('name')->willReturn('Ada Lovelace');
		return new CallsController($this->createMock(IRequest::class), $session, $mapper, $callers, $missed);
	}

	public function testARecordedCallIsStoredForTheSignedInUserAndPassedOnForNotifications(): void {
		$mapper = $this->createMock(CallMapper::class);
		$mapper->expects($this->once())->method('insert')->willReturnArgument(0);
		$missed = $this->createMock(MissedCalls::class);
		$missed->expects($this->once())->method('recorded')->with($this->callback(fn (Call $c) => $c->getUserId() === 'bob'
			&& $c->getRemote() === '+4930123456' && $c->getRemoteName() === 'Ada Lovelace' && $c->getOutcome() === 'missed'));
		$response = $this->controller($mapper, $missed)->create('in', '+4930123456', 'missed', time());
		$this->assertSame(201, $response->getStatus());
	}

	public static function invalid(): array {
		return [
			'direction' => ['sideways', '202', 'missed', 0, 0],
			'outcome' => ['in', '202', 'ignored', 0, 0],
			'number' => ['in', '202; DROP TABLE', 'missed', 0, 0],
			'duration' => ['out', '202', 'answered', 0, 90000],
			'a day off' => ['in', '202', 'missed', -90000, 0],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('invalid')]
	public function testAnInvalidRecordIsRefusedAndNotifiesNobody(string $direction, string $remote, string $outcome, int $offset, int $duration): void {
		$mapper = $this->createMock(CallMapper::class);
		$mapper->expects($this->never())->method('insert');
		$missed = $this->createMock(MissedCalls::class);
		$missed->expects($this->never())->method('recorded');
		$this->assertSame(400, $this->controller($mapper, $missed)->create($direction, $remote, $outcome, time() + $offset, $duration)->getStatus());
	}
}
