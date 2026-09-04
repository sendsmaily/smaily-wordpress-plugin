-- Rec-engine durable event queue.
-- Backs PLUGIN.md §7 smly_rec_event_queue + §11 event-dependency mechanism.
--
-- Created in Phase 1 even though the rec-engine integration arrives in
-- Phase 3 — the schema is committed up front so DB migrations are linear
-- and Phase 3 doesn't have to schedule a fresh activation/migration on
-- existing BETA installs.
--
-- Browse events deliberately do NOT land in this table (PLUGIN.md §3,
-- §7): they go to a 30s transient buffer + best-effort flush, since the
-- rec-engine ML pipeline is 5-10% loss-tolerant and the row volume would
-- be wasteful. Only catalog / customers / orders / identity.merge use
-- this queue.
--
-- `depends_on_event_id` references another row's `event_uuid` (not its
-- BIGINT id) so the dependency survives table truncations and is stable
-- across replication / dump-restore.

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
  KEY idx_depends_on (depends_on_event_id)
) {charset_collate};
