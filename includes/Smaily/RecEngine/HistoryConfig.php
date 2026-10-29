<?php
/**
 * The engine's history window for an orders import (contract v1.20.0
 * `config.history`, §1, §2, §25).
 *
 * @package Smaily\Connect\Smaily\RecEngine
 */

declare(strict_types=1);

namespace Smaily\Connect\Smaily\RecEngine;

use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Support\DebugLog;

defined( 'ABSPATH' ) || exit;

/**
 * How much order history an orders import sends: `start_months` (the start
 * part, sent first), `horizon_months` (no older order is sent) and `order`
 * (`newest_first`, the one order the contract names).
 *
 * An import that starts asks the ping first, so a change on the engine's side
 * reaches the store without reconnecting; the block the setup exchange stored
 * is the answer when the ping has none or cannot be reached, and 18 / 60 /
 * newest_first when neither has it (logged). The ping's block is not stored:
 * the import keeps the window it started with (OrderBackfillJob). Same rule
 * as the Magento extension's HistoryConfig.
 */
class HistoryConfig {

	public const FALLBACK = array(
		'start_months'   => 18,
		'horizon_months' => 60,
		'order'          => 'newest_first',
	);

	private RecEngineSettings $settings;

	/** @var callable(): Client */
	private $client_factory;

	/**
	 * @param callable(): Client $client_factory Builds the engine client the ping goes through.
	 */
	public function __construct( RecEngineSettings $settings, callable $client_factory ) {
		$this->settings       = $settings;
		$this->client_factory = $client_factory;
	}

	/**
	 * The window for an orders import that starts now.
	 *
	 * @return array{start_months: int, horizon_months: int, order: string}
	 */
	public function for_new_import(): array {
		$from_ping = null;
		if ( $this->settings->sending_allowed() ) {
			try {
				$ping      = ( $this->client_factory )()->ping();
				$from_ping = self::valid( is_array( $ping['config'] ?? null ) ? ( $ping['config']['history'] ?? null ) : null );
			} catch ( ApiException $e ) {
				$from_ping = null;
			}
		}
		if ( $from_ping !== null ) {
			return $from_ping;
		}

		$stored = self::valid( $this->settings->config()['history'] ?? null );
		if ( $stored !== null ) {
			return $stored;
		}

		DebugLog::write(
			sprintf(
				'[smaily-connect backfill.orders] the engine sent no history window; the import uses the default %d / %d months',
				self::FALLBACK['start_months'],
				self::FALLBACK['horizon_months']
			)
		);
		return self::FALLBACK;
	}

	/**
	 * A block whose months are whole numbers, the start part inside the
	 * horizon; null otherwise.
	 *
	 * @param mixed $history The `config.history` value as decoded.
	 * @return array{start_months: int, horizon_months: int, order: string}|null
	 */
	private static function valid( $history ): ?array {
		if ( ! is_array( $history ) ) {
			return null;
		}
		$start   = filter_var( $history['start_months'] ?? null, FILTER_VALIDATE_INT );
		$horizon = filter_var( $history['horizon_months'] ?? null, FILTER_VALIDATE_INT );
		if ( $start === false || $horizon === false || $start < 1 || $horizon < $start ) {
			return null;
		}

		return array(
			'start_months'   => $start,
			'horizon_months' => $horizon,
			'order'          => is_string( $history['order'] ?? null ) ? $history['order'] : self::FALLBACK['order'],
		);
	}
}
