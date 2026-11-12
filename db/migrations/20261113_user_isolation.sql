-- 2026-11-13: izolacja kont demo i wygasanie kodów parowania EA.
-- Bezpieczne do wielokrotnego uruchomienia.

-- Kody parowania MetaTrader są ważne 15 minut, dopóki EA się nie połączy.
SET @pairing_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'exchange_connections'
    AND COLUMN_NAME = 'pairing_expires_at'
);
SET @pairing_sql := IF(
  @pairing_col = 0,
  'ALTER TABLE exchange_connections ADD COLUMN pairing_expires_at DATETIME NULL AFTER pairing_hash',
  'DO 0'
);
PREPARE pairing_stmt FROM @pairing_sql;
EXECUTE pairing_stmt;
DEALLOCATE PREPARE pairing_stmt;

-- Stare, nieużyte kody dostają 15 minut ważności zamiast działać bezterminowo.
UPDATE exchange_connections
SET pairing_expires_at = UTC_TIMESTAMP() + INTERVAL 15 MINUTE
WHERE pairing_hash IS NOT NULL
  AND status = 'pending'
  AND pairing_expires_at IS NULL;

-- Stary wspólny użytkownik demo był widoczny dla wszystkich przeglądarek.
-- Usuwamy go razem z sesjami; nowe konta demo są tworzone per przeglądarka.
DELETE s FROM sessions s
JOIN users u ON u.id = s.user_id
WHERE u.discord_id = 'demo-local';
DELETE FROM users WHERE discord_id = 'demo-local';
