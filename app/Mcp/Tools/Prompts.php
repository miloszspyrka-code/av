<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Tools;

/**
 * Gotowe scenariusze pracy. Prompty tylko instruują model; nie wykonują
 * odczytu ani zapisu i nie zawierają danych użytkownika.
 */
final class Prompts
{
    public function prepareTradingDay(): array
    {
        return [
            [
                'role' => 'assistant',
                'content' => 'Jesteś asystentem tradingowym AlphaVault. Pracujesz wyłącznie na danych zwróconych przez narzędzia MCP. Nie wymyślaj liczb ani wydarzeń. Brak danych oznacza puste pole.',
            ],
            [
                'role' => 'user',
                'content' => "Przygotuj briefing na dziś.\n\n"
                    . "1. Wywołaj get_dashboard, get_day_plan, get_macro_calendar (dzisiaj i jutro), get_events, get_checkin_history (ostatnie 3 dni) i get_prop_challenges.\n"
                    . "2. Podaj: wynik dnia, gotowość, aktywny plan, najbliższe wydarzenia makro o impact 3, limity ryzyka i stan challenge'y.\n"
                    . "3. Wskaż ryzyka: przekroczone limity, brak planu, brak check-inu, wydarzenia w trakcie sesji.\n"
                    . "4. Zakończ trzema konkretnymi zdaniami: co robić, czego nie robić, kiedy przerwać.\n"
                    . "Nie składaj żadnej propozycji zapisu, dopóki użytkownik o to nie poprosi.",
            ],
        ];
    }

    public function reviewTrade(string $tradeId): array
    {
        return [
            [
                'role' => 'assistant',
                'content' => 'Jesteś asystentem tradingowym AlphaVault. Oceniasz proces, nie wynik. Dane pochodzą wyłącznie z MCP. Nie zmieniaj pól finansowych transakcji.',
            ],
            [
                'role' => 'user',
                'content' => "Przeanalizuj transakcję o id " . $tradeId . ".\n\n"
                    . "1. Wywołaj get_trade_context z tradeId=" . $tradeId . " oraz get_stats i get_day_plan dla daty transakcji.\n"
                    . "2. Oceń: zgodność z planem, setup i jego zasady, checklistę, emocję, błędy, R i MFE/MAE.\n"
                    . "3. Wskaż jedną rzecz do powtórzenia i jedną do poprawy.\n"
                    . "4. Jeśli użytkownik poprosi o zapis recenzji, użyj propose_trade_review. Nigdy nie proponuj zmiany entry, SL, TP, size, wyniku ani danych giełdowych.",
            ],
        ];
    }

    public function weeklyReview(?string $weekStart = null): array
    {
        $range = $weekStart !== null && $weekStart !== ''
            ? 'Tydzień rozpoczynający się ' . $weekStart . '.'
            : 'Ostatnie 7 dni.';

        return [
            [
                'role' => 'assistant',
                'content' => 'Jesteś asystentem tradingowym AlphaVault. Podsumowanie ma być oparte na danych MCP, bez zgadywania i bez tekstów zastępczych.',
            ],
            [
                'role' => 'user',
                'content' => "Przygotuj weekly review. " . $range . "\n\n"
                    . "1. Wywołaj get_stats (7d), list_trades (closed), list_setups, get_checkin_history i get_prop_challenges.\n"
                    . "2. Podaj: netto, win rate, profit factor, expectancy R, max drawdown, liczbę transakcji.\n"
                    . "3. Wskaż najlepszy i najgorszy setup, zależność wyników od oceny A/B/C i checklisty, emocje i powtarzalne błędy.\n"
                    . "4. Osobno opisz stan challenge'y: wykorzystanie limitu dziennego i obsunięcia.\n"
                    . "5. Zakończ trzema działaniami na kolejny tydzień. Nie wykonuj zapisów.",
            ],
        ];
    }
}
