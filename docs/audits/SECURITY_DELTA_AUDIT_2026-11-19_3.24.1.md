# Security delta audit — before 3.24.1 (3.24.0..bd9955f)

Follows [`SECURITY_CODE_QUALITY_DELTA_AUDIT_2026-11-17_PRE_3.24.0.md`](SECURITY_CODE_QUALITY_DELTA_AUDIT_2026-11-17_PRE_3.24.0.md)
and the 3.24.0 release-gate row in [`INDEX.md`](INDEX.md). Both stay valid for
what they covered. This one covers everything that landed on `main` after the
3.24.0 tag, ahead of the 3.24.1 security release.

- **Date:** 2026-11-19
- **Baseline:** tag `3.24.0`. Delta `3.24.0..bd9955f` on `main`: 3 commits,
  21 files, +1 024 / −69. The shipped code in the delta is **9 files,
  +145 / −39**; the rest is tests, STATUS / DECISIONS / LESSONS / CLAUDE.md
  and the docs site.
  - **#358 (CON-343) — profile values (a reported information-disclosure
    issue):** `Profile_Settings` no longer reads `$_GET['user_id']`
    (`smaily_get_edit_user_id()` is removed). The My Account → Account
    details form prints the values of `get_current_user_id()`; the wp-admin
    profile prints the `WP_User` that `show_user_profile` /
    `edit_user_profile` pass, and only for a user who may `edit_user` it.
  - **#359 (CON-344, CON-345, CON-346) — the pre-3.24.1 security review's
    fixes:** the Elementor Pro form's "Other fields" row title is the escaping
    `{{ smaily_field }}`; the public product RSS feed asks for
    `has_password => false` and its limit goes through `Rss::feed_limit()`
    (1–100, 0 → 50, negative or larger → 100); the Integrations step's Limit
    field goes up to 100; the newsletter block's button colours, width and
    radius are checked against fixed shapes before they reach the `style`
    attribute; the notice-dismiss AJAX action accepts only a registered
    notice id, for a user with that notice's capability;
    `Helper::get_current_url()` builds the forms' default return address from
    the `home_url()` origin and the request's path and query.
  - **#357** — STATUS only.
- **Auditor:** Claude (Opus 5.5).
- **Method:** static read of the shipped diff and the code it reaches (the
  callers of `get_current_url()`, `list_rss_feed_items()` and the notice
  registry; the profile save paths; the block's editor script), against
  DECISIONS "CON-343", "CON-345" and "CON-346". CI on #359 (run 44471009955:
  unit PHP 8.0–8.3, admin bundle, blocks, the three integration legs incl.
  WordPress 6.6 + WooCommerce 6.9) and on main after the merge was green.

## Verdict

**0 Critical, 0 High, 0 Medium, 0 Low, 5 Info.** No defect found in the fixes;
none opens a new path. **RESULT: 3.24.1 may proceed** once the release gate
(local ZIP, VERIFY, Plugin Check) is green.

## Findings

| # | Severity | Where | Summary | Before release? |
|---|---|---|---|---|
| I1 | Info | `includes/smaily-helper.class.php:388` | `get_current_url()` still passes `REQUEST_URI` through `sanitize_text_field()`, which removes percent-encoded octets, so a return address whose path or query holds `%20`, `%2F` or a non-ASCII character comes back altered. Present before this delta (the old code did the same); the host part is now fixed | No — backlog (read the path and query with `esc_url_raw()` instead) |
| I2 | Info | `admin/smaily-admin-notices.class.php:104`–`:124` | The dismiss action does not check the notice's `dismissible` flag. A dismissal only writes the caller's own user meta, so it hides a notice only from that user | No — accepted |
| I3 | Info | `blocks/newsletter-signup/smaily-integration.class.php:147` | A colour other than hex or `var(--name[, #hex])` (an `rgb()` / `hsl()` / named colour) falls back to the default. The editor saves hex from the picker and `var:preset|color|slug` → `var(--wp--preset--color--slug)` for a palette colour, so no colour the editor saves is lost (DECISIONS "CON-346") | No — accepted |
| I4 | Info | `admin/src/components/steps/RssFeedSection.tsx:82`–`:88`; `integrations/woocommerce/rss.class.php:198` | A feed address a merchant built before 3.24.1 with a limit of 101–250 now lists 100 products. The limit is only part of the address (no stored setting is validated), and the changelog says so | No — accepted (merchant-visible, in the changelog) |
| I5 | Info | `admin/smaily-admin-notices.class.php:115`, `:118` | The two new AJAX error texts are English and not translated; only the dismiss script reads the answer, and it shows nothing on an error | No — accepted |

## Confirmed clean

- **CON-343, storefront** (`integrations/woocommerce/profile-settings.class.php:55`–`:59`):
  `woocommerce_register_form` prints no values (no user logged in);
  `woocommerce_edit_account_form` reads the current user only. No other read
  of `user_id` from the request is left in shipped code
  (`grep "\$_GET\['user_id'\]"`).
- **CON-343, wp-admin** (`:121`–`:137`): the values come from the `WP_User`
  WordPress passes; `user-edit.php` has already refused a user who may not
  edit that profile, and the added `edit_user` check repeats it. The save
  paths are unchanged: `personal_options_update` / `edit_user_profile_update`
  receive WordPress's user id after its own capability check and verify
  `update-user_<id>`; `woocommerce_save_account_details` receives the current
  user's id and verifies `save_account_details`.
- **CON-344:** no `{{{` is left in the plugin's Elementor controls.
- **CON-345:** `has_password => false` reaches `WP_Query` through
  `wc_get_products()` (WooCommerce skips only `''`, `array()` and `null`); the
  oldest-stack CI leg (WooCommerce 6.9) runs `RssFeedItemsTest` green.
  `feed_limit()` is the only path to the query (`list_rss_feed_items()` is its
  only caller, the template its only caller). Category, order and tax rate
  are unchanged, so newsletter addresses in use keep working.
- **CON-346, block style:** each value is checked before `esc_attr()`; the
  regular expressions are anchored and admit no `;`, `:` or `}`; the radius
  is cast to a numeric string. The missing-radius warning is gone (default 0).
- **CON-346, notice dismissal:** the nonce check stays first; an unknown id or
  a user without the notice's capability gets an error and nothing is stored.
  NotificationManager's notices use their own action (`smly_dismiss_notice`)
  and are not affected.
- **CON-346, return address:** the scheme, host and port come from
  `home_url()` only; a request target such as `//other.example/x` keeps only
  its path (`/x`). A subdirectory install gets no doubled path (the origin
  carries no path; `REQUEST_URI` does). All four callers (shortcode, widget,
  block, Elementor widget) use it only as a default return address.

## Release gate — still to run on the bump branch

Local ZIP build → `bin/verify-release-zip.sh … 3.24.1` → Plugin Check 2.2.0 on
the built ZIP (expected: the accepted 3 ERRORS / 17 WARNINGS). Recorded as the
3.24.1 release-gate row in `INDEX.md`.

## Conclusion

The delta closes the reported profile disclosure and the review's four other
gaps without a new finding. The five Info notes need no change before the
release; I1 is a backlog candidate.
