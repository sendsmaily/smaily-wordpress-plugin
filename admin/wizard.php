<?php
/**
 * Setup Wizard admin page mount.
 *
 * Registers the Smaily Connect menu (Dashboard, Initial setup, Settings,
 * Log), enqueues the Vite-built admin bundle, and renders the React mount
 * node with the page's data-view. State hydration happens client-side via
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
 * Registers the Dashboard, Initial setup, Settings and Log pages under a
 * top-level "Smaily Connect" menu. Hooked from Bootstrap::boot() onto
 * admin_menu.
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

	// The menu reads Dashboard, Initial setup, Settings, Log, as Magento's
	// and PrestaShop's do (CON-464). The top-level item opens the Dashboard;
	// until setup is finished the Dashboard, Settings and the Log send the
	// store to the Initial setup instead (smaily_connect_require_finished_setup).
	// The older page slugs stay, so bookmarks and the links in admin notices
	// still resolve.
	$gated = array(
		add_menu_page(
			__( 'Smaily Connect', 'smaily-connect' ),
			'Smaily Connect',
			$capability,
			'smaily-connect-dashboard',
			'smaily_connect_render_dashboard_page',
			$icon,
			56
		),
	);

	add_submenu_page(
		'smaily-connect-dashboard',
		__( 'Dashboard', 'smaily-connect' ),
		__( 'Dashboard', 'smaily-connect' ),
		$capability,
		'smaily-connect-dashboard',
		'smaily_connect_render_dashboard_page'
	);

	add_submenu_page(
		'smaily-connect-dashboard',
		__( 'Initial setup', 'smaily-connect' ),
		__( 'Initial setup', 'smaily-connect' ),
		$capability,
		'smaily-connect-wizard',
		'smaily_connect_render_wizard_page'
	);

	$gated[] = add_submenu_page(
		'smaily-connect-dashboard',
		__( 'Settings', 'smaily-connect' ),
		__( 'Settings', 'smaily-connect' ),
		$capability,
		'smaily-connect-settings',
		'smaily_connect_render_settings_page'
	);

	$gated[] = add_submenu_page(
		'smaily-connect-dashboard',
		__( 'Log', 'smaily-connect' ),
		__( 'Log', 'smaily-connect' ),
		$capability,
		'smaily-connect-log',
		'smaily_connect_render_log_page'
	);

	foreach ( $gated as $hook_suffix ) {
		if ( is_string( $hook_suffix ) && $hook_suffix !== '' ) {
			add_action( 'load-' . $hook_suffix, 'smaily_connect_require_finished_setup' );
		}
	}
}

/**
 * Wizard-first gate: until the Initial setup is finished, the Dashboard,
 * Settings and the Log send the store to it, as Magento's pages do. Runs on
 * the page's `load-` hook, before any output, so the redirect can still be
 * sent. The boot payload's `setupCompleted` flag flips when the setup's
 * Finish runs.
 */
function smaily_connect_require_finished_setup(): void {
	if ( ! \Smaily\Connect\Settings\SetupState::completed() ) {
		wp_safe_redirect( admin_url( 'admin.php?page=smaily-connect-wizard' ) );
		exit;
	}
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
 */
function smaily_connect_render_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'smaily-connect' ) );
	}

	smaily_connect_emit_mount( 'settings' );
}

/**
 * Render the Dashboard mount node (CON-464): the plugin's state at a glance.
 */
function smaily_connect_render_dashboard_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'smaily-connect' ) );
	}

	smaily_connect_emit_mount( 'dashboard' );
}

/**
 * Render the Log mount node (CON-464): the Event Log on its own page.
 */
function smaily_connect_render_log_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'smaily-connect' ) );
	}

	smaily_connect_emit_mount( 'log' );
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
 * @param 'wizard'|'settings'|'dashboard'|'log' $view
 */
function smaily_connect_emit_mount( string $view ): void {
	// Always-visible link to the merchant documentation site, right where a
	// merchant lands during setup — help material one click away on install.
	// Rendered PHP-side on the wizard; the Dashboard carries its "User
	// Guide" link in its quick links (CON-464). URL is centralized in
	// Constants::docs_url() (one change when docs move to connect.smaily.com).
	$docs_url = \Smaily\Connect\Constants::docs_url();
	?>
	<div class="wrap" id="smaily-connect-wrap">
		<hr class="wp-header-end">
		<?php if ( $view === 'wizard' ) : ?>
		<p class="smaily-connect-docs-link" style="margin:0 0 6px;text-align:right;font-size:13px;">
			<a href="<?php echo esc_url( $docs_url ); ?>" target="_blank" rel="noopener noreferrer">
				<span class="dashicons dashicons-book-alt" style="vertical-align:text-bottom;"></span>
				<?php echo esc_html__( 'Documentation', 'smaily-connect' ); ?>
			</a>
		</p>
		<?php endif; ?>
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

	// React comes from WordPress core (CON-314): the bundle reads the globals
	// the `react`, `react-dom` and `react-jsx-runtime` handles set. It is an
	// IIFE, loaded as a classic script in the footer, after its mount node.
	wp_enqueue_script(
		'smaily-connect-admin',
		$dist_url . '/admin.js',
		array( 'react', 'react-dom', 'react-jsx-runtime', 'wp-i18n' ),
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

	// The same rule the mount node's data-view follows: each page slug has
	// its own screen, and every other Smaily Connect page is the wizard.
	$views = array(
		'smaily-connect-dashboard' => 'dashboard',
		'smaily-connect-settings'  => 'settings',
		'smaily-connect-log'       => 'log',
	);
	$page  = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$view  = $views[ $page ] ?? 'wizard';

	$boot = array(
		'buildHash'       => $build_hash,
		// The installed version, shown at the bottom of Settings (CON-302).
		'version'         => SMAILY_CONNECT_VERSION,
		'nonce'           => wp_create_nonce( 'wp_rest' ),
		// Trailing slash matters — configureApiClient strips it,
		// but the rest_url() helper returns it without one on some
		// permalink configurations. Always emit canonical form.
		'restUrl'         => esc_url_raw( rest_url( 'smaily-connect/v1/' ) ),
		'view'            => $view,
		'envSnapshot'     => $detector->snapshot(),
		// The confirmation cards' status line is shown in Settings only.
		'savedSettings'   => $detector->saved_settings( $view === 'settings' ),
		// "Edit design" opens the engine's design page in a frame over the
		// admin only where the engine allows it (CON-288), else in a new tab.
		'designFrameable' => \Smaily\Connect\REST\EmailDesignsEndpoint::frameable( (string) get_site_url(), (string) admin_url() ),
		// Which invitation banners the screens show (CON-350, CON-351).
		'banners'         => \Smaily\Connect\Notifications\NotificationManager::banner_boot(),
		// The signed-in admin's own email: the default address of an
		// automation's "Send test email" (CON-455). Never a customer's.
		'adminEmail'      => (string) wp_get_current_user()->user_email,
	);

	wp_add_inline_script(
		'smaily-connect-admin',
		'window.smailyConnectBoot = ' . wp_json_encode( $boot ) . ';',
		'before'
	);
}

// smaily_connect_hide_legacy_menu() was removed in F3-45: the legacy admin that
// registered the old "smaily-connect" menu is gone, so there's nothing to hide.
