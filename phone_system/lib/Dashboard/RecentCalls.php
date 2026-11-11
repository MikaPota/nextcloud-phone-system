<?php

declare(strict_types=1);

namespace OCA\PhoneSystem\Dashboard;

use OCA\PhoneSystem\AppInfo\Application;
use OCA\PhoneSystem\Db\CallMapper;
use OCP\Dashboard\IAPIWidget;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IDateTimeFormatter;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Recent and missed calls; each item calls back in the phone window. The dashboard renders
 * only widgets with the v2 API itself; v1 stays for older clients.
 */
class RecentCalls implements IAPIWidget, IAPIWidgetV2, IIconWidget {
	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $urls,
		private CallMapper $calls,
		private IDateTimeFormatter $formatter,
	) {
	}

	public function getId(): string {
		return 'phone_system-recent-calls';
	}

	public function getTitle(): string {
		return $this->l10n->t('Recent calls');
	}

	public function getOrder(): int {
		return 20;
	}

	public function getIconClass(): string {
		return 'icon-phone';
	}

	public function getIconUrl(): string {
		return $this->urls->getAbsoluteURL($this->urls->imagePath(Application::APP_ID, 'app-dark.svg'));
	}

	public function getUrl(): ?string {
		return $this->urls->linkToRouteAbsolute('phone_system.page.phone');
	}

	public function load(): void {
	}

	public function getItems(string $userId, ?string $since = null, int $limit = 7): array {
		$labels = [
			'answered' => $this->l10n->t('Answered'), 'missed' => $this->l10n->t('Missed'),
			'unanswered' => $this->l10n->t('Not answered'), 'failed' => $this->l10n->t('Failed'),
		];
		return array_map(function ($call) use ($labels) {
			$arrow = $call->getDirection() === 'in' ? '↙' : '↗';
			return new WidgetItem(
				$call->getRemoteName() ?: $call->getRemote(),
				$arrow . ' ' . $labels[$call->getOutcome()] . ' · ' . $this->formatter->formatDateTime($call->getStartedAt(), 'short', 'short'),
				$this->urls->linkToRouteAbsolute('phone_system.page.phone', ['window' => '1', 'dial' => $call->getRemote()]),
				$this->getIconUrl(),
				(string)$call->getStartedAt(),
			);
		}, $this->calls->recent($userId, $limit));
	}

	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		return new WidgetItems($this->getItems($userId, $since, $limit), $this->l10n->t('No calls yet'));
	}
}
