<?php
declare(strict_types=1);

namespace AlphaVault\Integrations;

/**
 * Szyfrowanie poświadczeń integracji po stronie serwera.
 *
 * AES-256-GCM (OpenSSL) z losowym IV i tagiem. Klucz pochodzi z INTEGRATIONS_KEY
 * i nigdy nie opuszcza serwera. Envelope jest zapisywany w kolumnie credentials.
 */
final class Crypto
{
    private string $key;

    public function __construct(string $keyMaterial)
    {
        if (strlen($keyMaterial) < 32) {
            throw new IntegrationException(
                'integrations_not_configured',
                'Integracje nie są skonfigurowane (INTEGRATIONS_KEY).',
                503
            );
        }
        $this->key = hash('sha256', $keyMaterial, true);
    }

    public static function configured(string $keyMaterial): bool
    {
        return strlen($keyMaterial) >= 32;
    }

    /** @param array<string,mixed> $data */
    public function seal(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new IntegrationException('encrypt_failed', 'Nie można zaszyfrować poświadczeń.', 500);
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($json, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($cipher === false) {
            throw new IntegrationException('encrypt_failed', 'Nie można zaszyfrować poświadczeń.', 500);
        }
        return base64_encode($iv . $tag . $cipher);
    }

    /** @return array<string,mixed> */
    public function open(string $envelope): array
    {
        $raw = base64_decode($envelope, true);
        if ($raw === false || strlen($raw) < 29) {
            throw new IntegrationException('decrypt_failed', 'Uszkodzone poświadczenia integracji.', 500);
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $json = openssl_decrypt($cipher, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($json === false) {
            throw new IntegrationException('decrypt_failed', 'Nie można odszyfrować poświadczeń integracji.', 500);
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new IntegrationException('decrypt_failed', 'Uszkodzone poświadczenia integracji.', 500);
        }
        return $data;
    }
}
