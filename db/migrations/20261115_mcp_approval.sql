-- MCP 2.1: zatwierdzanie propozycji przez MCP kodem jednorazowym.
-- Migracja addytywna i powtarzalna. Nie usuwa danych.

SET @has_diff_hash = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'mcp_proposals' AND column_name = 'diff_hash'
);
SET @sql = IF(@has_diff_hash = 0,
  'ALTER TABLE mcp_proposals ADD COLUMN diff_hash CHAR(64) NULL AFTER payload_hash',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_code_hash = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'mcp_proposals' AND column_name = 'approval_code_hash'
);
SET @sql = IF(@has_code_hash = 0,
  'ALTER TABLE mcp_proposals ADD COLUMN approval_code_hash CHAR(64) NULL AFTER idempotency_key',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_code_expires = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'mcp_proposals' AND column_name = 'approval_code_expires_at'
);
SET @sql = IF(@has_code_expires = 0,
  'ALTER TABLE mcp_proposals ADD COLUMN approval_code_expires_at DATETIME NULL AFTER approval_code_hash',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_code_attempts = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'mcp_proposals' AND column_name = 'approval_code_attempts'
);
SET @sql = IF(@has_code_attempts = 0,
  'ALTER TABLE mcp_proposals ADD COLUMN approval_code_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER approval_code_expires_at',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_code_used = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'mcp_proposals' AND column_name = 'approval_code_used_at'
);
SET @sql = IF(@has_code_used = 0,
  'ALTER TABLE mcp_proposals ADD COLUMN approval_code_used_at DATETIME NULL AFTER approval_code_attempts',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_decision_key = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'mcp_proposals' AND column_name = 'decision_key'
);
SET @sql = IF(@has_decision_key = 0,
  'ALTER TABLE mcp_proposals ADD COLUMN decision_key VARCHAR(120) NULL AFTER approval_code_used_at',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_decision_result = (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'mcp_proposals' AND column_name = 'decision_result_json'
);
SET @sql = IF(@has_decision_result = 0,
  'ALTER TABLE mcp_proposals ADD COLUMN decision_result_json JSON NULL AFTER decision_key',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_decision_index = (
  SELECT COUNT(*) FROM information_schema.statistics
   WHERE table_schema = DATABASE() AND table_name = 'mcp_proposals' AND index_name = 'uq_mcp_proposals_decision'
);
SET @sql = IF(@has_decision_index = 0,
  'ALTER TABLE mcp_proposals ADD UNIQUE KEY uq_mcp_proposals_decision (user_id, decision_key)',
  'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE mcp_proposals MODIFY COLUMN kind VARCHAR(40) NOT NULL;
