<?php
/**
 * EmailDesignWorkflows — which Smaily workflow ids from the engine's §23
 * answer are kept for the send paths (CON-173).
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Settings;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Settings\EmailDesignWorkflows;

final class EmailDesignWorkflowsTest extends TestCase {

	/** @var array<string, array{0: mixed, 1: mixed}> option => [value, autoload] */
	private array $written = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->written = array();
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value, $autoload = null ): bool {
				$this->written[ $name ] = array( $value, $autoload );
				return true;
			}
		);
		Functions\when( 'get_option' )->alias(
			fn ( string $name, $default = false ) => isset( $this->written[ $name ] ) ? $this->written[ $name ][0] : $default
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_keeps_the_workflow_id_of_each_plugin_and_transactional_email_unautoloaded(): void {
		$store = new EmailDesignWorkflows();

		$store->remember(
			array(
				$this->item( 'plugin.welcome', 'plugin', 'pushed', '456' ),
				$this->item( 'plugin.follow_up', 'plugin', 'failed', '457' ),
				$this->item( 'plugin.abandoned_cart', 'plugin', 'pending', '458' ),
				$this->item( 'transactional.order_confirmation', 'transactional', 'pushed', '459' ),
			)
		);

		self::assertSame( 456, $store->workflow_id( 'welcome' ) );
		self::assertSame( 457, $store->workflow_id( 'follow_up' ) );
		self::assertSame( 458, $store->workflow_id( 'abandoned_cart' ) );
		self::assertSame( 459, $store->workflow_id( 'order_confirmation' ) );
		self::assertSame( 0, $store->workflow_id( 'first_order' ), 'Not listed: no workflow of its own.' );
		self::assertFalse( $this->written[ EmailDesignWorkflows::OPTION ][1], 'Read at send time only — never autoloaded.' );
	}

	public function test_ignores_ids_the_contract_says_not_to_use(): void {
		$store = new EmailDesignWorkflows();

		$store->remember(
			array(
				$this->item( 'plugin.welcome', 'plugin', 'not_available', '456' ),
				$this->item( 'plugin.first_order', 'plugin', 'some_new_state', '457' ),
				$this->item( 'plugin.abandoned_cart', 'some_new_kind', 'pushed', '458' ),
				$this->item( 'replenish_due', 'engine', 'pushed', '459' ),
				$this->item( 'plugin.follow_up', 'plugin', 'pushed', null ),
				$this->item( 'transactional.shipping_confirmation', 'transactional', 'pushed', 'abc' ),
				'not an item',
			)
		);

		foreach ( array_keys( EmailDesignWorkflows::EMAIL_KEYS ) as $trigger ) {
			self::assertSame( 0, $store->workflow_id( $trigger ), $trigger );
		}
	}

	public function test_a_new_read_replaces_the_ids_of_the_last_one(): void {
		$store = new EmailDesignWorkflows();
		$store->remember( array( $this->item( 'plugin.welcome', 'plugin', 'pushed', '456' ) ) );

		$store->remember( array( $this->item( 'plugin.welcome', 'plugin', 'pushed', null ) ) );

		self::assertSame( 0, $store->workflow_id( 'welcome' ) );
	}

	public function test_maps_every_plugin_email_to_its_design_key(): void {
		self::assertSame(
			array(
				'welcome'               => 'plugin.welcome',
				'first_order'           => 'plugin.first_order',
				'abandoned_cart'        => 'plugin.abandoned_cart',
				'follow_up'             => 'plugin.follow_up',
				'order_confirmation'    => 'transactional.order_confirmation',
				'shipping_confirmation' => 'transactional.shipping_confirmation',
			),
			EmailDesignWorkflows::EMAIL_KEYS
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function item( string $email_key, string $kind, string $smaily_state, ?string $workflow_id ): array {
		return array(
			'email_key'          => $email_key,
			'kind'               => $kind,
			'smaily_state'       => $smaily_state,
			'smaily_template_id' => '999',
			'smaily_workflow_id' => $workflow_id,
		);
	}
}
