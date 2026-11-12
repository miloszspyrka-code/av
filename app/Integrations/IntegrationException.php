<?php
declare(strict_types=1);

namespace AlphaVault\Integrations;

/**
 * Błąd integracji z giełdą/brokerem. Kod trafia do API, wiadomość do UI.
 * Nigdy nie zawiera sekretów ani pełnych odpowiedzi dostawcy.
 */
final class IntegrationException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 400,
    ) {
        parent::__construct($message);
    }
}
