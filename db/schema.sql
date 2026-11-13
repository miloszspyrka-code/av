-- Alpha Vault — schemat MySQL/MariaDB
-- Zgodny z MySQL 8.x i MariaDB 11.x. Nie zawiera danych klientów.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  discord_id VARCHAR(32) NULL,
  username VARCHAR(100) NOT NULL,
  global_name VARCHAR(100) NULL,
  avatar_url VARCHAR(512) NULL,
  email VARCHAR(255) NULL,
  is_demo TINYINT(1) NOT NULL DEFAULT 0,
  plan ENUM('solo','full') NOT NULL DEFAULT 'solo',
  access_until DATE NULL,
  role_verified TINYINT(1) NOT NULL DEFAULT 0,
  role_checked_at DATETIME NULL,
  role_check_status VARCHAR(16) NOT NULL DEFAULT 'never',
  role_check_error VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  last_login_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_discord (discord_id),
  KEY idx_users_demo (is_demo),
  KEY idx_users_role_check (role_checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
  id CHAR(64) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  remember TINYINT(1) NOT NULL DEFAULT 1,
  user_agent VARCHAR(512) NULL,
  ip VARCHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_sessions_user (user_id),
  KEY idx_sessions_expires (expires_at),
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oauth_states (
  state CHAR(64) NOT NULL,
  remember TINYINT(1) NOT NULL DEFAULT 1,
  redirect_to VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  PRIMARY KEY (state),
  KEY idx_oauth_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_states (
  user_id BIGINT UNSIGNED NOT NULL,
  state JSON NOT NULL,
  revision INT UNSIGNED NOT NULL DEFAULT 1,
  bytes INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_states_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(64) NOT NULL,
  detail JSON NULL,
  ip VARCHAR(64) NULL,
  user_agent VARCHAR(512) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_user (user_id),
  KEY idx_audit_action (action),
  KEY idx_audit_created (created_at),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  bucket VARCHAR(64) NOT NULL,
  ip VARCHAR(64) NOT NULL,
  window_start BIGINT UNSIGNED NOT NULL,
  count INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (bucket, ip),
  KEY idx_rate_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_check_state (
  id TINYINT UNSIGNED NOT NULL,
  last_attempt_at DATETIME NULL,
  last_success_at DATETIME NULL,
  last_status VARCHAR(20) NOT NULL DEFAULT 'never',
  last_error VARCHAR(500) NULL,
  checked_count INT UNSIGNED NOT NULL DEFAULT 0,
  revoked_count INT UNSIGNED NOT NULL DEFAULT 0,
  error_count INT UNSIGNED NOT NULL DEFAULT 0,
  lock_token VARCHAR(64) NULL,
  locked_until DATETIME NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO role_check_state (id, last_status) VALUES (1, 'never')
ON DUPLICATE KEY UPDATE id = id;

CREATE TABLE IF NOT EXISTS discord_scheduled_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  discord_event_id VARCHAR(32) NOT NULL,
  guild_id VARCHAR(32) NOT NULL,
  name VARCHAR(255) NOT NULL,
  description TEXT NULL,
  event_type TINYINT UNSIGNED NOT NULL DEFAULT 0,
  status TINYINT UNSIGNED NOT NULL DEFAULT 0,
  channel_id VARCHAR(32) NULL,
  scheduled_start_time DATETIME NOT NULL,
  scheduled_start_iso VARCHAR(40) NOT NULL,
  scheduled_end_time DATETIME NULL,
  scheduled_end_iso VARCHAR(40) NULL,
  creator_id VARCHAR(32) NULL,
  creator_json JSON NULL,
  user_count INT UNSIGNED NULL,
  location VARCHAR(255) NULL,
  entity_id VARCHAR(32) NULL,
  entity_metadata JSON NULL,
  image VARCHAR(255) NULL,
  recurrence_rule JSON NULL,
  raw_payload JSON NOT NULL,
  category ENUM('live','consultation','unassigned') NOT NULL DEFAULT 'unassigned',
  category_source ENUM('manual','rule','default','none') NOT NULL DEFAULT 'none',
  manual_category ENUM('live','consultation','unassigned') NULL,
  host_name VARCHAR(100) NULL,
  host_source ENUM('name_rule','creator','manual','none') NOT NULL DEFAULT 'none',
  duration_minutes INT UNSIGNED NULL,
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  last_synced_at DATETIME NOT NULL,
  is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_discord_event_id (discord_event_id),
  KEY idx_discord_guild (guild_id),
  KEY idx_discord_status (status),
  KEY idx_discord_type (event_type),
  KEY idx_discord_start (scheduled_start_time),
  KEY idx_discord_category (category),
  KEY idx_discord_deleted (is_deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS discord_sync_state (
  id TINYINT UNSIGNED NOT NULL,
  last_attempt_at DATETIME NULL,
  last_success_at DATETIME NULL,
  last_status VARCHAR(20) NOT NULL DEFAULT 'never',
  last_error_code VARCHAR(64) NULL,
  last_error_message VARCHAR(500) NULL,
  last_http_status SMALLINT UNSIGNED NULL,
  events_count INT UNSIGNED NOT NULL DEFAULT 0,
  updated_count INT UNSIGNED NOT NULL DEFAULT 0,
  duration_ms INT UNSIGNED NULL,
  lock_token VARCHAR(64) NULL,
  locked_until DATETIME NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO discord_sync_state (id, last_status) VALUES (1, 'never')
ON DUPLICATE KEY UPDATE id = id;

CREATE TABLE IF NOT EXISTS discord_sync_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  status VARCHAR(20) NOT NULL,
  http_status SMALLINT UNSIGNED NULL,
  events_count INT UNSIGNED NOT NULL DEFAULT 0,
  updated_count INT UNSIGNED NOT NULL DEFAULT 0,
  duration_ms INT UNSIGNED NULL,
  error_message VARCHAR(500) NULL,
  PRIMARY KEY (id),
  KEY idx_sync_runs_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Integracje urządzeń: OAuth2 (Garmin/Oura/Whoop), most Apple Health.
CREATE TABLE IF NOT EXISTS wearable_connections (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  provider ENUM('garmin','apple','oura','whoop') NOT NULL,
  status ENUM('connected','revoked') NOT NULL DEFAULT 'connected',
  external_user_id VARCHAR(191) NULL,
  scopes VARCHAR(500) NULL,
  access_token_enc TEXT NULL,
  refresh_token_enc TEXT NULL,
  token_expires_at DATETIME NULL,
  refresh_token_expires_at DATETIME NULL,
  device_token_hash CHAR(64) NULL,
  device_name VARCHAR(100) NULL,
  last_sync_at DATETIME NULL,
  last_sync_status VARCHAR(20) NULL,
  last_error VARCHAR(300) NULL,
  consent_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wearable_user_provider (user_id, provider),
  KEY idx_wearable_device_token (device_token_hash),
  KEY idx_wearable_status (status),
  CONSTRAINT fk_wearable_conn_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wearable_daily_metrics (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  provider ENUM('garmin','apple','oura','whoop') NOT NULL,
  metric_date DATE NOT NULL,
  sleep_minutes SMALLINT UNSIGNED NULL,
  hrv_ms DECIMAL(6,1) NULL,
  resting_hr SMALLINT UNSIGNED NULL,
  body_battery TINYINT UNSIGNED NULL,
  provider_score TINYINT UNSIGNED NULL,
  raw_payload JSON NULL,
  synced_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wearable_metric (user_id, provider, metric_date),
  KEY idx_wearable_metric_date (user_id, metric_date),
  CONSTRAINT fk_wearable_metric_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wearable_oauth_states (
  state CHAR(64) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  provider ENUM('garmin','oura','whoop') NOT NULL,
  code_verifier VARCHAR(128) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  PRIMARY KEY (state),
  KEY idx_wearable_state_expires (expires_at),
  KEY idx_wearable_state_user (user_id),
  CONSTRAINT fk_wearable_state_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wearable_pair_codes (
  code_hash CHAR(64) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  PRIMARY KEY (code_hash),
  KEY idx_wearable_pair_expires (expires_at),
  KEY idx_wearable_pair_user (user_id),
  CONSTRAINT fk_wearable_pair_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exchange_connections (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(16) NOT NULL,
  label VARCHAR(64) NOT NULL,
  account_ref VARCHAR(128) NOT NULL DEFAULT '',
  key_mask VARCHAR(64) NOT NULL DEFAULT '',
  credentials TEXT NOT NULL,
  pairing_hash CHAR(64) NULL,
  pairing_expires_at DATETIME NULL,
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
  kind VARCHAR(40) NOT NULL,
  target_ref VARCHAR(120) NULL,
  base_revision INT UNSIGNED NULL,
  payload_hash CHAR(64) NULL,
  diff_hash CHAR(64) NULL,
  before_json JSON NULL,
  after_json JSON NOT NULL,
  diff_json JSON NOT NULL,
  status ENUM('pending','approved','rejected','expired') NOT NULL DEFAULT 'pending',
  idempotency_key VARCHAR(120) NOT NULL,
  approval_code_hash CHAR(64) NULL,
  approval_code_expires_at DATETIME NULL,
  approval_code_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  approval_code_used_at DATETIME NULL,
  decision_key VARCHAR(120) NULL,
  decision_result_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  decided_at DATETIME NULL,
  decided_by BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mcp_proposals_idem (user_id, idempotency_key),
  UNIQUE KEY uq_mcp_proposals_decision (user_id, decision_key),
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

-- OAuth 2.1 dla mcp.alphavault.pl. Tabele addytywne, wspólne z aplikacją.
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

CREATE TABLE IF NOT EXISTS schema_migrations (
  version VARCHAR(64) NOT NULL,
  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
