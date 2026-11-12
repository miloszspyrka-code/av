<?php
declare(strict_types=1);

namespace AlphaVault\Integrations;

use AlphaVault\Config;
use AlphaVault\Db;

final class Integrations
{
    /** @return array{store:ConnectionStore,sync:ExchangeSync,mt:MtEa}|null */
    public static function make(Config $config, Db $db): ?array
    {
        if (!$config->integrationsConfigured) {
            return null;
        }
        $crypto = new Crypto($config->integrationsKey);
        $http = new HttpClient();
        $bases = $config->integrationBases;
        $meta = new MetaApiProvider($http, $bases, $config->metaApiToken);
        $providers = [
            'okx' => new OKXProvider($http, $bases),
            'bingx' => new BingXProvider($http, $bases),
            'weex' => new WEEXProvider($http, $bases),
            'mt4' => $meta,
            'mt5' => $meta,
        ];
        $store = new ConnectionStore($db, $crypto);
        $sync = new ExchangeSync($db, $store, $providers, $config->maxStateBytes);
        return [
            'store' => $store,
            'sync' => $sync,
            'mt' => new MtEa($store, $sync),
        ];
    }
}
