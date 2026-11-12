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

-- The web app connects as OS user "hostkeep" through the unix socket: no password stored anywhere.
CREATE USER IF NOT EXISTS 'hostkeep'@'localhost' IDENTIFIED VIA unix_socket;
GRANT SELECT ON hostkeep.* TO 'hostkeep'@'localhost';
GRANT INSERT, DELETE ON hostkeep.login_attempts TO 'hostkeep'@'localhost';
GRANT INSERT, UPDATE ON hostkeep.audit_log TO 'hostkeep'@'localhost';
FLUSH PRIVILEGES;
