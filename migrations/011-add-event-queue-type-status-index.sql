-- Index the Smaily queue by (event_type, status) — PRO-1723.
--
-- The checkout path now asks the queue two questions about one shopper:
-- "did an abandoned-cart reminder actually go out to this address?" (so the
-- purchase marker is written only for a shopper the plugin itself emailed)
-- and "is one still pending for them?" (so a reminder can't go out behind a
-- completed purchase). Both filter on event_type + status.
--
-- The existing idx_status_created (status, created_at) starts at `status`, so
-- either query would scan every `sent` / `pending` row in the table —
-- contact syncs included, which on a store with a large contact list is the
-- whole queue. Leading with event_type narrows the scan to the handful of
-- abandoned-cart rows before anything else is read.
--
-- dbDelta() diffs against the live table and ADDs only the missing KEY, so
-- restating the full CREATE TABLE statement is the supported way to introduce
-- an index. Keep the dbDelta formatting invariants (two spaces after PRIMARY
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
  KEY idx_status_retry (status, next_retry_at),
  KEY idx_type_status (event_type, status)
) {charset_collate};
