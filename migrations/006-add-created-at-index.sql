-- Standalone created_at index on both durable event queues, for the
-- QueueJanitor's retention DELETEs (status + created_at cutoff).
--
-- The existing composite keys — idx_status_created (status, created_at)
-- on the plus-queue and idx_status_retry (status, next_retry_at) on the
-- rec-queue — serve the flush queries; the rec-queue had NO index usable
-- for a created_at range scan, so janitor pruning (and the Event Log's
-- failed-in-24h count) would full-scan as rows accumulate.
-- (FABLE_AUDIT §5 watch-item; BACKLOG "Queue janitor".)
--
-- dbDelta() diffs against the live table and ADDs only the missing KEY,
-- so restating both full CREATE TABLE statements is the supported way to
-- introduce an index. Keep the dbDelta formatting invariants (two spaces
-- after PRIMARY KEY, KEY not INDEX) — see migration 001's header.

CREATE TABLE {prefix}smly_plus_event_queue (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_type VARCHAR(64) NOT NULL,
  entity_id VARCHAR(128) DEFAULT NULL,
  payload LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL,
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_error TEXT,
  status VARCHAR(16) NOT NULL DEFAULT 'pending',
  PRIMARY KEY  (id),
  KEY idx_status_created (status, created_at),
  KEY idx_created_at (created_at)
) {charset_collate};

CREATE TABLE {prefix}smly_rec_event_queue (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_type VARCHAR(64) NOT NULL,
  entity_id VARCHAR(128) DEFAULT NULL,
  event_uuid CHAR(36) NOT NULL,
  depends_on_event_id CHAR(36) DEFAULT NULL,
  payload LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL,
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  last_error TEXT,
  status VARCHAR(16) NOT NULL DEFAULT 'pending',
  next_retry_at DATETIME DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uniq_event_uuid (event_uuid),
  KEY idx_status_retry (status, next_retry_at),
  KEY idx_depends_on (depends_on_event_id),
  KEY idx_created_at (created_at)
) {charset_collate};
