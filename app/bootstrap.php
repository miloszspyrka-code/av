<?php
declare(strict_types=1);

if (!defined('AV_START')) {
    define('AV_START', microtime(true));
}

$root = dirname(__DIR__);

require_once $root . '/app/Config.php';
require_once $root . '/app/Db.php';
require_once $root . '/app/HttpException.php';
require_once $root . '/app/Http.php';
require_once $root . '/app/Auth.php';
require_once $root . '/app/Discord.php';
require_once $root . '/app/DiscordApiException.php';
require_once $root . '/app/DiscordEvents.php';

$config = \AlphaVault\Config::load($root);
$db = new \AlphaVault\Db($config->db);
$auth = new \AlphaVault\Auth($config, $db);
$discord = new \AlphaVault\Discord($config);

return [$config, $db, $auth, $discord];
