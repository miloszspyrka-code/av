<?php
declare(strict_types=1);

namespace AlphaVault\Mcp;

use AlphaVault\Config;
use Defuse\Crypto\Key;

final class Keys
{
    public static function ensure(Config $config): array
    {
        $privatePath = $config->mcp['privateKeyPath'];
        $publicPath = $config->mcp['publicKeyPath'];
        $encryptionPath = $config->mcp['encryptionKeyPath'];

        $dir = dirname($privatePath);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Nie można utworzyć katalogu kluczy MCP.');
        }

        if (!is_file($privatePath)) {
            $options = [
                'private_key_bits' => 3072,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ];
            $opensslConfig = getenv('OPENSSL_CONF') ?: dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
            if (is_file($opensslConfig)) {
                $options['config'] = $opensslConfig;
            }
            $resource = openssl_pkey_new($options);
            if ($resource === false) {
                throw new \RuntimeException('Nie można wygenerować klucza RSA MCP.');
            }
            $privatePem = '';
            if (!openssl_pkey_export($resource, $privatePem, null, $options)) {
                throw new \RuntimeException('Nie można wyeksportować klucza prywatnego MCP.');
            }
            self::writeSecret($privatePath, $privatePem);
        }

        if (!is_file($publicPath)) {
            $privatePem = file_get_contents($privatePath);
            if ($privatePem === false) {
                throw new \RuntimeException('Nie można odczytać klucza prywatnego MCP.');
            }
            $resource = openssl_pkey_get_private($privatePem);
            if ($resource === false) {
                throw new \RuntimeException('Klucz prywatny MCP jest nieprawidłowy.');
            }
            $details = openssl_pkey_get_details($resource);
            if ($details === false || empty($details['key'])) {
                throw new \RuntimeException('Nie można wyprowadzić klucza publicznego MCP.');
            }
            self::writeFile($publicPath, (string) $details['key'], 0644);
        }

        if (!is_file($encryptionPath)) {
            self::writeSecret($encryptionPath, Key::createNewRandomKey()->saveToAsciiSafeString());
        }

        return [
            'privateKeyPath' => $privatePath,
            'publicKeyPath' => $publicPath,
            'encryptionKeyPath' => $encryptionPath,
            'keyId' => self::keyId($config),
        ];
    }

    public static function keyId(Config $config): string
    {
        $publicPem = file_get_contents($config->mcp['publicKeyPath']);
        if ($publicPem === false) {
            throw new \RuntimeException('Brak publicznego klucza MCP.');
        }

        return substr(hash('sha256', $publicPem), 0, 16);
    }

    public static function encryptionKey(Config $config): Key
    {
        $raw = file_get_contents($config->mcp['encryptionKeyPath']);
        if ($raw === false) {
            throw new \RuntimeException('Brak klucza szyfrowania MCP.');
        }

        return Key::loadFromAsciiSafeString(trim($raw));
    }

    public static function jwks(Config $config): array
    {
        $publicPem = file_get_contents($config->mcp['publicKeyPath']);
        if ($publicPem === false) {
            throw new \RuntimeException('Brak publicznego klucza MCP.');
        }
        $resource = openssl_pkey_get_public($publicPem);
        if ($resource === false) {
            throw new \RuntimeException('Publiczny klucz MCP jest nieprawidłowy.');
        }
        $details = openssl_pkey_get_details($resource);
        if ($details === false || empty($details['rsa']['n']) || empty($details['rsa']['e'])) {
            throw new \RuntimeException('Klucz MCP nie jest kluczem RSA.');
        }

        return [
            'keys' => [[
                'kty' => 'RSA',
                'use' => 'sig',
                'alg' => 'RS256',
                'kid' => self::keyId($config),
                'n' => self::base64Url((string) $details['rsa']['n']),
                'e' => self::base64Url((string) $details['rsa']['e']),
            ]],
        ];
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function writeSecret(string $path, string $contents): void
    {
        self::writeFile($path, $contents, 0600);
    }

    private static function writeFile(string $path, string $contents, int $mode): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new \RuntimeException('Nie można zapisać pliku klucza MCP.');
        }
        @chmod($path, $mode);
    }
}
