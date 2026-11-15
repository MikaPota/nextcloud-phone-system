<?php

declare(strict_types=1);

namespace OCA\BizzKickR\Service;

use OCP\Constants;
use OCP\Contacts\IManager;
use OCP\IAddressBook;

/**
 * The signed-in user's own address books, through Nextcloud's public contacts API: BIZZKICKR connections
 * become contacts there. Address books shared with the user and the system address book are never written.
 */
class Contacts {
	/** Card field => vCard property. */
	public const FIELDS = ['name' => 'FN', 'organization' => 'ORG', 'role' => 'TITLE', 'phone' => 'TEL', 'web' => 'URL'];

	public function __construct(private IManager $manager) {
	}

	/** @return array<string, IAddressBook> the user's own writable address books by key */
	private function own(): array {
		$books = [];
		foreach ($this->manager->getUserAddressBooks() as $book) {
			if (!$book->isSystemAddressBook() && !$book->isShared() && ($book->getPermissions() & Constants::PERMISSION_CREATE)) {
				$books[(string)$book->getKey()] = $book;
			}
		}
		return $books;
	}

	/** The address book new contacts go to: the user's default one ("contacts"), else their first own one. */
	public function target(): ?string {
		$books = $this->own();
		foreach ($books as $key => $book) {
			if ($book->getUri() === 'contacts') {
				return (string)$key;
			}
		}
		// Keys are numeric ids, which PHP turns into integer array keys.
		$first = array_key_first($books);
		return $first === null ? null : (string)$first;
	}

	private static function first(mixed $value): string {
		while (is_array($value)) {
			$value = $value['value'] ?? reset($value);
		}
		return is_string($value) ? trim($value) : '';
	}

	private static function values(array $contact): array {
		$values = [];
		foreach (self::FIELDS as $field => $property) {
			$value = $contact[$property] ?? '';
			// A second phone or web address added here makes the field the user's own: BIZZKICKR leaves it alone.
			$values[$field] = is_array($value) && array_is_list($value) && count($value) > 1
				? implode("\n", array_map([self::class, 'first'], $value)) : self::first($value);
		}
		return $values;
	}

	/** @return list<array{book: string, uri: string, values: array<string, string>}> own contacts with exactly this email address */
	public function withEmail(string $email): array {
		$found = [];
		foreach ($this->own() as $key => $book) {
			foreach ($book->search($email, ['EMAIL'], ['strict_search' => true]) as $contact) {
				$addresses = array_map('strtolower', array_map([self::class, 'first'], (array)($contact['EMAIL'] ?? [])));
				if (in_array(strtolower($email), $addresses, true) && is_string($contact['URI'] ?? null)) {
					$found[] = ['book' => (string)$key, 'uri' => $contact['URI'], 'values' => self::values($contact)];
				}
			}
		}
		return $found;
	}

	/** The contact's current values, or null once the user deleted it or its address book. */
	public function current(string $book, string $uri, string $email): ?array {
		foreach ($this->withEmail($email) as $contact) {
			if ($contact['book'] === $book && $contact['uri'] === $uri) {
				return $contact['values'];
			}
		}
		return null;
	}

	/** Creates a contact and returns its URI. */
	public function create(string $book, string $email, array $values): string {
		$properties = ['EMAIL' => [$email]];
		foreach (self::FIELDS as $field => $property) {
			if (($values[$field] ?? '') !== '') {
				$properties[$property] = $property === 'TEL' || $property === 'URL' ? [$values[$field]] : $values[$field];
			}
		}
		$contact = $this->own()[$book]->createOrUpdate($properties);
		return (string)($contact['URI'] ?? '');
	}

	/** Writes only the given fields; everything else on the contact stays as it is. */
	public function update(string $book, string $uri, array $changes): void {
		$properties = ['URI' => $uri];
		foreach ($changes as $field => $value) {
			$property = self::FIELDS[$field];
			$properties[$property] = $property === 'TEL' || $property === 'URL' ? ($value === '' ? [] : [$value]) : $value;
		}
		($this->own()[$book] ?? null)?->createOrUpdate($properties);
	}
}
