-- Hostkeep database. Run as MariaDB root (unix socket): mysql < schema.sql
CREATE DATABASE IF NOT EXISTS hostkeep CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE hostkeep;

CREATE TABLE IF NOT EXISTS metrics (
  ts INT UNSIGNED NOT NULL PRIMARY KEY,
  cpu FLOAT NOT NULL,
  load1 FLOAT NOT NULL, load5 FLOAT NOT NULL, load15 FLOAT NOT NULL,
  mem_total BIGINT UNSIGNED NOT NULL, mem_avail BIGINT UNSIGNED NOT NULL,
  swap_total BIGINT UNSIGNED NOT NULL, swap_used BIGINT UNSIGNED NOT NULL,
  disk_total BIGINT UNSIGNED NOT NULL, disk_used BIGINT UNSIGNED NOT NULL,
  net_rx FLOAT NOT NULL, net_tx FLOAT NOT NULL,
  procs INT UNSIGNED NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS snapshots (
  name VARCHAR(32) NOT NULL PRIMARY KEY,
  data MEDIUMTEXT NOT NULL,
  updated INT UNSIGNED NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS site_checks (
  ts INT UNSIGNED NOT NULL,
  host VARCHAR(255) NOT NULL,
  code SMALLINT UNSIGNED NOT NULL,
  ms INT UNSIGNED NOT NULL,
  KEY host_ts (host, ts)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_log (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ts INT UNSIGNED NOT NULL,
  ip VARCHAR(45) NOT NULL,
  user VARCHAR(64) NOT NULL,
  action VARCHAR(255) NOT NULL,
  job VARCHAR(40) NULL,
  rc SMALLINT NULL,
  KEY job (job)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_attempts (
  ip VARCHAR(45) NOT NULL,
  ts INT UNSIGNED NOT NULL,
  ok TINYINT(1) NOT NULL,
  KEY ip_ts (ip, ts)
) ENGINE=InnoDB;

-- Traffic statistics, built from /var/log/apache2/hostkeep-traffic.log (GlobalLog with the vhost name).
CREATE TABLE IF NOT EXISTS traffic_daily (
  day DATE NOT NULL,
  host VARCHAR(255) NOT NULL,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  s2 INT UNSIGNED NOT NULL DEFAULT 0, s3 INT UNSIGNED NOT NULL DEFAULT 0,
  s4 INT UNSIGNED NOT NULL DEFAULT 0, s5 INT UNSIGNED NOT NULL DEFAULT 0,
  bots INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (day, host)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS traffic_visitors (
  day DATE NOT NULL,
  host VARCHAR(255) NOT NULL,
  ip_hash BINARY(8) NOT NULL,
  PRIMARY KEY (day, host, ip_hash)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS traffic_top (
  day DATE NOT NULL,
  host VARCHAR(255) NOT NULL,
  kind VARCHAR(8) NOT NULL,          -- page, 404, 5xx, ip, bot, ref
  item VARCHAR(255) NOT NULL,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (day, host, kind, item)
) ENGINE=InnoDB;

-- Panel accounts. role: superadmin, admin, editor, viewer (see app/src/rbac.php).
CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(40) NOT NULL,
  email VARCHAR(190) NULL,
  display_name VARCHAR(80) NOT NULL DEFAULT '',
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(16) NOT NULL DEFAULT 'viewer',
  active TINYINT(1) NOT NULL DEFAULT 1,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  failed_logins SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until INT UNSIGNED NOT NULL DEFAULT 0,
  created_at INT UNSIGNED NOT NULL,
  updated_at INT UNSIGNED NOT NULL,
  password_changed_at INT UNSIGNED NOT NULL,
  last_login_at INT UNSIGNED NULL,
  last_login_ip VARCHAR(45) NULL,
  UNIQUE KEY username (username),
  UNIQUE KEY email (email)
) ENGINE=InnoDB;

-- Server-side record of each signed-in browser: lets users and admins see and revoke sessions.
-- id = SHA-256 of the random token kept in the PHP session (the token itself is never stored).
CREATE TABLE IF NOT EXISTS user_sessions (
  id CHAR(64) NOT NULL PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  created_at INT UNSIGNED NOT NULL,
  last_seen INT UNSIGNED NOT NULL,
  ip VARCHAR(45) NOT NULL,
  user_agent VARCHAR(255) NOT NULL DEFAULT '',
  KEY user_id (user_id),
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- One-time links (password reset, invitation). Only the SHA-256 of the token is stored.
CREATE TABLE IF NOT EXISTS password_resets (
  token_hash CHAR(64) NOT NULL PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  created_at INT UNSIGNED NOT NULL,
  expires_at INT UNSIGNED NOT NULL,
  KEY user_id (user_id),
  CONSTRAINT fk_resets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Panel settings edited from the web (see app/src/settings.php for the list and the defaults).
CREATE TABLE IF NOT EXISTS settings (
  name VARCHAR(64) NOT NULL PRIMARY KEY,
  value TEXT NOT NULL,
  updated_at INT UNSIGNED NOT NULL,
  updated_by VARCHAR(40) NULL
) ENGINE=InnoDB;

-- Two-factor authentication (2.5.0): TOTP secret (encrypted with HOSTKEEP_APP_KEY), last time step accepted
-- (a code works once), and single-use recovery codes (password_hash of each).
ALTER TABLE users ADD COLUMN IF NOT EXISTS totp_secret VARCHAR(255) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS totp_enabled_at INT UNSIGNED NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS totp_last_step BIGINT UNSIGNED NOT NULL DEFAULT 0;
CREATE TABLE IF NOT EXISTS user_recovery_codes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  code_hash VARCHAR(255) NOT NULL,
  created_at INT UNSIGNED NOT NULL,
  used_at INT UNSIGNED NULL,
  KEY user_id (user_id),
  CONSTRAINT fk_recovery_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- The web app connects as OS user "hostkeep" through the unix socket: no password stored anywhere.
CREATE USER IF NOT EXISTS 'hostkeep'@'localhost' IDENTIFIED VIA unix_socket;
GRANT SELECT ON hostkeep.* TO 'hostkeep'@'localhost';
GRANT INSERT, DELETE ON hostkeep.login_attempts TO 'hostkeep'@'localhost';
GRANT INSERT, UPDATE ON hostkeep.audit_log TO 'hostkeep'@'localhost';
GRANT INSERT, UPDATE, DELETE ON hostkeep.users TO 'hostkeep'@'localhost';
GRANT INSERT, UPDATE, DELETE ON hostkeep.user_sessions TO 'hostkeep'@'localhost';
GRANT INSERT, UPDATE, DELETE ON hostkeep.password_resets TO 'hostkeep'@'localhost';
GRANT INSERT, UPDATE, DELETE ON hostkeep.settings TO 'hostkeep'@'localhost';
GRANT INSERT, UPDATE, DELETE ON hostkeep.user_recovery_codes TO 'hostkeep'@'localhost';
FLUSH PRIVILEGES;
