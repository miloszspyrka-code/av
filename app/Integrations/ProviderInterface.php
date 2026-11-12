<?php
declare(strict_types=1);

namespace AlphaVault\Integrations;

interface ProviderInterface
{
    public function key(): string;

    public function label(): string;

    /**
     * Waliduje poświadczenia i potwierdza tryb tylko-do-odczytu.
     *
     * @param array<string,mixed> $input
     * @param int $userId
     * @return array<string,mixed> poświadczenia do zaszyfrowania + metadane
     */
    public function connect(array $input, int $userId): array;

    /**
     * @param array<string,mixed> $credentials
     * @return array<int,array<string,mixed>> znormalizowane transakcje
     */
    public function fetchTrades(array $credentials, int $fromMs, int $toMs): array;

    /**
     * Usuwa konto u dostawcy, jeśli integracja tego wymaga.
     *
     * @param array<string,mixed> $credentials
     */
    public function disconnect(array $credentials): void;
}
