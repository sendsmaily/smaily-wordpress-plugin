# Smaily Connect — Manual sanity-test

This guide stands up a local WordPress staging environment that mirrors
the pilot client stack (WP 6.9.4 + PHP 8.3 + WC 10.7 + Polylang) via
`@wordpress/env` and walks through the wizard + settings panels end to
end. It is the same sequence Erkki runs on his staging WP to validate
each sub-PR, just compressed to ~60 seconds via Docker.

## Prerequisites

- Docker daemon running (`docker info` returns successfully).
- Node 22 + npm available on the host (CI version — matches the
  package-lock.json resolution).
- `npx @wordpress/env` available (no global install required — `npx`
  downloads the package on first run). Use the full package name: the bare
  `npx wp-env` alias only prints a deprecation notice and exits 0 without
  starting anything.

## One-time setup

```bash
npm install
npm run build           # produces dist/admin/admin.js + admin.css
composer install        # vendor/ needs to be present for the plugin to boot
```

Without `dist/admin/admin.js` the React mount node stays empty and an
admin-notice asks you to run `npm run build`. Without `vendor/` the
plugin throws a fatal on activation (Composer autoload missing).

## Start the environment

```bash
npx @wordpress/env start
```

First run pulls the WordPress core image, the PHP 8.3 base, the
WooCommerce 10.7 ZIP, and the Polylang plugin from the WP.org plugin
directory. Subsequent runs reuse the cached layers and complete in a
few seconds.

The container exposes:

| URL                          | Purpose                  |
|------------------------------|--------------------------|
| http://localhost:8888        | Front-end (visitor view) |
| http://localhost:8888/wp-admin | Admin (login: admin / password) |

The default admin credentials are `admin` / `password`.

## Activate the plugin + dependencies

1. Visit `/wp-admin/plugins.php`.
2. Activate **Smaily Connect** (this plugin, mapped to
   `wp-content/plugins/smaily-connect`).
3. Activate **WooCommerce** — it will request to run its onboarding
   wizard; you can skip every step (we only need WC's classes loaded
   so the WC HPOS detection + order-count queries return real data).
4. Activate **Polylang** — opens its first-run wizard. Add two
   languages (e.g. English + Estonian). This puts the site in a
   multilingual state so Mode A in Step 1 unlocks.
5. (Optional) WC → Settings → Advanced → Features → enable
   **High-performance order storage**. With WC 10.7 this is on by
   default for fresh installs; the toggle is a sanity-check.

## Sanity-test the wizard

1. Visit **Smaily Connect → Setup wizard** in the sidebar.
2. Step 1 (Connect) — enter dummy subdomain + username + a password.
   Click **Test connection**. The button should transition pending
   → error (we don't have real Smaily creds), with an inline error
   banner under the card.
3. The MultilingualModePicker should be visible BELOW the credential
   block because Polylang detected two languages.
4. Pick Mode A → two extra credential blocks render, one per
   language. Switch back to Mode B → the per-language cards disappear
   (a `window.confirm()` will ask first if you'd already filled them).
5. With a fake "successful" connection still impossible, the Continue
   button stays disabled and the advance-hint says "Test your Smaily
   connection to continue."
6. Click the **StepRail** to jump to a completed step — only steps
   marked completed should be clickable; pending steps are not.

## Sanity-test the settings panel

1. Visit **Smaily Connect → Settings**.
2. The page lands on the **Connection** tab (URL: `#connection` in the
   hash).
3. Edit any field — the **Save changes** + **Discard changes** buttons
   in the footer should enable.
4. Click the **Subscribers** tab — the footer Save/Discard buttons
   should be disabled (Subscribers tab is not dirty).
5. Toggle "Sync contacts to Smaily" off, then on — Subscribers tab
   should now be dirty.
6. Click the **Integrations** tab — the Save/Discard footer should be
   HIDDEN (Integrations is read-only).
7. Browser back/forward should switch tabs without re-rendering
   everything.

## What to look for

- React DevTools (if installed) shows the `<App>` → `<Wizard>` (or
  `<Settings>`) → step / tab tree.
- The browser console should show no errors. A single WP-emitted
  warning about "skipping translations" for `smaily-connect` is
  expected — Phase 4 ships the `.mo` files.
- `window.smailyConnectBoot` in the console returns the env snapshot
  + saved-settings payload.

## Re-testing "fresh install"

WordPress's plugin **Deactivate** + **Delete** flow does NOT remove rows
from `wp_options` — uninstall.php is what wipes plugin state, and it
only runs when the merchant explicitly clicks **Delete** in the
plugins screen. Reactivating a deleted plugin therefore reads back any
flags that survived (notably `smly_plus_setup_completed`), so the
wizard-first gate at `/wp-admin/admin.php?page=smaily-connect-settings`
thinks the merchant has already onboarded.

To run a TRUE fresh-install test:

1. **Plugin Delete via wp-admin** — uninstall.php fires automatically.
   This is the production-correct path; use it for the canonical
   regression test.
2. **Or via wp-cli** when scripting:

   ```bash
   wp plugin uninstall smaily-connect          # fires uninstall.php
   wp plugin install /path/to/smaily-connect.zip --activate
   ```

3. **Or via SQL** when neither of the above is convenient (smoke-
   testing the wizard-first gate without re-uploading the ZIP):

   ```bash
   wp option delete smly_plus_setup_completed
   wp option delete smly_plus_default_connection_verified
   wp option delete smaily_connect_api_credentials
   # …or wipe everything Smaily Connect owns:
   wp db query "DELETE FROM wp_options WHERE option_name LIKE 'smly_plus_%'"
   ```

After any of the above, visiting `/wp-admin/admin.php?page=smaily-connect-settings`
should redirect to `?page=smaily-connect-wizard`. The previously-misleading
"my fresh install opens Settings" symptom was tracked to leftover
`wp_options` rows — see commit log for sub-PR 2.J.

## Tear-down

```bash
npx @wordpress/env stop      # keeps volumes, restart with `start` later
npx @wordpress/env destroy   # full reset, wipes the wp_options table too
```

## Known limitations (Phase 2 scope)

- Step 1 → 6 navigation works but the Finish button currently
  redirects to the Settings page without persisting. Bulk-save across
  the four tab payloads lands in Phase 3.
- rec-engine setup-token validation is stubbed (Phase 3 wires the
  real /rec-engine/v1/auth endpoint).
- Real Smaily account testing requires either a live `subdomain.sendsmaily.net`
  pilot account or a mocked REST stub — neither is provided by this
  env. The CONNECTION TEST FAILURE path is what the wizard surfaces.
