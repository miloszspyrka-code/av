-- AlphaVault MCP — tokeny, propozycje zmian i audyt.
-- Tabele addytywne. Nie zmieniają istniejących danych aplikacji.

CREATE TABLE IF NOT EXISTS mcp_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  scopes VARCHAR(200) NOT NULL DEFAULT 'panel:read',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at DATETIME NULL,
  expires_at DATETIME NULL,
  revoked_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mcp_tokens_hash (token_hash),
  KEY idx_mcp_tokens_user (user_id, revoked_at),
  CONSTRAINT fk_mcp_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mcp_proposals (
  id CHAR(36) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  client_name VARCHAR(100) NULL,
  kind ENUM('day_plan','checkin','panel_trade','trade_review') NOT NULL,
  target_ref VARCHAR(120) NULL,
  base_revision INT UNSIGNED NULL,
  before_json JSON NULL,
  after_json JSON NOT NULL,
  diff_json JSON NOT NULL,
  status ENUM('pending','approved','rejected','expired') NOT NULL DEFAULT 'pending',
  idempotency_key VARCHAR(120) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  decided_at DATETIME NULL,
  decided_by BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mcp_proposals_idem (user_id, idempotency_key),
  KEY idx_mcp_proposals_user (user_id, status, expires_at),
  CONSTRAINT fk_mcp_proposals_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mcp_audit (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  proposal_id CHAR(36) NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  actor ENUM('agent','user','system') NOT NULL,
  action VARCHAR(80) NOT NULL,
  detail_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mcp_audit_user (user_id, created_at),
  KEY idx_mcp_audit_proposal (proposal_id),
  CONSTRAINT fk_mcp_audit_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
