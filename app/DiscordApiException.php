<?php
declare(strict_types=1);

namespace AlphaVault;

final class DiscordApiException extends \RuntimeException
{
    public function __construct(
        public readonly int $httpStatus,
        public readonly string $errorCode,
        string $message,
        public readonly int $retryAfterSeconds = 0,
    ) {
        parent::__construct($message);
    }
}
