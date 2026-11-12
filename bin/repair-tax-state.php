#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Jednorazowa naprawa stanu PIT-38 po wdrożeniu 3.4.1.
 *
 * Stany zapisane przed wersją 2 schematu podatków nie miały możliwości edycji
 * nadwyżki kosztów — 380,00 zł było wartością demo przepisaną do nowych kont.
 * Skrypt zeruje wyłącznie ten dokładny artefakt u użytkowników innych niż demo
 * i oznacza stan jako wersję 2. Uruchom bez --apply, żeby zobaczyć podsumowanie.
 */

$root = dirname(__DIR__);
$apply = in_array('--apply', $argv, true);

try {
    [$config, $db] = require $root . '/app/bootstrap.php';

    $rows = $db->query(
        'SELECT s.user_id, s.state
           FROM user_states s
           JOIN users u ON u.id = s.user_id
          WHERE u.is_demo = 0'
    );

    $scanned = 0;
    $changed = 0;
    foreach ($rows as $row) {
        $scanned++;
        $state = json_decode((string) $row['state'], true);
        if (!is_array($state)) {
            continue;
        }
        $tax = is_array($state['tax'] ?? null) ? $state['tax'] : [];
        $version = (int) ($tax['version'] ?? 0);
        $carry = (float) ($tax['carryLoss'] ?? 0);
        if ($version >= 2 || $carry !== 380.0) {
            continue;
        }
        $tax['version'] = 2;
        $tax['carryLoss'] = 0;
        if ((int) ($tax['year'] ?? 0) < 2000) {
            $tax['year'] = (int) date('Y');
        }
        $state['tax'] = $tax;
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            continue;
        }
        $changed++;
        if ($apply) {
            $db->execute(
                'UPDATE user_states SET state = ?, bytes = ?, revision = revision + 1 WHERE user_id = ?',
                [$json, strlen($json), (int) $row['user_id']]
            );
        }
    }

    fwrite(STDOUT, sprintf(
        "AlphaVault tax repair: scanned=%d changed=%d mode=%s\n",
        $scanned,
        $changed,
        $apply ? 'apply' : 'dry-run'
    ));
    exit(0);
} catch (\Throwable $err) {
    fwrite(STDERR, 'AlphaVault tax repair: error ' . $err->getMessage() . "\n");
    exit(1);
}
