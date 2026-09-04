-- Rec-engine anonymous-visitor → identified-customer merge tracker.
-- Backs PLUGIN.md §7 smly_rec_visitor.
--
-- Created in Phase 1 alongside smly_rec_event_queue so the rec-engine
-- integration in Phase 3 can populate it without a fresh activation.
--
-- `visitor_id` is the UUID v4 the plugin generates when an anonymous
-- session first appears (set in the smaily_anon_sid cookie, 30-day TTL).
-- After an identify event (login / register / checkout / email_link),
-- `email` is filled in and the four `identified_*` fields are set,
-- subsequent visits update only `last_seen_at`.
--
-- `identified_source` mirrors PLUGIN.md §10 enum values
-- (login / register / checkout / email_link). VARCHAR(32) instead of
-- ENUM for the same dbDelta-friendliness reason as the queues.

CREATE TABLE {prefix}smly_rec_visitor (
  visitor_id CHAR(36) NOT NULL,
  email VARCHAR(255) DEFAULT NULL,
  identified_at DATETIME DEFAULT NULL,
  identified_source VARCHAR(32) DEFAULT NULL,
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  PRIMARY KEY  (visitor_id),
  KEY idx_email (email)
) {charset_collate};
