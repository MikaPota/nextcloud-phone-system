<?php

declare(strict_types=1);

namespace OCA\PhoneSystem\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getDirection()
 * @method void setDirection(string $direction)
 * @method string getRemote()
 * @method void setRemote(string $remote)
 * @method ?string getRemoteName()
 * @method void setRemoteName(?string $remoteName)
 * @method string getOutcome()
 * @method void setOutcome(string $outcome)
 * @method int getStartedAt()
 * @method void setStartedAt(int $startedAt)
 * @method int getDuration()
 * @method void setDuration(int $duration)
 */
class Call extends Entity implements \JsonSerializable {
	protected $userId;
	protected $direction;
	protected $remote;
	protected $remoteName;
	protected $outcome;
	protected $startedAt;
	protected $duration;

	public function __construct() {
		$this->addType('startedAt', 'integer');
		$this->addType('duration', 'integer');
	}

	public function jsonSerialize(): array {
		return ['id' => $this->id, 'direction' => $this->direction, 'remote' => $this->remote,
			'remoteName' => $this->remoteName, 'outcome' => $this->outcome,
			'startedAt' => $this->startedAt, 'duration' => $this->duration];
	}
}
