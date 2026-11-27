<?php
/**
 * Integration: the latest order's language in ContactLanguageResolver —
 * read from a real WooCommerce order through wc_get_orders(), on the store's
 * active order storage (CON-457).
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Multilingual\DetectorFactory;
use Smaily\Connect\Multilingual\DetectorInterface;
use Smaily\Connect\Multilingual\TranslatePressAdapter;
use Smaily\Connect\Support\ContactLanguageResolver;

/**
 * The resolver is the one language source of the Smaily contact sync, the
 * Smaily contact import and the Campaign Intelligence customer row, so these
 * cases cover every one of them. WPML stores an order's language in
 * `wpml_language`, TranslatePress in `trp_language` (a locale, `lv_LV`).
 */
final class ContactLanguageOrderSourceTest extends TestCase {

	/** @var array<int, int> */
	private array $created_users = array();

	/** @var array<int, int> */
	private array $created_orders = array();

	protected function tearDown(): void {
		foreach ( $this->created_orders as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->delete( true ); // Not wp_delete_post: a no-op under HPOS.
			}
		}
		$this->created_orders = array();
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		foreach ( $this->created_users as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->created_users = array();
		DetectorFactory::reset();
		delete_option( 'trp_settings' );
		parent::tearDown();
	}

	public function test_on_a_translatepress_store_the_latest_order_gives_the_language(): void {
		$this->translatepress_store();
		$user = $this->customer( 'trp-order-lang@example.test' );
		$this->order( $user, array( 'trp_language' => 'lv_LV' ) );

		self::assertSame( 'lv', ( new ContactLanguageResolver() )->for_user( $user ) );
	}

	public function test_on_a_translatepress_store_the_order_itself_gives_the_language(): void {
		$this->translatepress_store();
		$user  = $this->customer( 'trp-own-order@example.test' );
		$order = $this->order( $user, array( 'trp_language' => 'lv_LV' ) );

		self::assertSame( 'lv', ( new ContactLanguageResolver() )->for_order( $order ) );
	}

	public function test_a_wpml_order_language_is_read_as_before(): void {
		$this->store( 'et', array( 'et', 'lv', 'ru' ) );
		$user = $this->customer( 'wpml-order-lang@example.test' );
		$this->order( $user, array( 'wpml_language' => 'ru' ) );

		self::assertSame( 'ru', ( new ContactLanguageResolver() )->for_user( $user ) );
	}

	public function test_an_order_without_a_stored_language_leaves_the_next_source(): void {
		$this->translatepress_store();
		$user = $this->customer( 'no-order-lang@example.test' );
		$this->order( $user, array() );

		self::assertSame( 'et', ( new ContactLanguageResolver() )->for_user( $user ), 'The store default.' );

		update_user_meta( $user->ID, '_user_preferred_language', 'lv' );
		self::assertSame( 'lv', ( new ContactLanguageResolver() )->for_user( $user ), 'The preferred language still comes first.' );
	}

	/**
	 * TranslatePress as the active plugin: the real adapter over the option
	 * TranslatePress keeps its languages in.
	 */
	private function translatepress_store(): void {
		update_option(
			'trp_settings',
			array(
				'default-language'      => 'et_EE',
				'translation-languages' => array( 'et_EE', 'lv_LV' ),
			)
		);
		$this->use_detector( new TranslatePressAdapter() );
	}

	/**
	 * @param array<int, string> $active
	 */
	private function store( string $default_language, array $active ): void {
		$detector = $this->createMock( DetectorInterface::class );
		$detector->method( 'get_default_language' )->willReturn( $default_language );
		$detector->method( 'get_detected_languages' )->willReturn( $active );
		$this->use_detector( $detector );
	}

	private function use_detector( DetectorInterface $detector ): void {
		$cached = new \ReflectionProperty( DetectorFactory::class, 'cached' );
		$cached->setAccessible( true );
		$cached->setValue( null, $detector );
	}

	private function customer( string $email ): \WP_User {
		$existing = get_user_by( 'email', $email );
		if ( $existing instanceof \WP_User ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $existing->ID );
		}

		$user_id = wp_insert_user(
			array(
				'user_login' => 'lang_' . substr( md5( $email ), 0, 12 ),
				'user_email' => $email,
				'user_pass'  => wp_generate_password( 16 ),
				'role'       => 'customer',
				'locale'     => 'en_US',
			)
		);
		self::assertIsInt( $user_id );
		$this->created_users[] = $user_id;

		$user = get_user_by( 'id', $user_id );
		self::assertInstanceOf( \WP_User::class, $user );
		return $user;
	}

	/**
	 * @param array<string, string> $meta
	 */
	private function order( \WP_User $user, array $meta ): \WC_Order {
		$order = wc_create_order( array( 'customer_id' => $user->ID ) );
		self::assertInstanceOf( \WC_Order::class, $order );
		$this->created_orders[] = (int) $order->get_id();
		foreach ( $meta as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}
		$order->save();
		return $order;
	}
}
