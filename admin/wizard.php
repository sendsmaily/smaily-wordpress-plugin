<?php
/**
 * Setup Wizard admin page mount.
 *
 * Registers the `Smaily → Initial setup` submenu, enqueues the
 * Vite-built admin bundle, and renders the React mount node with
 * data-view="wizard". State hydration happens client-side via
 * `window.smailyConnectBoot` (set by wp_localize_script below).
 *
 * The legacy Smaily admin settings PAGE was removed (F3-45) — this is now the
 * ONLY admin UI. The legacy non-view stack (WooCommerce integrations, the
 * subscription widget, upgrade notices) still loads, but all configuration lives
 * here; the new Settings page reads/writes the same legacy option keys, so the
 * kept integrations keep working unchanged.
 *
 * @package Smaily\Connect
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

use Smaily\Connect\Wizard\EnvDetector;

/**
 * Registers the wizard + settings submenu pages under a fresh top-level
 * "Smaily Connect" menu. Hooked from Bootstrap::boot() onto admin_menu.
 */
function smaily_connect_register_admin_pages(): void {
	$capability = 'manage_options';

	// Reuse the legacy Smaily brand mark — `gfx/icon.svg` is the same
	// asset upstream's add_menu_page() loaded. Keeps the sidebar visual
	// identity consistent with what merchants already recognise.
	$icon_path = SMAILY_CONNECT_PLUGIN_PATH . 'gfx/icon.svg';
	$icon      = file_exists( $icon_path )
		? 'data:image/svg+xml;base64,' . base64_encode( (string) file_get_contents( $icon_path ) ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		: 'dashicons-email-alt';

	add_menu_page(
		__( 'Smaily Connect', 'smaily-connect' ),
		'Smaily Connect',
		$capability,
		'smaily-connect-wizard',
		'smaily_connect_render_wizard_page',
		$icon,
		56
	);

	add_submenu_page(
		'smaily-connect-wizard',
		__( 'Initial setup', 'smaily-connect' ),
		__( 'Initial setup', 'smaily-connect' ),
		$capability,
		'smaily-connect-wizard',
		'smaily_connect_render_wizard_page'
	);

	add_submenu_page(
		'smaily-connect-wizard',
		__( 'Settings', 'smaily-connect' ),
		__( 'Settings', 'smaily-connect' ),
		$capability,
		'smaily-connect-settings',
		'smaily_connect_render_settings_page'
	);
}

/**
 * Render the wizard mount node. The actual React bundle takes over from
 * `<div id="smaily-connect-app" data-view="wizard">` after DOMContentLoaded.
 */
function smaily_connect_render_wizard_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'smaily-connect' ) );
	}

	smaily_connect_emit_mount( 'wizard' );
}

/**
 * Render the settings mount node. Same React bundle, different data-view.
 *
 * Wizard-first gate (sub-PR 2.I): merchants who haven't completed the
 * setup wizard yet land on the wizard, not Settings. The boot payload's
 * `setupCompleted` flag flips to true when Step 6 Finish runs
 * (sub-PR 2.H.18). Until then a Settings menu click bounces here, into
 * the wizard, so the merchant doesn't see a sea of tabs they can't
 * meaningfully fill in without first connecting.
 *
 * We deliberately keep the Settings menu item registered (rather than
 * remove_submenu_page on the unfinished setup case) so wp-admin's
 * breadcrumbs and direct-link bookmarks still resolve. The redirect
 * fires after the capability check so a perm-denied user still sees
 * the wp_die() rather than getting bounced.
 */
function smaily_connect_render_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'smaily-connect' ) );
	}

	if ( ! \Smaily\Connect\Settings\SetupState::completed() ) {
		wp_safe_redirect( admin_url( 'admin.php?page=smaily-connect-wizard' ) );
		exit;
	}

	smaily_connect_emit_mount( 'settings' );
}

/**
 * Shared HTML wrapper. The `wp-header-end` marker is load-bearing: WP core's
 * common.js relocates `div.notice` admin notices after `.wp-header-end` when
 * present — WITHOUT it the fallback is the first h1 inside `.wrap`, which is
 * the REACT app's own flex-header h1, so notices (e.g. the NotificationManager
 * health notice) got injected INSIDE the header row, squeezed next to the
 * Settings tabs. With the marker they land here — full-width, above the React
 * tree, like on every other admin page. Core CSS keeps the hr invisible.
 *
 * @param 'wizard'|'settings' $view
 */
function smaily_connect_emit_mount( string $view ): void {
	// Always-visible link to the merchant documentation site, right where a
	// merchant lands during setup — help material one click away on install.
	// Rendered PHP-side (not in the React bundle) so it needs no JS/i18n rebuild
	// and shows on both the wizard and Settings screens. URL is centralized in
	// Constants::docs_url() (one change when docs move to connect.smaily.com).
	$docs_url = \Smaily\Connect\Constants::docs_url();
	?>
	<div class="wrap" id="smaily-connect-wrap">
		<hr class="wp-header-end">
		<p class="smaily-connect-docs-link" style="margin:0 0 6px;text-align:right;font-size:13px;">
			<a href="<?php echo esc_url( $docs_url ); ?>" target="_blank" rel="noopener noreferrer">
				<span class="dashicons dashicons-book-alt" style="vertical-align:text-bottom;"></span>
				<?php echo esc_html__( 'Documentation', 'smaily-connect' ); ?>
			</a>
		</p>
		<div
			id="smaily-connect-app"
			data-view="<?php echo esc_attr( $view ); ?>"
		></div>
	</div>
	<?php
}

/**
 * Enqueue the Vite-built admin bundle when on a Smaily Connect screen.
 * Bound from Bootstrap::boot() onto admin_enqueue_scripts.
 *
 * @param string $hook_suffix The current admin page hook (toplevel_page_*).
 */
function smaily_connect_enqueue_admin_bundle( string $hook_suffix ): void {
	if ( strpos( $hook_suffix, 'smaily-connect' ) === false ) {
		return;
	}

	$dist_dir = SMAILY_CONNECT_PLUGIN_PATH . 'dist/admin';
	$dist_url = plugins_url( 'dist/admin', SMAILY_CONNECT_PLUGIN_FILE );

	$js_path  = $dist_dir . '/admin.js';
	$css_path = $dist_dir . '/admin.css';

	if ( ! file_exists( $js_path ) ) {
		add_action(
			'admin_notices',
			static function (): void {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html__( 'Smaily Connect admin bundle not built. Run `npm run build` in the plugin directory.', 'smaily-connect' )
				);
			}
		);
		return;
	}

	wp_enqueue_script(
		'smaily-connect-admin',
		$dist_url . '/admin.js',
		array( 'wp-i18n' ),
		(string) filemtime( $js_path ),
		true
	);

	// Load the JS translation catalog for the admin bundle. UI strings are
	// wrapped with a thin wp.i18n shim (admin/src/lib/i18n.ts); `wp i18n make-json`
	// emits the catalog hashed to dist/admin/admin.js (see the build:i18n step).
	wp_set_script_translations(
		'smaily-connect-admin',
		'smaily-connect',
		SMAILY_CONNECT_PLUGIN_PATH . 'languages'
	);

	// ESM bundle — Vite emits `type="module"` markup. WordPress' enqueue
	// API doesn't set the attribute directly; we tag it via the
	// script_loader_tag filter just for this handle.
	add_filter(
		'script_loader_tag',
		static function ( string $tag, string $handle ): string {
			if ( $handle === 'smaily-connect-admin' ) {
				return str_replace( ' src=', ' type="module" src=', $tag );
			}
			return $tag;
		},
		10,
		2
	);

	if ( file_exists( $css_path ) ) {
		wp_enqueue_style(
			'smaily-connect-admin',
			$dist_url . '/admin.css',
			array(),
			(string) filemtime( $css_path )
		);
	}

	$detector = new EnvDetector();

	// Sub-PR 2.H.8 — buildHash for "is staging running the code I just
	// shipped?" certainty. composer run package writes build-hash.txt at
	// packaging time (short git SHA + dirty marker). PHP reads it
	// here and emits to the boot payload; `window.smailyConnectBoot.buildHash`
	// in the browser console answers the question without rebuilding.
	// It lives at the plugin ROOT, not inside dist/: vite empties its out-dir
	// on every build, which used to wipe the stamp and fail BuildHashTest on a
	// build artifact rather than on the change under test (PRO-1781).
	$build_hash_file = SMAILY_CONNECT_PLUGIN_PATH . 'build-hash.txt';
	$build_hash      = file_exists( $build_hash_file )
		? trim( (string) file_get_contents( $build_hash_file ) ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		: 'dev';

	$boot = array(
		'buildHash'     => $build_hash,
		'nonce'         => wp_create_nonce( 'wp_rest' ),
		// Trailing slash matters — configureApiClient strips it,
		// but the rest_url() helper returns it without one on some
		// permalink configurations. Always emit canonical form.
		'restUrl'       => esc_url_raw( rest_url( 'smaily-connect/v1/' ) ),
		'view'          => isset( $_GET['page'] ) && $_GET['page'] === 'smaily-connect-settings' ? 'settings' : 'wizard', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		'envSnapshot'   => $detector->snapshot(),
		'savedSettings' => $detector->saved_settings(),
	);

	wp_add_inline_script(
		'smaily-connect-admin',
		'window.smailyConnectBoot = ' . wp_json_encode( $boot ) . ';',
		'before'
	);
}

// smaily_connect_hide_legacy_menu() was removed in F3-45: the legacy admin that
// registered the old "smaily-connect" menu is gone, so there's nothing to hide.
