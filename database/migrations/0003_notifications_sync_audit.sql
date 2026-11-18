CREATE TABLE reminders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  appointment_id BIGINT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  offset_minutes INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'minutes avant le début',
  channel ENUM('inapp','push','email') NOT NULL DEFAULT 'inapp',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reminder (appointment_id, user_id, offset_minutes, channel),
  KEY idx_reminders_user (user_id),
  CONSTRAINT fk_rem_appt FOREIGN KEY (appointment_id) REFERENCES appointments (id) ON DELETE CASCADE,
  CONSTRAINT fk_rem_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notification_deliveries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reminder_id BIGINT UNSIGNED NULL,
  appointment_id BIGINT UNSIGNED NULL,
  user_id INT UNSIGNED NOT NULL,
  channel ENUM('inapp','push','email') NOT NULL,
  occurrence_start_utc DATETIME NOT NULL,
  scheduled_at DATETIME NOT NULL,
  status ENUM('pending','processing','sent','failed','cancelled') NOT NULL DEFAULT 'pending',
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at DATETIME NULL,
  locked_by VARCHAR(64) NULL COMMENT 'identifiant du worker qui a réservé la ligne',
  locked_at DATETIME NULL,
  sent_at DATETIME NULL,
  read_at DATETIME NULL,
  title VARCHAR(200) NOT NULL,
  body VARCHAR(500) NULL,
  last_error VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_delivery_once (reminder_id, occurrence_start_utc, channel),
  KEY idx_deliv_due (status, scheduled_at, next_attempt_at),
  KEY idx_deliv_user (user_id, read_at),
  KEY idx_deliv_lock (locked_at),
  CONSTRAINT fk_deliv_rem FOREIGN KEY (reminder_id) REFERENCES reminders (id) ON DELETE SET NULL,
  CONSTRAINT fk_deliv_appt FOREIGN KEY (appointment_id) REFERENCES appointments (id) ON DELETE SET NULL,
  CONSTRAINT fk_deliv_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE push_subscriptions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  endpoint_hash CHAR(64) NOT NULL COMMENT 'SHA-256 de endpoint',
  endpoint TEXT NOT NULL,
  p256dh VARCHAR(255) NOT NULL,
  auth_key VARCHAR(255) NOT NULL,
  user_agent VARCHAR(255) NULL,
  expires_at DATETIME NULL,
  failed_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_success_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_push_endpoint (endpoint_hash),
  KEY idx_push_user (user_id),
  CONSTRAINT fk_push_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sync_changes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'curseur de synchronisation',
  calendar_id INT UNSIGNED NULL,
  entity_type ENUM('appointment','calendar','contact','category') NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  change_type ENUM('created','updated','deleted','restored') NOT NULL,
  changed_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sync_calendar (calendar_id, id),
  KEY idx_sync_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  entity_type VARCHAR(40) NULL,
  entity_id BIGINT UNSIGNED NULL,
  ip_address VARCHAR(45) NULL,
  details JSON NULL COMMENT 'jamais de mot de passe ni de jeton',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_user (user_id, created_at),
  KEY idx_audit_action (action, created_at),
  KEY idx_audit_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
