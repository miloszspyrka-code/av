<?php
declare(strict_types=1);

namespace AlphaVault\Integrations;

/**
 * Ingestion danych z Expert Advisor (MetaTrader 4/5) po kodzie parowania.
 * Kod jest jednorazowo pokazywany użytkownikowi; w bazie leży tylko SHA-256.
 */
final class MtEa
{
    public function __construct(
        private readonly ConnectionStore $store,
        private readonly ExchangeSync $sync,
    ) {
    }

    /** @return array{code:string,id:int} */
    public function pair(int $userId, string $platform): array
    {
        // W UI jest jedno połączenie MetaTrader; nowy kod zastępuje poprzednie.
        foreach ($this->store->listForUser($userId) as $row) {
            if (!in_array((string) $row['provider'], ['mt4', 'mt5'], true)) {
                continue;
            }
            $full = $this->store->findForUser($userId, (int) $row['id']);
            if ($full === null || empty($full['pairing_hash'])) {
                // MetaApi to też provider mt4/mt5 — nie usuwaj go przy parowaniu EA.
                continue;
            }
            $this->sync->disconnect($full);
            $this->store->delete($userId, (int) $row['id']);
        }
        return $this->store->createPairing($userId, $platform);
    }

    /**
     * @param array<int,array<string,mixed>> $rawTrades
     * @return array{imported:int,skipped:int,connectionId:int}
     */
    public function ingest(string $code, array $rawTrades): array
    {
        $code = strtoupper(trim($code));
        if (!preg_match('/^AV-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $code)) {
            throw new IntegrationException('invalid_pairing_code', 'Niepoprawny kod parowania.', 401);
        }
        $row = $this->store->findByPairingHash(hash('sha256', $code));
        if ($row === null) {
            throw new IntegrationException('invalid_pairing_code', 'Kod parowania wygasł lub został unieważniony.', 401);
        }
        if (count($rawTrades) > 200) {
            throw new IntegrationException('too_many_trades', 'Maksymalnie 200 transakcji w jednym żądaniu.', 413);
        }

        $trades = [];
        foreach ($rawTrades as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $normalized = $this->normalize($raw);
            if ($normalized !== null) {
                $trades[] = $normalized;
            }
        }
        if ($trades === []) {
            throw new IntegrationException('no_valid_trades', 'Brak poprawnych transakcji w żądaniu.', 422);
        }

        $provider = (string) $row['provider'];
        $label = (string) ($row['label'] ?? 'MetaTrader');
        $result = $this->sync->mergeIntoTrades((int) $row['user_id'], $provider, $trades, $label);
        $this->store->markPairingConnected((int) $row['id'], 'ea', (int) $result['imported']);

        return [
            'imported' => (int) $result['imported'],
            'skipped' => (int) $result['skipped'],
            'connectionId' => (int) $row['id'],
        ];
    }

    /** @param array<string,mixed> $raw
     *  @return array<string,mixed>|null
     */
    private function normalize(array $raw): ?array
    {
        $ticket = trim((string) ($raw['ticket'] ?? $raw['id'] ?? ''));
        $symbol = trim((string) ($raw['symbol'] ?? ''));
        $side = strtolower(trim((string) ($raw['side'] ?? $raw['type'] ?? '')));
        $closeMs = Normalizer::toMs($raw['closeTime'] ?? $raw['close_time'] ?? null);
        $openMs = Normalizer::toMs($raw['openTime'] ?? $raw['open_time'] ?? null);
        $lots = Normalizer::num($raw['lots'] ?? $raw['volume'] ?? null);
        $openPrice = Normalizer::num($raw['openPrice'] ?? $raw['open_price'] ?? null);
        $closePrice = Normalizer::num($raw['closePrice'] ?? $raw['close_price'] ?? null);
        $profit = Normalizer::num($raw['profit'] ?? null) ?? 0.0;
        $commission = Normalizer::num($raw['commission'] ?? null) ?? 0.0;
        $swap = Normalizer::num($raw['swap'] ?? null) ?? 0.0;

        if ($ticket === '' || $symbol === '' || $closeMs === null || $lots === null || $lots <= 0) {
            return null;
        }
        if (!in_array($side, ['buy', 'sell', 'long', 'short'], true)) {
            return null;
        }
        if ($openPrice === null || $closePrice === null || $openPrice <= 0 || $closePrice <= 0) {
            return null;
        }
        $dir = in_array($side, ['sell', 'short'], true) ? 's' : 'l';
        $pair = Normalizer::pair($symbol);
        if ($pair === '') {
            return null;
        }
        $openMs = $openMs ?? $closeMs;

        return [
            'extId' => 'ea:' . $ticket,
            'date' => Normalizer::iso($closeMs),
            'pair' => $pair,
            'dir' => $dir,
            'entry' => Normalizer::price($openPrice),
            'exit' => Normalizer::price($closePrice),
            'size' => Normalizer::size($lots),
            'result' => round($profit + $commission + $swap, 8),
            'fee' => round(abs($commission + $swap), 8),
            'durationMin' => max(0, (int) round(($closeMs - $openMs) / 60000)),
            'source' => 'MetaTrader',
            'account' => 'MetaTrader',
        ];
    }
}
