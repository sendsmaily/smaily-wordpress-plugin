<?php
/**
 * Contact Form 7 — the opt-in setting its workflow trigger sends (PRO-3824).
 *
 * @package Smaily\Connect\Tests
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Unit\Integrations\CF7;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Smaily_Connect\Includes\Options;
use Smaily_Connect\Includes\Smaily_Client;
use Smaily_Connect\Integrations\CF7\Public_Base;

require_once dirname( __DIR__, 4 ) . '/includes/smaily-options.class.php';
require_once dirname( __DIR__, 4 ) . '/includes/smaily-client.class.php';
require_once dirname( __DIR__, 4 ) . '/includes/smaily-helper.class.php';
require_once dirname( __DIR__, 4 ) . '/integrations/cf7/public.class.php';
require_once __DIR__ . '/cf7-shims.php';

/**
 * A Smaily form in Contact Form 7 is a signup form, so a submission is fresh
 * consent and may subscribe again a contact who unsubscribed (PRO-3824, the
 * same rule as the Elementor Pro action, PRO-3806). The trigger must say so
 * itself: if it leaned on the legacy client's `force_opt_in` default, a change
 * of that default would silently stop resubscribing.
 */
final class PublicBaseTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $value ): string => trim( (string) $value ) );
	}

	protected function tearDown(): void {
		\WPCF7_Submission::$instance = null;
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_workflow_trigger_sends_force_opt_in_true_explicitly(): void {
		// The spy's own default is `false` — the opposite of the decision — so
		// only an explicit argument at the call site can make it record `true`.
		$client = new class() extends Smaily_Client {
			/** @var list<array{autoresponder_id: int, addresses: mixed, force_opt_in: mixed, arg_count: int}> */
			public array $calls = array();

			public function trigger_automation( int $autoresponder_id, $addresses, $force_opt_in = false ) {
				$this->calls[] = array(
					'autoresponder_id' => $autoresponder_id,
					'addresses'        => $addresses,
					'force_opt_in'     => $force_opt_in,
					'arg_count'        => func_num_args(),
				);
				return array( 'body' => array( 'code' => 101 ) );
			}
		};

		$options = $this->createMock( Options::class );
		$options->method( 'has_credentials' )->willReturn( true );
		$options->method( 'get_settings' )->willReturn(
			array(
				'cf7' => array(
					7 => array(
						'is_enabled'       => true,
						'autoresponder_id' => 42,
					),
				),
			)
		);

		$integration = new class( $options, $client ) extends Public_Base {
			private Smaily_Client $client;

			public function __construct( Options $options, Smaily_Client $client ) {
				parent::__construct( $options );
				$this->client = $client;
			}

			protected function smaily_client(): Smaily_Client {
				return $this->client;
			}
		};

		$submission                  = new \WPCF7_Submission();
		$submission->posted_data     = array( 'your-email' => 'reader@example.test' );
		\WPCF7_Submission::$instance = $submission;

		$integration->submit( $this->form_with_email_field( 7 ), array() );

		self::assertCount( 1, $client->calls );
		self::assertSame( 42, $client->calls[0]['autoresponder_id'] );
		self::assertSame( array( array( 'email' => 'reader@example.test' ) ), $client->calls[0]['addresses'] );
		self::assertSame( 3, $client->calls[0]['arg_count'], 'force_opt_in must be passed explicitly, not left to the client default.' );
		self::assertTrue( $client->calls[0]['force_opt_in'] );
	}

	/**
	 * A Contact Form 7 form with one email field, as `submit()` reads it.
	 */
	private function form_with_email_field( int $id ): object {
		$tag = new class() {
			/** @var string */
			public $basetype = 'email';
			/** @var string */
			public $name = 'your-email';
			/** @var array<int, string> */
			public $values = array();

			public function get_option( string $name, string $pattern = '', bool $single = false ): string {
				return '';
			}

			public function has_option( string $name ): bool {
				return false;
			}
		};

		return new class( $id, $tag ) {
			private int $id;
			private object $tag;

			public function __construct( int $id, object $tag ) {
				$this->id  = $id;
				$this->tag = $tag;
			}

			public function id(): int {
				return $this->id;
			}

			/** @return array<int, object> */
			public function scan_form_tags(): array {
				return array( $this->tag );
			}
		};
	}
}
