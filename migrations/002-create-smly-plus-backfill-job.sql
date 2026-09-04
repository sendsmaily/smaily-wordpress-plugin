-- Backfill job state tracker.
-- Backs PLUGIN.md §7 smly_plus_backfill_job: per-data-type (contacts /
-- orders / customers / products) progress for Settings and Wizard UIs.
--
-- One row per (job_type, target) — `target` is "smaily" or "rec_engine"
-- so the same job_type can have parallel runs against different APIs.
-- Cursor is opaque (could be a paginated user-id offset, a timestamp,
-- or a rec-engine cursor token) — the calling code knows how to
-- interpret it.

CREATE TABLE {prefix}smly_plus_backfill_job (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_type VARCHAR(64) NOT NULL,
  target VARCHAR(64) NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'idle',
  total_count INT UNSIGNED DEFAULT NULL,
  processed_count INT UNSIGNED NOT NULL DEFAULT 0,
  cursor_value VARCHAR(255) DEFAULT NULL,
  started_at DATETIME DEFAULT NULL,
  completed_at DATETIME DEFAULT NULL,
  error_message TEXT,
  PRIMARY KEY  (id),
  UNIQUE KEY uniq_type_target (job_type, target)
) {charset_collate};
