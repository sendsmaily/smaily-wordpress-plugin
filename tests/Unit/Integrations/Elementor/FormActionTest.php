<?php
/**
 * The "Smaily" action under Elementor Pro's Actions After Submit — how it
 * reads Elementor's record and answers through Elementor's ajax handler
 * (PRO-3806). Elementor Pro is shimmed; a real submission is human acceptance.
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
use Smaily_Connect\Integrations\Elementor\Admin;
use Smaily_Connect\Integrations\Elementor\Form_Action;

if ( ! defined( 'SMAILY_CONNECT_PLUGIN_NAME' ) ) {
	define( 'SMAILY_CONNECT_PLUGIN_NAME', 'smaily-connect' );
}

require_once __DIR__ . '/elementor-pro-shims.php';
require_once dirname( __DIR__, 4 ) . '/integrations/elementor/admin.class.php';
require_once dirname( __DIR__, 4 ) . '/integrations/elementor/form-action.class.php';

final class FormActionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'is_email' )->alias(
			static fn ( string $email ) => filter_var( $email, FILTER_VALIDATE_EMAIL ) !== false ? $email : false
		);
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $value ): string => trim( (string) $value ) );
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'home_url' )->justReturn( 'https://shop.example.test' );
		Functions\when( 'site_url' )->justReturn( 'https://shop.example.test' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_it_is_registered_as_the_smaily_action(): void {
		$registrar = new class() {
			/** @var array<int, object> */
			public array $registered = array();

			public function register( object $action ): void {
				$this->registered[] = $action;
			}
		};

		( new Admin() )->register_form_actions( $registrar );

		self::assertCount( 1, $registrar->registered );
		self::assertInstanceOf( Form_Action::class, $registrar->registered[0] );
		self::assertSame( 'smaily', $registrar->registered[0]->get_name() );
		self::assertSame( 'Smaily', $registrar->registered[0]->get_label() );
	}

	public function test_a_submission_reaches_smaily_with_the_page_url(): void {
		$client  = $this->fake_client();
		$handler = $this->ajax_handler();

		$this->action( $client )->run(
			$this->record(
				array(
					'email'   => array( 'value' => 'visitor@example.test' ),
					'message' => array( 'value' => 'never sent' ),
				)
			),
			$handler
		);

		self::assertSame( 'visitor@example.test', $client->upserts[0][0]['email'] );
		self::assertSame( 'https://shop.example.test/contact/', $client->upserts[0][0][ FormSubscription::FIELD_FORM_URL ] );
		self::assertArrayNotHasKey( 'message', $client->upserts[0][0] );
		self::assertSame( array(), $handler->errors );
		self::assertSame( array(), $handler->admin_errors );
	}

	public function test_an_invalid_email_is_an_elementor_error(): void {
		$client  = $this->fake_client();
		$handler = $this->ajax_handler();

		$this->action( $client )->run( $this->record( array( 'email' => array( 'value' => 'nope' ) ) ), $handler );

		self::assertSame( array( 'Please enter a valid email address.' ), $handler->errors );
		self::assertSame( array(), $client->upserts );
	}

	public function test_a_smaily_failure_tells_the_visitor_without_internal_details(): void {
		$client               = $this->fake_client();
		$client->upsert_throw = new ApiException( 'Smaily API returned HTTP 401 for POST contact', 401 );
		$handler              = $this->ajax_handler();

		$this->action( $client )->run( $this->record( array( 'email' => array( 'value' => 'visitor@example.test' ) ) ), $handler );

		self::assertSame( array( 'Sorry, we could not sign you up right now. Please try again later.' ), $handler->errors );
		self::assertStringNotContainsString( 'HTTP', $handler->errors[0] );
		self::assertCount( 1, $handler->admin_errors, 'The site editor gets the technical reason — Elementor shows it to editors only.' );
		self::assertStringContainsString( 'HTTP 401', $handler->admin_errors[0] );
		self::assertStringNotContainsString( 'secret-password', $handler->admin_errors[0] );
	}

	public function test_contact_mode_without_consent_adds_no_error(): void {
		$client  = $this->fake_client();
		$handler = $this->ajax_handler();

		$this->action( $client )->run(
			$this->record(
				array( 'email' => array( 'value' => 'visitor@example.test' ) ),
				array(
					'smaily_mode'          => FormSubscription::MODE_CONTACT,
					'smaily_consent_field' => 'marketing',
				)
			),
			$handler
		);

		self::assertSame( array(), $client->upserts );
		self::assertSame( array(), $handler->errors, 'No consent must not block the form\'s other actions.' );
		self::assertSame( array(), $handler->admin_errors );
	}

	public function test_the_settings_section_shows_only_for_the_smaily_action_and_holds_no_credentials(): void {
		$widget = new class() {
			/** @var array<int, array{0: string, 1: string, 2: array<string, mixed>}> */
			public array $calls = array();

			public function start_controls_section( string $id, array $args ): void {
				$this->calls[] = array( 'section', $id, $args );
			}

			public function add_control( string $id, array $args ): void {
				$this->calls[] = array( 'control', $id, $args );
			}

			public function end_controls_section(): void {
				$this->calls[] = array( 'end', '', array() );
			}
		};

		$this->action( $this->fake_client() )->register_settings_section( $widget );

		self::assertSame( 'section', $widget->calls[0][0] );
		self::assertSame( array( 'submit_actions' => 'smaily' ), $widget->calls[0][2]['condition'] );
		$controls = array_column(
			array_filter( $widget->calls, static fn ( array $call ): bool => $call[0] === 'control' ),
			1
		);
		self::assertSame(
			array( 'smaily_mode', 'smaily_email_field', 'smaily_name_field', 'smaily_consent_field', 'smaily_fields', 'smaily_workflow_id' ),
			$controls
		);
		self::assertSame( 'end', $widget->calls[ count( $widget->calls ) - 1 ][0] );
		self::assertStringNotContainsString( 'secret-password', (string) json_encode( $widget->calls ) );
	}

	public function test_the_workflow_dropdown_lists_the_workflows_for_a_site_editor(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );

		$client            = $this->fake_client();
		$client->workflows = array(
			array(
				'id'   => 42,
				'name' => 'Welcome series',
			),
		);

		self::assertSame(
			array(
				''   => 'No workflow',
				'42' => 'Welcome series',
			),
			$this->workflow_control_options( $client )
		);
	}

	public function test_the_workflow_dropdown_never_calls_smaily_for_a_visitor(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( false );

		$client = $this->fake_client();

		self::assertSame( array( '' => 'No workflow' ), $this->workflow_control_options( $client ) );
		self::assertSame( 0, $client->list_calls );
	}

	public function test_export_drops_the_account_specific_workflow(): void {
		$exported = $this->action( $this->fake_client() )->on_export(
			array(
				'widgetType' => 'form',
				'settings'   => array(
					'smaily_workflow_id' => '42',
					'smaily_email_field' => 'email',
				),
			)
		);

		self::assertSame(
			array(
				'widgetType' => 'form',
				'settings'   => array( 'smaily_email_field' => 'email' ),
			),
			$exported
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function workflow_control_options( Client $client ): array {
		$widget = new class() {
			/** @var array<string, string> */
			public array $options = array();

			public function start_controls_section( string $id, array $args ): void {}

			public function add_control( string $id, array $args ): void {
				if ( $id === 'smaily_workflow_id' ) {
					$this->options = $args['options'];
				}
			}

			public function end_controls_section(): void {}
		};

		$this->action( $client )->register_settings_section( $widget );

		return $widget->options;
	}

	private function action( Client $client ): Form_Action {
		return new Form_Action( new FormSubscription( static fn (): Client => $client ) );
	}

	/**
	 * @param array<string, array<string, mixed>> $fields
	 * @param array<string, mixed>                $settings
	 */
	private function record( array $fields, array $settings = array() ): object {
		return new class( $fields, $settings ) {
			/** @var array<string, array<string, mixed>> */
			public array $fields;
			/** @var array<string, mixed> */
			public array $settings;

			/**
			 * @param array<string, array<string, mixed>> $fields
			 * @param array<string, mixed>                $settings
			 */
			public function __construct( array $fields, array $settings ) {
				$this->fields   = $fields;
				$this->settings = array_merge(
					array(
						'id'                 => 'a1b2c3d',
						'form_name'          => 'Contact',
						'smaily_mode'        => FormSubscription::MODE_NEWSLETTER,
						'smaily_email_field' => 'email',
					),
					$settings
				);
			}

			/** @return mixed */
			public function get( string $property ) {
				return $property === 'fields' ? $this->fields : ( $property === 'form_settings' ? $this->settings : null );
			}

			/**
			 * @param array<int, string> $keys
			 *
			 * @return array<string, array{title: string, value: string}>
			 */
			public function get_form_meta( array $keys ): array {
				return array(
					'page_url' => array(
						'title' => 'Page URL',
						'value' => 'https://shop.example.test/contact/',
					),
				);
			}
		};
	}

	private function ajax_handler(): object {
		return new class() {
			/** @var array<int, string> */
			public array $errors = array();
			/** @var array<int, string> */
			public array $admin_errors = array();

			public function add_error_message( string $message ): void {
				$this->errors[] = $message;
			}

			public function add_admin_error_message( string $message ): void {
				$this->admin_errors[] = $message;
			}
		};
	}

	private function fake_client(): Client {
		return new class() extends Client {
			/** @var array<int, array<int, array<string, mixed>>> */
			public array $upserts = array();
			public ?ApiException $upsert_throw = null;
			/** @var array<int, array<string, mixed>> */
			public array $workflows = array();
			public int $list_calls  = 0;

			public function __construct() {
				parent::__construct( 'demo', 'user', 'secret-password' );
			}

			public function upsert_subscribers( array $subscribers ): array {
				$this->upserts[] = $subscribers;
				if ( $this->upsert_throw !== null ) {
					throw $this->upsert_throw;
				}
				return array( 'code' => 101 );
			}

			public function list_autoresponders(): array {
				++$this->list_calls;
				return $this->workflows;
			}
		};
	}
}

