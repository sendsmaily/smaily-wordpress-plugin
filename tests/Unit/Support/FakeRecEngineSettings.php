<?php
/**
 * The rec-engine settings double every unit test that needs a connection state
 * shares — overrides the wp_options readers so no WP is needed, and answers the
 * connected / refused pair the sending gate is built on (PRO-1893).
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Support;

use Smaily\Connect\Settings\RecEngineSettings;

final class FakeRecEngineSettings extends RecEngineSettings {

	private bool $test_connected;
	private bool $test_refused;
	private string $test_api_key;
	private string $test_base_url;

	/** @var array<string, string> */
	private array $test_endpoints;

	/**
	 * @param array<string, string> $endpoints
	 */
	public function __construct(
		bool $connected = true,
		bool $refused = false,
		string $api_key = 'sk_unit',
		string $base_url = 'https://engine.unit',
		array $endpoints = array()
	) {
		$this->test_connected = $connected;
		$this->test_refused   = $refused;
		$this->test_api_key   = $api_key;
		$this->test_base_url  = $base_url;
		$this->test_endpoints = $endpoints;
	}

	public function is_connected(): bool {
		return $this->test_connected;
	}

	public function is_refused(): bool {
		return $this->test_refused;
	}

	public function api_key(): string {
		return $this->test_api_key;
	}

	public function base_url(): string {
		return $this->test_base_url;
	}

	/**
	 * @return array<string, string>
	 */
	public function endpoints(): array {
		return $this->test_endpoints;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function config(): array {
		return array();
	}
}
