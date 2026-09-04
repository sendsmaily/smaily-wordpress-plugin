-- Namespaced abandoned-cart session tracker (PRO-1195).
--
-- Replaces the legacy {prefix}smaily_connect_abandoned_carts flow: instead of
-- `serialize( WC()->cart->get_cart() )` (whole WC_Product objects — fragile
-- across WC versions, the F3-53 poison class) each row stores a minimal JSON
-- array of scalars (`cart_content`: [{product_id, variation_id, quantity}]).
-- Rows are keyed by the WC session customer id (`cart_token`) so GUEST carts
-- are tracked too; `email` stays '' until an identity is known (logged-in
-- user or checkout-entered email) — the sweeper only enqueues rows with an
-- email. `reminder_enqueued_at` is the one-reminder-per-cart marker (the
-- legacy `mail_sent` semantics); completing an order deletes the row.
--
-- The legacy table is NOT dropped (safe rollback + analytics); a one-time
-- drain (Migration\LegacyCartDrain) copies its in-flight rows here on
-- upgrade.
--
-- Placeholders `{prefix}` and `{charset_collate}` are substituted by
-- Smaily\Connect\DB\Migrator before the statement is fed to dbDelta().
-- Keep the dbDelta formatting invariants (two spaces after PRIMARY KEY,
-- KEY not INDEX, UNIQUE KEYs after regular KEYs) — see migration 001.

CREATE TABLE {prefix}smly_plus_cart_session (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cart_token VARCHAR(191) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  email VARCHAR(191) NOT NULL DEFAULT '',
  first_name VARCHAR(191) NOT NULL DEFAULT '',
  last_name VARCHAR(191) NOT NULL DEFAULT '',
  cart_content LONGTEXT NOT NULL,
  cart_updated DATETIME NOT NULL,
  reminder_enqueued_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_reminder_updated (reminder_enqueued_at, cart_updated),
  KEY idx_email (email),
  UNIQUE KEY uniq_cart_token (cart_token)
) {charset_collate};
