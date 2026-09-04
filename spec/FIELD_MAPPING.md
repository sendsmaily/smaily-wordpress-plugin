# Field-mapping standard

The canonical field-naming standard for the Smaily ecosystem.

A contact that lands in Smaily from any source channel — this
WooCommerce plugin, a future Shopify app, the rec-engine, a hand-rolled
REST integration — **must carry the same field names**, so that
Smaily-side templates (`{{ first_name }}`, `{{ store }}`),
segmentation rules, and automation triggers behave identically
regardless of where the contact originated.

This document is the source of truth. New integrations inherit from
it; existing ones reconcile to it (the WooCommerce-plugin legacy
mapping in `integrations/woocommerce/data-handler.class.php` IS this
standard — it predates the doc and was promoted to canonical because
upstream `sendsmaily/smaily-woocommerce-plugin` ships the same shape).

---

## 1. Reserved / always-sent

These fields are present on every contact-sync payload regardless of
the merchant's per-field opt-in toggles.

| Field name | Type     | Source                                |
|------------|----------|---------------------------------------|
| `email`    | required | The Smaily contact identifier         |
| `store`    | always   | `get_site_url()` — template context   |

`store` is NOT user-data per se; it's the source-shop URL Smaily
templates use to render footer text, unsubscribe links, etc.
(`{{ store }}` is a documented Smaily template variable). The merchant
doesn't toggle it on or off — every payload carries it.

---

## 2. Personal — opt-in via Step 2 sync-field checkboxes

| Field name    | UI label  | WP user_meta source | Transform                          |
|---------------|-----------|---------------------|------------------------------------|
| `first_name`  | First name| `first_name`        | trim                               |
| `last_name`   | Last name | `last_name`         | trim                               |
| `nickname`    | Nickname  | `nickname`          | trim                               |
| `user_phone`  | Phone     | `user_phone`        | trim                               |
| `user_gender` | Gender    | `user_gender`       | `'0'` → `'Female'`, else `'Male'`  |
| `birthday`    | Birthday  | `user_dob`          | `gmdate('Y-m-d', strtotime(value))`|

### Why the `user_*` prefix is preserved

`first_name` / `last_name` ship without a prefix because they ARE
the WordPress core user-meta names — every contact list in every
Smaily template assumes `{{ first_name }}` works.

`user_phone`, `user_gender` keep the `user_` prefix even though it
reads slightly inconsistent with `first_name`. Two reasons:

1. **Backward compatibility.** The legacy plugin
   `integrations/woocommerce/data-handler.class.php` shipped these
   names. Pilot merchants who installed the 1.x plugin already have
   Smaily segments and templates that reference `{{ user_phone }}` /
   `{{ user_gender }}`. Renaming them on the wire would silently
   break those segments — contacts would suddenly stop satisfying
   "phone is set" rules.
2. **WP user_meta lineage.** The values come straight from
   `wp_usermeta` rows whose `meta_key` already carries the prefix
   (`user_phone`, `user_gender`, `user_dob`). Stripping it in
   transit would diverge the Smaily-side names from the WP-side
   keys for no benefit.

`first_name` and `last_name` are the exceptions that prove the rule:
they came from the WP standard `first_name` / `last_name` user_meta
columns, no `user_` prefix to drop in the first place.

---

## 3. Customer lifecycle — opt-in via Step 2 sync-field checkboxes

| Field name         | UI label              | Source                              | Transform                       |
|--------------------|-----------------------|-------------------------------------|---------------------------------|
| `customer_id`      | Customer ID           | `$user->ID`                         | cast int                        |
| `customer_group`   | Customer group        | `$user->roles[0]`                   | first WP role (admin/customer)  |
| `first_registered` | First registered date | `$user->user_registered`            | raw MySQL datetime              |
| `site_title`       | Site title            | `get_bloginfo('name')`              | —                               |

`customer_group` is "first role". A user with multiple roles
(`administrator + customer`) gets the first one as their group; the
field is segmentation context, not a multi-value tag list. (Smaily
tags exist for that purpose; they're sent separately via the
automation-trigger flow.)

`first_registered` ships as raw MySQL `YYYY-MM-DD HH:MM:SS` because
that's what `$user->user_registered` returns. Smaily templates that
need it formatted use the upstream `{{ first_registered | date }}`
filter.

---

## 4. Platform-specific — NOT cross-channel

These fields are namespaced per source so Shopify-sourced contacts
don't collide with WooCommerce-sourced ones when both populate the
same Smaily contact list.

- `wc_*` — WooCommerce-only fields. e.g. `wc_billing_country`,
  `wc_total_spent`, `wc_order_count`. Reserved for Phase 3 when
  the abandoned-cart + order-driven automations need them.
- `shopify_*` — reserved for the future Shopify app.
- `rec_*` — derived by the rec-engine integration. e.g.
  `rec_last_order_at`, `rec_ltv`, `rec_predicted_next_order_days`.

Cross-channel fields (§1–§3) MUST NOT carry these prefixes. If
WooCommerce and Shopify both want to ship "first name", they both
ship `first_name` — Smaily merges them by `email`.

---

## 5. Adding a new field

1. Decide whether the field is cross-channel (§2/§3) or
   platform-specific (§4).
2. Pick a name. Cross-channel: prefer the WP user_meta key as-is.
   Platform-specific: prefix with `wc_` / `shopify_` / `rec_`.
3. Add it to the right table above with its source, transform, and
   UI label.
4. Surface it as a Step 2 sync-field checkbox in
   `admin/src/state/types.ts` `DEFAULT_SYNC_FIELDS`.
5. Wire the transform in `includes/Smaily/BackfillJob.php`
   (`build_subscriber_payload`) AND
   `includes/Integrations/WooCommerce/HookHandler.php` (the per-event
   payload builders). Both code paths must read the same option
   (`smaily_connect_subscriber_sync_fields`) and apply the same
   transform; otherwise backfill and live-sync disagree on what a
   contact looks like.
6. Document any Smaily admin "Manage fields" parameter the merchant
   has to create on their account before the field appears in
   templates (Smaily ignores unknown parameter names silently).

---

## 6. Implementation notes

- The plugin reads opt-in toggles from
  `wp_options.smaily_connect_subscriber_sync_fields` (array of
  cross-channel field names per §2/§3).
- `email` and `store` are sent regardless of the toggle array.
- An opt-in toggle that resolves to an empty source value (e.g.
  `nickname` toggle but the user has no `nickname` meta) is OMITTED
  from the payload — Smaily treats absent and empty as different,
  and absent leaves any existing value intact.
- Per-channel transforms (gender enum, birthday date) live in the
  payload-builder, NOT in the option storage. The merchant's stored
  setting is the raw checkbox state; the wire shape is derived.
