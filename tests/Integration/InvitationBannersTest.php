<?php
/**
 * Integration: the invitation banners (CON-350, CON-351) — the Smaily
 * invitation on the Dashboard, what the admin bundle is told to show, and
 * the route that closes the Campaign Intelligence banner on Settings.
 *
 * Runs the REAL NotificationManager with the REAL ReportBuilder and
 * ConnectionHealth, so "connected" and "plan blocked" are read from the
 * stored options the way the plugin reads them.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Notifications\NotificationManager;
use Smaily\Connect\Report\ConnectionHealth;
use Smaily\Connect\Settings\Credentials;
use Smaily\Connect\Settings\RecEngineSettings;
use Smaily\Connect\Smaily\Client as SmailyClient;
use Smaily\Connect\Smaily\RecEngine\Client as RecEngineClient;
use Smaily\Connect\Tests\Integration\Support\EnvScrub;
use Smaily\Connect\Tests\Integration\Support\RestRequestHelper;
use WP_REST_Request;
use WP_REST_Response;

final class InvitationBannersTest extends TestCase {

	private const TEXT = 'Connect your store to Smaily to sync contacts and set up automated emails.';

	/** @var array<int, int> */
	private array $created_users = array();

	protected function setUp(): void {
		parent::setUp();
		EnvScrub::reset();
		RestRequestHelper::login_as_admin();
		if ( ! function_exists( 'set_current_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}
	}

	protected function tearDown(): void {
		$GLOBALS['current_screen'] = null;
		wp_dequeue_style( NotificationManager::BANNER_STYLE_HANDLE );
		wp_deregister_style( NotificationManager::BANNER_STYLE_HANDLE );
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		foreach ( $this->created_users as $user_id ) {
			wp_delete_user( $user_id );
		}
		RestRequestHelper::login_as_admin();
		EnvScrub::reset();
		parent::tearDown();
	}

	private function manager(): NotificationManager {
		return new NotificationManager(
			new RecEngineSettings(),
			static fn (): RecEngineClient => new RecEngineClient( 'sk_unused', 'https://unused.test' ),
			static fn (): ?SmailyClient => null
		);
	}

	private function render_on( string $screen ): string {
		set_current_screen( $screen );

		ob_start();
		$this->manager()->render();

		return (string) ob_get_clean();
	}

	private function store_account(): void {
		update_option(
			Credentials::LEGACY_OPTION_KEY,
			array(
				'subdomain' => 'demo',
				'username'  => 'alice',
				'password'  => \Smaily_Connect\Includes\Cypher::encrypt( 's3cret' ),
			)
		);
	}

	private function sign_in( string $role ): void {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'smly_banner_' . $role . '_' . wp_generate_password( 6, false ),
				'user_email' => 'banner-' . wp_generate_password( 6, false ) . '@example.test',
				'user_pass'  => wp_generate_password( 20 ),
				'role'       => $role,
			)
		);
		self::assertIsInt( $user_id );
		$this->created_users[] = $user_id;
		wp_set_current_user( $user_id );
	}

	// --- the Smaily invitation on the Dashboard (CON-351) -------------------

	public function test_the_dashboard_shows_the_banner_instead_of_the_one_line_notice(): void {
		$html = $this->render_on( 'dashboard' );

		self::assertStringContainsString( 'smaily-connect-banner', $html );
		self::assertStringContainsString( self::TEXT, $html );
		self::assertStringContainsString( 'page=smaily-connect-settings#connection', $html );
		self::assertStringContainsString( 'href="' . \Smaily\Connect\Constants::SIGNUP_URL . '"', $html );
		self::assertStringContainsString( 'Log in to Smaily: gear icon', $html );
		self::assertStringContainsString( 'key=' . NotificationManager::INVITE_KEY, $html, 'The banner closes through the invitation\'s own dismissal.' );
		self::assertStringNotContainsString( 'notice-info', $html, 'No one-line notice beside the banner.' );
		self::assertStringContainsString( '<path fill="currentColor" d="M24.2563 0H4.8576', $html, 'The logo passes wp_kses() whole.' );
		self::assertStringContainsString( '<circle cx="17" cy="19" r="1.5"', $html, 'The panel icons pass wp_kses() whole.' );
		self::assertStringContainsString( 'stroke-linejoin="round" focusable="false">', $html );
	}

	public function test_other_admin_screens_keep_the_one_line_notice(): void {
		$html = $this->render_on( 'edit-post' );

		self::assertStringContainsString( 'notice-info', $html );
		self::assertStringContainsString( self::TEXT, $html );
		self::assertStringNotContainsString( 'smaily-connect-banner', $html );
	}

	public function test_the_plugins_own_screens_leave_the_invitation_to_the_wizard(): void {
		$html = $this->render_on( 'toplevel_page_smaily-connect-wizard' );

		self::assertStringNotContainsString( self::TEXT, $html );
	}

	public function test_closing_it_hides_banner_and_notice_for_ninety_days(): void {
		$this->manager()->dismiss( NotificationManager::INVITE_KEY );

		self::assertStringNotContainsString( self::TEXT, $this->render_on( 'dashboard' ) );
		self::assertStringNotContainsString( self::TEXT, $this->render_on( 'edit-post' ) );

		update_option(
			NotificationManager::OPTION_DISMISSED,
			array( NotificationManager::INVITE_KEY => time() - NotificationManager::INVITE_DISMISS_COOLDOWN - 1 ),
			false
		);

		self::assertStringContainsString( 'smaily-connect-banner', $this->render_on( 'dashboard' ) );
	}

	public function test_a_connected_account_shows_nothing(): void {
		$this->store_account();

		self::assertStringNotContainsString( self::TEXT, $this->render_on( 'dashboard' ) );
		self::assertStringNotContainsString( self::TEXT, $this->render_on( 'edit-post' ) );
	}

	public function test_a_user_who_cannot_manage_the_plugin_sees_nothing(): void {
		$this->sign_in( 'editor' );

		self::assertStringNotContainsString( self::TEXT, $this->render_on( 'dashboard' ) );
	}

	public function test_the_stylesheet_loads_on_the_dashboard_only_while_the_banner_shows(): void {
		$manager = $this->manager();

		$manager->enqueue_banner_style( 'plugins.php' );
		self::assertFalse( wp_style_is( NotificationManager::BANNER_STYLE_HANDLE, 'enqueued' ) );

		$manager->enqueue_banner_style( 'index.php' );
		self::assertTrue( wp_style_is( NotificationManager::BANNER_STYLE_HANDLE, 'enqueued' ) );

		wp_dequeue_style( NotificationManager::BANNER_STYLE_HANDLE );
		$this->store_account();
		// A new request: the manager decides the invitation once per request.
		$this->manager()->enqueue_banner_style( 'index.php' );
		self::assertFalse( wp_style_is( NotificationManager::BANNER_STYLE_HANDLE, 'enqueued' ) );
	}

	// --- what the admin bundle is told (CON-350, CON-351) -------------------

	public function test_the_boot_says_no_account_without_one(): void {
		self::assertSame(
			array(
				'smailyAccountConnected' => false,
				'smailyPlanBlocked'      => false,
				'smailyLoginUrl'         => '',
				'ciBannerDismissed'      => false,
			),
			NotificationManager::banner_boot()
		);
	}

	public function test_the_boot_reads_a_plan_blocked_account_and_its_login_address(): void {
		$this->store_account();
		( new ConnectionHealth() )->record_smaily( 'demo', 'plan_blocked', 'GET', 'workflows' );

		$boot = NotificationManager::banner_boot();

		self::assertTrue( $boot['smailyAccountConnected'] );
		self::assertTrue( $boot['smailyPlanBlocked'] );
		self::assertSame( 'https://demo.sendsmaily.net/', $boot['smailyLoginUrl'] );

		( new ConnectionHealth() )->record_smaily( 'demo', '', 'GET', 'workflows' );
		self::assertFalse( NotificationManager::banner_boot()['smailyPlanBlocked'], 'A good answer ends the upsell.' );
	}

	// --- closing the Campaign Intelligence banner on Settings (CON-350) -----

	private function close( string $key, ?string $nonce = null ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/smaily-connect/v1/notices/dismiss' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', $nonce ?? wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'key', $key );

		return rest_get_server()->dispatch( $request );
	}

	public function test_closing_the_campaign_intelligence_banner_keeps_it_away_thirty_days(): void {
		$response = $this->close( NotificationManager::CI_BANNER_KEY );

		self::assertSame( 200, $response->get_status() );
		self::assertTrue( NotificationManager::banner_boot()['ciBannerDismissed'] );

		update_option(
			NotificationManager::OPTION_DISMISSED,
			array( NotificationManager::CI_BANNER_KEY => time() - NotificationManager::CI_BANNER_DISMISS_COOLDOWN - 1 ),
			false
		);
		self::assertFalse( NotificationManager::banner_boot()['ciBannerDismissed'] );
	}

	public function test_the_route_needs_the_nonce(): void {
		self::assertSame( 403, $this->close( NotificationManager::CI_BANNER_KEY, 'not-a-nonce' )->get_status() );
		self::assertFalse( NotificationManager::banner_boot()['ciBannerDismissed'] );
	}

	public function test_the_route_needs_the_capability(): void {
		$this->sign_in( 'editor' );

		self::assertSame( 403, $this->close( NotificationManager::CI_BANNER_KEY )->get_status() );
		self::assertSame( array(), get_option( NotificationManager::OPTION_DISMISSED, array() ) );
	}

	public function test_the_route_closes_nothing_else(): void {
		$response = $this->close( NotificationManager::INVITE_KEY );

		self::assertSame( 400, $response->get_status() );
		self::assertSame( array(), get_option( NotificationManager::OPTION_DISMISSED, array() ) );
	}
}
