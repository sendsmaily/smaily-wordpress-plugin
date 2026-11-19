<?php
/**
 * Integration: the admin-notice dismiss AJAX action stores only a notice the
 * plugin registered, for a user who may see it (CON-346).
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily_Connect\Admin\Notices;
use Smaily_Connect\Includes\Notice_Registry;

final class NoticeDismissTest extends TestCase {

	private const NOTICE_ID = 'con346-test-notice';

	/** @var list<int> */
	private array $users = array();

	protected function setUp(): void {
		parent::setUp();
		Notice_Registry::add_notice( self::NOTICE_ID, 'con346-test', array( 'capability' => 'manage_options' ) );
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', array( $this, 'die_handler' ) );
	}

	protected function tearDown(): void {
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_filter( 'wp_die_ajax_handler', array( $this, 'die_handler' ) );
		Notice_Registry::remove_notice( self::NOTICE_ID );
		unset( $_POST['id'], $_REQUEST['nonce'] );
		wp_set_current_user( 0 );
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		foreach ( $this->users as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->users = array();
		parent::tearDown();
	}

	public function test_a_user_who_can_see_the_notice_dismisses_it(): void {
		$admin = $this->user( 'administrator' );

		$answer = $this->dismiss( $admin, self::NOTICE_ID );

		self::assertTrue( $answer['success'] );
		self::assertTrue( Notice_Registry::is_dismissed( self::NOTICE_ID, $admin ) );
	}

	public function test_a_user_who_cannot_see_the_notice_cannot_dismiss_it(): void {
		$subscriber = $this->user( 'subscriber' );

		$answer = $this->dismiss( $subscriber, self::NOTICE_ID );

		self::assertFalse( $answer['success'] );
		self::assertFalse( Notice_Registry::is_dismissed( self::NOTICE_ID, $subscriber ) );
	}

	public function test_an_id_the_plugin_did_not_register_is_not_stored(): void {
		$admin = $this->user( 'administrator' );

		$answer = $this->dismiss( $admin, 'not-a-notice-' . str_repeat( 'x', 64 ) );

		self::assertFalse( $answer['success'] );
		self::assertSame( '', get_user_meta( $admin, 'smaily_connect_notice_dismissed', true ) );
	}

	/**
	 * @return callable
	 */
	public function die_handler() {
		return static function (): void {
			throw new \RuntimeException( 'wp_die' );
		};
	}

	/**
	 * @return array<string, mixed>
	 */
	private function dismiss( int $user_id, string $notice_id ): array {
		wp_set_current_user( $user_id );
		$_POST['id']       = $notice_id;
		$_REQUEST['nonce'] = wp_create_nonce( 'smaily_connect_dismiss_notice' );

		ob_start();
		try {
			( new Notices() )->dismiss_notice();
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'wp_die', $e->getMessage() );
		}
		$answer = json_decode( (string) ob_get_clean(), true );

		self::assertIsArray( $answer );
		return $answer;
	}

	private function user( string $role ): int {
		$id = wp_insert_user(
			array(
				'user_login' => 'con346-' . $role . '-' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'user_email' => 'con346-' . $role . '-' . wp_generate_password( 6, false ) . '@example.test',
				'role'       => $role,
			)
		);
		self::assertIsInt( $id );
		$this->users[] = $id;
		return $id;
	}
}
