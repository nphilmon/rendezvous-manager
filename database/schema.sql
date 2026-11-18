-- RendezVous Manager : schéma complet (généré par scripts/build_schema.php, ne pas modifier à la main)
-- Pour une installation normale, utiliser scripts/migrate.php.

-- 0001_identity.sql
CREATE TABLE roles (
  id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(30) NOT NULL,
  label VARCHAR(80) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
  id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(60) NOT NULL,
  label VARCHAR(120) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_permissions_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
  role_id TINYINT UNSIGNED NOT NULL,
  permission_id SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  role_id TINYINT UNSIGNED NOT NULL,
  username VARCHAR(60) NOT NULL,
  email VARCHAR(190) NOT NULL,
  first_name VARCHAR(80) NOT NULL,
  last_name VARCHAR(80) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  failed_logins SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  timezone VARCHAR(64) NOT NULL DEFAULT 'Europe/Paris',
  locale VARCHAR(10) NOT NULL DEFAULT 'fr',
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role (role_id),
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_sessions (
  id CHAR(64) NOT NULL COMMENT 'SHA-256 du jeton de session',
  user_id INT UNSIGNED NOT NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_sessions_user (user_id),
  KEY idx_sessions_expires (expires_at),
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  identifier VARCHAR(190) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  succeeded TINYINT(1) NOT NULL DEFAULT 0,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_la_identifier (identifier, attempted_at),
  KEY idx_la_ip (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_resets (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pr_token (token_hash),
  CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = réglage global',
  setting_key VARCHAR(80) NOT NULL,
  setting_value TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_settings (user_id, setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 0002_calendar_core.sql
CREATE TABLE calendars (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  owner_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  description TEXT NULL,
  color CHAR(7) NOT NULL DEFAULT '#3b82f6',
  kind ENUM('personal','professional','team','training','administrative','other') NOT NULL DEFAULT 'personal',
  visibility ENUM('private','shared','organization') NOT NULL DEFAULT 'private',
  timezone VARCHAR(64) NOT NULL DEFAULT 'Europe/Paris',
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_calendars_owner (owner_id),
  CONSTRAINT fk_calendars_owner FOREIGN KEY (owner_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE calendar_permissions (
  calendar_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  can_read TINYINT(1) NOT NULL DEFAULT 1,
  can_create TINYINT(1) NOT NULL DEFAULT 0,
  can_update TINYINT(1) NOT NULL DEFAULT 0,
  can_delete TINYINT(1) NOT NULL DEFAULT 0,
  can_share TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (calendar_id, user_id),
  KEY idx_cp_user (user_id),
  CONSTRAINT fk_cp_calendar FOREIGN KEY (calendar_id) REFERENCES calendars (id) ON DELETE CASCADE,
  CONSTRAINT fk_cp_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(80) NOT NULL,
  color CHAR(7) NOT NULL DEFAULT '#64748b',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_categories_name (name),
  CONSTRAINT fk_categories_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contacts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  owner_id INT UNSIGNED NOT NULL,
  last_name VARCHAR(80) NOT NULL,
  first_name VARCHAR(80) NULL,
  company VARCHAR(120) NULL,
  phone VARCHAR(40) NULL,
  email VARCHAR(190) NULL,
  address VARCHAR(255) NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_contacts_owner (owner_id),
  KEY idx_contacts_name (last_name, first_name),
  CONSTRAINT fk_contacts_owner FOREIGN KEY (owner_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE appointments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uid CHAR(36) NOT NULL COMMENT 'UUID, utilisé pour ICS',
  calendar_id INT UNSIGNED NOT NULL,
  category_id INT UNSIGNED NULL,
  contact_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  description TEXT NULL,
  start_utc DATETIME NOT NULL,
  end_utc DATETIME NOT NULL,
  all_day TINYINT(1) NOT NULL DEFAULT 0,
  timezone VARCHAR(64) NOT NULL DEFAULT 'Europe/Paris' COMMENT 'fuseau d''origine (récurrences, changements d''heure)',
  location VARCHAR(190) NULL,
  address VARCHAR(255) NULL,
  status ENUM('scheduled','confirmed','tentative','cancelled','completed') NOT NULL DEFAULT 'scheduled',
  priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  color CHAR(7) NULL,
  notes TEXT NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'verrou optimiste',
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_appointments_uid (uid),
  KEY idx_appt_range (calendar_id, start_utc, end_utc),
  KEY idx_appt_start (start_utc),
  KEY idx_appt_status (status),
  KEY idx_appt_contact (contact_id),
  KEY idx_appt_category (category_id),
  KEY idx_appt_deleted (deleted_at),
  FULLTEXT KEY ft_appt_search (title, description, location),
  CONSTRAINT chk_appt_range CHECK (end_utc >= start_utc),
  CONSTRAINT fk_appt_calendar FOREIGN KEY (calendar_id) REFERENCES calendars (id),
  CONSTRAINT fk_appt_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL,
  CONSTRAINT fk_appt_contact FOREIGN KEY (contact_id) REFERENCES contacts (id) ON DELETE SET NULL,
  CONSTRAINT fk_appt_creator FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE appointment_recurrence (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  appointment_id BIGINT UNSIGNED NOT NULL,
  rrule VARCHAR(500) NOT NULL COMMENT 'RRULE iCalendar, ex. FREQ=WEEKLY;BYDAY=MO,WE',
  dtstart_local DATETIME NOT NULL COMMENT 'début en heure locale du fuseau',
  timezone VARCHAR(64) NOT NULL,
  until_utc DATETIME NULL,
  occurrence_count INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rec_appt (appointment_id),
  KEY idx_rec_until (until_utc),
  CONSTRAINT fk_rec_appt FOREIGN KEY (appointment_id) REFERENCES appointments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE appointment_exceptions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  appointment_id BIGINT UNSIGNED NOT NULL,
  original_start_utc DATETIME NOT NULL COMMENT 'occurrence concernée (RECURRENCE-ID)',
  exception_type ENUM('cancelled','modified') NOT NULL,
  new_start_utc DATETIME NULL,
  new_end_utc DATETIME NULL,
  overrides JSON NULL COMMENT 'champs modifiés pour cette occurrence',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_exc (appointment_id, original_start_utc),
  CONSTRAINT fk_exc_appt FOREIGN KEY (appointment_id) REFERENCES appointments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 0003_notifications_sync_audit.sql
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

-- 0004_seed_roles_permissions.sql
INSERT INTO roles (id, code, label) VALUES
  (1, 'admin', 'Administrateur'),
  (2, 'manager', 'Responsable'),
  (3, 'user', 'Utilisateur'),
  (4, 'readonly', 'Lecture seule');

INSERT INTO permissions (code, label) VALUES
  ('appointments.read', 'Consulter les rendez-vous'),
  ('appointments.write', 'Créer et modifier les rendez-vous'),
  ('appointments.delete', 'Supprimer les rendez-vous'),
  ('calendars.create', 'Créer des calendriers'),
  ('calendars.manage_all', 'Gérer tous les calendriers'),
  ('contacts.read', 'Consulter les contacts'),
  ('contacts.write', 'Gérer les contacts'),
  ('categories.manage', 'Gérer les catégories'),
  ('reports.view', 'Consulter les rapports'),
  ('data.export', 'Exporter les données'),
  ('data.import', 'Importer des données'),
  ('users.manage', 'Gérer les utilisateurs'),
  ('settings.admin', 'Administrer les paramètres'),
  ('audit.view', 'Consulter le journal d''audit'),
  ('backups.manage', 'Gérer les sauvegardes');

INSERT INTO role_permissions (role_id, permission_id)
  SELECT 1, id FROM permissions;

INSERT INTO role_permissions (role_id, permission_id)
  SELECT 2, id FROM permissions
  WHERE code IN ('appointments.read','appointments.write','appointments.delete','calendars.create','calendars.manage_all',
                 'contacts.read','contacts.write','categories.manage','reports.view','data.export','data.import','audit.view');

INSERT INTO role_permissions (role_id, permission_id)
  SELECT 3, id FROM permissions
  WHERE code IN ('appointments.read','appointments.write','appointments.delete','calendars.create',
                 'contacts.read','contacts.write','data.export','data.import');

INSERT INTO role_permissions (role_id, permission_id)
  SELECT 4, id FROM permissions
  WHERE code IN ('appointments.read','contacts.read');

-- 0005_session_csrf.sql
ALTER TABLE user_sessions
  ADD COLUMN csrf_token CHAR(64) NOT NULL DEFAULT '' AFTER user_agent;

