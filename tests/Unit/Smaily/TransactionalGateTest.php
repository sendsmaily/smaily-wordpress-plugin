<?php
/**
 * TransactionalGate tests (PRO-1504 Stage 2) — the shared four-condition
 * gate reused by the WC hook handler AND the native-email suppression
 * filters, so they can never drift apart.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Settings\Credentials;
use Smaily\Connect\Settings\CredentialSet;
use Smaily\Connect\Smaily\TransactionalGate;
use Smaily\Connect\Smaily\WorkflowMatch;
use Smaily\Connect\Smaily\WorkflowResolverInterface;

final class TransactionalGateTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = array(
			'smly_plus_transactional_emails_enabled' => true,
			'smly_plus_order_confirmation_enabled'   => true,
			'smly_plus_shipping_confirmation_enabled' => true,
		);

		$opts = &$this->options;
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $fallback = false ) use ( &$opts ) {
				return $opts[ $key ] ?? $fallback;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_all_four_conditions_open_resolves_the_match(): void {
		$gate = new TransactionalGate(
			$this->credentials_with( new CredentialSet( 'sub', 'user', 'pass' ) ),
			$this->resolver_returning( new WorkflowMatch( 4242, 'transactional' ) )
		);

		$match = $gate->resolve_if_open( TransactionalGate::TRIGGER_ORDER_CONFIRMATION );

		self::assertNotNull( $match );
		self::assertSame( 4242, $match->workflow_id );
	}

	public function test_master_toggle_off_closes_the_gate(): void {
		$this->options['smly_plus_transactional_emails_enabled'] = false;

		$gate = new TransactionalGate(
			$this->credentials_with( new CredentialSet( 'sub', 'user', 'pass' ) ),
			$this->resolver_returning( new WorkflowMatch( 1, 'transactional' ) )
		);

		self::assertNull( $gate->resolve_if_open( TransactionalGate::TRIGGER_ORDER_CONFIRMATION ) );
	}

	public function test_per_trigger_toggle_off_closes_only_that_trigger(): void {
		$this->options['smly_plus_shipping_confirmation_enabled'] = false;

		$gate = new TransactionalGate(
			$this->credentials_with( new CredentialSet( 'sub', 'user', 'pass' ) ),
			$this->resolver_returning( new WorkflowMatch( 1, 'transactional' ) )
		);

		self::assertNull( $gate->resolve_if_open( TransactionalGate::TRIGGER_SHIPPING_CONFIRMATION ) );
		self::assertNotNull( $gate->resolve_if_open( TransactionalGate::TRIGGER_ORDER_CONFIRMATION ), 'order_confirmation stays open — the toggles are independent.' );
	}

	public function test_no_mapping_row_closes_the_gate(): void {
		$gate = new TransactionalGate(
			$this->credentials_with( new CredentialSet( 'sub', 'user', 'pass' ) ),
			$this->resolver_returning( null )
		);

		self::assertNull( $gate->resolve_if_open( TransactionalGate::TRIGGER_ORDER_CONFIRMATION ) );
	}

	public function test_incomplete_credentials_close_the_gate(): void {
		$gate = new TransactionalGate(
			$this->credentials_with( new CredentialSet( '', '', '' ) ),
			$this->resolver_returning( new WorkflowMatch( 1, 'transactional' ) )
		);

		self::assertNull( $gate->resolve_if_open( TransactionalGate::TRIGGER_ORDER_CONFIRMATION ) );
	}

	public function test_missing_credentials_close_the_gate(): void {
		$gate = new TransactionalGate(
			$this->credentials_with( null ),
			$this->resolver_returning( new WorkflowMatch( 1, 'transactional' ) )
		);

		self::assertNull( $gate->resolve_if_open( TransactionalGate::TRIGGER_ORDER_CONFIRMATION ) );
	}

	public function test_resolver_is_queried_with_null_language_this_account_has_no_per_language_variant(): void {
		$captured = array();
		$resolver = new class( $captured ) implements WorkflowResolverInterface {
			public array $calls = array();
			public function __construct( array $calls ) {
				$this->calls = $calls;
			}
			public function resolve_workflow( string $trigger_type, ?string $language ): ?WorkflowMatch {
				$this->calls[] = array( $trigger_type, $language );
				return new WorkflowMatch( 1, 'transactional' );
			}
		};

		( new TransactionalGate( $this->credentials_with( new CredentialSet( 's', 'u', 'p' ) ), $resolver ) )
			->resolve_if_open( TransactionalGate::TRIGGER_SHIPPING_CONFIRMATION );

		self::assertSame( array( array( 'shipping_confirmation', null ) ), $resolver->calls );
	}

	// --- helpers -------------------------------------------------------------

	private function resolver_returning( ?WorkflowMatch $match ): WorkflowResolverInterface {
		return new class( $match ) implements WorkflowResolverInterface {
			private ?WorkflowMatch $match;
			public function __construct( ?WorkflowMatch $match ) {
				$this->match = $match;
			}
			public function resolve_workflow( string $trigger_type, ?string $language ): ?WorkflowMatch {
				return $this->match;
			}
		};
	}

	private function credentials_with( ?CredentialSet $set ): Credentials {
		return new class( $set ) extends Credentials {
			private ?CredentialSet $set;
			public function __construct( ?CredentialSet $set ) {
				$this->set = $set;
			}
			public function get( string $account_key = self::DEFAULT_ACCOUNT_KEY ): ?CredentialSet {
				return $this->set;
			}
		};
	}
}
