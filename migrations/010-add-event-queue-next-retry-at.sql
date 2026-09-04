-- Park a retried Smaily-queue row until its backoff has elapsed (PRO-1685).
--
-- Before this the Smaily queue had no retry-spacing column at all: a failing
-- row was re-attempted by every 60s flush tick, forever. The written policy
-- (Smaily\RetryPolicy / spec PLUGIN.md §8: 4xx no-retry, 429 honour
-- Retry-After, 5xx exponential backoff) needs somewhere to record "not before
-- X" — the same mechanism the rec-engine queue has carried since migration
-- 004 (`next_retry_at` + `idx_status_retry`). NULL = due now, which is what
-- every existing row and every fresh enqueue means.
--
-- dbDelta() diffs against the live table and ADDs only the missing column /
-- index, so restating the full CREATE TABLE statement is the supported way to
-- add them. Keep the dbDelta formatting invariants (two spaces after PRIMARY
-- KEY, KEY not INDEX) — see migration 001's header.

CREATE TABLE {prefix}smly_plus_event_queue (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_type VARCHAR(64) NOT NULL,
  entity_id VARCHAR(128) DEFAULT NULL,
  payload LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL,
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_error TEXT,
  status VARCHAR(16) NOT NULL DEFAULT 'pending',
  next_retry_at DATETIME DEFAULT NULL,
  sent_payload LONGTEXT,
  last_response LONGTEXT,
  PRIMARY KEY  (id),
  KEY idx_status_created (status, created_at),
  KEY idx_created_at (created_at),
  KEY idx_status_retry (status, next_retry_at)
) {charset_collate};
