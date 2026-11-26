<?php
/**
 * CustomerPayloadBuilder tests — WP_User → rec-engine customer object,
 * email-first identity (lowercased), event_uuid → event_id symmetry, the
 * absent != empty omission policy, and the W4 "no consent" guarantee.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily\RecEngine;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Multilingual\DetectorInterface;
use Smaily\Connect\Smaily\RecEngine\CustomerPayloadBuilder;
use Smaily\Connect\Support\ContactLanguageResolver;

final class CustomerPayloadBuilderTest extends TestCase {

	/** @var array<string, string> get_user_meta fixtures keyed "<user_id>:<meta_key>". */
	private array $user_meta = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->user_meta = array();
		$fixtures        =& $this->user_meta;
		Functions\when( 'get_user_meta' )->alias(
			static function ( int $user_id, string $key ) use ( &$fixtures ) {
				return $fixtures[ $user_id . ':' . $key ] ?? '';
			}
		);
		// No site locale either, so `language` is omitted unless a test
		// gives the resolver a source.
		Functions\when( 'get_locale' )->justReturn( '' );
		// CON-354: the WP profile locale is never a source.
		Functions\expect( 'get_user_locale' )->never();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_build_maps_identity_event_id_and_external_id(): void {
		$user = $this->fake_user( 67, 'Mari@Example.com' );

		$payload = $this->builder()->build( $user, 'evt-uuid-cust-1' );

		self::assertSame( 'evt-uuid-cust-1', $payload['event_id'] );
		self::assertSame( 'mari@example.com', $payload['email'], 'Email is the identity, lowercased.' );
		self::assertSame( '67', $payload['external_id'] );
	}

	public function test_email_is_lowercased_and_trimmed(): void {
		$user = $this->fake_user( 1, '  ALICE@Example.TEST  ' );

		$payload = $this->builder()->build( $user, 'u' );

		self::assertSame( 'alice@example.test', $payload['email'] );
	}

	public function test_optional_fields_omitted_when_source_empty(): void {
		$user = $this->fake_user(
			5,
			'bare@example.test',
			array(
				'first_name'      => '',
				'last_name'       => '',
				'user_registered' => '',
			)
		);

		$payload = $this->builder()->build( $user, 'u' );

		self::assertArrayNotHasKey( 'first_name', $payload );
		self::assertArrayNotHasKey( 'last_name', $payload );
		self::assertArrayNotHasKey( 'country', $payload, 'No WC_Customer in unit env → country absent, not "".' );
		self::assertArrayNotHasKey( 'phone', $payload );
		self::assertArrayNotHasKey( 'language', $payload );
		self::assertArrayNotHasKey( 'first_seen_at', $payload );
	}

	public function test_never_sends_consent(): void {
		$user = $this->fake_user( 5, 'a@example.test' );

		$payload = $this->builder()->build( $user, 'u' );

		self::assertArrayNotHasKey( 'consent', $payload, 'W4 removed consent from the customers contract entirely.' );
	}

	public function test_names_included_and_trimmed_when_present(): void {
		$user = $this->fake_user(
			5,
			'a@example.test',
			array(
				'first_name' => '  Mari ',
				'last_name'  => 'Tamm',
			)
		);

		$payload = $this->builder()->build( $user, 'u' );

		self::assertSame( 'Mari', $payload['first_name'] );
		self::assertSame( 'Tamm', $payload['last_name'] );
	}

	public function test_first_seen_at_serialised_iso8601_utc_with_z_suffix(): void {
		$user = $this->fake_user( 5, 'a@example.test', array( 'user_registered' => '2026-01-15 10:30:00' ) );

		$payload = $this->builder()->build( $user, 'u' );

		// `Z`, not `+00:00` — the engine's strict Zod .datetime() rejects an
		// offset (3.3.4 live-walk caught this).
		self::assertSame( '2026-01-15T10:30:00Z', $payload['first_seen_at'] );
	}

	public function test_first_seen_at_omitted_for_zero_date(): void {
		$user = $this->fake_user( 5, 'a@example.test', array( 'user_registered' => '0000-00-00 00:00:00' ) );

		$payload = $this->builder()->build( $user, 'u' );

		self::assertArrayNotHasKey( 'first_seen_at', $payload );
	}

	public function test_language_is_the_preferred_language_as_a_two_letter_code(): void {
		// CON-354: the same source the Smaily contact sync reads.
		$this->user_meta['5:_user_preferred_language'] = 'en_GB';
		$user = $this->fake_user( 5, 'a@example.test' );

		$payload = $this->builder( 'et', array( 'et', 'en', 'ru' ) )->build( $user, 'u' );

		self::assertSame( 'en', $payload['language'], 'en_GB → en, as the resolver returns it.' );
	}

	public function test_language_falls_back_to_the_latest_order_language(): void {
		$user = $this->fake_user( 5, 'a@example.test' );

		$payload = $this->builder( 'et', array( 'et', 'ru' ), 'ru' )->build( $user, 'u' );

		self::assertSame( 'ru', $payload['language'] );
	}

	public function test_language_is_the_store_default_when_the_customer_has_none(): void {
		$user = $this->fake_user( 5, 'a@example.test' );

		$payload = $this->builder( 'et', array( 'et', 'ru' ) )->build( $user, 'u' );

		self::assertSame( 'et', $payload['language'], 'The multilingual default, as for the Smaily contact.' );
	}

	public function test_language_outside_the_active_languages_is_the_default(): void {
		$this->user_meta['5:_user_preferred_language'] = 'lv';
		$user = $this->fake_user( 5, 'a@example.test' );

		$payload = $this->builder( 'et', array( 'et', 'ru' ) )->build( $user, 'u' );

		self::assertSame( 'et', $payload['language'] );
	}

	public function test_woocommerce_country_and_phone_included_when_present(): void {
		$user    = $this->fake_user( 5, 'a@example.test' );
		$builder = $this->builder_with_wc( 'EE', '+372 555 1234' );

		$payload = $builder->build( $user, 'u' );

		self::assertSame( 'EE', $payload['country'] );
		self::assertSame( '+372 555 1234', $payload['phone'] );
	}

	/**
	 * @param array<string, mixed> $overrides
	 */
	private function fake_user( int $id, string $email, array $overrides = array() ): \WP_User {
		$defaults = array(
			'ID'              => $id,
			'user_email'      => $email,
			'first_name'      => '',
			'last_name'       => '',
			'user_registered' => '2020-01-01 00:00:00',
		);
		$attrs = array_merge( $defaults, $overrides );

		return new class( $attrs ) extends \WP_User {
			// Declared, not created on the fly: a dynamic property is a PHP 8.2+
			// deprecation that marks the test risky wherever notices are shown.
			/** @var string */
			public $user_registered = '';

			/** @param array<string, mixed> $attrs */
			public function __construct( array $attrs ) {
				foreach ( $attrs as $k => $v ) {
					$this->{$k} = $v;
				}
			}
		};
	}

	/**
	 * A builder whose language resolver reads a fake multilingual plugin
	 * (default + active languages) and a fake latest-order language. The
	 * defaults resolve nothing, so `language` is left out.
	 *
	 * @param array<int, string> $active
	 */
	private function builder( string $default_language = '', array $active = array(), string $order_language = '' ): CustomerPayloadBuilder {
		return new CustomerPayloadBuilder( $this->resolver( $default_language, $active, $order_language ) );
	}

	/**
	 * @param array<int, string> $active
	 */
	private function resolver( string $default_language, array $active, string $order_language ): ContactLanguageResolver {
		$detector = $this->createMock( DetectorInterface::class );
		$detector->method( 'get_default_language' )->willReturn( $default_language );
		$detector->method( 'get_detected_languages' )->willReturn( $active );

		return new ContactLanguageResolver(
			$detector,
			static function () use ( $order_language ): string {
				return $order_language;
			}
		);
	}

	/**
	 * A builder with stubbed WooCommerce billing fields — the unit env has no
	 * WC_Customer, so the country/phone seam is overridden directly.
	 */
	private function builder_with_wc( string $country, string $phone ): CustomerPayloadBuilder {
		return new class( $country, $phone, $this->resolver( '', array(), '' ) ) extends CustomerPayloadBuilder {
			private string $country;
			private string $phone;

			public function __construct( string $country, string $phone, ContactLanguageResolver $resolver ) {
				parent::__construct( $resolver );
				$this->country = $country;
				$this->phone   = $phone;
			}

			/** @return array{country: string, phone: string} */
			protected function woocommerce_fields( \WP_User $user ): array {
				return array(
					'country' => $this->country,
					'phone'   => $this->phone,
				);
			}
		};
	}
}
