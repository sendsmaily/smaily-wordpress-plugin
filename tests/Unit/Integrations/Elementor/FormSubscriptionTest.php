<?php
/**
 * FormSubscription — what an Elementor Pro form submission sends to Smaily
 * (PRO-3806).
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Integrations\Elementor;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Integrations\Elementor\FormSubscription;
use Smaily\Connect\Smaily\ApiException;
use Smaily\Connect\Smaily\Client;

final class FormSubscriptionTest extends TestCase {

	/** @var array<string, array{value: mixed, ttl: int}> */
	private array $transients = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->transients = array();

		Functions\when( 'is_email' )->alias(
			static fn ( string $email ) => filter_var( $email, FILTER_VALIDATE_EMAIL ) !== false ? $email : false
		);
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $value ): string => trim( strip_tags( (string) $value ) ) );
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'get_transient' )->alias(
			fn ( string $key ) => $this->transients[ $key ]['value'] ?? false
		);
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value, int $ttl = 0 ): bool {
				$this->transients[ $key ] = array(
					'value' => $value,
					'ttl'   => $ttl,
				);
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_newsletter_signup_sends_only_the_mapped_fields_with_an_explicit_subscribe(): void {
		$client = $this->fake_client();

		$outcome = $this->subscription( $client )->submit(
			$this->settings(
				array(
					'smaily_name_field' => 'name',
					'smaily_fields'     => array(
						array(
							'form_field'   => 'city',
							'smaily_field' => 'city',
						),
					),
				)
			),
			array(
				'email'   => 'visitor@example.test',
				'name'    => 'Mari',
				'city'    => 'Tartu',
				'message' => 'A private note that must never reach Smaily',
				'phone'   => '+372 5555 5555',
			),
			'https://shop.example.test/contact/'
		);

		self::assertSame( FormSubscription::OUTCOME_SUBSCRIBED, $outcome );
		self::assertCount( 1, $client->upserts );
		$contact = $client->upserts[0][0];
		$sent_at = $contact['elementor_form_submitted_at'];
		unset( $contact['elementor_form_submitted_at'] );
		self::assertSame(
			array(
				'email'               => 'visitor@example.test',
				'is_unsubscribed'     => 0,
				'name'                => 'Mari',
				'city'                => 'Tartu',
				'elementor_form_name' => 'Newsletter',
				'elementor_form_url'  => 'https://shop.example.test/contact/',
			),
			$contact,
			'Only the mapped fields, the explicit subscribe and the source fields are sent — never the whole submission.'
		);
		self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $sent_at );
		self::assertSame( array(), $client->triggers, 'No workflow is configured, so none is triggered.' );
	}

	public function test_contact_mode_without_a_ticked_consent_field_sends_nothing(): void {
		$factory_calls = 0;
		$subscription  = new FormSubscription(
			static function () use ( &$factory_calls ): Client {
				++$factory_calls;
				throw new \RuntimeException( 'must not be reached' );
			}
		);

		$settings = $this->settings(
			array(
				'smaily_mode'          => FormSubscription::MODE_CONTACT,
				'smaily_consent_field' => 'marketing',
			)
		);

		self::assertSame(
			FormSubscription::OUTCOME_SKIPPED,
			$subscription->submit( $settings, array( 'email' => 'visitor@example.test', 'marketing' => '' ), '' )
		);
		self::assertSame(
			FormSubscription::OUTCOME_SKIPPED,
			$subscription->submit( $settings, array( 'email' => 'visitor@example.test' ), '' ),
			'A consent field missing from the submission is not consent.'
		);
		self::assertSame( 0, $factory_calls );
	}

	public function test_contact_mode_without_a_configured_consent_field_sends_nothing(): void {
		$client = $this->fake_client();

		$outcome = $this->subscription( $client )->submit(
			$this->settings( array( 'smaily_mode' => FormSubscription::MODE_CONTACT ) ),
			array(
				'email'     => 'visitor@example.test',
				'marketing' => 'on',
			),
			''
		);

		self::assertSame( FormSubscription::OUTCOME_SKIPPED, $outcome );
		self::assertSame( array(), $client->upserts );
	}

	public function test_contact_mode_with_a_ticked_consent_field_subscribes(): void {
		$client = $this->fake_client();

		$outcome = $this->subscription( $client )->submit(
			$this->settings(
				array(
					'smaily_mode'          => FormSubscription::MODE_CONTACT,
					'smaily_consent_field' => 'marketing',
				)
			),
			array(
				'email'     => 'visitor@example.test',
				'marketing' => 'on',
			),
			''
		);

		self::assertSame( FormSubscription::OUTCOME_SUBSCRIBED, $outcome );
		self::assertSame( 0, $client->upserts[0][0]['is_unsubscribed'] );
		self::assertArrayNotHasKey( 'marketing', $client->upserts[0][0], 'The consent box itself is not a contact field.' );
	}

	public function test_an_invalid_or_missing_email_sends_nothing(): void {
		$client       = $this->fake_client();
		$subscription = $this->subscription( $client );

		self::assertSame(
			FormSubscription::OUTCOME_INVALID_EMAIL,
			$subscription->submit( $this->settings(), array( 'email' => 'not-an-email' ), '' )
		);
		self::assertSame(
			FormSubscription::OUTCOME_INVALID_EMAIL,
			$subscription->submit( $this->settings(), array( 'name' => 'Mari' ), '' )
		);
		self::assertSame(
			FormSubscription::OUTCOME_INVALID_EMAIL,
			$subscription->submit( $this->settings( array( 'smaily_email_field' => '' ) ), array( 'email' => 'visitor@example.test' ), '' )
		);
		self::assertSame( array(), $client->upserts );
	}

	public function test_the_email_is_trimmed_and_lowercased(): void {
		$client = $this->fake_client();

		$this->subscription( $client )->submit( $this->settings(), array( 'email' => '  Visitor@Example.TEST ' ), '' );

		self::assertSame( 'visitor@example.test', $client->upserts[0][0]['email'] );
	}

	public function test_custom_field_mappings_cannot_overwrite_the_reserved_fields_and_skip_empty_values(): void {
		$client = $this->fake_client();

		$this->subscription( $client )->submit(
			$this->settings(
				array(
					'smaily_fields' => array(
						array(
							'form_field'   => 'other_email',
							'smaily_field' => 'email',
						),
						array(
							'form_field'   => 'flag',
							'smaily_field' => 'is_unsubscribed',
						),
						array(
							'form_field'   => 'flag',
							'smaily_field' => 'elementor_form_name',
						),
						array(
							'form_field'   => 'city',
							'smaily_field' => 'Not a field!',
						),
						array(
							'form_field'   => 'company',
							'smaily_field' => ' Company ',
						),
						array(
							'form_field'   => 'empty',
							'smaily_field' => 'empty',
						),
					),
				)
			),
			array(
				'email'       => 'visitor@example.test',
				'other_email' => 'someone-else@example.test',
				'flag'        => '1',
				'city'        => 'Tartu',
				'company'     => 'Acme',
				'empty'       => '',
			),
			''
		);

		$contact = $client->upserts[0][0];
		self::assertSame( 'visitor@example.test', $contact['email'] );
		self::assertSame( 0, $contact['is_unsubscribed'] );
		self::assertSame( 'Newsletter', $contact['elementor_form_name'] );
		self::assertSame( 'Acme', $contact['company'] );
		self::assertArrayNotHasKey( 'not_a_field', $contact );
		self::assertArrayNotHasKey( 'Not a field!', $contact );
		self::assertArrayNotHasKey( 'empty', $contact, 'An empty value is left out — Smaily would wipe the stored one.' );
	}

	public function test_a_refused_subscriber_write_fails_and_triggers_no_workflow(): void {
		$client               = $this->fake_client();
		$client->upsert_throw = new ApiException( 'Smaily API returned HTTP 401 for POST contact', 401 );

		$subscription = $this->subscription( $client );
		$outcome      = $subscription->submit( $this->settings( array( 'smaily_workflow_id' => '42' ) ), array( 'email' => 'visitor@example.test' ), '' );

		self::assertSame( FormSubscription::OUTCOME_FAILED, $outcome );
		self::assertStringContainsString( 'HTTP 401', $subscription->error() );
		self::assertStringNotContainsString( 'secret-password', $subscription->error() );
		self::assertSame( array(), $client->triggers );
	}

	public function test_a_smaily_refusal_code_on_http_200_fails(): void {
		$client              = $this->fake_client();
		$client->upsert_body = array(
			'code'    => 203,
			'message' => 'Invalid data submitted',
		);

		$subscription = $this->subscription( $client );
		$outcome      = $subscription->submit( $this->settings( array( 'smaily_workflow_id' => '42' ) ), array( 'email' => 'visitor@example.test' ), '' );

		self::assertSame( FormSubscription::OUTCOME_FAILED, $outcome );
		self::assertStringContainsString( '203', $subscription->error() );
		self::assertSame( array(), $client->triggers );
	}

	public function test_unconfigured_credentials_fail(): void {
		$subscription = new FormSubscription(
			static function (): Client {
				throw new \RuntimeException( 'Smaily credentials are not configured for account "default"' );
			}
		);

		self::assertSame(
			FormSubscription::OUTCOME_FAILED,
			$subscription->submit( $this->settings(), array( 'email' => 'visitor@example.test' ), '' )
		);
	}

	public function test_a_configured_workflow_is_triggered_after_the_subscriber_write(): void {
		$client = $this->fake_client();

		$this->subscription( $client )->submit(
			$this->settings( array( 'smaily_workflow_id' => '42' ) ),
			array( 'email' => 'visitor@example.test' ),
			''
		);

		self::assertSame(
			array(
				array(
					'workflow_id'  => 42,
					'addresses'    => array( array( 'email' => 'visitor@example.test' ) ),
					'force_opt_in' => false,
				),
			),
			$client->triggers
		);
	}

	public function test_a_double_submit_triggers_the_workflow_once(): void {
		$client       = $this->fake_client();
		$subscription = $this->subscription( $client );
		$settings     = $this->settings( array( 'smaily_workflow_id' => '42' ) );

		$subscription->submit( $settings, array( 'email' => 'visitor@example.test' ), '' );
		$second = $subscription->submit( $settings, array( 'email' => 'Visitor@example.test' ), '' );

		self::assertSame( FormSubscription::OUTCOME_SUBSCRIBED, $second );
		self::assertCount( 1, $client->triggers );
		self::assertCount( 1, $this->transients );
		$ttl = array_values( $this->transients )[0]['ttl'];
		self::assertGreaterThan( 0, $ttl, 'A no-expiry transient is an autoloaded option forever.' );
		self::assertLessThanOrEqual( 10 * MINUTE_IN_SECONDS, $ttl );

		$subscription->submit( $this->settings( array( 'smaily_workflow_id' => '42', 'id' => 'other' ) ), array( 'email' => 'visitor@example.test' ), '' );
		self::assertCount( 2, $client->triggers, 'Another form is a separate signup.' );
	}

	public function test_a_failed_workflow_trigger_still_counts_as_a_signup(): void {
		$client                = $this->fake_client();
		$client->trigger_throw = new ApiException( 'Smaily API returned HTTP 500 for POST autoresponder', 500 );

		self::assertSame(
			FormSubscription::OUTCOME_SUBSCRIBED,
			$this->subscription( $client )->submit( $this->settings( array( 'smaily_workflow_id' => '42' ) ), array( 'email' => 'visitor@example.test' ), '' )
		);

		$client                = $this->fake_client();
		$client->trigger_body  = array( 'code' => 221 );
		self::assertSame(
			FormSubscription::OUTCOME_SUBSCRIBED,
			$this->subscription( $client )->submit( $this->settings( array( 'smaily_workflow_id' => '43' ) ), array( 'email' => 'visitor@example.test' ), '' )
		);
	}

	public function test_workflows_list_the_active_workflows_and_cache_them_briefly(): void {
		$client            = $this->fake_client();
		$client->workflows = array(
			array(
				'id'   => 42,
				'name' => 'Welcome series',
			),
			array( 'id' => 43 ),
		);
		$subscription = $this->subscription( $client );

		$workflows = $subscription->workflows();
		$subscription->workflows();

		self::assertSame( array( '42' => 'Welcome series' ), $workflows );
		self::assertSame( 1, $client->list_calls, 'The second editor load reads the cache.' );
		$ttl = array_values( $this->transients )[0]['ttl'];
		self::assertGreaterThan( 0, $ttl );
	}

	/**
	 * @param array<string, mixed> $overrides
	 *
	 * @return array<string, mixed>
	 */
	private function settings( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'                 => 'a1b2c3d',
				'form_name'          => 'Newsletter',
				'smaily_mode'        => FormSubscription::MODE_NEWSLETTER,
				'smaily_email_field' => 'email',
			),
			$overrides
		);
	}

	private function subscription( Client $client ): FormSubscription {
		return new FormSubscription( static fn (): Client => $client );
	}

	private function fake_client(): Client {
		return new class() extends Client {
			/** @var array<int, array<int, array<string, mixed>>> */
			public array $upserts = array();
			/** @var array<int, array<string, mixed>> */
			public array $triggers = array();
			/** @var array<string, mixed> */
			public array $upsert_body = array( 'code' => 101 );
			/** @var array<string, mixed> */
			public array $trigger_body = array( 'code' => 101 );
			public ?ApiException $upsert_throw = null;
			public ?ApiException $trigger_throw = null;
			/** @var array<int, array<string, mixed>> */
			public array $workflows = array();
			public int $list_calls = 0;

			public function __construct() {
				parent::__construct( 'demo', 'user', 'secret-password' );
			}

			public function upsert_subscribers( array $subscribers ): array {
				$this->upserts[] = $subscribers;
				if ( $this->upsert_throw !== null ) {
					throw $this->upsert_throw;
				}
				return $this->upsert_body;
			}

			public function trigger_automation( int $workflow_id, array $addresses, bool $force_opt_in = false ): array {
				$this->triggers[] = compact( 'workflow_id', 'addresses', 'force_opt_in' );
				if ( $this->trigger_throw !== null ) {
					throw $this->trigger_throw;
				}
				return $this->trigger_body;
			}

			public function list_autoresponders(): array {
				++$this->list_calls;
				return $this->workflows;
			}
		};
	}
}
