-- 2026-11-12: integracje giełd, MetaTrader i API (WEEX, OKX, BingX, MetaApi, EA)
-- Bezpieczne do wielokrotnego uruchomienia.

CREATE TABLE IF NOT EXISTS exchange_connections (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(16) NOT NULL,
  label VARCHAR(64) NOT NULL,
  account_ref VARCHAR(128) NOT NULL DEFAULT '',
  key_mask VARCHAR(64) NOT NULL DEFAULT '',
  credentials TEXT NOT NULL,
  pairing_hash CHAR(64) NULL,
  permissions VARCHAR(24) NOT NULL DEFAULT 'read-only',
  status VARCHAR(16) NOT NULL DEFAULT 'connected',
  last_sync_at DATETIME NULL,
  last_sync_status VARCHAR(16) NULL,
  last_error VARCHAR(500) NULL,
  imported_count INT UNSIGNED NOT NULL DEFAULT 0,
  cursor_ms BIGINT NULL,
  backfill_done TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_exchange_conn (user_id, provider, account_ref),
  KEY idx_exchange_pairing (pairing_hash),
  KEY idx_exchange_due (status, last_sync_at),
  CONSTRAINT fk_exchange_conn_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exchange_imports (
  user_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(16) NOT NULL,
  external_id VARCHAR(191) NOT NULL,
  trade_id VARCHAR(64) NOT NULL,
  imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, provider, external_id),
  KEY idx_exchange_import_trade (trade_id),
  CONSTRAINT fk_exchange_import_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exchange_sync_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  connection_id BIGINT UNSIGNED NOT NULL,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  status VARCHAR(20) NOT NULL,
  fetched INT UNSIGNED NOT NULL DEFAULT 0,
  imported INT UNSIGNED NOT NULL DEFAULT 0,
  error_message VARCHAR(500) NULL,
  PRIMARY KEY (id),
  KEY idx_exchange_run_connection (connection_id, started_at),
  CONSTRAINT fk_exchange_run_connection FOREIGN KEY (connection_id) REFERENCES exchange_connections (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exchange_sync_state (
  id TINYINT UNSIGNED NOT NULL,
  last_attempt_at DATETIME NULL,
  last_success_at DATETIME NULL,
  last_status VARCHAR(20) NOT NULL DEFAULT 'never',
  last_error VARCHAR(500) NULL,
  connections_count INT UNSIGNED NOT NULL DEFAULT 0,
  imported_count INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO exchange_sync_state (id, last_status) VALUES (1, 'never')
ON DUPLICATE KEY UPDATE id = id;
