<?php
/**
 * HistoryConfig — the orders import's history window (contract v1.20.0
 * `config.history`, CON-245): the ping first, else the setup exchange's
 * stored block, else 18 / 60 / newest_first. Real engine path (mock engine +
 * the logged fallback): RecEngineOrderBackfillTest.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily\RecEngine;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\RecEngine\ApiException;
use Smaily\Connect\Smaily\RecEngine\Client;
use Smaily\Connect\Smaily\RecEngine\HistoryConfig;

final class HistoryConfigTest extends TestCase {

	private const PING_BLOCK   = array(
		'start_months'   => 6,
		'horizon_months' => 24,
		'order'          => 'newest_first',
	);
	private const STORED_BLOCK = array(
		'start_months'   => 12,
		'horizon_months' => 36,
		'order'          => 'newest_first',
	);

	public function test_the_ping_block_wins_over_the_stored_one(): void {
		$config = $this->config( array( 'config' => array( 'history' => self::PING_BLOCK ) ), self::STORED_BLOCK );

		self::assertSame( self::PING_BLOCK, $config->for_new_import() );
	}

	public function test_a_ping_without_the_block_falls_back_to_the_stored_one(): void {
		$config = $this->config( array( 'ok' => true ), self::STORED_BLOCK );

		self::assertSame( self::STORED_BLOCK, $config->for_new_import() );
	}

	public function test_a_failed_ping_falls_back_to_the_stored_one(): void {
		$config = $this->config( new ApiException( 503, 'unavailable', 'down' ), self::STORED_BLOCK );

		self::assertSame( self::STORED_BLOCK, $config->for_new_import() );
	}

	public function test_no_ping_while_sending_is_not_allowed(): void {
		$config = $this->config( new \LogicException( 'must not ping' ), self::STORED_BLOCK, false );

		self::assertSame( self::STORED_BLOCK, $config->for_new_import() );
	}

	public function test_neither_block_gives_the_default(): void {
		$config = $this->config( array( 'ok' => true ), null );

		self::assertSame(
			array(
				'start_months'   => 18,
				'horizon_months' => 60,
				'order'          => 'newest_first',
			),
			$config->for_new_import()
		);
	}

	public function test_a_block_with_the_start_part_past_the_horizon_is_not_used(): void {
		$bad    = array(
			'start_months'   => 24,
			'horizon_months' => 12,
			'order'          => 'newest_first',
		);
		$config = $this->config( array( 'config' => array( 'history' => $bad ) ), self::STORED_BLOCK );

		self::assertSame( self::STORED_BLOCK, $config->for_new_import() );
	}

	/**
	 * @param array<string, mixed>|\Throwable $ping   The ping's answer, or what it throws.
	 * @param array<string, mixed>|null       $stored The setup exchange's history block.
	 */
	private function config( $ping, ?array $stored, bool $sending_allowed = true ): HistoryConfig {
		$settings = $this->createMock( RecEngineSettings::class );
		$settings->method( 'sending_allowed' )->willReturn( $sending_allowed );
		$settings->method( 'config' )->willReturn( $stored === null ? array() : array( 'history' => $stored ) );

		$client = $this->createMock( Client::class );
		if ( $ping instanceof \Throwable ) {
			$client->method( 'ping' )->willThrowException( $ping );
		} else {
			$client->method( 'ping' )->willReturn( $ping );
		}

		return new HistoryConfig(
			$settings,
			static function () use ( $client ): Client {
				return $client;
			}
		);
	}
}
