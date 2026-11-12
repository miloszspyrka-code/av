<?php
declare(strict_types=1);

/**
 * Testy decyzji dostępu i interpretacji odpowiedzi Discord.
 * Uruchom: php tests/role-guard.test.php
 */

$root = dirname(__DIR__);
if (getenv('ALPHA_VAULT_CONFIG') === false && is_file($root . '/tests/config.test.json')) {
    putenv('ALPHA_VAULT_CONFIG=' . $root . '/tests/config.test.json');
}

[$config, $db, $auth, $discord] = require $root . '/app/bootstrap.php';

use AlphaVault\RoleGuard;

function check(bool $condition, string $label): void
{
    echo ($condition ? 'PASS' : 'FAIL') . '  ' . $label . "\n";
    if (!$condition) {
        $GLOBALS['failed'] = true;
    }
}

$GLOBALS['failed'] = false;
$guard = new RoleGuard($config, $db, $discord);

$roleOk = $discord->interpretMember(['status' => 200, 'body' => ['roles' => ['test-role']]]);
check($roleOk['ok'] === true && $roleOk['status'] === 'ok', 'rola obecna = ok');

$roleMissing = $discord->interpretMember(['status' => 200, 'body' => ['roles' => []]]);
check($roleMissing['ok'] === false && $roleMissing['status'] === 'missing', 'brak roli = missing');

$roleNoMember = $discord->interpretMember(['status' => 404, 'body' => ['message' => 'Unknown Member']]);
check($roleNoMember['status'] === 'not_member', '404 = not_member');

$roleRateLimit = $discord->interpretMember(['status' => 429, 'body' => ['message' => 'rate limited']]);
check($roleRateLimit['status'] === 'unavailable', '429 = unavailable');

$roleServerError = $discord->interpretMember(['status' => 500, 'body' => ['message' => 'boom']]);
check($roleServerError['status'] === 'unavailable', '500 = unavailable');

$roleForbidden = $discord->interpretMember(['status' => 403, 'body' => ['message' => 'Missing Access']]);
check($roleForbidden['status'] === 'unavailable', '403 bota = unavailable (nie odbiera dostępu)');

$verifiedUser = ['role_verified' => 1];
$unverifiedUser = ['role_verified' => 0];

$d1 = $guard->loginDecision(null, $roleOk);
check($d1['allow'] === true && $d1['grace'] === false, 'login: rola ok');

$d2 = $guard->loginDecision(null, $roleMissing);
check($d2['allow'] === false && $d2['http'] === 403, 'login: brak roli = 403');

$d3 = $guard->loginDecision($verifiedUser, $roleRateLimit);
check($d3['allow'] === true && $d3['grace'] === true, 'login: 429 + wcześniej zweryfikowany = dostęp z grace');

$d4 = $guard->loginDecision($unverifiedUser, $roleRateLimit);
check($d4['allow'] === false && $d4['http'] === 503, 'login: 429 + nigdy niezweryfikowany = 503');

$d5 = $guard->loginDecision(null, $roleServerError);
check($d5['allow'] === false && $d5['http'] === 503, 'login: 500 bez konta = 503');

$notConfigured = ['status' => 'not_configured', 'ok' => false];
$d6 = $guard->loginDecision($verifiedUser, $notConfigured);
check($d6['allow'] === false && $d6['http'] === 503, 'login: brak konfiguracji = fail closed');

echo "\n" . ($GLOBALS['failed'] ? 'FAIL' : 'PASS') . "\n";
exit($GLOBALS['failed'] ? 1 : 0);
