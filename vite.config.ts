import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'node:path';

/**
 * Single Vite config with two named build entries:
 *
 *   admin/admin        → dist/admin/admin.js + dist/admin/admin.css
 *   public/js/sc-runtime → dist/public/js/sc-runtime.js
 *
 * Erkki ratified the single-config approach for sub-PR 2.A. We split into
 * separate `vite.*.config.ts` files only if the build-time options start
 * to diverge in ways named modes can't express (no projection of that yet).
 *
 * The admin bundle is consumed by the WordPress admin (loaded with
 * wp_enqueue_script in admin/settings.php and admin/wizard.php in
 * sub-PR 2.H). It imports React, ReactDOM, the admin/src/* TypeScript
 * tree, and Tailwind via the index.css side-effect import.
 *
 * The WordPress-free RecEngineClient (public/js/lib/rec-engine-client.ts) is
 * NOT an entry of its own — beacon.ts inlines it, and it stays as source for
 * the Milestone-2 extraction into @smaily/recengine-client. There is no
 * `client` mode; `--mode admin` and the default mode build the same two
 * entries (PRO-1949 removed the `build:client` script that implied otherwise).
 *
 * A THIRD entry — the attribution-only storefront bundle (public/js/landing.ts
 * → dist/public/js/sc-landing.js, PRO-1767) — is built in its OWN pass
 * (`--mode landing`, appending to the dist the main pass just wrote). It shares
 * public/js/lib/attribution.ts with the browse runtime on purpose (one capture
 * implementation for both), and Rollup moves a module used by two entries of
 * the SAME build into a shared chunk — which gives both bundles a top-level
 * `import`, and neither then loads as the classic <script> StorefrontBeacon
 * enqueues. Separate passes keep the shared code inlined in both. Every build
 * script chains the landing pass, because the main pass runs with emptyOutDir
 * and would otherwise leave dist without it.
 */
export default defineConfig(({ mode }) => {
  const input: Record<string, string> = mode === 'landing'
    ? {
      // The attribution-only writer — URL params in, cookies out, nothing
      // else. Neutral shipped name, same rule as sc-runtime.js (F3-41).
      'public/js/sc-landing': resolve(__dirname, 'public/js/landing.ts'),
    }
    : {
      'admin/admin': resolve(__dirname, 'admin/src/index.tsx'),
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
    emptyOutDir: mode !== 'landing',
    sourcemap: true,

    rollupOptions: {
      // Both entries are side-effect apps (admin renders React; beacon boots
      // the storefront tracker). Neither is consumed as a library, so let
      // Rollup drop unused exports — the built bundles then carry no top-level
      // `export`, which is what lets sc-runtime.js load as a classic <script>
      // (StorefrontBeacon enqueues it without type="module").
      preserveEntrySignatures: false,

      input,
      output: {
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
