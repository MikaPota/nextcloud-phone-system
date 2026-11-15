<?php

declare(strict_types=1);

namespace OCA\BizzKickR\Service;

use OCA\BizzKickR\AppInfo\Application;
use OCP\Accounts\IAccountManager;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IUser;
use Psr\Log\LoggerInterface;

/**
 * A Nextcloud user in BIZZKICKR, through GCNA as reseller (GCNA REQ-006, GCNA-BK-APP).
 *
 * The person's cards, requests and connections live at BIZZKICKR. Nextcloud keeps per user only their state
 * there, a copy of their default card to show it, when connections were last fetched, and which contact
 * each connection became.
 */
class People {
	private const EMAIL = '/^[^\s@<>()",;:\\\\]{1,64}@[A-Za-z0-9.-]{1,253}\.[A-Za-z]{2,63}$/';
	private const TEXT = '/^[^\x00-\x1f<>]{1,100}$/u';
	private const CARD_ID = '/^[A-Za-z0-9_-]{1,64}$/';
	private const HASH = '/^[0-9a-f]{64}$/';
	private const ISO = '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(\.[0-9]{1,6})?Z$/';
	public const CARD_FIELDS = ['name', 'organization', 'role', 'phone', 'email', 'web'];
	/** Opening BIZZKICKR fetches new connections at most this often. */
	private const SYNC_EVERY = 300;

	public function __construct(
		private Gcna $gcna,
		private Contacts $contacts,
		private IUserConfig $config,
		private IAppConfig $appConfig,
		private IAccountManager $accounts,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
	}

	// ── what Nextcloud keeps per user ─────────────────────────────────────────────────────────────────

	private function person(IUser $user): array {
		$person = json_decode($this->config->getValueString($user->getUID(), Application::APP_ID, 'person'), true);
		return is_array($person) ? $person + ['state' => 'none', 'email' => null, 'card' => null, 'syncedAt' => null]
			: ['state' => 'none', 'email' => null, 'card' => null, 'syncedAt' => null];
	}

	private function save(IUser $user, array $person): array {
		$this->config->setValueString($user->getUID(), Application::APP_ID, 'person', json_encode($person));
		return $person;
	}

	/** The address BIZZKICKR knows the user by: the one they registered with, else their Nextcloud address. */
	private function email(IUser $user, array $person): string {
		$email = $person['state'] !== 'none' && is_string($person['email']) ? $person['email'] : strtolower(trim((string)$user->getEMailAddress()));
		if (!preg_match(self::EMAIL, $email)) {
			throw new BizzKickRError($this->l10n->t('Add a valid email address to your Nextcloud account first: Personal settings.'));
		}
		return $email;
	}

	private function call(IUser $user, array $person, string $path, array $body = [], array $expected = [200]): array {
		return $this->gcna->post('/v1/bizzkickr/' . $path, ['person' => $this->email($user, $person)] + $body, $expected, true);
	}

	private function malformed(): BizzKickRError {
		$this->logger->warning('BIZZKICKR returned a malformed answer.');
		return new BizzKickRError($this->l10n->t('BIZZKICKR answered unexpectedly. Retry later or contact your service provider.'));
	}

	private static function text(mixed $value): string {
		if ($value === null || $value === '') {
			return '';
		}
		if (!is_string($value) || !preg_match(self::TEXT, $value)) {
			throw new \UnexpectedValueException();
		}
		return $value;
	}

	private function lang(): string {
		return $this->l10n->getLanguageCode();
	}

	/** What the page shows. */
	public function view(IUser $user, array $person, ?string $notice = null): array {
		return [
			'linked' => $this->gcna->linkedThrough() !== null,
			'state' => $person['state'],
			'email' => $person['state'] !== 'none' ? $person['email'] : (string)$user->getEMailAddress(),
			'card' => $person['card'],
			'syncedAt' => $person['syncedAt'],
			'notice' => $notice,
		];
	}

	// ── the user's own person and card ────────────────────────────────────────────────────────────────

	/** The page opens: the person's state and card, and connections new since the last visit become contacts. */
	public function open(IUser $user): array {
		$person = $this->person($user);
		if ($person['state'] === 'none' || $this->gcna->linkedThrough() === null) {
			return $this->view($user, $person);
		}
		try {
			$person = $this->refreshed($user, $person);
			if (in_array($person['state'], ['no_card', 'ready'], true) && ($person['syncedAt'] === null || strtotime($person['syncedAt']) < time() - self::SYNC_EVERY)) {
				$person = $this->synced($user, $person);
			}
		} catch (BizzKickRError $error) {
			// The page still opens; it says what is wrong.
			return $this->view($user, $person, $error->getMessage());
		}
		return $this->view($user, $person);
	}

	/** BIZZKICKR emails the user a link; opening it confirms that GCNA may act for them for this company. */
	public function register(IUser $user): array {
		$person = $this->person($user);
		$email = strtolower(trim((string)$user->getEMailAddress()));
		$person = ['state' => 'none', 'email' => $email] + $person;
		$this->email($user, $person);
		$company = $this->appConfig->getValueString('theming', 'name', '');
		$payload = $this->call($user, $person, 'people', ['lang' => $this->lang()] + ($company !== '' ? ['companyName' => $company] : []), [200, 202]);
		$state = $payload['state'] ?? null;
		if (!in_array($state, ['confirmed', 'unconfirmed'], true)) {
			throw $this->malformed();
		}
		$person = $this->save($user, ['state' => $state === 'unconfirmed' ? 'unconfirmed' : 'no_card', 'email' => $email] + $person);
		return $this->view($user, $state === 'confirmed' ? $this->refreshed($user, $person) : $person);
	}

	public function refresh(IUser $user): array {
		$person = $this->person($user);
		if ($person['state'] === 'none') {
			throw new BizzKickRError($this->l10n->t('Register for BIZZKICKR first.'));
		}
		return $this->view($user, $this->refreshed($user, $person));
	}

	/** Asks BIZZKICKR for the person's state and default card. */
	private function refreshed(IUser $user, array $person): array {
		$state = $this->call($user, $person, 'person')['state'] ?? null;
		if ($state === 'none') {
			$this->config->deleteUserConfig($user->getUID(), Application::APP_ID, 'qr');
			return $this->save($user, ['state' => 'none', 'card' => null] + $person);
		}
		if ($state === 'unconfirmed') {
			return $this->save($user, ['state' => 'unconfirmed'] + $person);
		}
		if ($state !== 'confirmed') {
			throw $this->malformed();
		}
		$cards = $this->call($user, $person, 'cards')['cards'] ?? null;
		if (!is_array($cards) || !array_is_list($cards)) {
			throw $this->malformed();
		}
		$card = null;
		foreach ($cards as $candidate) {
			if (is_array($candidate) && ($candidate['isDefault'] ?? false) === true) {
				$card = $candidate;
			}
		}
		$card ??= $cards[0] ?? null;
		if ($card === null) {
			$this->config->deleteUserConfig($user->getUID(), Application::APP_ID, 'qr');
			return $this->save($user, ['state' => 'no_card', 'card' => null] + $person);
		}
		return $this->shown($user, $person, $card);
	}

	private function shown(IUser $user, array $person, mixed $card): array {
		try {
			if (!is_array($card) || !is_string($card['id'] ?? null) || !preg_match(self::CARD_ID, $card['id'])) {
				throw new \UnexpectedValueException();
			}
			$shown = ['id' => $card['id']];
			foreach (self::CARD_FIELDS as $field) {
				$shown[$field] = self::text($card[$field] ?? null);
			}
			if (!is_string($card['link'] ?? null) || !preg_match('#^https://[^\s<>"]{1,300}$#', $card['link'])) {
				throw new \UnexpectedValueException();
			}
			$shown['link'] = $card['link'];
			$qr = $card['qrSvg'] ?? null;
			if (!is_string($qr) || !self::safeSvg($qr)) {
				throw new \UnexpectedValueException();
			}
		} catch (\UnexpectedValueException) {
			throw $this->malformed();
		}
		$this->config->setValueString($user->getUID(), Application::APP_ID, 'qr', $qr, true);
		return $this->save($user, ['state' => 'ready', 'card' => $shown] + $person);
	}

	/** The QR code BIZZKICKR drew: shapes only, no script, link, style or reference to anything outside. */
	public static function safeSvg(string $svg): bool {
		if (strlen($svg) > 200000 || !preg_match('/^\s*(<\?xml[^>]*\?>\s*)?<svg\b/', $svg) || !str_ends_with(rtrim($svg), '</svg>')) {
			return false;
		}
		preg_match_all('/<\s*\/?\s*([^\s>\/]+)/', $svg, $tags);
		foreach ($tags[1] as $tag) {
			if (!in_array(strtolower($tag), ['svg', 'path', 'rect', 'g', '?xml'], true)) {
				return false;
			}
		}
		return !preg_match('/\son[a-z]+\s*=|href|javascript:|url\s*\(|<!|&/i', $svg);
	}

	public function qr(IUser $user): ?string {
		$qr = $this->config->getValueString($user->getUID(), Application::APP_ID, 'qr', '', true);
		return $qr !== '' && self::safeSvg($qr) ? $qr : null;
	}

	/** A first card from what Nextcloud knows about the user: their profile and the company's name. */
	private function fromNextcloud(IUser $user): array {
		$account = $this->accounts->getAccount($user);
		$property = fn (string $name) => trim((string)$account->getProperty($name)->getValue());
		$organization = $property(IAccountManager::PROPERTY_ORGANISATION) ?: $this->appConfig->getValueString('theming', 'name', '');
		$web = $property(IAccountManager::PROPERTY_WEBSITE) ?: $this->appConfig->getValueString('theming', 'url', '');
		$card = [
			'name' => $user->getDisplayName(), 'organization' => $organization, 'role' => $property(IAccountManager::PROPERTY_ROLE),
			'phone' => $property(IAccountManager::PROPERTY_PHONE), 'email' => (string)$user->getEMailAddress(),
			'web' => preg_replace('#^https?://#', '', $web),
		];
		return array_map(fn ($value) => mb_substr(str_replace(['<', '>'], '', (string)$value), 0, 100), $card);
	}

	public function createCard(IUser $user): array {
		$person = $this->ready($user, false);
		$payload = $this->call($user, $person, 'cards/create', ['card' => $this->fromNextcloud($user)], [201]);
		return $this->view($user, $this->shown($user, $person, $payload['card'] ?? null));
	}

	public function saveCard(IUser $user, array $fields): array {
		$person = $this->ready($user);
		$card = [];
		foreach (self::CARD_FIELDS as $field) {
			$value = trim((string)($fields[$field] ?? ''));
			if ($value !== '' && !preg_match(self::TEXT, $value)) {
				throw new BizzKickRError($this->l10n->t('Check the card: each field takes at most 100 characters, without < or >.'));
			}
			$card[$field] = $value;
		}
		if ($card['name'] === '') {
			throw new BizzKickRError($this->l10n->t('Your card needs a name.'));
		}
		$payload = $this->call($user, $person, 'cards/' . $person['card']['id'] . '/update', ['card' => $card]);
		return $this->view($user, $this->shown($user, $person, $payload['card'] ?? null));
	}

	/** The person registered and confirmed, and – unless creating it – with a card. */
	private function ready(IUser $user, bool $withCard = true): array {
		$person = $this->person($user);
		if ($person['state'] === 'none') {
			throw new BizzKickRError($this->l10n->t('Register for BIZZKICKR first.'));
		}
		if ($person['state'] === 'unconfirmed') {
			throw new BizzKickRError($this->l10n->t('Open the link BIZZKICKR emailed you to confirm, then refresh.'));
		}
		if ($withCard && !is_array($person['card'])) {
			throw new BizzKickRError($this->l10n->t('Create your BIZZKICKR card first.'));
		}
		return $person;
	}

	public function hasCard(IUser $user): bool {
		return $this->person($user)['state'] === 'ready';
	}

	/** BIZZKICKR mails the person's default card, with its vCard and the link to connect. */
	public function send(IUser $user, string $to): void {
		$person = $this->ready($user);
		$to = strtolower(trim($to));
		if (!preg_match(self::EMAIL, $to)) {
			throw new BizzKickRError($this->l10n->t('This contact has no valid email address.'));
		}
		$this->call($user, $person, 'cards/' . $person['card']['id'] . '/send', ['to' => $to, 'lang' => $this->lang()], [202]);
	}

	// ── requests and connections ──────────────────────────────────────────────────────────────────────

	public function requests(IUser $user): array {
		$person = $this->ready($user, false);
		$rows = $this->call($user, $person, 'requests')['requests'] ?? null;
		if (!is_array($rows) || !array_is_list($rows)) {
			throw $this->malformed();
		}
		$requests = [];
		foreach ($rows as $row) {
			try {
				if (!is_array($row) || !is_string($row['id'] ?? null) || !preg_match(self::HASH, $row['id'])) {
					throw new \UnexpectedValueException();
				}
				$email = $row['email'] ?? null;
				$requests[] = [
					'id' => $row['id'], 'name' => self::text($row['name'] ?? null) ?: '?', 'organization' => self::text($row['organization'] ?? null),
					'email' => is_string($email) && preg_match(self::EMAIL, $email) ? $email : '', 'event' => self::text($row['event'] ?? null),
					'requestedAt' => isset($row['requestedAt']) && is_string($row['requestedAt']) && preg_match(self::ISO, $row['requestedAt']) ? $row['requestedAt'] : null,
				];
			} catch (\UnexpectedValueException) {
				$this->logger->warning('BIZZKICKR returned a malformed request; it is not shown.');
			}
		}
		return $requests;
	}

	public function answer(IUser $user, string $id, string $verb): array {
		if (!preg_match(self::HASH, $id) || !in_array($verb, ['accept', 'decline'], true)) {
			throw new BizzKickRError($this->l10n->t('This no longer exists. Refresh and try again.'));
		}
		$person = $this->ready($user, false);
		$this->call($user, $person, "requests/$id/$verb");
		if ($verb === 'accept') {
			$this->synced($user, $person);
		}
		return $this->requests($user);
	}

	public function sync(IUser $user): array {
		return $this->view($user, $this->synced($user, $this->ready($user, false)));
	}

	/** Connections changed since the last fetch become contacts in the user's own address book. */
	private function synced(IUser $user, array $person): array {
		$started = gmdate('Y-m-d\TH:i:s\Z');
		$rows = $this->call($user, $person, 'connections', $person['syncedAt'] ? ['since' => $person['syncedAt']] : [])['connections'] ?? null;
		if (!is_array($rows) || !array_is_list($rows)) {
			throw $this->malformed();
		}
		$book = $this->contacts->target();
		if ($book === null && $rows !== []) {
			throw new BizzKickRError($this->l10n->t('Open Contacts once so Nextcloud creates your address book, then refresh.'));
		}
		$links = json_decode($this->config->getValueString($user->getUID(), Application::APP_ID, 'connections', '{}', true), true) ?: [];
		foreach ($rows as $row) {
			try {
				$links = $this->applied($links, (string)$book, $row);
			} catch (\UnexpectedValueException) {
				$this->logger->warning('BIZZKICKR returned a malformed connection; it was skipped.');
			}
		}
		$this->config->setValueString($user->getUID(), Application::APP_ID, 'connections', json_encode((object)$links), true);
		return $this->save($user, ['syncedAt' => $started] + $person);
	}

	/** One connection: a new contact, an existing one filled where empty, or a followed change (GCNA-BK-CONN-3). */
	private function applied(array $links, string $book, mixed $row): array {
		if (!is_array($row) || !is_string($row['id'] ?? null) || !preg_match(self::HASH, $row['id'])) {
			throw new \UnexpectedValueException();
		}
		$email = $row['email'] ?? null;
		if (!is_string($email) || !preg_match(self::EMAIL, $email)) {
			throw new \UnexpectedValueException();
		}
		$email = strtolower($email);
		$web = $row['web'] ?? null;
		if ($web !== null && $web !== '' && (!is_string($web) || !preg_match('#^https?://[^\s<>"]{1,200}$#', $web))) {
			throw new \UnexpectedValueException();
		}
		$theirs = ['name' => self::text($row['name'] ?? null), 'organization' => self::text($row['organization'] ?? null),
			'role' => self::text($row['role'] ?? null), 'phone' => self::text($row['phone'] ?? null), 'web' => (string)$web];
		if ($theirs['name'] === '') {
			throw new \UnexpectedValueException();
		}
		$link = $links[$row['id']] ?? null;
		$current = is_array($link) ? $this->contacts->current($link['book'], $link['uri'], $email) : null;
		if ($current !== null) {
			// A field follows BIZZKICKR only while nobody here changed it since the last sync.
			$before = $link['values'];
			$changes = array_filter($theirs, fn ($value, $field) => $current[$field] !== $value && $current[$field] === ($before[$field] ?? ''), ARRAY_FILTER_USE_BOTH);
			if ($changes !== []) {
				$this->contacts->update($link['book'], $link['uri'], $changes);
			}
			$links[$row['id']] = ['book' => $link['book'], 'uri' => $link['uri'], 'values' => $theirs];
			return $links;
		}
		if (is_array($link)) {
			// The user deleted the contact: it is not brought back.
			return $links;
		}
		$found = $this->contacts->withEmail($email);
		if (count($found) === 1) {
			// A contact kept here already: BIZZKICKR only fills what is empty.
			$changes = array_filter($theirs, fn ($value, $field) => $value !== '' && $found[0]['values'][$field] === '', ARRAY_FILTER_USE_BOTH);
			if ($changes !== []) {
				$this->contacts->update($found[0]['book'], $found[0]['uri'], $changes);
			}
			// What BIZZKICKR said is remembered, so a value kept here that differs from it is never overwritten later.
			$links[$row['id']] = ['book' => $found[0]['book'], 'uri' => $found[0]['uri'], 'values' => $theirs];
			return $links;
		}
		$uri = $this->contacts->create($book, $email, $theirs);
		$links[$row['id']] = ['book' => $book, 'uri' => $uri, 'values' => $theirs];
		return $links;
	}
}
