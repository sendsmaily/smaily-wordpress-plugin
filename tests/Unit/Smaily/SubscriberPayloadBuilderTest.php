<?php
/**
 * Tests for SubscriberPayloadBuilder — single source of truth for
 * WP user → Smaily contact-payload mapping.
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Smaily;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Smaily\SubscriberPayloadBuilder;

final class SubscriberPayloadBuilderTest extends TestCase {

	/** @var array<string, array<string, string>> */
	private array $user_meta_fixtures = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'get_site_url' )->justReturn( 'http://example.test' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Example Shop' );
		Functions\when( 'get_option' )->justReturn( null );

		// get_user_meta lookups dispatch into per-test fixtures keyed
		// by "<user_id>:<meta_key>". Tests set entries via
		// $this->seed_meta() before running build().
		$fixtures =& $this->user_meta_fixtures;
		Functions\when( 'get_user_meta' )->alias(
			static function ( int $user_id, string $key, bool $single = false ) use ( &$fixtures ) {
				$bucket_key = $user_id . ':' . $key;
				return $fixtures[ $bucket_key ] ?? '';
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
		$this->user_meta_fixtures = array();
	}

	public function test_email_and_store_always_present_even_without_opt_ins(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$user = $this->fake_user( 42, 'alice@example.test' );

		$payload = ( new SubscriberPayloadBuilder() )->build( $user );

		self::assertSame( 'alice@example.test', $payload['email'] );
		self::assertSame( 'http://example.test', $payload['store'] );
		self::assertArrayNotHasKey( 'first_name', $payload );
	}

	public function test_opt_in_fields_map_to_canonical_smaily_names(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'first_name',
				'last_name',
				'nickname',
				'user_phone',
				'customer_id',
				'customer_group',
				'first_registered',
				'site_title',
			)
		);

		$user = $this->fake_user(
			42,
			'alice@example.test',
			array(
				'first_name'      => 'Alice',
				'last_name'       => 'Smith',
				'nickname'        => 'al',
				'roles'           => array( 'customer', 'subscriber' ),
				'user_registered' => '2024-01-15 12:00:00',
			)
		);
		$this->seed_meta( 42, 'user_phone', '+372 555 12345' );

		$payload = ( new SubscriberPayloadBuilder() )->build( $user );

		self::assertSame( 'Alice', $payload['first_name'] );
		self::assertSame( 'Smith', $payload['last_name'] );
		self::assertSame( 'al', $payload['nickname'] );
		self::assertSame( '+372 555 12345', $payload['user_phone'] );
		self::assertSame( '42', $payload['customer_id'] );
		self::assertSame( 'customer', $payload['customer_group'] );
		self::assertSame( '2024-01-15 12:00:00', $payload['first_registered'] );
		self::assertSame( 'Example Shop', $payload['site_title'] );
	}

	public function test_gender_enum_transforms_zero_to_female(): void {
		Functions\when( 'get_option' )->justReturn( array( 'user_gender' ) );

		$user = $this->fake_user( 42, 'alice@example.test' );
		$this->seed_meta( 42, 'user_gender', '0' );

		$payload = ( new SubscriberPayloadBuilder() )->build( $user );

		self::assertSame( 'Female', $payload['user_gender'] );
	}

	public function test_gender_enum_transforms_non_zero_to_male(): void {
		Functions\when( 'get_option' )->justReturn( array( 'user_gender' ) );

		$user = $this->fake_user( 42, 'alice@example.test' );
		$this->seed_meta( 42, 'user_gender', '1' );

		$payload = ( new SubscriberPayloadBuilder() )->build( $user );

		self::assertSame( 'Male', $payload['user_gender'] );
	}

	public function test_birthday_normalises_to_yyyy_mm_dd(): void {
		Functions\when( 'get_option' )->justReturn( array( 'birthday' ) );

		$user = $this->fake_user( 42, 'alice@example.test' );
		$this->seed_meta( 42, 'user_dob', '2014-03-28' );

		$payload = ( new SubscriberPayloadBuilder() )->build( $user );

		self::assertSame( '2014-03-28', $payload['birthday'] );
	}

	public function test_birthday_with_unparseable_value_is_dropped(): void {
		Functions\when( 'get_option' )->justReturn( array( 'birthday' ) );

		$user = $this->fake_user( 42, 'alice@example.test' );
		$this->seed_meta( 42, 'user_dob', 'definitely-not-a-date' );

		$payload = ( new SubscriberPayloadBuilder() )->build( $user );

		self::assertArrayNotHasKey( 'birthday', $payload );
	}

	public function test_unknown_field_names_in_option_are_silently_dropped(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'first_name', 'totally_made_up_field' )
		);

		$user = $this->fake_user( 42, 'alice@example.test', array( 'first_name' => 'Alice' ) );

		$payload = ( new SubscriberPayloadBuilder() )->build( $user );

		self::assertSame( 'Alice', $payload['first_name'] );
		self::assertArrayNotHasKey( 'totally_made_up_field', $payload );
	}

	public function test_empty_source_value_omits_the_field_from_payload(): void {
		Functions\when( 'get_option' )->justReturn( array( 'first_name', 'last_name', 'user_phone' ) );

		$user = $this->fake_user( 42, 'alice@example.test', array( 'first_name' => 'Alice' ) );
		// last_name not seeded → empty string. user_phone meta not seeded → empty.

		$payload = ( new SubscriberPayloadBuilder() )->build( $user );

		self::assertSame( 'Alice', $payload['first_name'] );
		self::assertArrayNotHasKey( 'last_name', $payload, 'Empty values must be omitted, not sent as empty strings.' );
		self::assertArrayNotHasKey( 'user_phone', $payload );
	}

	public function test_null_stored_option_defaults_to_all_supported_fields(): void {
		// get_option returns null when the merchant never saved — falls
		// back to the documented merchant-friendly default.
		Functions\when( 'get_option' )->justReturn( null );

		$user = $this->fake_user( 42, 'alice@example.test', array( 'first_name' => 'Alice' ) );

		$payload = ( new SubscriberPayloadBuilder() )->build( $user );

		// All cross-channel fields available; only those with non-empty
		// source values land in the payload.
		self::assertSame( 'Alice', $payload['first_name'] );
		self::assertSame( '42', $payload['customer_id'] );
	}

	public function test_a_selection_saved_under_the_pre_fix_wizard_names_still_syncs(): void {
		// What every store that used the wizard before PRO-1683 has on
		// disk: the checkbox list wrote `phone`/`gender`, which this
		// builder never recognised — both were dropped before the send.
		Functions\when( 'get_option' )->justReturn( array( 'first_name', 'phone', 'gender' ) );

		$user = $this->fake_user( 42, 'alice@example.test', array( 'first_name' => 'Alice' ) );
		$this->seed_meta( 42, 'user_phone', '+372 555 12345' );
		$this->seed_meta( 42, 'user_gender', '0' );

		$payload = ( new SubscriberPayloadBuilder() )->build( $user );

		self::assertSame( '+372 555 12345', $payload['user_phone'] );
		self::assertSame( 'Female', $payload['user_gender'] );
		self::assertArrayNotHasKey( 'phone', $payload, 'The alias only translates the stored selection — the wire names never change.' );
		self::assertArrayNotHasKey( 'gender', $payload );
	}

	/**
	 * The legacy settings page stored the selection as a MAP of every known
	 * field => bool. Read as a list of names it matches nothing, which is why
	 * an upgraded store silently synced no optional field at all (PRO-1684).
	 */
	public function test_a_selection_saved_by_the_legacy_settings_page_honours_its_values(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'store_url'        => true,
				'user_email'       => true,
				'language'         => true,
				'customer_group'   => false,
				'customer_id'      => false,
				'first_name'       => true,
				'first_registered' => false,
				'last_name'        => false,
				'nickname'         => false,
				'site_title'       => false,
				'user_dob'         => true,
				'user_gender'      => true,
				'user_phone'       => true,
			)
		);

		$user = $this->fake_user( 42, 'alice@example.test', array( 'first_name' => 'Alice' ) );
		$this->seed_meta( 42, 'user_phone', '+372 555 12345' );
		$this->seed_meta( 42, 'user_gender', '0' );
		$this->seed_meta( 42, 'user_dob', '1984-02-24' );

		$payload = ( new SubscriberPayloadBuilder() )->build( $user );

		self::assertSame( 'Alice', $payload['first_name'] );
		self::assertSame( '+372 555 12345', $payload['user_phone'] );
		self::assertSame( 'Female', $payload['user_gender'] );
		self::assertSame( '1984-02-24', $payload['birthday'], 'The legacy `user_dob` key is the `birthday` field.' );
		self::assertArrayNotHasKey( 'customer_id', $payload, 'A legacy false is a real "do not send this".' );
		self::assertArrayNotHasKey( 'store_url', $payload, 'Legacy keys with no toggle equivalent never become fields.' );
		self::assertArrayNotHasKey( 'user_dob', $payload );
	}

	/**
	 * @dataProvider provide_stored_selections
	 *
	 * @param mixed                  $stored
	 * @param array<int, string>|null $expected
	 */
	public function test_interpret_selection_reads_both_shapes_and_admits_when_it_cannot( $stored, ?array $expected ): void {
		self::assertSame( $expected, SubscriberPayloadBuilder::interpret_selection( $stored ) );
	}

	/**
	 * @return array<string, array{0: mixed, 1: array<int, string>|null}>
	 */
	public static function provide_stored_selections(): array {
		return array(
			'wizard list'                 => array( array( 'first_name', 'user_phone' ), array( 'first_name', 'user_phone' ) ),
			'pre-PRO-1683 wizard list'    => array( array( 'phone', 'gender' ), array( 'user_phone', 'user_gender' ) ),
			'empty list is a real answer' => array( array(), array() ),
			'unknown name is dropped'     => array( array( 'first_name', 'made_up' ), array( 'first_name' ) ),
			'legacy map'                  => array(
				array(
					'user_email' => true,
					'first_name' => true,
					'user_dob'   => true,
					'last_name'  => false,
				),
				array( 'first_name', 'birthday' ),
			),
			'legacy map, all off'         => array(
				array(
					'user_email' => true,
					'first_name' => false,
				),
				array(),
			),
			'unknown key inside a legacy map is ignored' => array(
				array(
					'first_name'     => true,
					'some_other_key' => true,
				),
				array( 'first_name' ),
			),
			'never saved'                 => array( null, null ),
			'a scalar'                    => array( 'first_name', null ),
			'a map of nothing we know'    => array( array( 'lorem' => 'ipsum' ), null ),
			'a list of booleans'          => array( array( true, false ), null ),
		);
	}

	public function test_a_never_saved_selection_is_not_reported_as_unreadable(): void {
		Functions\when( 'get_option' )->justReturn( null );

		self::assertFalse(
			SubscriberPayloadBuilder::selection_unreadable(),
			'A fresh install must not nag the merchant about a setting they never touched.'
		);
	}

	public function test_a_selection_in_no_known_shape_is_reported_as_unreadable(): void {
		Functions\when( 'get_option' )->justReturn( array( 'lorem' => 'ipsum' ) );

		self::assertTrue( SubscriberPayloadBuilder::selection_unreadable() );
	}

	/**
	 * The legacy readers that print the merchant's profile/account/checkout
	 * inputs speak the pre-wizard key names, so they get the same answer in
	 * their own vocabulary rather than a second translation table (PRO-1743).
	 */
	public function test_a_wizard_selection_is_offered_to_the_legacy_readers_under_their_own_key_names(): void {
		Functions\when( 'get_option' )->justReturn( array( 'user_phone', 'user_gender', 'birthday' ) );

		$keys = SubscriberPayloadBuilder::effective_selection_legacy_keys();

		self::assertContains( 'user_phone', $keys );
		self::assertContains( 'user_gender', $keys );
		self::assertContains( 'user_dob', $keys, 'The form field is `user_dob`; the contact field is `birthday`.' );
		self::assertNotContains( 'first_name', $keys, 'An unticked box stays unticked for the form readers too.' );
		self::assertNotContains( 'birthday', $keys, 'The legacy readers never saw a `birthday` key.' );
	}

	public function test_the_legacy_readers_always_get_the_keys_that_are_not_a_choice(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$keys = SubscriberPayloadBuilder::effective_selection_legacy_keys();

		self::assertSame(
			array( 'store_url', 'user_email', 'language' ),
			$keys,
			'Email, store and language are sent regardless — the legacy settings page forced all three on as well.'
		);
	}

	public function test_a_legacy_selection_reaches_the_legacy_readers_unchanged(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'store_url'   => true,
				'user_email'  => true,
				'language'    => true,
				'customer_id' => false,
				'first_name'  => true,
				'user_dob'    => true,
				'user_gender' => false,
				'user_phone'  => true,
			)
		);

		self::assertEqualsCanonicalizing(
			array( 'store_url', 'user_email', 'language', 'first_name', 'user_dob', 'user_phone' ),
			SubscriberPayloadBuilder::effective_selection_legacy_keys(),
			'A store configured before the wizard must get exactly the answer it always got.'
		);
	}

	/**
	 * The drift PRO-1683 fixed: the wizard's checkbox list is what the
	 * merchant's selection is saved as, so every name in it must be a name
	 * this builder supports. A name only the wizard knows is silently
	 * discarded — no error, no notice, the tick simply does nothing.
	 */
	public function test_the_wizard_checkbox_list_only_uses_names_the_builder_supports(): void {
		$source = (string) file_get_contents( SMAILY_CONNECT_PLUGIN_PATH . 'admin/src/state/types.ts' );

		$matched = preg_match( '/export const DEFAULT_SYNC_FIELDS = \[(.*?)\]/s', $source, $matches );
		self::assertSame( 1, $matched, 'DEFAULT_SYNC_FIELDS must stay findable in admin/src/state/types.ts.' );

		preg_match_all( "/'([a-z_]+)'/", $matches[1], $names );
		$wizard_fields = $names[1];
		self::assertNotEmpty( $wizard_fields );

		$supported = ( new \ReflectionClassConstant( SubscriberPayloadBuilder::class, 'SUPPORTED_FIELDS' ) )->getValue();

		self::assertSame(
			array(),
			array_diff( $wizard_fields, (array) $supported ),
			'Every wizard checkbox must map to a SubscriberPayloadBuilder field, or ticking it does nothing.'
		);
	}

	public function test_build_fields_wraps_the_same_set_without_email(): void {
		Functions\when( 'get_option' )->justReturn( array( 'first_name' ) );

		$user = $this->fake_user( 42, 'alice@example.test', array( 'first_name' => 'Alice' ) );

		$fields = ( new SubscriberPayloadBuilder() )->build_fields( $user );

		self::assertSame( 'http://example.test', $fields['store'] );
		self::assertSame( 'Alice', $fields['first_name'] );
		self::assertArrayNotHasKey( 'email', $fields, 'build_fields() is the nested-shape used by HookHandler; email lives one level up.' );
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
			'nickname'        => '',
			'roles'           => array(),
			'user_registered' => '',
		);
		$attrs = array_merge( $defaults, $overrides );

		return new class( $attrs ) extends \WP_User {
			/** @param array<string, mixed> $attrs */
			public function __construct( array $attrs ) {
				foreach ( $attrs as $k => $v ) {
					$this->{$k} = $v;
				}
			}
		};
	}

	private function seed_meta( int $user_id, string $key, string $value ): void {
		$this->user_meta_fixtures[ $user_id . ':' . $key ] = $value;
	}
}
