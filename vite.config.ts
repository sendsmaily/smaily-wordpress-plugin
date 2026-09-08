import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'node:path';

/**
 * Single Vite config, THREE build passes — one entry per pass, every bundle an
 * IIFE:
 *
 *   (default / --mode admin)  admin/admin          → dist/admin/admin.js + admin.css
 *   --mode runtime            public/js/sc-runtime → dist/public/js/sc-runtime.js
 *   --mode landing            public/js/sc-landing → dist/public/js/sc-landing.js
 *
 * Erkki ratified the single-config approach for sub-PR 2.A. We split into
 * separate `vite.*.config.ts` files only if the build-time options start
 * to diverge in ways named modes can't express (no projection of that yet).
 *
 * WHY ONE ENTRY PER PASS AND WHY IIFE (PRO-2391, the MiuMjau variable-product
 * outage, 2026-09-08): every bundle here is enqueued as a CLASSIC <script>. Up
 * to 3.12.0 the admin and runtime entries shared one pass, and a multi-entry
 * Rollup build can only emit the `es` format — whose output has NO wrapper, so
 * its minified top-level `const m=…,_=/^vt_…/` became GLOBAL LEXICAL bindings.
 * A top-level `const` in a classic script shadows the same-named `window`
 * property for every script that loads after it: `underscore.min.js` still
 * set `window._`, but the bare `_` that `wp-util` reads at
 * `wp.template = _.memoize(…)` resolved to our RegExp — `_.memoize is not a
 * function` — and WooCommerce's variation form (which depends on wp-util)
 * never enabled the add-to-cart button on any variable product. The `es`
 * output was also one collision away from a SyntaxError against any other
 * classic script declaring a top-level `let m`/`const w`, and admin.js's
 * top-level `var wc=…` clobbered WooCommerce admin's `window.wc` namespace.
 * IIFE output wraps the whole bundle in `(function(){…})()`, so nothing leaks;
 * Rollup allows `iife` only for a single-entry build, hence one pass per entry.
 * `bin/check-bundle-scope.sh` proves it after every build and inside the
 * release-ZIP gate.
 *
 * The admin bundle is consumed by the WordPress admin (loaded with
 * wp_enqueue_script in admin/settings.php and admin/wizard.php in
 * sub-PR 2.H). It imports React, ReactDOM, the admin/src/* TypeScript
 * tree, and Tailwind via the index.css side-effect import.
 *
 * The WordPress-free RecEngineClient (public/js/lib/rec-engine-client.ts) is
 * NOT an entry of its own — beacon.ts inlines it, and it stays as source for
 * the Milestone-2 extraction into @smaily/recengine-client.
 *
 * The attribution-only storefront bundle (public/js/landing.ts →
 * dist/public/js/sc-landing.js, PRO-1767) shares public/js/lib/attribution.ts
 * with the browse runtime on purpose (one capture implementation for both);
 * separate passes keep the shared code inlined in both instead of a shared
 * chunk with a top-level `import`. Only the first pass empties dist/; every
 * build script chains all three (see package.json `build:admin`).
 */
export default defineConfig(({ mode }) => {
  const input: Record<string, string> = mode === 'landing'
    ? {
      // The attribution-only writer — URL params in, cookies out, nothing
      // else. Neutral shipped name, same rule as sc-runtime.js (F3-41).
      'public/js/sc-landing': resolve(__dirname, 'public/js/landing.ts'),
    }
    : mode === 'runtime'
      ? {
        // beacon.ts inlines RecEngineClient (rec-engine-client.ts is NOT a
        // separate entry, so there is no shared chunk and the bundle has no
        // top-level `import`). The lib stays as source for the Milestone-2
        // npm extraction. The OUTPUT is deliberately named `sc-runtime.js` (not
        // `beacon.js`): the source name `beacon` matches EasyPrivacy ad-block
        // filter lists, which blocked the storefront request for real users
        // (the route is renamed off `/beacon` → `/relay` for the same reason).
        // The entry-key IS the output basename (`[name].js`), so the source file
        // keeps its name and only the shipped filename changes (F3-41).
        'public/js/sc-runtime': resolve(__dirname, 'public/js/beacon.ts'),
      }
      : {
        'admin/admin': resolve(__dirname, 'admin/src/index.tsx'),
      };

  return {
  plugins: [react()],

  // Vite's publicDir default is `public/` — which here is the plugin's
  // storefront PHP tree plus the TypeScript SOURCES of the very bundles this
  // config builds. Copied verbatim into dist/, they shipped in the release ZIP
  // (raw *.ts including *.test.ts, a duplicate smaily-public.class.php, the
  // partials/template PHP). Nothing in public/ is a static asset the built
  // bundles need, so the copy step is switched off entirely.
  publicDir: false,

  build: {
    outDir: 'dist',
    // The admin pass runs first and empties dist/; the two storefront passes
    // append to it.
    emptyOutDir: mode !== 'landing' && mode !== 'runtime',
    sourcemap: true,
    // With a non-ES output format Vite would otherwise INJECT the CSS from JS
    // (a runtime <style> tag) instead of emitting dist/admin/admin.css — which
    // admin/wizard.php and admin/settings.php enqueue as a real stylesheet.
    // A single extracted file is exactly what we want (one entry per pass).
    cssCodeSplit: false,

    rollupOptions: {
      // Every entry is a side-effect app (admin renders React; the storefront
      // bundles boot themselves). None is consumed as a library, so let Rollup
      // drop unused exports — an IIFE with exports would need a global `name`.
      preserveEntrySignatures: false,

      input,
      output: {
        // Wrapped in `(function(){…})()` — see the header comment (PRO-2391).
        format: 'iife',
        entryFileNames: '[name].js',
        // Chunks shared between the two entries land in a neutral folder
        // so neither bundle's directory carries hash-named artifacts.
        chunkFileNames: 'shared/[name]-[hash].js',
        // CSS produced by the admin entry needs to land next to admin.js.
        // The asset-info name carries the originating source filename.
        assetFileNames: (assetInfo) => {
          if (assetInfo.name?.endsWith('.css')) {
            return 'admin/admin.css';
          }
          return 'shared/[name]-[hash][extname]';
        },
      },
    },
  },

  resolve: {
    alias: {
      '@admin': resolve(__dirname, 'admin/src'),
      '@client': resolve(__dirname, 'public/js/lib'),
    },
  },

  server: {
    port: 5173,
    strictPort: false,
  },
  };
});
