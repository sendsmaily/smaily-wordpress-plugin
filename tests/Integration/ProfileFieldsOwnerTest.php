<?php
/**
 * Integration: the plugin's profile fields show one user's values only to
 * that user and to someone who may edit them (CON-343).
 *
 * The account details form and the wp-admin user profile used to read the
 * user to show from the `user_id` in the page address, so a logged-in shopper
 * could load another customer's newsletter status (and Phone, Gender and
 * Birthday where a developer showed them) on their own Account details page,
 * and a wp-admin user could load another user's values on their own profile.
 * The account details form now always shows the current user's values, and
 * the wp-admin profile the user WordPress opened the screen for.
 *
 * These cases fire the real WooCommerce and WordPress form hooks on a real
 * install, with a user id of someone else in the request.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;
use Smaily_Connect\Includes\Options;
use Smaily_Connect\Integrations\WooCommerce\Profile_Settings;

final class ProfileFieldsOwnerTest extends TestCase {

	private const PROFILE_FIELDS = array( 'user_gender', 'user_phone', 'user_dob' );

	private int $admin_id = 0;

	private int $shopper_id = 0;

	private int $contributor_id = 0;

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();
		RestRequestHelper::login_as_admin();

		// The plugin's own instance caches its field list for the process;
		// each case registers a fresh one (see SubscriberFieldFormsTest).
		$this->forget_hooks( Profile_Settings::class );

		$response = RestRequestHelper::post(
			'/settings',
			array(
				'tab'  => 'subscribers',
				'data' => array(
					'subscriberSyncEnabled' => true,
					'syncFields'            => array( 'user_phone', 'user_gender', 'birthday' ),
				),
			)
		);
		self::assertSame( 200, $response->get_status() );
		// After the save: the subscribers tab writes this switch too.
		update_option( Options::CHECKOUT_SUBSCRIPTION_ENABLED_OPTION, true );

		$this->admin_id       = $this->make_user(
			'administrator',
			array(
				'user_phone'      => '555-TEST-ADMIN',
				'user_gender'     => '1',
				'user_dob'        => '1901-02-03',
				'user_newsletter' => '1',
			)
		);
		$this->shopper_id     = $this->make_user(
			'customer',
			array(
				'user_phone'  => '555-TEST-SHOPPER',
				'user_gender' => '2',
				'user_dob'    => '1902-04-05',
			)
		);
		$this->contributor_id = $this->make_user( 'contributor', array( 'user_phone' => '555-TEST-CONTRIB' ) );
	}

	protected function tearDown(): void {
		unset( $_GET['user_id'], $_REQUEST['user_id'] );

		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		foreach ( array( $this->admin_id, $this->shopper_id, $this->contributor_id ) as $user_id ) {
			if ( $user_id > 0 ) {
				wp_delete_user( $user_id );
			}
		}

		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_the_account_details_form_shows_the_shoppers_own_newsletter_status_whatever_the_address_says(): void {
		wp_set_current_user( $this->shopper_id );
		$this->request_user_id( $this->admin_id );

		$html = $this->render( 'woocommerce_edit_account_form' );

		self::assertStringContainsString( 'name="user_newsletter"', $html, 'The box is on the form.' );
		self::assertDoesNotMatchRegularExpression( '/<input[^>]*name="user_newsletter"[^>]*checked/', $html, 'The other user is subscribed; the shopper is not.' );
	}

	public function test_the_account_details_form_shows_the_shoppers_own_fields_whatever_the_address_says(): void {
		$show = static function ( array $fields ): array {
			foreach ( self::PROFILE_FIELDS as $key ) {
				if ( isset( $fields[ $key ] ) ) {
					$fields[ $key ]['hide_in_account'] = false;
				}
			}
			return $fields;
		};
		add_filter( 'smaily_connect_account_fields', $show );

		try {
			wp_set_current_user( $this->shopper_id );
			$this->request_user_id( $this->admin_id );

			$html = $this->render( 'woocommerce_edit_account_form' );
		} finally {
			remove_filter( 'smaily_connect_account_fields', $show );
		}

		self::assertStringNotContainsString( '555-TEST-ADMIN', $html );
		self::assertStringNotContainsString( '1901-02-03', $html );
		self::assertStringContainsString( 'value="555-TEST-SHOPPER"', $html );
		self::assertStringContainsString( 'value="1902-04-05"', $html );
	}

	public function test_the_own_wp_admin_profile_shows_the_users_own_values_whatever_the_address_says(): void {
		wp_set_current_user( $this->contributor_id );
		$this->request_user_id( $this->admin_id );

		$html = $this->render( 'show_user_profile', get_userdata( $this->contributor_id ) );

		self::assertStringContainsString( 'value="555-TEST-CONTRIB"', $html );
		self::assertStringNotContainsString( '555-TEST-ADMIN', $html );
		self::assertStringNotContainsString( '1901-02-03', $html );
	}

	public function test_an_administrator_editing_another_user_sees_that_users_values(): void {
		wp_set_current_user( $this->admin_id );
		$this->request_user_id( $this->shopper_id );

		$html = $this->render( 'edit_user_profile', get_userdata( $this->shopper_id ) );

		self::assertStringContainsString( 'value="555-TEST-SHOPPER"', $html );
		self::assertStringContainsString( 'value="1902-04-05"', $html );
		self::assertMatchesRegularExpression( '/id="user_gender_2" checked/', $html );
		self::assertStringNotContainsString( '555-TEST-ADMIN', $html );
	}

	public function test_an_administrators_save_of_another_user_still_writes_that_user(): void {
		wp_set_current_user( $this->admin_id );
		$settings = new Profile_Settings();
		$settings->register_hooks();
		$_REQUEST = array( '_wpnonce' => wp_create_nonce( 'update-user_' . $this->shopper_id ) );
		$_POST    = array(
			'action'     => 'update',
			'user_phone' => '555-TEST-SAVED',
			'user_dob'   => '1903-06-07',
		);
		if ( ! function_exists( 'set_current_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}
		set_current_screen( 'user-edit' );

		try {
			do_action( 'edit_user_profile_update', $this->shopper_id );
		} finally {
			$_REQUEST = array();
			$_POST    = array();
			$GLOBALS['current_screen'] = null;
			$this->forget_hooks( $settings );
		}

		self::assertSame( '555-TEST-SAVED', get_user_meta( $this->shopper_id, 'user_phone', true ) );
		self::assertSame( '1903-06-07', get_user_meta( $this->shopper_id, 'user_dob', true ) );
		self::assertSame( '555-TEST-ADMIN', get_user_meta( $this->admin_id, 'user_phone', true ), 'The editor\'s own values are untouched.' );
	}

	public function test_a_viewer_who_may_not_edit_the_user_gets_nothing(): void {
		wp_set_current_user( $this->contributor_id );

		$html = $this->render( 'edit_user_profile', get_userdata( $this->admin_id ) );

		self::assertSame( '', $html );
	}

	// --- helpers -------------------------------------------------------------

	private function request_user_id( int $user_id ): void {
		$_GET['user_id']     = (string) $user_id;
		$_REQUEST['user_id'] = (string) $user_id;
	}

	/**
	 * Whatever the plugin prints on a real form hook.
	 *
	 * @param \WP_User|false|null $user The user the profile hooks pass.
	 */
	private function render( string $hook, $user = null ): string {
		$settings = new Profile_Settings();
		$settings->register_hooks();

		try {
			ob_start();
			if ( $user !== null ) {
				self::assertInstanceOf( \WP_User::class, $user );
				do_action( $hook, $user );
			} else {
				do_action( $hook );
			}

			return (string) ob_get_clean();
		} finally {
			$this->forget_hooks( $settings );
		}
	}

	/**
	 * @param array<string, string> $meta
	 */
	private function make_user( string $role, array $meta ): int {
		$slug    = wp_generate_password( 6, false );
		$user_id = wp_insert_user(
			array(
				'user_login' => 'smly_owner_' . $slug,
				'user_email' => 'owner-' . $slug . '@example.test',
				'user_pass'  => wp_generate_password( 20 ),
				'role'       => $role,
			)
		);
		self::assertIsInt( $user_id );

		foreach ( $meta as $key => $value ) {
			update_user_meta( $user_id, $key, $value );
		}

		return $user_id;
	}

	/**
	 * Remove every hook a service registered.
	 *
	 * @param object|class-string $service An instance, or a class whose every
	 *                                     registered instance is forgotten.
	 */
	private function forget_hooks( $service ): void {
		global $wp_filter;

		foreach ( $wp_filter as $hook_name => $hook ) {
			foreach ( $hook->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = $callback['function'] ?? null;
					if ( ! is_array( $function ) || ! isset( $function[0] ) || ! is_object( $function[0] ) ) {
						continue;
					}

					$matches = is_string( $service )
						? $function[0] instanceof $service
						: $function[0] === $service;

					if ( $matches ) {
						remove_action( (string) $hook_name, $function, (int) $priority );
					}
				}
			}
		}
	}
}
