-- Multilingual automation-workflow mapping.
-- Backs PLUGIN.md §7 smly_plus_automation_mapping and §4 multilingual
-- modes A/B/C: lookups by (trigger_type, language, account_key) returning
-- the Smaily workflow_id that MultilingualRouter should fire.
--
-- `account_key` is a logical identifier the Settings layer assigns to
-- each Smaily-credential set ("default", "et_account", "en_account", …).
-- In Mode B/C there's exactly one credential set so account_key is
-- always "default" (Mode A introduces several).
--
-- `is_default_fallback` marks the row that handles users whose detected
-- language has no explicit mapping. Exactly one row per trigger_type
-- should have it set, and the application layer enforces that invariant
-- on write.
--
-- dbDelta caveat: the parser splits on `;` even inside SQL `--` comment
-- blocks, so semicolons in comments silently break the migration. Keep
-- comments semicolon-free.

CREATE TABLE {prefix}smly_plus_automation_mapping (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  trigger_type VARCHAR(32) NOT NULL,
  language VARCHAR(10) NOT NULL,
  account_key VARCHAR(64) NOT NULL,
  workflow_id VARCHAR(128) NOT NULL,
  is_default_fallback TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY uniq_trigger_lang_account (trigger_type, language, account_key)
) {charset_collate};
