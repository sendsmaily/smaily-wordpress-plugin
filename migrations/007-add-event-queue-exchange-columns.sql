-- Store the real request payload + engine response per queue row, so the
-- Event Log "Details" panel can show what was actually sent and what the
-- engine replied (PLUGIN.md §13 / engine brief 2026-06-19 Problem 3 / F3-44).
--
-- Before this, order/catalog rows enqueue an EMPTY payload (the flusher builds
-- the wire object fresh at send), so "Details" showed `Payload: []` and a
-- terminal-skip row read "sent" with no trace it never POSTed. These two
-- nullable columns capture the send-time exchange on BOTH durable queues:
--   sent_payload  = the exact JSON body POSTed (null when nothing was sent —
--                   e.g. a terminal skip), trimmed to ~10 KB by the flusher.
--   last_response = a small JSON summary of the engine reply
--                   ({http, outcome, processed?, error?}), trimmed to ~10 KB.
-- The Authorization header is NEVER stored (only method/endpoint/body + reply).
--
-- dbDelta() diffs against the live table and ADDs only the missing columns, so
-- restating both full CREATE TABLE statements is the supported way to add them.
-- Keep the dbDelta formatting invariants (two spaces after PRIMARY KEY, KEY not
-- INDEX, nullable LONGTEXT mirrors the existing `last_error TEXT`) — see
-- migration 001's header. The columns are pruned with their row by QueueJanitor.

CREATE TABLE {prefix}smly_plus_event_queue (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_type VARCHAR(64) NOT NULL,
  entity_id VARCHAR(128) DEFAULT NULL,
  payload LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL,
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_error TEXT,
  status VARCHAR(16) NOT NULL DEFAULT 'pending',
  sent_payload LONGTEXT,
  last_response LONGTEXT,
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
  sent_payload LONGTEXT,
  last_response LONGTEXT,
  PRIMARY KEY  (id),
  UNIQUE KEY uniq_event_uuid (event_uuid),
  KEY idx_status_retry (status, next_retry_at),
  KEY idx_depends_on (depends_on_event_id),
  KEY idx_created_at (created_at)
) {charset_collate};
