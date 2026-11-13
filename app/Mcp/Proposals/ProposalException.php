<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Proposals;

final class ProposalException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400
    ) {
        parent::__construct($message);
    }
}
