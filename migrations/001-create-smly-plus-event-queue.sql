-- Smaily-side durable event queue.
-- Backs PLUGIN.md §7 smly_plus_event_queue: contact.sync and automation.*
-- events that target the Smaily marketing API.
--
-- Placeholders `{prefix}` and `{charset_collate}` are substituted by
-- Smaily\Connect\DB\Migrator before the statement is fed to dbDelta().
-- The dbDelta() function is forgiving but strict about formatting: each
-- column on its own line, two spaces after PRIMARY KEY, KEY (not INDEX),
-- and UNIQUE KEYs after regular KEYs. Keep those invariants when editing.
--
-- `status` uses VARCHAR(16) instead of MySQL's ENUM because dbDelta()
-- mishandles ENUM column alterations (it tends to drop+recreate the
-- column, which would lose pending events on schema upgrades). Validation
-- is enforced in PHP at the EventQueue layer.

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
  KEY idx_status_created (status, created_at)
) {charset_collate};
