<?php
/**
 * EmailDesignWorkflows — which Smaily workflow ids from the engine's §23
 * answer are kept for the send paths, and on which Smaily account each one
 * may be used (CON-173, CON-218).
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

	public function test_keeps_each_workflow_id_with_the_account_the_engine_names_unautoloaded(): void {
		$store = new EmailDesignWorkflows();

		$store->remember(
			array(
				$this->item( 'plugin.welcome', 'plugin', 'pushed', '456' ),
				$this->item( 'plugin.follow_up', 'plugin', 'failed', '457', 'workflow', 'shopexample-tx' ),
				$this->item( 'plugin.abandoned_cart', 'plugin', 'pending', '458' ),
				$this->item( 'transactional.order_confirmation', 'transactional', 'pushed', '459', 'transactional', 'shopexample-tx' ),
				$this->item( 'transactional.shipping_confirmation', 'transactional', 'pushed', '460', 'transactional', 'shopexample-tx' ),
			)
		);

		self::assertSame(
			array(
				'plugin.welcome'                      => array( 'workflow_id' => 456, 'subdomain' => 'shopexample' ),
				'plugin.follow_up'                    => array( 'workflow_id' => 457, 'subdomain' => 'shopexample-tx' ),
				'plugin.abandoned_cart'               => array( 'workflow_id' => 458, 'subdomain' => 'shopexample' ),
				'transactional.order_confirmation'    => array( 'workflow_id' => 459, 'subdomain' => 'shopexample-tx' ),
				'transactional.shipping_confirmation' => array( 'workflow_id' => 460, 'subdomain' => 'shopexample-tx' ),
			),
			$this->written[ EmailDesignWorkflows::OPTION ][0]
		);
		self::assertFalse( $this->written[ EmailDesignWorkflows::OPTION ][1], 'Read at send time only — never autoloaded.' );
		self::assertSame( 0, $store->workflow_id( 'first_order', 'shopexample' ), 'Not listed: no workflow of its own.' );
	}

	public function test_an_id_is_used_only_on_the_account_the_engine_names(): void {
		$store = new EmailDesignWorkflows();
		$store->remember(
			array(
				$this->item( 'plugin.welcome', 'plugin', 'pushed', '456' ),
				$this->item( 'transactional.order_confirmation', 'transactional', 'pushed', '459', 'transactional', 'shopexample-tx' ),
			)
		);

		self::assertSame( 456, $store->workflow_id( 'welcome', 'shopexample' ) );
		self::assertSame( 459, $store->workflow_id( 'order_confirmation', 'shopexample-tx' ) );
		self::assertSame( 0, $store->workflow_id( 'welcome', 'shopexample-tx' ), 'Another account: the store keeps its workflow.' );
		self::assertSame( 0, $store->workflow_id( 'order_confirmation', 'shopexample' ) );
		self::assertSame( 0, $store->workflow_id( 'welcome', '' ), 'An account with no subdomain is not the named one.' );
	}

	public function test_the_account_compares_without_case_or_surrounding_space(): void {
		$store = new EmailDesignWorkflows();
		$store->remember( array( $this->item( 'plugin.welcome', 'plugin', 'pushed', '456', 'workflow', 'ShopExample' ) ) );

		self::assertSame( 456, $store->workflow_id( 'welcome', 'shopexample' ) );
		self::assertSame( 456, $store->workflow_id( 'welcome', ' SHOPEXAMPLE ' ) );
	}

	public function test_an_id_with_no_named_account_is_not_kept(): void {
		$store = new EmailDesignWorkflows();

		$store->remember(
			array(
				$this->item( 'plugin.welcome', 'plugin', 'pushed', '456', 'workflow', null ),
				$this->item( 'plugin.first_order', 'plugin', 'pushed', '457', 'workflow', '' ),
				$this->item( 'plugin.abandoned_cart', 'plugin', 'pushed', '458', 'workflow', 'shop.sendsmaily.net' ),
				$this->item( 'plugin.follow_up', 'plugin', 'pushed', '459', 'workflow', str_repeat( 'a', 65 ) ),
			)
		);
		$without_field = $this->item( 'transactional.order_confirmation', 'transactional', 'pushed', '460', 'transactional' );
		unset( $without_field['smaily_subdomain'] );
		$store->remember( array( $without_field ) );

		self::assertSame( array(), $this->written[ EmailDesignWorkflows::OPTION ][0] );
	}

	public function test_an_id_stored_before_accounts_were_kept_names_no_account(): void {
		$this->written[ EmailDesignWorkflows::OPTION ] = array( array( 'plugin.welcome' => 456 ), false );

		self::assertSame( 0, ( new EmailDesignWorkflows() )->workflow_id( 'welcome', 'shopexample' ) );
	}

	public function test_an_email_not_sent_through_a_smaily_workflow_stores_no_id(): void {
		$store = new EmailDesignWorkflows();

		$store->remember(
			array(
				$this->item( 'transactional.shipping_confirmation', 'transactional', 'pushed', '460', 'workflow ' ),
				$this->item( 'plugin.welcome', 'plugin', 'pushed', '461', 'template' ),
				$this->item( 'plugin.first_order', 'plugin', 'pushed', '462', null ),
			)
		);

		self::assertSame( array(), $this->written[ EmailDesignWorkflows::OPTION ][0] );
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
				$this->item( 'transactional.shipping_confirmation', 'transactional', 'pushed', 'abc', 'transactional' ),
				'not an item',
			)
		);

		foreach ( array_keys( EmailDesignWorkflows::EMAIL_KEYS ) as $trigger ) {
			self::assertSame( 0, $store->workflow_id( $trigger, 'shopexample' ), $trigger );
		}
	}

	public function test_a_new_read_replaces_the_ids_of_the_last_one(): void {
		$store = new EmailDesignWorkflows();
		$store->remember( array( $this->item( 'plugin.welcome', 'plugin', 'pushed', '456' ) ) );

		$store->remember( array( $this->item( 'plugin.welcome', 'plugin', 'pushed', null ) ) );

		self::assertSame( 0, $store->workflow_id( 'welcome', 'shopexample' ) );
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
	private function item( string $email_key, string $kind, string $smaily_state, ?string $workflow_id, ?string $sent_through = 'workflow', ?string $subdomain = 'shopexample' ): array {
		$item = array(
			'email_key'          => $email_key,
			'kind'               => $kind,
			'smaily_state'       => $smaily_state,
			'smaily_template_id' => '999',
			'smaily_workflow_id' => $workflow_id,
			'smaily_subdomain'   => $subdomain,
		);
		if ( $sent_through !== null ) {
			$item['sent_through'] = $sent_through;
		}

		return $item;
	}
}
