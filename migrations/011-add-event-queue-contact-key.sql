-- Key the Smaily queue's rows to their contact — PRO-1723.
--
-- The checkout path asks the queue about one shopper: was an abandoned-cart
-- reminder actually delivered to this address (so the purchase marker is
-- written only for a shopper the plugin itself emailed), and is one still
-- pending for them (so a reminder can't go out behind a completed purchase)?
--
-- The queue has no email column, and searching the stored payload text for
-- the address is unindexable — it would scan every row of that type on the
-- checkout path, and it puts knowledge of the payload's JSON shape inside the
-- queue. `contact_key` is the address itself hashed (sha256 of the trimmed,
-- lowercased email — EventQueue::contact_key()), never the address: the queue
-- stores no second copy of a contact's email. NULL when the row carries no
-- address at all.
--
-- The index leads with event_type + contact_key so one shopper's rows of one
-- type are found directly, and carries `status` so the delivered/pending
-- split is answered from the index. Rows written before this migration have
-- a NULL key and are therefore invisible to that lookup — a reminder sent
-- before the upgrade cannot be detected, which is accepted (the window is one
-- reminder series).
--
-- dbDelta() diffs against the live table and ADDs only the missing column /
-- index, so restating the full CREATE TABLE statement is the supported way to
-- introduce them. Keep the dbDelta formatting invariants (two spaces after
-- PRIMARY KEY, KEY not INDEX) — see migration 001's header.

CREATE TABLE {prefix}smly_plus_event_queue (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_type VARCHAR(64) NOT NULL,
  entity_id VARCHAR(128) DEFAULT NULL,
  payload LONGTEXT NOT NULL,
  contact_key CHAR(64) DEFAULT NULL,
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
  KEY idx_type_contact_status (event_type, contact_key, status)
) {charset_collate};
