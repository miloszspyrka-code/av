<?php
declare(strict_types=1);

namespace AlphaVault\Integrations;

/**
 * Odtwarza zamknięte transakcje z pojedynczych wypełnień (fills) dla giełd,
 * które nie udostępniają historii zamkniętych pozycji (WEEX, spot OKX).
 */
final class PositionReconstructor
{
    /**
     * @param array<int,array{id:string,symbol:string,side:string,positionSide:?string,qty:float,price:float,fee:float,pnl:?float,time:int}> $fills
     * @return array<int,array<string,mixed>>
     */
    public static function build(array $fills, string $provider, string $label): array
    {
        usort($fills, static fn (array $a, array $b): int => $a['time'] <=> $b['time']);

        /** @var array<string,array<string,mixed>> $open */
        $open = [];
        $trades = [];

        foreach ($fills as $fill) {
            if ($fill['qty'] <= 0 || $fill['price'] <= 0) {
                continue;
            }
            $groupKey = $fill['symbol'] . '|' . ($fill['positionSide'] ?: 'net');
            if (!isset($open[$groupKey])) {
                $open[$groupKey] = self::newCycle($fill);
            }

            $remaining = $fill['qty'];
            $guard = 0;
            while ($remaining > 1e-9 && $guard++ < 4) {
                if (!isset($open[$groupKey])) {
                    $open[$groupKey] = self::newCycle($fill);
                }
                $cycle = &$open[$groupKey];

                if ($cycle['qty'] <= 1e-9) {
                    $open[$groupKey] = self::newCycle($fill);
                    $cycle = &$open[$groupKey];
                }

                if (self::isOpening($cycle, $fill)) {
                    $cycle['qty'] += $remaining;
                    $cycle['entryNotional'] += $remaining * $fill['price'];
                    $cycle['entryTime'] = $cycle['entryTime'] ?? $fill['time'];
                    $cycle['fees'] += $fill['fee'] * ($remaining / $fill['qty']);
                    $cycle['ids'][] = $fill['id'];
                    $remaining = 0;
                    break;
                }

                $closeQty = min($remaining, $cycle['qty']);
                $ratio = $closeQty / $fill['qty'];
                $avgEntry = $cycle['qty'] > 0 ? $cycle['entryNotional'] / $cycle['qty'] : $fill['price'];
                $cycle['closedEntryNotional'] += $avgEntry * $closeQty;
                $cycle['exitNotional'] += $closeQty * $fill['price'];
                $cycle['exitQty'] += $closeQty;
                $cycle['exitTime'] = $fill['time'];
                $cycle['fees'] += $fill['fee'] * $ratio;
                $cycle['ids'][] = $fill['id'];

                if ($fill['pnl'] !== null) {
                    $cycle['pnl'] += $fill['pnl'] * $ratio;
                } else {
                    $cycle['pnl'] += ($fill['price'] - $avgEntry) * $closeQty * ($cycle['dir'] === 'l' ? 1 : -1);
                }

                $cycle['qty'] -= $closeQty;
                $cycle['entryNotional'] -= $avgEntry * $closeQty;
                $remaining -= $closeQty;

                if ($cycle['qty'] <= 1e-9) {
                    $trades[] = self::trade($cycle, $provider, $label);
                    unset($open[$groupKey]);
                    if ($remaining > 1e-9) {
                        $open[$groupKey] = self::newCycle($fill);
                    }
                }
            }
            unset($cycle);
        }

        usort($trades, static fn (array $a, array $b): int => strcmp((string) $a['date'], (string) $b['date']));
        return $trades;
    }

    /** @param array<string,mixed> $fill */
    private static function newCycle(array $fill): array
    {
        return [
            'symbol' => $fill['symbol'],
            'dir' => Normalizer::direction($fill['side'], $fill['positionSide']),
            'qty' => 0.0,
            'entryNotional' => 0.0,
            'closedEntryNotional' => 0.0,
            'entryTime' => null,
            'exitNotional' => 0.0,
            'exitQty' => 0.0,
            'exitTime' => null,
            'fees' => 0.0,
            'pnl' => 0.0,
            'ids' => [],
        ];
    }

    /** @param array<string,mixed> $cycle */
    private static function isOpening(array $cycle, array $fill): bool
    {
        $side = strtolower($fill['side']);
        return $cycle['dir'] === 'l' ? $side === 'buy' : $side === 'sell';
    }

    /** @param array<string,mixed> $cycle */
    private static function trade(array $cycle, string $provider, string $label): array
    {
        $qty = (float) $cycle['exitQty'];
        if ($qty <= 0) {
            return [];
        }
        $entry = (float) $cycle['closedEntryNotional'] / $qty;
        $exit = (float) $cycle['exitNotional'] / $qty;
        $pnl = (float) $cycle['pnl'];
        $fees = (float) $cycle['fees'];
        $firstId = (string) ($cycle['ids'][0] ?? bin2hex(random_bytes(6)));
        $closeAt = (int) ($cycle['exitTime'] ?? $cycle['entryTime'] ?? time() * 1000);
        $openAt = (int) ($cycle['entryTime'] ?? $closeAt);
        $symbol = (string) $cycle['symbol'];

        return [
            'extId' => $provider . ':' . $symbol . ':' . $firstId . ':' . $closeAt,
            'date' => Normalizer::iso($closeAt),
            'pair' => Normalizer::pair($symbol),
            'dir' => $cycle['dir'],
            'entry' => Normalizer::price($entry),
            'exit' => Normalizer::price($exit),
            'size' => Normalizer::size($qty),
            'result' => round($pnl + $fees, 8),
            'fee' => round(abs($fees), 8),
            'durationMin' => max(0, (int) round(($closeAt - $openAt) / 60000)),
            'source' => $label,
            'account' => $label,
        ];
    }
}
