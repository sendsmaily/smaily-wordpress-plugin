-- Add sent_count to the backfill job tracker (CON-248).
--
-- Contract v1.20.0 §25 reports, per entity, the rows of a history import the
-- engine ACCEPTED. processed_count counts rows WALKED, and the import's queue
-- rows drain together with the live sync's, so neither the walk nor a count of
-- the queue's `sent` rows is that number.
--
-- sent_count is the number of this run's queue rows the engine accepted: not
-- rejected per item (D6 errors[]), not skipped as older than the history
-- horizon (§5 skipped_orders[]), not a terminal skip that sent nothing. The
-- flushers add to it as they send (Backfill\ImportRows), so a row retried
-- later is counted when it goes through. start() sets it to 0; NULL means the
-- run started before this column existed and was never counted — the progress
-- report then falls back to processed_count, as the Magento extension does.
-- The contacts import (target smaily) never writes it.
--
-- dbDelta() diffs against the live table and ADDs only the missing column, so
-- restating the full CREATE TABLE is the supported way to add it. Keep the
-- dbDelta formatting invariants (two spaces after PRIMARY KEY, KEY not INDEX).
-- The table holds no order data, so the step is the same on HPOS and legacy
-- order storage.

CREATE TABLE {prefix}smly_plus_backfill_job (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_type VARCHAR(64) NOT NULL,
  target VARCHAR(64) NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'idle',
  total_count INT UNSIGNED DEFAULT NULL,
  processed_count INT UNSIGNED NOT NULL DEFAULT 0,
  synced_count INT UNSIGNED NOT NULL DEFAULT 0,
  sent_count INT UNSIGNED DEFAULT NULL,
  cursor_value VARCHAR(255) DEFAULT NULL,
  started_at DATETIME DEFAULT NULL,
  completed_at DATETIME DEFAULT NULL,
  error_message TEXT,
  PRIMARY KEY  (id),
  UNIQUE KEY uniq_type_target (job_type, target)
) {charset_collate};
