-- Add synced_count to the backfill job tracker (F3-55).
--
-- The contact backfill walks EVERY WP user but POSTs only the contact-sync
-- mode's audience (F3-48: consent = opted-in only). processed_count tracks
-- rows WALKED (drives percent/ETA, always reaches total_count), so the UI
-- had nothing honest to label "contacts synced" — it showed the walk count,
-- which on a consent-mode store reads as a consent violation (the Prike
-- "30k contacts go to Smaily, we have 16k opt-ins" report).
--
-- synced_count is the cumulative number of AUDIENCE members handled:
-- POSTed this run + skipped-as-already-fresh. Engine-target jobs (products /
-- customers / orders) enqueue everything they walk, so they leave it 0 and
-- the UI keeps using processed_count for them.
--
-- dbDelta() diffs against the live table and ADDs only the missing column, so
-- restating the full CREATE TABLE is the supported way to add it. Keep the
-- dbDelta formatting invariants (two spaces after PRIMARY KEY, KEY not INDEX).

CREATE TABLE {prefix}smly_plus_backfill_job (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_type VARCHAR(64) NOT NULL,
  target VARCHAR(64) NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'idle',
  total_count INT UNSIGNED DEFAULT NULL,
  processed_count INT UNSIGNED NOT NULL DEFAULT 0,
  synced_count INT UNSIGNED NOT NULL DEFAULT 0,
  cursor_value VARCHAR(255) DEFAULT NULL,
  started_at DATETIME DEFAULT NULL,
  completed_at DATETIME DEFAULT NULL,
  error_message TEXT,
  PRIMARY KEY  (id),
  UNIQUE KEY uniq_type_target (job_type, target)
) {charset_collate};
