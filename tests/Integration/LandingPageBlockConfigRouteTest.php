<?php
/**
 * Integration: the landing-page block can read the account subdomain as the
 * people who actually build pages (PRO-2346).
 *
 * The block fetches /smaily/v1/configuration on every mount and refuses to
 * show its URL field until a subdomain comes back. That route used to require
 * manage_options, so for an Editor — the usual role for a marketing user —
 * the fetch was refused, the subdomain stayed empty and the block claimed the
 * plugin was not configured on a fully connected store, with no way forward.
 *
 * These cases pin the capability the route settles on: whoever may edit
 * content may read it; whoever may not, still may not.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use WP_REST_Request;

final class LandingPageBlockConfigRouteTest extends TestCase {

	/** @var array<int, int> */
	private array $created_users = array();

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();

		update_option(
			'smaily_connect_api_credentials',
			array(
				'subdomain' => 'demostore',
				'username'  => 'api-user',
				'password'  => '',
			)
		);
	}

	protected function tearDown(): void {
		wp_set_current_user( 0 );
		// wp_delete_user() lives in wp-admin/includes/user.php, which a
		// front-end request never loads — every sibling test loads it itself.
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		foreach ( $this->created_users as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->created_users = array();
		delete_option( 'smaily_connect_api_credentials' );
		parent::tearDown();
	}

	public function test_an_editor_reads_the_subdomain_the_block_needs(): void {
		wp_set_current_user( $this->create_user( 'editor' ) );

		$response = $this->request_configuration();

		self::assertSame( 200, $response->get_status(), 'An Editor must be able to configure the landing-page block.' );
		self::assertSame( 'demostore', $response->get_data()['subdomain'] );
	}

	public function test_a_subscriber_is_still_refused(): void {
		wp_set_current_user( $this->create_user( 'subscriber' ) );

		self::assertSame( 403, $this->request_configuration()->get_status() );
	}

	private function request_configuration(): \WP_REST_Response {
		return rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/smaily/v1/configuration' ) );
	}

	private function create_user( string $role ): int {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'smly_pro2346_' . $role . '_' . uniqid(),
				'user_pass'  => wp_generate_password(),
				'user_email' => uniqid( 'smly_pro2346_' ) . '@example.test',
				'role'       => $role,
			)
		);

		self::assertIsInt( $user_id );
		$this->created_users[] = $user_id;

		return $user_id;
	}
}
