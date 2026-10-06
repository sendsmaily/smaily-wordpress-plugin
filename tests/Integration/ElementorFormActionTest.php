<?php
/**
 * Integration: a store without Elementor Pro must load nothing of the Smaily
 * form action and must not error (PRO-3806).
 *
 * There is no Elementor (free or Pro) in wp-env, so a real Elementor Pro form
 * submission is human acceptance; the unit suite drives the action against
 * shims. What is testable here is the half that protects every other store.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily_Connect\Includes\Helper;
use Smaily_Connect\Integrations\Elementor\Admin;

final class ElementorFormActionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( class_exists( 'ElementorPro\Modules\Forms\Classes\Action_Base' ) ) {
			self::markTestSkipped( 'This case pins the behaviour of a store WITHOUT Elementor Pro.' );
		}
	}

	public function test_nothing_is_hooked_without_elementor(): void {
		if ( Helper::is_elementor_active() ) {
			self::markTestSkipped( 'This case pins the behaviour of a store WITHOUT Elementor.' );
		}

		self::assertFalse(
			has_action( 'elementor_pro/forms/actions/register' ),
			'A store without Elementor must not hook into Elementor Pro forms.'
		);
	}

	public function test_the_registration_hook_without_elementor_pro_registers_and_loads_nothing(): void {
		require_once SMAILY_CONNECT_PLUGIN_PATH . 'integrations/elementor/admin.class.php';

		$registrar = new class() {
			/** @var array<int, object> */
			public array $registered = array();

			public function register( object $action ): void {
				$this->registered[] = $action;
			}
		};

		( new Admin() )->register_form_actions( $registrar );

		self::assertSame( array(), $registrar->registered );
		self::assertFalse(
			class_exists( 'Smaily_Connect\Integrations\Elementor\Form_Action', false ),
			'The action class extends an Elementor Pro class, so it must not even be loaded.'
		);
	}
}
