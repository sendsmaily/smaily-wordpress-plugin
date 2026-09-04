# MIGRATION.md — Upgrading Smaily Connect from 2.0.0 to 3.11.2

This guide is for sites currently running **Smaily Connect 2.0.0** from
wordpress.org — the last release of the plugin's 1.x line. It describes the
upgrade to **Smaily Connect 3.11.2**, the first wordpress.org release of the
new (3.x) version. If you're installing the plugin fresh on a clean WordPress
site, see `INSTALL.md` instead.

**Which versions this covers.** `2.0.0 → 3.11.2`, delivered by WordPress's own
plugin updater on the same `smaily-connect` slug. It is the same plugin, in the
same folder, updated in place. (The new version line was numbered "2.0" while it
was in development; it is released as 3.x because the 1.x line already occupies
`2.0.0` on wordpress.org.)

**Read this document fully before starting the upgrade.** The upgrade is designed
to be safe and reversible, but a few specifics about how 3.11.2 coexists with
your existing 2.0.0 settings are important to understand in advance.

---

## TL;DR — what to expect

- **It's an in-place update.** Same plugin slug, same folder, same file. WordPress
  replaces 2.0.0 with 3.11.2 like any other plugin update.
- **Your existing data and credentials persist.** Smaily account, subscriber
  sync settings, WooCommerce integration — all continue working.
- **Live contact sync continues uninterrupted** during the upgrade: a customer
  who checks out or registers an account is synced to Smaily exactly as before,
  with no window where that stops.
- **The daily catch-up sync pauses until you finish the wizard.** The scheduled
  once-a-day pass that re-syncs existing contacts waits for you to confirm your
  setup, so an upgraded store makes no scheduled calls to Smaily before you have
  reviewed the settings. It resumes automatically once the wizard is finished.
- **A new setup wizard runs the first time you open the plugin in WP-admin**
  after upgrading. The 2.0.0 admin pages are replaced by it, but the underlying
  data is untouched.
- **After the wizard finishes**, the new code takes over subscriber syncing and
  the 2.0.0 hooks are deactivated. You get access to new features (Backfill,
  Campaign Intelligence).
- **Rollback is possible** by reinstalling 2.0.0 from wordpress.org — your data
  is not destroyed.

The upgrade was rehearsed end to end before release: a throwaway WordPress site
running the real wordpress.org 2.0.0 package, configured the way a merchant is,
taken onto the 3.x package the same way the updater takes it. What that
rehearsal observed is what this guide describes.

---

## Before you start

### Required reading

This document, in full. Take 10 minutes.

### Required actions

1. **Take a full backup** of your WordPress site (files + database). Any
   reputable backup plugin works (UpdraftPlus, BackWPup, your hosting provider's
   built-in backup). **Do not skip this step.**

2. **Note your current Smaily account settings**, in case you ever need to
   reconfigure manually:
   - Smaily subdomain (e.g. `mystore` from `mystore.sendsmaily.net`)
   - API username
   - Which subscribers list(s) are receiving WooCommerce contacts

3. **Verify version compatibility:**
   - WordPress: **6.6 or later** (tested up to 7.0)
   - WooCommerce: **6.9 or later** (tested up to 10.7)
   - PHP: **8.0 or later** (8.3 recommended)

   You can check these in `Tools → Site Health → Info` in WP-admin.

4. **Plan a low-traffic window** for the upgrade itself (~10 minutes of admin
   work, plus 24 hours of light observation). Choose a time when checkout
   activity is low.

---

## The upgrade procedure

### Recommended: the WordPress plugin updater

Once 3.11.2 is published, WordPress offers it like any other plugin update:

1. Go to `Dashboard → Updates`, or `Plugins → Installed Plugins`
2. Find **Smaily Connect** and click `Update now`
3. **Wait** for the update to complete (typically 10-30 seconds)
4. The plugin remains active. You should see the standard "Plugin updated
   successfully" message.

If you have auto-updates enabled for this plugin, WordPress installs 3.11.2 on
its own — nothing to do, but still walk through the first-hour checks below when
you next open WP-admin.

### CLI: WP-CLI

For sites managed via SSH or CI:

```bash
wp plugin update smaily-connect
```

### Manual install from the release ZIP (agencies, staging sites)

If you're testing on a staging copy before the directory update reaches
production, or your environment installs plugins from a ZIP:

1. `Plugins → Add New Plugin → Upload Plugin` → choose the 3.11.2 ZIP
2. When asked "Replace current with uploaded?", click **Replace**
3. The plugin stays active

The WP-CLI equivalent is:

```bash
wp plugin install /path/to/smaily-connect.zip --force
wp plugin deactivate smaily-connect
wp plugin activate smaily-connect
```

**Important:** the `deactivate` + `activate` step is worth doing after
`--force`, because `--force` on an already-active plugin doesn't trigger the
activation hook on its own. The plugin's built-in upgrade detector (see below)
catches this on the next WP-admin page load anyway, but explicit
deactivate-activate runs it immediately and is cleaner.

---

## What happens during the upgrade

3.11.2 contains both the 2.0.0 code (`Smaily_Connect\*` namespace) and the new
code (`Smaily\Connect\*` namespace) in the same plugin. They run side by side
until you finish the new setup wizard. Here's what changes immediately:

| What | Change | Impact on you |
|------|--------|---------------|
| Smaily credentials | Kept; the stored password is silently re-encrypted to a stronger format and still decrypts | None — your subdomain, username, and password keep working |
| Subscriber sync settings | Kept as saved in 2.0.0, including which optional fields you sync and the checkout opt-in setting, and read by the new code | None — the same contacts, the same fields |
| Abandoned cart settings | Kept, including your order-status selection and your cutoff (e.g. 45 minutes) | None — the same timing, on the new pipeline |
| RSS feed settings | Kept as saved | None — your existing feed URLs keep working |
| Subscriber sync | Continues via the 2.0.0 code (the new code is gated until the wizard finishes) | None — contacts continue flowing to Smaily |
| Admin menu | The 2.0.0 "Smaily" pages are replaced by the new **Smaily Connect** menu; the old Settings URL redirects into the wizard | The plugin's admin area looks new |
| Database tables | New tables (`smly_plus_*`, `smly_rec_*`) are created automatically | Background change; you don't need to do anything |
| Abandoned-cart table | The 2.0.0 table is kept untouched; any cart in it that was never sent is moved once into the new cart storage | Carts in flight at upgrade time are not lost |
| Scheduled jobs | The three 2.0.0 WP-Cron events are cleared and recurring Action Scheduler jobs are scheduled in their place | More reliable background work; you can monitor in `Tools → Scheduled Actions` |

Deactivating and reactivating the plugin afterwards (or reactivating
WooCommerce) is safe: it does not duplicate scheduled jobs, does not re-run the
one-time migrations, and does not bring the old cron events back.

**Existing Smaily templates that reference fields like `{{ first_name }}`
continue to work** — the new code uses the same field-naming convention as
2.0.0.

---

## The first hour after upgrading

Open WP-admin within an hour of upgrading and walk through these checks. They
take about 10 minutes.

### Step 1: Confirm the new admin menu appears

1. Reload any WP-admin page
2. Look for **Smaily Connect** in the left sidebar (top-level menu, with the
   Smaily logo)
3. The 2.0.0 "Smaily" admin page is gone. Its Settings URL
   (`admin.php?page=smaily-connect-settings`) now opens the new plugin and
   redirects into the wizard until setup is finished.

If the old menu is still visible after a hard reload, see Troubleshooting below.

### Step 2: The wizard should launch

1. Click `Smaily Connect` in the sidebar
2. The setup wizard should open on Step 1 (Create a connection)
3. Your existing Smaily **subdomain** and **API username** are **pre-filled**.
   The **API password** field is empty — the plugin never sends a stored
   password back to the browser. Leave it empty: **Test connection** uses the
   password already stored for that account. Type one only if you're switching
   to a different Smaily account or rotating the password.

If the wizard doesn't launch automatically, you can open it directly at
`/wp-admin/admin.php?page=smaily-connect-wizard`.

### Step 3: Verify subscriber sync is still working (without touching anything)

Until you finish the wizard, the **live** sync is the only sync running: a
customer who checks out or registers is synced through the 2.0.0 code path, and
the scheduled daily catch-up stays paused (see "Don't skip the wizard
indefinitely" below). So this check is a check of the live path.

1. **Do not complete the wizard yet.**
2. Open a private/incognito window and make a small WooCommerce purchase (a
   test product, low price, your own email)
3. Wait 1-2 minutes
4. Check your Smaily account — the test contact should appear, exactly as it
   did before the upgrade

If the contact arrives, **live sync is healthy**. You can proceed to the wizard
at your convenience.

### Step 4: Check the database

In WP-admin, go to `Tools → Site Health → Info → Database`. Verify the new
tables exist:

- `wp_smly_plus_event_queue` (or your prefix instead of `wp_`)
- `wp_smly_plus_backfill_job`
- `wp_smly_plus_automation_mapping`
- `wp_smly_plus_cart_session`
- `wp_smly_rec_event_queue`
- `wp_smly_rec_visitor`

If any are missing, the activation hook may not have run. See "Activation hook
didn't fire" in Troubleshooting.

### Step 5: Check Action Scheduler

`Tools → Scheduled Actions`. Filter by `smly_`. You should see recurring jobs
scheduled (contact sync, abandoned cart, queue flush, retry). Status should be
`Pending` or `Complete`, not `Failed`.

---

## Completing the wizard

When you're ready (any time within the first few days after upgrading), complete
the setup wizard. This is when sync officially hands off from the 2.0.0 code to
the new code.

### What changes when you finish the wizard

The new code's hook handler activates and the 2.0.0 hooks are deregistered.
After this point:

- **Only the new code** handles new WooCommerce events (customer registration,
  account updates, checkout)
- **The daily catch-up sync resumes** on its next scheduled run
- **Existing data** (credentials, queued events, automation mappings) is
  preserved unchanged
- **Smaily-side data** (your subscriber lists, templates, automations) is
  completely unaffected — the change is only in how your WordPress site **sends**
  data to Smaily, not what's stored there

### The wizard steps

1. **Create a connection** — your subdomain and API username are pre-filled and
   the password field is empty; press **Test connection** (it reuses the stored
   password) and continue
2. **Contacts** — synchronization settings: which WordPress events trigger
   contact sync, and which optional fields are sent
3. **Automated letters** — triggers for automations (abandoned cart, welcome
   series, first order)
4. **Campaign Intelligence** (optional) — connect to Smaily Campaign
   Intelligence if you have a setup token from Smaily
5. **Subscription forms and RSS** — signup forms and the product RSS feed
6. **Overview** — summary and last check

**The wizard saves your choices step by step**, so you can close it and return
later without losing progress.

### After Finish

You'll be redirected to the new Settings page. Subscriber sync is now running
through the new code. You can verify by repeating the test purchase from Step 3
above — the contact should still arrive in Smaily, but now through the new code
path.

---

## Important warnings

### The 2.0.0 credential guard

The 2.0.0 code registers a WordPress filter
(`pre_update_option_smaily_connect_api_credentials`) that **protects** the
credential record from outside modification. This is a security feature, not a
bug.

**Practical implication:** if you ever need to change your Smaily credentials
through WP-CLI (`wp option update smaily_connect_api_credentials ...`) or
direct database access, that filter may reject the change. Use the wizard or the
new Settings page instead — those go through the proper code path that the guard
recognizes.

### Don't skip the wizard indefinitely

While the 2.0.0 code keeps live subscriber syncing alive without the wizard, the
new code's features (Backfill, Campaign Intelligence, the new abandoned-cart
workflow) are **locked** until the wizard finishes, and the daily catch-up sync
stays paused. New checkouts and registrations still reach Smaily, so using the
old sync path for a few days while you familiarize yourself with the new UI costs
you little — but plan to complete the wizard within a week or two so the daily
catch-up resumes and you can take advantage of the new features.

### Don't reinstall 2.0.0 on top of 3.11.2

If for any reason you upload the 2.0.0 ZIP **on top of** an installed 3.11.2,
the resulting state is undefined — the new plugin is overwritten by the old one
while the new database tables and settings remain. If you need to go back, use
the rollback procedure below instead.

### Your sale-price data is read fresh, not migrated

If your store uses sale prices, you might wonder whether your existing
discount data carries over to Campaign Intelligence. It doesn't need to —
and that's by design.

The plugin reads sale prices **fresh from WooCommerce** every time it syncs a
product (the regular price and the sale price are both pulled from the product
at sync time). It does not convert or copy any stored discount values from the
2.0.0 settings.

This matters because Campaign Intelligence's sale model uses a "compare-at"
convention: it stores the **higher** pre-sale reference price and compares it to
the **current** price to determine whether a product is on sale. The 1.x line
stored the **lower** on-sale price under a different name. A literal copy of the
old value into the new field would invert the meaning — genuinely discounted
products would read as "not on sale." Because the plugin re-reads live prices
from WooCommerce rather than copying old data, this inversion never happens.
Your sale prices simply work, with no migration step on your part.

---

## Rollback — if you need to go back to 2.0.0

The upgrade is reversible. Your data is not destroyed.

wordpress.org keeps every published release of a plugin: on the plugin's page in
the directory, open **Advanced View** and use the **Previous versions** section
to download the 2.0.0 ZIP.

### Steps

1. Download the 2.0.0 ZIP as described above
2. `Plugins → Installed Plugins → Smaily Connect → Deactivate`
3. `Plugins → Add New Plugin → Upload Plugin` → upload the 2.0.0 ZIP
4. When asked "Replace current with uploaded?", click **Replace**
5. `Activate`

2.0.0 resumes operation. Your `smaily_connect_*` settings are untouched, so it
picks up where it left off.

**After rolling back, check the connection.** The upgrade re-encrypted your
stored API password into the newer format; if 2.0.0 reports a credential or
connection error, re-enter the Smaily API password on its settings page. (The
rollback direction was not part of the pre-release upgrade rehearsal — the
forward path was. Report anything unexpected; see Support below.)

### What stays behind after rollback

The new database tables (`smly_plus_*`, `smly_rec_*`) remain. They don't
interfere with 2.0.0 (which doesn't know about them), but they take up a small
amount of space.

If you want to remove them:

```sql
DROP TABLE wp_smly_plus_event_queue;
DROP TABLE wp_smly_plus_backfill_job;
DROP TABLE wp_smly_plus_automation_mapping;
DROP TABLE wp_smly_plus_cart_session;
DROP TABLE wp_smly_rec_event_queue;
DROP TABLE wp_smly_rec_visitor;
DELETE FROM wp_options WHERE option_name LIKE 'smly_plus_%';
DELETE FROM wp_options WHERE option_name LIKE 'smly_rec_%';
```

(Replace `wp_` with your table prefix.) Do this only after rollback is
complete and verified.

### When rollback is appropriate

- A specific feature isn't working and you need to keep your site running
  while we investigate
- You need to revert to a known-good state before a high-traffic event

Rollback is **not** a permanent solution. If you hit an issue with 3.11.2,
please report it (see Support below) so it can be fixed; rollback is a temporary
safety net, not the recommended end state.

---

## Troubleshooting

### Wizard didn't launch automatically

Go directly to `/wp-admin/admin.php?page=smaily-connect-wizard`. If the page
loads, the plugin is working; the auto-launch is just a redirect convenience.

### The old "Smaily" menu still appears

Hard-reload WP-admin (`Ctrl+Shift+R` or `Cmd+Shift+R`). If still visible,
disable browser extensions that may cache admin pages, or open in a private
window.

If it persists across hard reloads and private windows, the plugin's admin-menu
hook may have failed to register. Check `Plugins → Installed Plugins` to confirm
Smaily Connect is active and shows version 3.11.2, and look at the debug log
(`wp-content/debug.log` if enabled) for PHP errors.

### Activation hook didn't fire (new tables missing)

This can happen with `wp plugin install --force` without a subsequent
deactivate-activate. The plugin includes an upgrade-detector that runs on the
next WP-admin page load — so the simplest fix is to **open any WP-admin page**
and the tables will be created automatically.

If they're still missing after that, manually trigger activation:

```bash
wp plugin deactivate smaily-connect
wp plugin activate smaily-connect
```

### Contact sync stopped working after the upgrade

Within the first hour, before completing the wizard:

1. Check that the plugin is running. Open the WP-admin Plugins page and verify
   `Smaily Connect` shows version **3.11.2** and is **Active**.
2. Look at `Tools → Scheduled Actions` for any failed actions related to
   `smaily_connect_*` or `smly_plus_contact_sync`.
3. Check `wp-content/debug.log` (if `WP_DEBUG_LOG` is enabled) for PHP errors.

Note that the daily catch-up sync is paused by design until you finish the
wizard — a `smly_plus_contact_sync` run that logs `skipped: setup not completed`
is expected, not a fault. Live syncing (checkout, registration) is unaffected.

After completing the wizard:

1. Verify the wizard reached the Overview step successfully (you were redirected
   to the new Settings page)
2. Check `Tools → Scheduled Actions` for failed `smly_plus_*` actions
3. The new Settings page has a "Test connection" button on the Connection tab
   — run it. If it fails, your credentials may need to be re-entered.

### Wizard Finish errors

Check `wp-content/debug.log`. The most common cause is the activation hook not
having run (see above) — the wizard's last step expects the new database tables
to exist.

If the log shows a "table doesn't exist" error, run the activation manually
(deactivate + activate), then return to the wizard.

### "Class not found" PHP errors

This usually indicates leftover files from a previous plugin version. The fix:

1. Deactivate Smaily Connect
2. SSH or FTP into the site
3. Navigate to `wp-content/plugins/smaily-connect/`
4. Confirm only the new version's files are present (the `Version:` header
   in `smaily-connect.php` should show `3.11.2` or later)
5. If there are unexpected `.php` files from older versions, delete the entire
   `smaily-connect` directory and reinstall the plugin cleanly

---

## After the first 24 hours

If everything is working, you don't need to do anything special. Subscriber
sync is healthy, the new Settings page is your interface, and the 2.0.0 code is
sitting dormant.

A few good practices for the first week:

- **Check the Smaily account daily** to confirm new contacts continue arriving
  at the expected rate
- **Monitor `Tools → Scheduled Actions`** for any failures (`Failed` status
  with a `smly_plus_*` hook name)
- **Try the new features** — Backfill (syncing historical customers to
  Smaily), the abandoned cart workflow, the new mobile-friendly Settings UI

If you see anything unexpected, see Support below.

---

## Support

For issues encountered during or after the upgrade:

- **Smaily support:** https://smaily.com/help/ (your first stop for any issue
  related to Smaily-side data, templates, or automations)
- **Plugin issues:** report on GitHub at
  https://github.com/sendsmaily/smaily-wordpress-plugin
- **Critical urgency** (site is down, customers can't checkout): immediately
  roll back to 2.0.0 (see Rollback above) and contact support with details

When reporting an issue, please include:

- WordPress version, WooCommerce version, PHP version (from `Tools → Site
  Health → Info`)
- The plugin version (from `Plugins → Installed Plugins`)
- The build hash from your browser console: open WP-admin, open the browser
  console (F12), type `window.smailyConnectBoot?.buildHash` and copy what it
  prints
- A description of what you were doing when the issue appeared
- Any error messages from `wp-content/debug.log` if available
