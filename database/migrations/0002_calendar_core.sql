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
