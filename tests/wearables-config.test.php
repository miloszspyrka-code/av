<?php
declare(strict_types=1);

/**
 * Test konfiguracji: brak WEARABLES_ENCRYPTION_KEY nie może być maskowany
 * przez SESSION_SECRET. Uruchamiany przez tests/wearables.e2e.mjs.
 */

$root = dirname(__DIR__);
putenv('ALPHA_VAULT_CONFIG=' . $root . '/tests/config.test.json');
putenv('APP_URL=http://127.0.0.1:4175');
putenv('OURA_CLIENT_ID=fake-oura-client');
putenv('OURA_CLIENT_SECRET=fake-oura-secret');
putenv('WEARABLES_ENCRYPTION_KEY');

[$config, $db, $auth] = require $root . '/app/bootstrap.php';

$passed = 0;
$failed = 0;
$check = static function (string $name, bool $ok) use (&$passed, &$failed): void {
    if ($ok) {
        $passed++;
        echo "PASS  {$name}\n";
    } else {
        $failed++;
        echo "FAIL  {$name}\n";
    }
};

$wearables = new \AlphaVault\Wearables($config, $db, $auth);
$check('brak klucza: Oura nie jest skonfigurowana mimo SESSION_SECRET', $wearables->providerConfigured('oura') === false);
$check('brak klucza: Apple działa bez OAuth', $wearables->providerConfigured('apple') === true);

$blocked = false;
try {
    $wearables->startUrl(1, 'oura');
} catch (\AlphaVault\HttpException $err) {
    $blocked = $err->status === 503;
}
$check('brak klucza: start OAuth = 503', $blocked);

putenv('WEARABLES_ENCRYPTION_KEY=wearables-config-test-key-0123456789');
$configWithKey = \AlphaVault\Config::load($root);
$wearablesWithKey = new \AlphaVault\Wearables($configWithKey, $db, $auth);
$check('klucz ustawiony: Oura gotowa do połączenia', $wearablesWithKey->providerConfigured('oura') === true);

putenv('GARMIN_CLIENT_ID=fake-garmin-client');
putenv('GARMIN_CLIENT_SECRET=fake-garmin-secret');
putenv('GARMIN_ACCESS_PLAN');
$configGarminNoPlan = \AlphaVault\Config::load($root);
$wearablesGarminNoPlan = new \AlphaVault\Wearables($configGarminNoPlan, $db, $auth);
$check(
    'Garmin bez planu: credentials są, ale połączenie zablokowane',
    $wearablesGarminNoPlan->providerConfigured('garmin') === true
    && $wearablesGarminNoPlan->providerCanConnect('garmin') === false
);

putenv('GARMIN_ACCESS_PLAN=starter');
$configGarminStarter = \AlphaVault\Config::load($root);
$wearablesGarminStarter = new \AlphaVault\Wearables($configGarminStarter, $db, $auth);
$check('Garmin Starter: połączenie dozwolone, dane nadal pending', $wearablesGarminStarter->providerCanConnect('garmin') === true && $wearablesGarminStarter->garminDataDeliveryReady() === false);

echo "\n{$passed}/" . ($passed + $failed) . " PASS\n";
exit($failed > 0 ? 1 : 0);
