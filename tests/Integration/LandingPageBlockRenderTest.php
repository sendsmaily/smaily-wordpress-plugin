<?php
/**
 * Integration: the landing-page block renders server-side, so post_content
 * stays iframe-free and survives a save by an author without
 * `unfiltered_html`. See docs/DECISIONS.md, PRO-2346 (reopened).
 *
 * @package Smaily\Connect\Tests\Integration
 */

declare(strict_types=1);

namespace Smaily\Connect\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class LandingPageBlockRenderTest extends TestCase {

	private const PK = '01a42617-cb5d-4f65-8ea6-a8f4d1b94ffa';

	/** @var array<int, int> */
	private array $created_posts = array();

	/** @var array<int, int> */
	private array $created_users = array();

	protected function tearDown(): void {
		wp_set_current_user( 0 );
		remove_filter( 'map_meta_cap', array( $this, 'deny_unfiltered_html' ), 10 );
		kses_remove_filters();

		foreach ( $this->created_posts as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		$this->created_posts = array();

		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		foreach ( $this->created_users as $user_id ) {
			wp_delete_user( $user_id );
		}
		$this->created_users = array();

		parent::tearDown();
	}

	public function test_the_block_renders_the_landing_page_embed(): void {
		$rendered = do_blocks( $this->block_markup() );

		self::assertStringContainsString(
			'src="https://demostore.sendsmaily.net/landing-pages/' . self::PK . '/html/"',
			$rendered
		);
		self::assertStringContainsString( 'smaily-connect-landingpage-block-front-wrapper', $rendered );
		self::assertStringContainsString( 'height:450px;width:500px', $rendered );
	}

	public function test_the_block_embeds_nothing_without_a_landing_page(): void {
		$rendered = do_blocks( '<!-- wp:smaily/landingpage-block {"subdomain":"demostore"} /-->' );

		self::assertStringNotContainsString( '<iframe', $rendered );
	}

	/**
	 * The regression itself: an author who may not post raw HTML saves the
	 * block, and the landing page still reaches the published page.
	 */
	public function test_the_embed_survives_a_save_by_an_author_without_unfiltered_html(): void {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'smly_pro2346_render_' . uniqid(),
				'user_pass'  => wp_generate_password(),
				'user_email' => uniqid( 'smly_pro2346_render_' ) . '@example.test',
				'role'       => 'administrator',
			)
		);
		self::assertIsInt( $user_id );
		$this->created_users[] = $user_id;

		wp_set_current_user( $user_id );
		// What a multisite site admin, a DISALLOW_UNFILTERED_HTML host or a
		// hardening plugin leaves an administrator with.
		add_filter( 'map_meta_cap', array( $this, 'deny_unfiltered_html' ), 10, 2 );
		kses_init_filters();
		self::assertFalse( current_user_can( 'unfiltered_html' ), 'The probe must actually remove the capability.' );

		$post_id = wp_insert_post(
			array(
				'post_title'   => 'PRO-2346 landing page',
				'post_content' => $this->block_markup(),
				'post_status'  => 'publish',
			)
		);
		self::assertIsInt( $post_id );
		$this->created_posts[] = $post_id;

		$saved = get_post( $post_id );
		self::assertNotNull( $saved );

		self::assertStringContainsString(
			'"landingpagePK":"' . self::PK . '"',
			$saved->post_content,
			'The block attributes must survive the content filter.'
		);
		self::assertStringContainsString(
			'src="https://demostore.sendsmaily.net/landing-pages/' . self::PK . '/html/"',
			do_blocks( $saved->post_content ),
			'The published page must still show the landing page.'
		);
	}

	/**
	 * @param array<int, string> $caps
	 * @return array<int, string>
	 */
	public function deny_unfiltered_html( array $caps, string $cap ): array {
		return 'unfiltered_html' === $cap ? array( 'do_not_allow' ) : $caps;
	}

	private function block_markup(): string {
		return '<!-- wp:smaily/landingpage-block {"subdomain":"demostore","url":"https://demostore.sendsmaily.net/landing-pages/'
			. self::PK . '/html/","landingpagePK":"' . self::PK . '"} /-->';
	}
}
