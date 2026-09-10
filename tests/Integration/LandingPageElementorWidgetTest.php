<?php
/**
 * Integration: a store without Elementor must load nothing of the Elementor
 * landing-page widget and must not error (PRO-2440).
 *
 * The widget's own behaviour is human acceptance — there is no Elementor in
 * wp-env — but the half that protects every OTHER store is testable here: the
 * whole integration hangs off Helper::is_elementor_active(), so nothing may be
 * registered and no Elementor class may be referenced when it is absent.
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Smaily_Connect\Includes\Helper;

final class LandingPageElementorWidgetTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		if ( Helper::is_elementor_active() ) {
			self::markTestSkipped( 'This case pins the behaviour of a store WITHOUT Elementor.' );
		}
	}

	public function test_nothing_elementor_is_registered_without_elementor(): void {
		self::assertFalse(
			has_action( 'elementor/widgets/register' ),
			'A store without Elementor must not register widgets.'
		);
		self::assertFalse(
			class_exists( 'Smaily_Connect\Integrations\Elementor\Landingpage_Widget', false ),
			'The widget class extends an Elementor class, so it must not even be loaded.'
		);
	}

	public function test_the_shared_render_path_works_without_elementor(): void {
		update_option(
			'smaily_connect_api_credentials',
			array(
				'subdomain' => 'demostore',
				'username'  => 'api-user',
				'password'  => '',
			)
		);

		$embed = \Smaily_Connect\Blocks\Landing_Page\Integration::render_embed(
			'https://demostore.sendsmaily.net/landing-pages/01a42617-cb5d-4f65-8ea6-a8f4d1b94ffa/html/',
			600,
			'100%'
		);

		delete_option( 'smaily_connect_api_credentials' );

		self::assertStringContainsString( 'height:600px;width:100%', $embed );
	}
}
