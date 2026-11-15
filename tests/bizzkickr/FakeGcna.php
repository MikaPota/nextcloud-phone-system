<?php

declare(strict_types=1);

namespace OCA\BizzKickR\Tests;

use OCP\Http\Client\IResponse;

/** GCNA as the app sees it: answers per path, every call recorded. A test's IClient mock posts here. */
final class FakeGcna {
	public array $calls = [];
	/** @var array<string, array{0: int, 1: mixed}> */
	public array $answers = [];
	public bool $down = false;

	public function post(string $uri, array $options = []): IResponse {
		if ($this->down) {
			throw new \RuntimeException('connection refused');
		}
		$path = (string)parse_url($uri, PHP_URL_PATH);
		$this->calls[] = [$path, json_decode($options['body'], true), $options['headers']];
		[$status, $payload] = $this->answers[$path] ?? [200, []];
		return new class($status, $payload) implements IResponse {
			public function __construct(private int $status, private mixed $payload) {
			}
			public function getBody() {
				return json_encode($this->payload);
			}
			public function getStatusCode(): int {
				return $this->status;
			}
			public function getHeader(string $key): string {
				return '';
			}
			public function getHeaders(): array {
				return [];
			}
		};
	}

	public function last(string $path): array {
		foreach (array_reverse($this->calls) as [$p, $body]) {
			if ($p === $path) {
				return $body;
			}
		}
		throw new \LogicException("no call to $path");
	}
}
