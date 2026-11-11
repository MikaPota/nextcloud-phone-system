<?php

declare(strict_types=1);

namespace OCA\PhoneSystem\Service;

use OCP\Accounts\IAccountManager;
use OCP\Contacts\IManager as IContacts;
use OCP\IUserManager;

/** Names a number or extension: colleagues first, then the address books the user can read. */
class Callers {
	public function __construct(
		private Mappings $mappings,
		private IUserManager $users,
		private IAccountManager $accounts,
		private IContacts $contacts,
	) {
	}

	public static function digits(string $number): string {
		$digits = preg_replace('/[^0-9]/', '', $number);
		// +49 30…, 0049 30… and 030… compare on the national significant number.
		return ltrim(preg_replace('/^(00)?49/', '', $digits), '0');
	}

	public function name(string $remote): ?string {
		foreach ($this->mappings->extensions() as $uid => $extension) {
			if ($extension === $remote) {
				return $this->users->get($uid)?->getDisplayName();
			}
		}
		$wanted = self::digits($remote);
		if (strlen($wanted) < 5) {
			return null;
		}
		$found = null;
		$this->users->callForSeenUsers(function ($user) use ($wanted, &$found) {
			$phone = $this->accounts->getAccount($user)->getProperty(IAccountManager::PROPERTY_PHONE)->getValue();
			if ($phone !== '' && self::digits($phone) === $wanted) {
				$found = $user->getDisplayName();
				return false;
			}
			return true;
		});
		if ($found !== null) {
			return $found;
		}
		foreach ($this->contacts->search($wanted, ['TEL'], ['limit' => 20]) as $contact) {
			foreach ((array)($contact['TEL'] ?? []) as $tel) {
				$value = is_array($tel) ? ($tel['value'] ?? '') : (string)$tel;
				if (self::digits($value) === $wanted) {
					return (string)($contact['FN'] ?? $value);
				}
			}
		}
		return null;
	}
}
