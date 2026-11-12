<?php
declare(strict_types=1);

namespace AlphaVault\Integrations;

use AlphaVault\Db;

/**
 * Orkiestracja synchronizacji: pobranie transakcji, scalenie ze stanem użytkownika,
 * deduplikacja po externalId i zapis statusu połączenia.
 */
final class ExchangeSync
{
    /** @param array<string,ProviderInterface> $providers */
    public function __construct(
        private readonly Db $db,
        private readonly ConnectionStore $store,
        private readonly array $providers,
        private readonly int $maxStateBytes,
    ) {
    }

    /** @return array<string,mixed> */
    public function syncUserConnection(int $userId, int $id): array
    {
        $row = $this->store->findForUser($userId, $id);
        if ($row === null) {
            throw new IntegrationException('not_found', 'Nie znaleziono połączenia.', 404);
        }
        return $this->syncRecord($row);
    }

    /** @return array<string,mixed> */
    public function syncUser(int $userId, ?string $onlyProvider = null): array
    {
        $summaries = [];
        $deadline = microtime(true) + 22.0;
        foreach ($this->store->listForUser($userId) as $public) {
            if ($onlyProvider !== null && $public['provider'] !== $onlyProvider) {
                continue;
            }
            if ($public['status'] === 'pending' && !str_starts_with((string) $public['provider'], 'mt')) {
                continue;
            }
            if ($public['lastSyncAt'] !== null && strtotime((string) $public['lastSyncAt'] . ' UTC') > time() - 60) {
                $summaries[] = ['id' => $public['id'], 'provider' => $public['provider'], 'skipped' => true, 'imported' => 0];
                continue;
            }
            if (microtime(true) >= $deadline) {
                $summaries[] = ['id' => $public['id'], 'provider' => $public['provider'], 'pending' => true, 'imported' => 0];
                continue;
            }
            $row = $this->store->findForUser($userId, (int) $public['id']);
            if ($row === null) {
                continue;
            }
            try {
                $summaries[] = $this->syncRecord($row);
            } catch (IntegrationException $err) {
                $summaries[] = [
                    'id' => (int) $public['id'],
                    'provider' => (string) $public['provider'],
                    'error' => $err->errorCode,
                    'message' => $err->getMessage(),
                    'imported' => 0,
                ];
            }
        }
        return ['ok' => true, 'connections' => $summaries];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function syncRecord(array $row): array
    {
        $provider = (string) $row['provider'];
        $adapter = $this->providers[$provider] ?? null;
        if ($adapter === null) {
            throw new IntegrationException('unsupported_provider', 'Nieobsługiwany dostawca: ' . $provider, 422);
        }
        $credentials = is_array($row['credentials'] ?? null) ? $row['credentials'] : [];
        if ($credentials === []) {
            throw new IntegrationException('invalid_credentials', 'Brak poświadczeń połączenia. Połącz ponownie.', 422);
        }

        $now = (int) round(microtime(true) * 1000);
        $backfillDone = (int) ($row['backfill_done'] ?? 0) === 1;
        $lastSync = $row['last_sync_at'] !== null ? strtotime((string) $row['last_sync_at'] . ' UTC') : null;
        $target = $now - 90 * 86400 * 1000;
        $cursorOut = $now;
        if ($backfillDone && $lastSync !== null) {
            $from = max(0, ($lastSync - 86400) * 1000);
            $to = $now;
            $done = true;
        } else {
            $cursor = (int) ($row['cursor_ms'] ?? 0);
            if ($cursor <= 0) {
                // Najpierw najnowsze 30 dni, potem starsze paczki aż do 90 dni.
                $to = $now;
                $from = max($target, $now - 30 * 86400 * 1000);
            } else {
                $to = min($cursor, $now);
                $from = max($target, $to - 30 * 86400 * 1000);
            }
            $done = $from <= $target;
            $cursorOut = $done ? $now : max($target, $from - 1);
        }
        if ($to <= $from) {
            return ['id' => (int) $row['id'], 'provider' => $provider, 'fetched' => 0, 'imported' => 0, 'done' => $backfillDone];
        }

        $trades = $adapter->fetchTrades($credentials, $from, $to);
        $merge = $this->mergeIntoTrades((int) $row['user_id'], $provider, $trades, (string) $row['label']);
        $this->store->markSync((int) $row['id'], 'success', null, (int) $merge['imported'], $cursorOut, $done);
        $this->store->recordRun((int) $row['id'], 'success', count($trades), (int) $merge['imported'], null);

        return [
            'id' => (int) $row['id'],
            'provider' => $provider,
            'fetched' => count($trades),
            'imported' => (int) $merge['imported'],
            'skipped' => (int) $merge['skipped'],
            'done' => $done,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $trades
     * @return array{imported:int,skipped:int}
     */
    public function mergeIntoTrades(int $userId, string $provider, array $trades, string $label): array
    {
        if ($trades === []) {
            return ['imported' => 0, 'skipped' => 0];
        }
        $max = 500;
        $trades = array_slice($trades, 0, $max);

        return $this->db->withTransaction(function (Db $db) use ($userId, $provider, $trades, $label): array {
            $row = $db->queryOne('SELECT state, revision FROM user_states WHERE user_id = ? FOR UPDATE', [$userId]);
            if ($row === null) {
                throw new IntegrationException(
                    'state_missing',
                    'Brak stanu użytkownika. Otwórz aplikację, aby założyć dziennik, i uruchom synchronizację ponownie.',
                    409
                );
            }
            $state = json_decode((string) $row['state'], true);
            if (!is_array($state)) {
                throw new IntegrationException('state_invalid', 'Stan użytkownika jest uszkodzony.', 500);
            }
            $existing = [];
            $stateTrades = is_array($state['trades'] ?? null) ? $state['trades'] : [];
            $accountName = $this->accountName($state, $provider, $label);
            foreach ($stateTrades as $trade) {
                if (is_array($trade) && !empty($trade['extId'])) {
                    $existing[(string) $trade['extId']] = true;
                }
            }
            $imports = $db->query(
                'SELECT external_id FROM exchange_imports WHERE user_id = ? AND provider = ?',
                [$userId, $provider]
            );
            foreach ($imports as $import) {
                $existing[(string) $import['external_id']] = true;
            }

            $imported = 0;
            $skipped = 0;
            foreach ($trades as $trade) {
                $extId = substr(trim((string) ($trade['extId'] ?? '')), 0, 191);
                $pair = trim((string) ($trade['pair'] ?? ''));
                $date = trim((string) ($trade['date'] ?? ''));
                if ($extId === '' || $pair === '' || $date === '') {
                    $skipped++;
                    continue;
                }
                if (isset($existing[$extId])) {
                    $skipped++;
                    continue;
                }
                $tradeId = 'tr' . bin2hex(random_bytes(5));
                $stateTrades[] = $this->stateTrade($trade, $extId, $tradeId, $label, $accountName);
                $db->execute(
                    'INSERT IGNORE INTO exchange_imports (user_id, provider, external_id, trade_id, imported_at) VALUES (?, ?, ?, ?, NOW())',
                    [$userId, $provider, $extId, $tradeId]
                );
                $existing[$extId] = true;
                $imported++;
            }

            if ($imported === 0) {
                return ['imported' => 0, 'skipped' => $skipped];
            }
            $state['trades'] = array_values($stateTrades);
            $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new IntegrationException('state_encode_failed', 'Nie można zapisać transakcji w stanie.', 500);
            }
            $bytes = strlen($json);
            if ($bytes > $this->maxStateBytes) {
                throw new IntegrationException('state_too_large', 'Dziennik jest zbyt duży, aby dodać transakcje.', 413);
            }
            $db->execute(
                'UPDATE user_states SET state = ?, revision = revision + 1, bytes = ? WHERE user_id = ?',
                [$json, $bytes, $userId]
            );
            return ['imported' => $imported, 'skipped' => $skipped];
        });
    }

    /**
     * Dopasowuje transakcję do konta w dzienniku, żeby nie wypadała z filtrów.
     *
     * @param array<string,mixed> $state
     */
    private function accountName(array $state, string $provider, string $label): string
    {
        $accounts = is_array($state['accounts'] ?? null) ? $state['accounts'] : [];
        $needles = [
            'weex' => ['weex'],
            'okx' => ['okx'],
            'bingx' => ['bingx'],
            'mt4' => ['metatrader', 'mt4', 'mt5', 'forex'],
            'mt5' => ['metatrader', 'mt5', 'mt4', 'forex'],
        ][$provider] ?? [strtolower($label)];
        foreach ($accounts as $account) {
            if (!is_array($account) || empty($account['include'])) {
                continue;
            }
            $haystack = strtolower((string) ($account['name'] ?? '') . ' ' . (string) ($account['exchange'] ?? ''));
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    return (string) ($account['name'] ?? $label);
                }
            }
        }
        foreach ($accounts as $account) {
            if (is_array($account) && !empty($account['include']) && !empty($account['primary'])) {
                return (string) ($account['name'] ?? $label);
            }
        }
        foreach ($accounts as $account) {
            if (is_array($account) && !empty($account['include'])) {
                return (string) ($account['name'] ?? $label);
            }
        }
        return $label;
    }

    /**
     * @param array<string,mixed> $trade
     * @return array<string,mixed>
     */
    private function stateTrade(array $trade, string $extId, string $tradeId, string $label, string $accountName): array
    {
        $date = (string) $trade['date'];
        $timestamp = strtotime($date);
        return [
            'id' => $tradeId,
            'date' => $date,
            'pair' => (string) $trade['pair'],
            'dir' => ($trade['dir'] ?? 'l') === 's' ? 's' : 'l',
            'entry' => (string) ($trade['entry'] ?? ''),
            'exit' => (string) ($trade['exit'] ?? ''),
            'sl' => '',
            'tp' => '',
            'size' => (string) ($trade['size'] ?? ''),
            'result' => round((float) ($trade['result'] ?? 0), 8),
            'r' => null,
            'grade' => '',
            'checklist' => 0,
            'checkTotal' => 7,
            'errors' => [],
            'emotion' => '',
            'note' => 'Import automatyczny · ' . $label . '.',
            'mentor' => '',
            'setup' => '',
            'session' => '',
            'mae' => 0,
            'mfe' => 0,
            'durationMin' => max(0, (int) ($trade['durationMin'] ?? 0)),
            'fee' => round((float) ($trade['fee'] ?? 0), 8),
            'plan' => false,
            'source' => (string) ($trade['source'] ?? $label),
            'account' => $accountName,
            'screens' => (object) [],
            'extId' => $extId,
            'importedAt' => $timestamp !== false ? gmdate('Y-m-d\TH:i:s\Z', $timestamp) : gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * @param array<string,mixed> $row
     */
    public function disconnect(array $row): void
    {
        $provider = (string) ($row['provider'] ?? '');
        $adapter = $this->providers[$provider] ?? null;
        $credentials = is_array($row['credentials'] ?? null) ? $row['credentials'] : [];
        if ($adapter === null || $credentials === []) {
            return;
        }
        try {
            $adapter->disconnect($credentials);
        } catch (\Throwable) {
            // Odłączenie lokalne nie może zależeć od dostępności dostawcy.
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function syncDue(bool $force = false): array
    {
        $state = $this->store->syncState();
        $last = $state['last_attempt_at'] ?? null;
        if (!$force && $last !== null && strtotime((string) $last . ' UTC') > time() - 25 * 60) {
            return ['skipped' => true, 'reason' => 'not_due'];
        }

        $this->store->markScheduler('running');
        $connections = $this->store->due(50);
        $imported = 0;
        $done = 0;
        $errors = 0;
        $deadline = microtime(true) + 75.0;
        foreach ($connections as $row) {
            if (microtime(true) >= $deadline) {
                break;
            }
            try {
                $result = $this->syncRecord($row);
                $imported += (int) ($result['imported'] ?? 0);
                $done++;
            } catch (\Throwable $err) {
                $errors++;
                $this->store->markError((int) $row['id'], $err->getMessage());
                $this->store->recordRun((int) $row['id'], 'error', 0, 0, $err->getMessage());
            }
        }
        $status = $errors === 0 ? 'success' : ($done > 0 ? 'partial' : 'error');
        $this->store->markScheduler($status, $errors > 0 ? $errors . ' błędów synchronizacji' : null, $done, $imported);
        return [
            'ok' => $errors === 0,
            'connections' => $done,
            'errors' => $errors,
            'imported' => $imported,
        ];
    }
}
