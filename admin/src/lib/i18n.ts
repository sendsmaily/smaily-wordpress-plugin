/**
 * Thin wrapper over WordPress' `wp.i18n` runtime.
 *
 * The admin bundle is a self-contained Vite ESM app (it does not externalize the
 * `@wordpress/*` packages), so instead of bundling a second copy of
 * `@wordpress/i18n` we read `window.wp.i18n` at call time. WordPress provides it
 * via the `wp-i18n` script dependency, and the translations registered by
 * `wp_set_script_translations()` apply automatically. When `wp.i18n` is absent
 * (unit tests, or before the runtime loads) the source string is returned, so the
 * UI degrades to English rather than breaking.
 *
 * Always call with the literal text domain so `wp i18n make-pot` can extract the
 * strings, e.g. `__( 'Connect', 'smaily-connect' )`. For interpolation, wrap the
 * format string and pass values through `sprintf`:
 * `sprintf( __( 'Synced %d products', 'smaily-connect' ), count )`.
 */

const DOMAIN = 'smaily-connect';

interface WpI18n {
	__: ( text: string, domain?: string ) => string;
	_n: ( single: string, plural: string, n: number, domain?: string ) => string;
	sprintf: ( format: string, ...args: Array< string | number > ) => string;
}

function runtime(): WpI18n | undefined {
	if ( typeof window === 'undefined' ) {
		return undefined;
	}
	return ( window as unknown as { wp?: { i18n?: WpI18n } } ).wp?.i18n;
}

/** Translate a string. */
export function __( text: string, domain: string = DOMAIN ): string {
	return runtime()?.__( text, domain ) ?? text;
}

/** Translate with singular/plural selection. */
export function _n(
	single: string,
	plural: string,
	n: number,
	domain: string = DOMAIN
): string {
	const fn = runtime()?._n;
	return fn ? fn( single, plural, n, domain ) : n === 1 ? single : plural;
}

/**
 * printf-style interpolation. Delegates to wp.i18n.sprintf when available
 * (production). The fallback (tests / before the runtime loads) covers the
 * subset the UI uses: `%s`, `%d`, positional `%1$s` / `%2$d`, and `%%` → `%`.
 */
export function sprintf( format: string, ...args: Array< string | number > ): string {
	const fn = runtime()?.sprintf;
	if ( fn ) {
		return fn( format, ...args );
	}
	let auto = 0;
	return format.replace( /%%|%(\d+\$)?[sd]/g, ( match: string, pos?: string ): string => {
		if ( match === '%%' ) {
			return '%';
		}
		const index = pos ? parseInt( pos, 10 ) - 1 : auto++;
		return String( args[ index ] ?? '' );
	} );
}
