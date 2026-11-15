<?php

declare(strict_types=1);

namespace OCA\BizzKickR\Tests;

use OCP\Constants;
use OCP\IAddressBook;

/** An address book that keeps vCard properties like Nextcloud's: only the given properties are replaced. */
final class FakeAddressBook implements IAddressBook {
	/** @var array<string, array<string, mixed>> uri => properties */
	public array $cards = [];
	private int $next = 0;

	public function __construct(private string $key, private string $uri, private bool $shared = false, private bool $system = false) {
	}

	public function getKey() {
		return $this->key;
	}

	public function getUri(): string {
		return $this->uri;
	}

	public function getDisplayName() {
		return $this->uri;
	}

	public function search($pattern, $searchProperties, $options) {
		$found = [];
		foreach ($this->cards as $uri => $card) {
			foreach ((array)($card['EMAIL'] ?? []) as $email) {
				if (strcasecmp($email, $pattern) === 0) {
					$found[] = ['URI' => $uri] + $card;
				}
			}
		}
		return $found;
	}

	public function createOrUpdate($properties) {
		$uri = $properties['URI'] ?? 'card-' . (++$this->next) . '.vcf';
		unset($properties['URI']);
		$card = $this->cards[$uri] ?? [];
		foreach ($properties as $key => $value) {
			if ($value === []) {
				unset($card[$key]);
			} else {
				$card[$key] = $value;
			}
		}
		$this->cards[$uri] = $card;
		return ['URI' => $uri] + $card;
	}

	public function getPermissions() {
		return $this->shared ? Constants::PERMISSION_READ : Constants::PERMISSION_ALL;
	}

	public function delete($id) {
		unset($this->cards[$id]);
		return true;
	}

	public function isShared(): bool {
		return $this->shared;
	}

	public function isSystemAddressBook(): bool {
		return $this->system;
	}
}
