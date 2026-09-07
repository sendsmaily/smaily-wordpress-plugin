<?php
/**
 * Integration: the newsletter-signup block can list the store's Smaily
 * automations as the people who actually build pages (PRO-2347).
 *
 * The block fetches /smaily/v1/autoresponders on every mount to fill its
 * automation dropdown, and renders nothing but a spinner until the list
 * arrives. That route used to require manage_options, so for an Editor — the
 * usual role for a marketing user — the fetch was refused and the block never
 * became configurable on a fully connected store.
 *
 * These cases pin the capability the route settles on and what it hands back:
 * whoever may edit content may read it, whoever may not still may not, and the
 * response carries the automations' names and ids only — the store's Smaily
 * credentials stay server-side.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily_Connect\Includes\Cypher;
use WP_REST_Request;

final class NewsletterBlockAutorespondersRouteTest extends TestCase {

	/** @var array<int, int> */
	private array $created_users = array();

	/** @var callable|null */
	private $http_stub = null;

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();

		update_option(
			'smaily_connect_api_credentials',
			array(
				'subdomain' => 'demostore',
				'username'  => 'api-user',
				'password'  => Cypher::encrypt( 'api-password' ),
			)
		);
	}

	protected function tearDown(): void {
		if ( null !== $this->http_stub ) {
			remove_filter( 'pre_http_request', $this->http_stub, 10 );
			$this->http_stub = null;
		}
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
		// The credentials this test writes are left for EnvScrub::reset() to
		// clear, like every sibling — it owns that option key.
		parent::tearDown();
	}

	public function test_an_editor_lists_the_automations_the_block_offers(): void {
		$this->stub_smaily_workflows();
		wp_set_current_user( $this->create_user( 'editor' ) );

		$response = $this->request_autoresponders();

		self::assertSame( 200, $response->get_status(), 'An Editor must be able to configure the newsletter block.' );
		self::assertSame(
			array(
				array(
					'value' => '4321',
					'label' => 'Welcome series',
				),
			),
			$response->get_data()
		);
	}

	public function test_a_subscriber_is_still_refused(): void {
		wp_set_current_user( $this->create_user( 'subscriber' ) );

		self::assertSame( 403, $this->request_autoresponders()->get_status() );
	}

	/**
	 * Answer the Smaily workflows call with one enabled and one disabled
	 * automation, so the response shape is asserted without a live account.
	 */
	private function stub_smaily_workflows(): void {
		$this->http_stub = function ( $preempt, $args, $url = '' ) {
			if ( false === strpos( (string) $url, '/api/workflows.php' ) ) {
				return $preempt;
			}

			return array(
				'headers'  => array(),
				'body'     => (string) wp_json_encode(
					array(
						array(
							'id'         => 4321,
							'title'      => 'Welcome series',
							'is_enabled' => true,
						),
						array(
							'id'         => 8765,
							'title'      => 'Retired series',
							'is_enabled' => false,
						),
					)
				),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => '',
			);
		};

		add_filter( 'pre_http_request', $this->http_stub, 10, 3 );
	}

	private function request_autoresponders(): \WP_REST_Response {
		return rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/smaily/v1/autoresponders' ) );
	}

	private function create_user( string $role ): int {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'smly_pro2347_' . $role . '_' . uniqid(),
				'user_pass'  => wp_generate_password(),
				'user_email' => uniqid( 'smly_pro2347_' ) . '@example.test',
				'role'       => $role,
			)
		);

		self::assertIsInt( $user_id );
		$this->created_users[] = $user_id;

		return $user_id;
	}
}
