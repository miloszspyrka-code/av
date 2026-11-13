-- MCP 2.0: rozszerzenie modelu propozycji i tabele OAuth 2.1.
-- Migracja jest addytywna i powtarzalna. Nie usuwa danych.

SET @mcp_payload_hash_exists = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'mcp_proposals'
     AND column_name = 'payload_hash'
);
SET @mcp_payload_hash_sql = IF(
  @mcp_payload_hash_exists = 0,
  'ALTER TABLE mcp_proposals ADD COLUMN payload_hash CHAR(64) NULL AFTER base_revision',
  'DO 0'
);
PREPARE mcp_payload_hash_stmt FROM @mcp_payload_hash_sql;
EXECUTE mcp_payload_hash_stmt;
DEALLOCATE PREPARE mcp_payload_hash_stmt;

ALTER TABLE mcp_proposals MODIFY COLUMN kind VARCHAR(40) NOT NULL;

CREATE TABLE IF NOT EXISTS mcp_clients (
  client_id VARCHAR(128) NOT NULL,
  client_name VARCHAR(200) NOT NULL,
  redirect_uris JSON NOT NULL,
  is_confidential TINYINT(1) NOT NULL DEFAULT 0,
  client_secret_hash VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at TIMESTAMP NULL,
  PRIMARY KEY (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mcp_auth_codes (
  id VARCHAR(128) NOT NULL,
  client_id VARCHAR(128) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  expires_at DATETIME NOT NULL,
  revoked TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mcp_auth_codes_expires (expires_at),
  KEY idx_mcp_auth_codes_user (user_id),
  CONSTRAINT fk_mcp_auth_codes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mcp_access_tokens (
  id VARCHAR(128) NOT NULL,
  client_id VARCHAR(128) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  scopes JSON NOT NULL,
  audience VARCHAR(255) NOT NULL,
  expires_at DATETIME NOT NULL,
  revoked TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mcp_access_tokens_user (user_id),
  KEY idx_mcp_access_tokens_expires (expires_at),
  CONSTRAINT fk_mcp_access_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mcp_refresh_tokens (
  id VARCHAR(128) NOT NULL,
  client_id VARCHAR(128) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  expires_at DATETIME NOT NULL,
  revoked TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mcp_refresh_tokens_user (user_id),
  KEY idx_mcp_refresh_tokens_expires (expires_at),
  CONSTRAINT fk_mcp_refresh_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mcp_login_requests (
  id CHAR(64) NOT NULL,
  return_to VARCHAR(2048) NOT NULL,
  discord_state CHAR(64) NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mcp_login_discord_state (discord_state),
  KEY idx_mcp_login_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mcp_consents (
  id CHAR(64) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  client_id VARCHAR(128) NOT NULL,
  redirect_uri VARCHAR(512) NOT NULL,
  scopes VARCHAR(255) NOT NULL,
  state VARCHAR(512) NULL,
  code_challenge VARCHAR(128) NOT NULL,
  code_challenge_method VARCHAR(10) NOT NULL,
  resource VARCHAR(255) NOT NULL,
  csrf CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mcp_consents_expires (expires_at),
  KEY idx_mcp_consents_user (user_id),
  CONSTRAINT fk_mcp_consents_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mcp_idempotency (
  user_id BIGINT UNSIGNED NOT NULL,
  idem_key VARCHAR(128) NOT NULL,
  tool VARCHAR(64) NOT NULL,
  response_json JSON NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, idem_key),
  KEY idx_mcp_idempotency_expires (expires_at),
  CONSTRAINT fk_mcp_idempotency_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
