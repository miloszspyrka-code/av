-- Migration: 20261112_macro_events
-- Adds a shared, database-backed macro calendar (ForexFactory weekly feed).
-- Apply after a backup; idempotent CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS macro_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  external_id CHAR(40) NOT NULL,
  source ENUM('forexfactory','tradingview') NOT NULL DEFAULT 'forexfactory',
  name VARCHAR(255) NOT NULL,
  description VARCHAR(500) NULL,
  currency VARCHAR(8) NOT NULL,
  impact TINYINT UNSIGNED NOT NULL,
  starts_at_utc DATETIME NOT NULL,
  starts_at_iso VARCHAR(40) NOT NULL,
  forecast VARCHAR(64) NULL,
  previous VARCHAR(64) NULL,
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_macro_external (external_id),
  KEY idx_macro_start (starts_at_utc),
  KEY idx_macro_impact (impact),
  KEY idx_macro_source (source),
  KEY idx_macro_deleted (is_deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS macro_sync_state (
  id TINYINT UNSIGNED NOT NULL,
  last_attempt_at DATETIME NULL,
  last_success_at DATETIME NULL,
  last_status VARCHAR(20) NOT NULL DEFAULT 'never',
  last_error VARCHAR(500) NULL,
  events_count INT UNSIGNED NOT NULL DEFAULT 0,
  updated_count INT UNSIGNED NOT NULL DEFAULT 0,
  duration_ms INT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO macro_sync_state (id, last_status) VALUES (1, 'never')
ON DUPLICATE KEY UPDATE id = id;
