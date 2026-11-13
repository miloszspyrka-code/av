<?php
declare(strict_types=1);

if (!defined('AV_START')) {
    define('AV_START', microtime(true));
}

$root = dirname(__DIR__);

require_once $root . '/app/Config.php';
require_once $root . '/app/Db.php';
require_once $root . '/app/HttpException.php';
require_once $root . '/app/Encoding.php';
require_once $root . '/app/Http.php';
require_once $root . '/app/Auth.php';
require_once $root . '/app/UserService.php';
require_once $root . '/app/Discord.php';
require_once $root . '/app/RoleGuard.php';
require_once $root . '/app/DiscordApiException.php';
require_once $root . '/app/DiscordEvents.php';
require_once $root . '/app/MacroEvents.php';
require_once $root . '/app/Wearables.php';
require_once $root . '/app/Quotes.php';
require_once $root . '/app/DemoState.php';
require_once $root . '/app/Integrations/IntegrationException.php';
require_once $root . '/app/Integrations/Crypto.php';
require_once $root . '/app/Integrations/HttpClient.php';
require_once $root . '/app/Integrations/Normalizer.php';
require_once $root . '/app/Integrations/PositionReconstructor.php';
require_once $root . '/app/Integrations/ProviderInterface.php';
require_once $root . '/app/Integrations/AbstractProvider.php';
require_once $root . '/app/Integrations/OKXProvider.php';
require_once $root . '/app/Integrations/BingXProvider.php';
require_once $root . '/app/Integrations/WEEXProvider.php';
require_once $root . '/app/Integrations/MetaApiProvider.php';
require_once $root . '/app/Integrations/ConnectionStore.php';
require_once $root . '/app/Integrations/ExchangeSync.php';
require_once $root . '/app/Integrations/MtEa.php';
require_once $root . '/app/Integrations/Integrations.php';

// Composer jest wymagany tylko na domenie MCP. Na alphavault.pl katalog
// vendor/ nie jest wdrażany, więc aplikacja działa bez autoloadera PSR-4.
$vendorAutoload = $root . '/vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require_once $vendorAutoload;
}

$config = \AlphaVault\Config::load($root);
$db = new \AlphaVault\Db($config->db);
$auth = new \AlphaVault\Auth($config, $db);
$discord = new \AlphaVault\Discord($config);

return [$config, $db, $auth, $discord];
