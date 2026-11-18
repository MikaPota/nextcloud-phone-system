<?php

declare(strict_types=1);

namespace OCA\PhoneSystem\BackgroundJob;

use OCA\PhoneSystem\Service\Gcna;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/** Once a day: renew GCNA's 30-day link before it ends (GCNA POST /v1/installation/renew). */
class RenewGcnaLink extends TimedJob {
	public function __construct(ITimeFactory $time, private Gcna $gcna) {
		parent::__construct($time);
		$this->setInterval(86400);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		$this->gcna->renewIfDue();
	}
}
