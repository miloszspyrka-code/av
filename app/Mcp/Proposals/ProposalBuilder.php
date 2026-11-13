<?php
declare(strict_types=1);

namespace AlphaVault\Mcp\Proposals;

use AlphaVault\Db;
use AlphaVault\Mcp\Support\Dates;
use AlphaVault\Mcp\Support\Numbers;
use AlphaVault\Mcp\Support\PropChallengeService;

/**
 * Buduje i waliduje propozycje zmian. Nie zapisuje stanu aplikacji.
 */
final class ProposalBuilder
{
    private const MOODS = ['Spokój', 'Pewność', 'Skupienie', 'Nuda', 'Zmęczenie', 'Strach', 'FOMO', 'Chciwość', 'Złość'];
    private const EMOTIONS = ['Spokój', 'Skupienie', 'Pewność', 'Nuda', 'Zmęczenie', 'Strach', 'FOMO', 'Złość', 'Chciwość'];

    public function __construct(
        private readonly Db $db,
        private readonly ProposalService $proposals
    ) {
    }

    public function createTrade(int $userId, array $args, string $clientName): array
    {
        $key = $this->idempotencyKey($args);
        $this->rejectFields($args, [
            'pair', 'dir', 'entry', 'sl', 'tp', 'size', 'setup', 'emotion', 'grade',
            'checklist', 'checkTotal', 'errors', 'note', 'date', 'account', 'idempotencyKey',
        ]);
        $state = $this->state($userId);

        $pair = strtoupper(trim((string) ($args['pair'] ?? '')));
        if (preg_match('/^[A-Z0-9._\/-]{2,20}$/', $pair) !== 1) {
            throw new ProposalException('bad_request', 'Para musi mieć 2–20 znaków: A–Z, 0–9, . _ / -.', 400);
        }
        $dir = (string) ($args['dir'] ?? '');
        if (!in_array($dir, ['long', 'short', 'l', 's'], true)) {
            throw new ProposalException('bad_request', 'Kierunek musi być long albo short.', 400);
        }
        $direction = in_array($dir, ['short', 's'], true) ? 's' : 'l';

        $entry = $this->positiveNumber($args['entry'] ?? null, 'entry');
        $size = $this->positiveNumber($args['size'] ?? null, 'size');
        $sl = null;
        $tp = null;
        if (array_key_exists('sl', $args) && $args['sl'] !== null && $args['sl'] !== '') {
            $sl = $this->positiveNumber($args['sl'], 'sl');
            if (($direction === 'l' && $sl >= $entry) || ($direction === 's' && $sl <= $entry)) {
                throw new ProposalException('bad_request', 'Stop loss jest po złej stronie ceny wejścia.', 400);
            }
        }
        if (array_key_exists('tp', $args) && $args['tp'] !== null && $args['tp'] !== '') {
            $tp = $this->positiveNumber($args['tp'], 'tp');
            if (($direction === 'l' && $tp <= $entry) || ($direction === 's' && $tp >= $entry)) {
                throw new ProposalException('bad_request', 'Take profit jest po złej stronie ceny wejścia.', 400);
            }
        }

        $setups = $this->setupNames($state);
        $setup = trim((string) ($args['setup'] ?? 'Bez setupu'));
        if ($setup === '') {
            $setup = 'Bez setupu';
        }
        if ($setup !== 'Bez setupu' && !in_array($setup, $setups, true)) {
            throw new ProposalException('bad_request', 'Setup nie istnieje w Playbooku. Użyj list_setups.', 400);
        }

        $emotion = trim((string) ($args['emotion'] ?? 'Spokój'));
        if (!in_array($emotion, self::EMOTIONS, true)) {
            throw new ProposalException('bad_request', 'Nieprawidłowa emocja.', 400);
        }

        $checkTotal = array_key_exists('checkTotal', $args) ? (int) $args['checkTotal'] : $this->defaultCheckTotal($state);
        if ($checkTotal < 1 || $checkTotal > 20) {
            throw new ProposalException('bad_request', 'checkTotal musi być w zakresie 1–20.', 400);
        }
        $checklist = array_key_exists('checklist', $args) ? (int) $args['checklist'] : 0;
        if ($checklist < 0 || $checklist > $checkTotal) {
            throw new ProposalException('bad_request', 'checklist musi być w zakresie 0–checkTotal.', 400);
        }
        $grade = strtoupper(trim((string) ($args['grade'] ?? '')));
        if ($grade !== '' && !in_array($grade, ['A', 'B', 'C'], true)) {
            throw new ProposalException('bad_request', 'grade musi być A, B albo C.', 400);
        }
        if ($grade === '') {
            $grade = $checklist >= $checkTotal ? 'A' : ($checklist >= (int) ceil($checkTotal * 0.7) ? 'B' : 'C');
        }

        $errors = $this->errors($args['errors'] ?? []);
        $note = mb_substr(trim((string) ($args['note'] ?? '')), 0, 2000);
        $date = $this->tradeDate((string) ($args['date'] ?? ''));
        $account = $this->account($state, $args['account'] ?? null);
        $plan = $this->planForToday($state, $setup);

        $decimals = $this->decimals($entry);
        $trade = [
            'id' => 'manual_' . $this->shortId(),
            'date' => $date,
            'exit' => 'otwarta',
            'result' => null,
            'r' => null,
            'mae' => 0,
            'mfe' => 0,
            'durationMin' => 0,
            'fee' => 0,
            'source' => 'Ręcznie',
            'account' => $account,
            'session' => $this->session(),
            'screens' => new \stdClass(),
            'pair' => $pair,
            'dir' => $direction,
            'entry' => $this->formatNumber($entry, $decimals),
            'sl' => $sl !== null ? $this->formatNumber($sl, $decimals) : 'brak',
            'tp' => $tp !== null ? $this->formatNumber($tp, $decimals) : '—',
            'size' => $this->formatNumber($size, $size < 1 ? 4 : 2),
            'grade' => $grade,
            'checklist' => $checklist,
            'checkTotal' => $checkTotal,
            'errors' => $errors,
            'emotion' => $emotion,
            'setup' => $setup,
            'note' => $note,
            'plan' => $plan,
            'mentor' => $this->mentorForGrade($grade, count($errors)),
        ];

        return $this->proposals->create($userId, $clientName, 'manual_trade', 'trade:new', null, $trade, $key, $this->fingerprint($args));
    }

    public function closeTrade(int $userId, array $args, string $clientName): array
    {
        $key = $this->idempotencyKey($args);
        $this->rejectFields($args, ['tradeId', 'exitPrice', 'idempotencyKey']);
        $tradeId = trim((string) ($args['tradeId'] ?? ''));
        if ($tradeId === '') {
            throw new ProposalException('bad_request', 'Pole tradeId jest wymagane.', 400);
        }
        $exit = $this->positiveNumber($args['exitPrice'] ?? null, 'exitPrice');
        $state = $this->state($userId);
        $trade = $this->findTrade($state, $tradeId);
        if ($trade === null) {
            throw new ProposalException('not_found', 'Transakcja nie istnieje.', 404);
        }
        if ($this->proposals->isExchangeTrade($userId, $trade)) {
            throw new ProposalException('exchange_data_readonly', 'Transakcji importowanej z giełdy nie można zamknąć przez MCP.', 403);
        }
        if (($trade['result'] ?? null) !== null) {
            throw new ProposalException('already_closed', 'Transakcja jest już zamknięta.', 409);
        }

        $entry = (float) ($trade['entry'] ?? 0);
        $size = (float) ($trade['size'] ?? 0);
        $direction = (string) ($trade['dir'] ?? 'l') === 's' ? -1 : 1;
        $result = round(($exit - $entry) * $size * $direction, 2);
        $slRaw = (string) ($trade['sl'] ?? 'brak');
        $risk = ($slRaw !== '' && $slRaw !== 'brak')
            ? abs($entry - (float) $slRaw) * $size
            : abs($entry * 0.01 * $size);
        $r = $risk > 0 ? round($result / $risk, 2) : 0;
        $openedAt = strtotime((string) ($trade['date'] ?? '')) ?: time();
        $after = [
            'id' => $tradeId,
            'exit' => $this->formatNumber($exit, $this->decimals($entry)),
            'result' => $result,
            'r' => $r,
            'mfe' => max((float) ($trade['mfe'] ?? 0), abs($r)),
            'durationMin' => max(1, (int) round((time() - $openedAt) / 60)),
            'mentor' => $result >= 0
                ? 'Pozycja zamknięta z zyskiem. Sprawdź, czy wyszedłeś zgodnie z planem, a nie z emocji.'
                : 'Pozycja zamknięta na minusie. Sprawdź, czy SL był ustawiony przed wejściem.',
        ];
        $before = [
            'exit' => $trade['exit'] ?? null,
            'result' => null,
            'r' => null,
            'mfe' => $trade['mfe'] ?? null,
            'durationMin' => $trade['durationMin'] ?? null,
        ];

        return $this->proposals->create($userId, $clientName, 'close_trade', 'trade:' . $tradeId, $before, $after, $key, $this->fingerprint($args));
    }

    public function tradeReview(int $userId, array $args, string $clientName): array
    {
        $key = $this->idempotencyKey($args);
        $this->rejectFields($args, ['tradeId', 'note', 'grade', 'setup', 'emotion', 'checklist', 'errors', 'idempotencyKey']);
        $tradeId = trim((string) ($args['tradeId'] ?? ''));
        if ($tradeId === '') {
            throw new ProposalException('bad_request', 'Pole tradeId jest wymagane.', 400);
        }
        $state = $this->state($userId);
        $trade = $this->findTrade($state, $tradeId);
        if ($trade === null) {
            throw new ProposalException('not_found', 'Transakcja nie istnieje.', 404);
        }

        $before = is_array($state['tradeReviews'][$tradeId] ?? null) ? $state['tradeReviews'][$tradeId] : null;
        $after = $before ?? ['tradeId' => $tradeId, 'exchangeOwned' => $this->proposals->isExchangeTrade($userId, $trade)];
        if (array_key_exists('note', $args)) {
            $after['note'] = mb_substr(trim((string) $args['note']), 0, 2000);
        }
        if (array_key_exists('grade', $args)) {
            $grade = strtoupper(trim((string) $args['grade']));
            if (!in_array($grade, ['A', 'B', 'C'], true)) {
                throw new ProposalException('bad_request', 'grade musi być A, B albo C.', 400);
            }
            $after['grade'] = $grade;
        }
        if (array_key_exists('setup', $args)) {
            $setup = trim((string) $args['setup']);
            if ($setup !== 'Bez setupu' && !in_array($setup, $this->setupNames($state), true)) {
                throw new ProposalException('bad_request', 'Setup nie istnieje w Playbooku.', 400);
            }
            $after['setup'] = $setup;
        }
        if (array_key_exists('emotion', $args)) {
            $emotion = trim((string) $args['emotion']);
            if (!in_array($emotion, self::EMOTIONS, true)) {
                throw new ProposalException('bad_request', 'Nieprawidłowa emocja.', 400);
            }
            $after['emotion'] = $emotion;
        }
        if (array_key_exists('checklist', $args)) {
            $total = (int) ($trade['checkTotal'] ?? 20);
            $value = (int) $args['checklist'];
            if ($value < 0 || $value > max(1, $total)) {
                throw new ProposalException('bad_request', 'checklist poza zakresem transakcji.', 400);
            }
            $after['checklist'] = $value;
        }
        if (array_key_exists('errors', $args)) {
            $after['errors'] = $this->errors($args['errors']);
        }
        if (count($after) <= 2) {
            throw new ProposalException('bad_request', 'Brak pól recenzji do zapisania.', 400);
        }
        $after['tradeId'] = $tradeId;
        $after['exchangeOwned'] = $this->proposals->isExchangeTrade($userId, $trade);

        return $this->proposals->create($userId, $clientName, 'trade_review', 'trade:' . $tradeId, $before, $after, $key, $this->fingerprint($args));
    }

    public function saveDayPlan(int $userId, array $args, string $clientName): array
    {
        $key = $this->idempotencyKey($args);
        $this->rejectFields($args, [
            'date', 'market', 'bias', 'why', 'levels', 'ifMain', 'thenMain', 'ifAlt', 'thenAlt',
            'riskPercent', 'maxTrades', 'until', 'notDo', 'idempotencyKey',
        ]);
        $date = trim((string) ($args['date'] ?? Dates::todayKey()));
        if (!Dates::isValidKey($date)) {
            throw new ProposalException('bad_request', 'Data musi być w formacie YYYY-MM-DD.', 400);
        }
        $today = Dates::todayKey();
        $yesterday = (new \DateTimeImmutable('yesterday', Dates::tz()))->format('Y-m-d');
        if (!in_array($date, [$today, $yesterday], true)) {
            throw new ProposalException('bad_request', 'Plan można zaproponować tylko na dziś lub wczoraj.', 400);
        }

        $state = $this->state($userId);
        $before = is_array($state['plans'][$date] ?? null) ? $state['plans'][$date] : null;
        $after = $before ?? ['date' => $date, 'approvedAt' => null];
        $after['date'] = $date;

        if (array_key_exists('market', $args)) {
            $market = trim((string) $args['market']);
            if ($market === '' || mb_strlen($market) > 20) {
                throw new ProposalException('bad_request', 'market musi mieć 1–20 znaków.', 400);
            }
            $after['market'] = $market;
        }
        if (array_key_exists('bias', $args)) {
            $bias = (string) $args['bias'];
            if (!in_array($bias, ['long', 'short', 'neutral', 'none'], true)) {
                throw new ProposalException('bad_request', 'bias musi być long, short, neutral albo none.', 400);
            }
            $after['bias'] = $bias;
        }
        foreach (['why', 'ifMain', 'thenMain', 'ifAlt', 'thenAlt', 'notDo'] as $field) {
            if (array_key_exists($field, $args)) {
                $after[$field] = mb_substr(trim((string) $args[$field]), 0, 2000);
            }
        }
        if (array_key_exists('levels', $args)) {
            $after['levels'] = $this->levels($args['levels']);
        }
        if (array_key_exists('riskPercent', $args)) {
            $risk = (float) $args['riskPercent'];
            if (!is_finite($risk) || $risk < 0.1 || $risk > 100) {
                throw new ProposalException('bad_request', 'riskPercent musi być w zakresie 0.1–100.', 400);
            }
            $after['risk'] = rtrim(rtrim(number_format($risk, 2, '.', ''), '0'), '.') . '%';
        }
        if (array_key_exists('maxTrades', $args)) {
            $maxTrades = (int) $args['maxTrades'];
            if ($maxTrades < 1 || $maxTrades > 50) {
                throw new ProposalException('bad_request', 'maxTrades musi być w zakresie 1–50.', 400);
            }
            $after['maxTrades'] = (string) $maxTrades;
        }
        if (array_key_exists('until', $args)) {
            $until = trim((string) $args['until']);
            if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $until) !== 1) {
                throw new ProposalException('bad_request', 'until musi mieć format HH:MM.', 400);
            }
            $after['until'] = $until;
        }
        // approvedAt nigdy nie jest ustawiane przez agenta.
        $after['approvedAt'] = $before['approvedAt'] ?? null;

        return $this->proposals->create($userId, $clientName, 'day_plan', 'plan:' . $date, $before, $after, $key, $this->fingerprint($args));
    }

    public function saveCheckin(int $userId, array $args, string $clientName): array
    {
        $key = $this->idempotencyKey($args);
        $this->rejectFields($args, ['mood', 'energy', 'note', 'idempotencyKey']);
        $mood = trim((string) ($args['mood'] ?? ''));
        if (!in_array($mood, self::MOODS, true)) {
            throw new ProposalException('bad_request', 'Nieprawidłowy nastrój.', 400);
        }
        $energy = (int) ($args['energy'] ?? 0);
        if ($energy < 1 || $energy > 5) {
            throw new ProposalException('bad_request', 'Energia musi być w zakresie 1–5.', 400);
        }
        $date = Dates::todayKey();
        $state = $this->state($userId);
        $before = null;
        foreach ((array) ($state['checkins'] ?? []) as $checkin) {
            if (is_array($checkin) && (string) ($checkin['date'] ?? '') === $date) {
                $before = $checkin;
                break;
            }
        }
        $after = [
            'date' => $date,
            'mood' => $mood,
            'energy' => $energy,
            'note' => mb_substr(trim((string) ($args['note'] ?? '')), 0, 500),
            'score' => $this->readinessScore($mood, $energy),
        ];

        return $this->proposals->create($userId, $clientName, 'checkin', 'checkin:' . $date, $before, $after, $key, $this->fingerprint($args));
    }

    public function saveSetup(int $userId, array $args, string $clientName): array
    {
        $key = $this->idempotencyKey($args);
        $this->rejectFields($args, ['id', 'name', 'tf', 'grade', 'rules', 'bad', 'seed', 'idempotencyKey']);
        $name = trim((string) ($args['name'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
            throw new ProposalException('bad_request', 'Nazwa setupu musi mieć 2–80 znaków.', 400);
        }
        $rules = $this->rules($args['rules'] ?? []);
        $tf = trim((string) ($args['tf'] ?? '—'));
        if ($tf === '') {
            $tf = '—';
        }
        if (mb_strlen($tf) > 40) {
            throw new ProposalException('bad_request', 'Interwał może mieć maks. 40 znaków.', 400);
        }
        $grade = strtoupper(trim((string) ($args['grade'] ?? 'B')));
        if (!in_array($grade, ['A', 'B', 'C'], true)) {
            throw new ProposalException('bad_request', 'grade musi być A, B albo C.', 400);
        }
        $state = $this->state($userId);
        $id = trim((string) ($args['id'] ?? ''));
        $before = null;
        if ($id !== '') {
            foreach ((array) ($state['setups'] ?? []) as $setup) {
                if (is_array($setup) && (string) ($setup['id'] ?? '') === $id) {
                    $before = $setup;
                    break;
                }
            }
            if ($before === null) {
                throw new ProposalException('not_found', 'Setup nie istnieje.', 404);
            }
        } else {
            $id = 'pb_' . $this->shortId();
        }
        $seed = array_key_exists('seed', $args) ? (int) $args['seed'] : random_int(1, 200);
        if ($seed < 1 || $seed > 9999) {
            throw new ProposalException('bad_request', 'seed musi być w zakresie 1–9999.', 400);
        }
        $after = [
            'id' => $id,
            'name' => $name,
            'tf' => $tf,
            'grade' => $grade,
            'rules' => $rules,
            'bad' => (bool) ($args['bad'] ?? false),
            'seed' => $seed,
        ];

        return $this->proposals->create($userId, $clientName, 'setup', $before === null ? 'setup:new' : 'setup:' . $id, $before, $after, $key, $this->fingerprint($args));
    }

    public function savePropChallenge(int $userId, array $args, string $clientName): array
    {
        $key = $this->idempotencyKey($args);
        $this->rejectFields($args, [
            'id', 'name', 'firm', 'templateId', 'phase', 'account', 'currency', 'accountSize',
            'startBalance', 'targetPct', 'dailyLossPct', 'maxLossPct', 'drawdownType', 'dailyBasis',
            'minDays', 'startDate', 'endDate', 'notes', 'idempotencyKey',
        ]);
        $state = $this->state($userId);
        $id = trim((string) ($args['id'] ?? ''));
        $before = null;
        if ($id !== '') {
            foreach ((array) ($state['prop']['challenges'] ?? []) as $challenge) {
                if (is_array($challenge) && (string) ($challenge['id'] ?? '') === $id) {
                    $before = $challenge;
                    break;
                }
            }
            if ($before === null) {
                throw new ProposalException('not_found', 'Challenge nie istnieje.', 404);
            }
        }

        $templateId = trim((string) ($args['templateId'] ?? 'custom'));
        if (PropChallengeService::templateById($templateId) === null) {
            throw new ProposalException('bad_request', 'Nieznany templateId.', 400);
        }
        $name = trim((string) ($args['name'] ?? ($before['name'] ?? '')));
        if ($name === '' || mb_strlen($name) > 100) {
            throw new ProposalException('bad_request', 'Nazwa challenge\'u musi mieć 1–100 znaków.', 400);
        }
        $account = trim((string) ($args['account'] ?? ($before['account'] ?? '')));
        if ($account !== '' && !in_array($account, $this->accountNames($state), true)) {
            throw new ProposalException('bad_request', 'Konto nie istnieje w ustawieniach AlphaVault.', 400);
        }
        $currency = strtoupper(trim((string) ($args['currency'] ?? ($before['currency'] ?? 'USD'))));
        if (!in_array($currency, PropChallengeService::CURRENCIES, true)) {
            throw new ProposalException('bad_request', 'Nieprawidłowa waluta.', 400);
        }
        $accountSize = (float) ($args['accountSize'] ?? ($before['accountSize'] ?? 0));
        $startBalance = (float) ($args['startBalance'] ?? ($before['startBalance'] ?? $accountSize));
        if (!is_finite($accountSize) || $accountSize <= 0 || !is_finite($startBalance) || $startBalance <= 0) {
            throw new ProposalException('bad_request', 'accountSize i startBalance muszą być większe od 0.', 400);
        }
        $targetPct = (float) ($args['targetPct'] ?? ($before['targetPct'] ?? 10));
        $dailyLossPct = (float) ($args['dailyLossPct'] ?? ($before['dailyLossPct'] ?? 5));
        $maxLossPct = (float) ($args['maxLossPct'] ?? ($before['maxLossPct'] ?? 10));
        if ($targetPct < 0 || $targetPct > 1000 || $dailyLossPct < 0 || $dailyLossPct > 100 || $maxLossPct < 0 || $maxLossPct > 100) {
            throw new ProposalException('bad_request', 'Limit procentowy poza zakresem.', 400);
        }
        $drawdownType = (string) ($args['drawdownType'] ?? ($before['drawdownType'] ?? 'static'));
        $dailyBasis = (string) ($args['dailyBasis'] ?? ($before['dailyBasis'] ?? 'initial'));
        if (!in_array($drawdownType, PropChallengeService::DRAWDOWN_TYPES, true) || !in_array($dailyBasis, PropChallengeService::DAILY_BASES, true)) {
            throw new ProposalException('bad_request', 'drawdownType lub dailyBasis jest nieprawidłowy.', 400);
        }
        $minDays = (int) ($args['minDays'] ?? ($before['minDays'] ?? 0));
        if ($minDays < 0 || $minDays > 60) {
            throw new ProposalException('bad_request', 'minDays musi być w zakresie 0–60.', 400);
        }
        $startDate = trim((string) ($args['startDate'] ?? ($before['startDate'] ?? Dates::todayKey())));
        $endDate = trim((string) ($args['endDate'] ?? ($before['endDate'] ?? '')));
        if (!Dates::isValidKey($startDate)) {
            throw new ProposalException('bad_request', 'startDate musi mieć format YYYY-MM-DD.', 400);
        }
        if ($endDate !== '' && !Dates::isValidKey($endDate)) {
            throw new ProposalException('bad_request', 'endDate musi mieć format YYYY-MM-DD.', 400);
        }
        if ($endDate !== '' && strcmp($endDate, $startDate) < 0) {
            throw new ProposalException('bad_request', 'endDate nie może być przed startDate.', 400);
        }
        $notes = mb_substr(trim((string) ($args['notes'] ?? ($before['notes'] ?? ''))), 0, 2000);

        $payload = array_merge($before ?? [], [
            'id' => $id !== '' ? $id : 'prop_' . $this->shortId(),
            'name' => $name,
            'firm' => trim((string) ($args['firm'] ?? ($before['firm'] ?? ''))),
            'templateId' => $templateId,
            'phase' => trim((string) ($args['phase'] ?? ($before['phase'] ?? 'Phase 1'))),
            'account' => $account,
            'currency' => $currency,
            'accountSize' => $accountSize,
            'startBalance' => $startBalance,
            'targetPct' => $targetPct,
            'dailyLossPct' => $dailyLossPct,
            'maxLossPct' => $maxLossPct,
            'drawdownType' => $drawdownType,
            'dailyBasis' => $dailyBasis,
            'minDays' => $minDays,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'notes' => $notes,
            'archived' => (bool) ($before['archived'] ?? false),
            'createdAt' => (string) ($before['createdAt'] ?? gmdate('c')),
        ]);
        $after = PropChallengeService::normalize($payload);
        if ($after['endDate'] === '' && $endDate !== '') {
            $after['endDate'] = $endDate;
        }

        return $this->proposals->create($userId, $clientName, 'prop_challenge', $before === null ? 'prop:new' : 'prop:' . $after['id'], $before, $after, $key, $this->fingerprint($args));
    }

    public function saveAccount(int $userId, array $args, string $clientName): array
    {
        $key = $this->idempotencyKey($args);
        $this->rejectFields($args, ['id', 'name', 'exchange', 'type', 'currency', 'include', 'primary', 'lev', 'goal', 'date', 'idempotencyKey']);
        $state = $this->state($userId);
        $id = trim((string) ($args['id'] ?? ''));
        $before = null;
        if ($id !== '') {
            foreach ((array) ($state['accounts'] ?? []) as $account) {
                if (is_array($account) && (string) ($account['id'] ?? '') === $id) {
                    $before = $account;
                    break;
                }
            }
            if ($before === null) {
                throw new ProposalException('not_found', 'Konto nie istnieje.', 404);
            }
        }

        $name = trim((string) ($args['name'] ?? ($before['name'] ?? '')));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
            throw new ProposalException('bad_request', 'Nazwa konta musi mieć 2–80 znaków.', 400);
        }
        foreach ($this->accountNames($state) as $existingName) {
            if (strcasecmp($existingName, $name) === 0 && $existingName !== (string) ($before['name'] ?? '')) {
                throw new ProposalException('bad_request', 'Konto o tej nazwie już istnieje.', 400);
            }
        }
        $exchange = trim((string) ($args['exchange'] ?? ($before['exchange'] ?? '')));
        if (mb_strlen($exchange) > 80) {
            throw new ProposalException('bad_request', 'Broker lub giełda może mieć maks. 80 znaków.', 400);
        }
        $type = trim((string) ($args['type'] ?? ($before['type'] ?? 'Futures')));
        if (mb_strlen($type) > 40) {
            throw new ProposalException('bad_request', 'Typ konta może mieć maks. 40 znaków.', 400);
        }
        $currency = strtoupper(trim((string) ($args['currency'] ?? ($before['currency'] ?? 'USDT'))));
        if (!in_array($currency, PropChallengeService::CURRENCIES, true)) {
            throw new ProposalException('bad_request', 'Nieprawidłowa waluta konta.', 400);
        }
        $lev = trim((string) ($args['lev'] ?? ($before['lev'] ?? '')));
        if (mb_strlen($lev) > 20) {
            throw new ProposalException('bad_request', 'Dźwignia może mieć maks. 20 znaków.', 400);
        }
        $goal = trim((string) ($args['goal'] ?? ($before['goal'] ?? '')));
        if (mb_strlen($goal) > 200) {
            throw new ProposalException('bad_request', 'Cel konta może mieć maks. 200 znaków.', 400);
        }
        $date = trim((string) ($args['date'] ?? ($before['date'] ?? '')));
        if ($date === '') {
            $date = gmdate('Y-m-d');
        }
        if (mb_strlen($date) > 20) {
            throw new ProposalException('bad_request', 'Data konta może mieć maks. 20 znaków.', 400);
        }
        $include = array_key_exists('include', $args) ? (bool) $args['include'] : (bool) ($before['include'] ?? true);
        $primary = array_key_exists('primary', $args) ? (bool) $args['primary'] : (bool) ($before['primary'] ?? false);

        $after = [
            'id' => $before !== null ? $id : 'acc_' . $this->shortId(),
            'name' => $name,
            'exchange' => $exchange,
            'type' => $type,
            'currency' => $currency,
            // start i balance są przenoszone bez zmian; MCP ich nie ustawia.
            'start' => (float) ($before['start'] ?? 0),
            'date' => $date,
            'lev' => $lev,
            'goal' => $goal,
            'balance' => (float) ($before['balance'] ?? 0),
            'include' => $include,
            'primary' => $primary,
        ];

        return $this->proposals->create(
            $userId,
            $clientName,
            'account',
            $before === null ? 'account:new' : 'account:' . $after['id'],
            $before,
            $after,
            $key,
            $this->fingerprint($args)
        );
    }

    public function saveCapitalFlow(int $userId, array $args, string $clientName): array
    {
        $key = $this->idempotencyKey($args);
        $this->rejectFields($args, ['id', 'date', 'account', 'type', 'amount', 'currency', 'fiat', 'fx', 'note', 'idempotencyKey']);
        $state = $this->state($userId);
        $id = trim((string) ($args['id'] ?? ''));
        $before = null;
        if ($id !== '') {
            foreach ((array) ($state['flows'] ?? []) as $flow) {
                if (is_array($flow) && (string) ($flow['id'] ?? '') === $id) {
                    $before = $flow;
                    break;
                }
            }
            if ($before === null) {
                throw new ProposalException('not_found', 'Wpis kapitałowy nie istnieje.', 404);
            }
        }

        $typeRaw = strtolower(trim((string) ($args['type'] ?? ($before['type'] ?? 'deposit'))));
        $type = match ($typeRaw) {
            'deposit', 'wpłata', 'wplata' => 'Wpłata',
            'withdrawal', 'withdraw', 'wypłata', 'wyplata' => 'Wypłata',
            default => '',
        };
        if ($type === '') {
            throw new ProposalException('bad_request', 'Typ wpisu musi być deposit albo withdrawal.', 400);
        }
        $date = trim((string) ($args['date'] ?? ($before['date'] ?? '')));
        if ($date === '') {
            $date = gmdate('Y-m-d');
        }
        if (!Dates::isValidKey($date)) {
            throw new ProposalException('bad_request', 'date musi mieć format YYYY-MM-DD.', 400);
        }
        $ts = strtotime($date . ' 12:00:00 UTC');
        if ($ts === false || $ts > time() + 86400 || $ts < time() - 5 * 365 * 86400) {
            throw new ProposalException('bad_request', 'Data wpisu musi mieścić się w ostatnich 5 latach i nie może być z przyszłości.', 400);
        }
        $account = trim((string) ($args['account'] ?? ($before['account'] ?? '')));
        if ($account === '' || !in_array($account, $this->accountNames($state), true)) {
            throw new ProposalException('bad_request', 'Konto nie istnieje w ustawieniach AlphaVault.', 400);
        }
        $amount = $this->positiveNumber($args['amount'] ?? ($before['amount'] ?? null), 'amount');
        $currency = strtoupper(trim((string) ($args['currency'] ?? ($before['currency'] ?? 'PLN'))));
        if (!in_array($currency, PropChallengeService::CURRENCIES, true)) {
            throw new ProposalException('bad_request', 'Nieprawidłowa waluta wpisu.', 400);
        }
        $fiat = array_key_exists('fiat', $args) ? (float) $args['fiat'] : (float) ($before['fiat'] ?? 0);
        if (!is_finite($fiat) || $fiat < 0) {
            throw new ProposalException('bad_request', 'fiat musi być liczbą większą lub równą 0.', 400);
        }
        $fx = array_key_exists('fx', $args) ? (float) $args['fx'] : (float) ($before['fx'] ?? 1);
        if (!is_finite($fx) || $fx <= 0 || $fx > 1000) {
            throw new ProposalException('bad_request', 'fx musi być w zakresie 0–1000.', 400);
        }
        $op = mb_substr(trim((string) ($args['note'] ?? ($before['op'] ?? ''))), 0, 200);

        $after = array_merge($before ?? [], [
            'id' => $before !== null ? $id : 'flow_' . $this->shortId(),
            'date' => $date,
            'account' => $account,
            'type' => $type,
            'amount' => $amount,
            'currency' => $currency,
            'fiat' => $fiat,
            'fx' => $fx,
            'op' => $op,
            'tax' => $type === 'Wpłata' ? 'cost' : 'income',
        ]);
        if ($op === '' && $before === null) {
            unset($after['op']);
        }

        return $this->proposals->create(
            $userId,
            $clientName,
            'capital_flow',
            $before === null ? 'flow:new' : 'flow:' . $after['id'],
            $before,
            $after,
            $key,
            $this->fingerprint($args)
        );
    }

    private function state(int $userId): array
    {
        $row = $this->db->queryOne('SELECT state FROM user_states WHERE user_id = ?', [$userId]);
        if ($row === null) {
            return [];
        }
        $state = json_decode((string) $row['state'], true);

        return is_array($state) ? $state : [];
    }

    private function idempotencyKey(array $args): string
    {
        $key = trim((string) ($args['idempotencyKey'] ?? ''));
        if (mb_strlen($key) < 8 || mb_strlen($key) > 120) {
            throw new ProposalException('bad_request', 'idempotencyKey musi mieć 8–120 znaków.', 400);
        }

        return $key;
    }

    /**
     * Odcisk surowego wniosku agenta. Nie zawiera pól generowanych przez
     * serwer (id, createdAt, seed, czas), więc ponowienie tego samego
     * idempotencyKey daje tę samą propozycję.
     */
    private function fingerprint(array $args): string
    {
        unset($args['idempotencyKey']);
        $normalized = $this->sortKeys($args);
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new ProposalException('bad_request', 'Nie można zakodować wniosku.', 400);
        }

        return $json;
    }

    private function sortKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortKeys($item), $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->sortKeys($item);
        }

        return $value;
    }

    private function rejectFields(array $args, array $allowed): void
    {
        $forbidden = ['entry', 'exit', 'exitPrice', 'price', 'fill', 'fillPrice', 'result', 'pnl', 'size', 'qty', 'quantity', 'sl', 'tp', 'leverage', 'fee', 'fees', 'balance', 'start', 'equity', 'source', 'exchangeId', 'externalId'];
        foreach ($args as $field => $_) {
            if (!in_array($field, $allowed, true)) {
                if (in_array($field, $forbidden, true)) {
                    throw new ProposalException('exchange_data_readonly', 'Pole ' . $field . ' należy do danych giełdowych i jest tylko do odczytu.', 403);
                }
                throw new ProposalException('bad_request', 'Nieznane pole: ' . $field . '.', 400);
            }
        }
    }

    private function positiveNumber(mixed $value, string $field): float
    {
        if (!is_numeric($value)) {
            throw new ProposalException('bad_request', 'Pole ' . $field . ' musi być liczbą.', 400);
        }
        $number = (float) $value;
        if (!is_finite($number) || $number <= 0) {
            throw new ProposalException('bad_request', 'Pole ' . $field . ' musi być większe od 0.', 400);
        }

        return $number;
    }

    private function errors(mixed $value): array
    {
        if (!is_array($value)) {
            throw new ProposalException('bad_request', 'errors musi być tablicą.', 400);
        }
        if (count($value) > 10) {
            throw new ProposalException('bad_request', 'Maksymalnie 10 błędów.', 400);
        }
        $out = [];
        foreach ($value as $error) {
            $text = trim((string) $error);
            if ($text === '' || mb_strlen($text) > 60) {
                throw new ProposalException('bad_request', 'Każdy błąd musi mieć 1–60 znaków.', 400);
            }
            $out[] = $text;
        }

        return $out;
    }

    private function rules(mixed $value): array
    {
        if (!is_array($value) || count($value) < 2 || count($value) > 5) {
            throw new ProposalException('bad_request', 'Setup musi mieć 2–5 zasad.', 400);
        }
        $out = [];
        foreach ($value as $rule) {
            $text = trim((string) $rule);
            if (mb_strlen($text) < 3 || mb_strlen($text) > 200) {
                throw new ProposalException('bad_request', 'Zasada musi mieć 3–200 znaków.', 400);
            }
            $out[] = $text;
        }

        return $out;
    }

    private function levels(mixed $value): array
    {
        if (!is_array($value) || count($value) > 10) {
            throw new ProposalException('bad_request', 'levels musi być tablicą maks. 10 poziomów.', 400);
        }
        $out = [];
        foreach ($value as $level) {
            if (!is_array($level)) {
                throw new ProposalException('bad_request', 'Poziom musi być obiektem.', 400);
            }
            $typeRaw = strtolower(trim((string) ($level['type'] ?? '')));
            $type = in_array($typeRaw, ['resistance', 'opór', 'opor'], true) ? 'Opór' : 'Wsparcie';
            $price = trim((string) ($level['price'] ?? ''));
            if ($price !== '' && (!is_numeric(str_replace(',', '.', $price)) || (float) str_replace(',', '.', $price) <= 0)) {
                throw new ProposalException('bad_request', 'Cena poziomu musi być liczbą większą od 0.', 400);
            }
            $note = mb_substr(trim((string) ($level['note'] ?? '')), 0, 200);
            if ($price === '' && $note === '') {
                continue;
            }
            $out[] = ['type' => $type, 'price' => str_replace(',', '.', $price), 'note' => $note];
        }

        return $out;
    }

    private function setupNames(array $state): array
    {
        $names = [];
        foreach ((array) ($state['setups'] ?? []) as $setup) {
            if (is_array($setup) && trim((string) ($setup['name'] ?? '')) !== '') {
                $names[] = (string) $setup['name'];
            }
        }

        return $names;
    }

    private function accountNames(array $state): array
    {
        $names = [];
        foreach ((array) ($state['accounts'] ?? []) as $account) {
            if (is_array($account) && trim((string) ($account['name'] ?? '')) !== '') {
                $names[] = (string) $account['name'];
            }
        }

        return $names;
    }

    private function account(array $state, mixed $requested): string
    {
        $requested = trim((string) ($requested ?? ''));
        if ($requested !== '') {
            if (!in_array($requested, $this->accountNames($state), true)) {
                throw new ProposalException('bad_request', 'Konto nie należy do użytkownika.', 400);
            }
            return $requested;
        }
        foreach ((array) ($state['accounts'] ?? []) as $account) {
            if (is_array($account) && ($account['include'] ?? true) !== false && trim((string) ($account['name'] ?? '')) !== '') {
                return (string) $account['name'];
            }
        }

        return 'Konto główne';
    }

    private function defaultCheckTotal(array $state): int
    {
        $points = (array) ($state['settings']['checklist'] ?? []);
        if ($points !== []) {
            return max(1, min(20, count($points)));
        }

        return 7;
    }

    private function planForToday(array $state, string $setup): bool
    {
        if ($setup === 'Bez setupu') {
            return false;
        }
        $plan = $state['plans'][Dates::todayKey()] ?? null;

        return is_array($plan) && !empty($plan['approvedAt']);
    }

    private function mentorForGrade(string $grade, int $errorCount): string
    {
        if ($grade === 'A') {
            return 'Wzorowo: plan, pełna checklista, SL, spokojna głowa. Tak wyglądają Twoje najlepsze transakcje.';
        }
        if ($grade === 'B') {
            return 'Setup z planu, drobne odstępstwa. Trzymaj się checklisty do końca.';
        }
        if ($errorCount > 0) {
            return 'Ocena C i ' . $errorCount . ' błędów. To nie pech, to decyzja. Wróć do planu.';
        }

        return 'Ocena C: brak setupu albo pośpiech. Odczekaj i wróć z planem.';
    }

    private function tradeDate(string $value): string
    {
        if (trim($value) === '') {
            return gmdate('Y-m-d\TH:i:s\Z');
        }
        $ts = strtotime($value);
        if ($ts === false) {
            throw new ProposalException('bad_request', 'Data jest nieprawidłowa.', 400);
        }
        if ($ts > time() + 300) {
            throw new ProposalException('bad_request', 'Data nie może być z przyszłości.', 400);
        }
        if ($ts < time() - 2 * 365 * 86400) {
            throw new ProposalException('bad_request', 'Data nie może być starsza niż 2 lata.', 400);
        }

        return gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    private function session(): string
    {
        $hour = (int) (new \DateTimeImmutable('now', Dates::tz()))->format('G');
        if ($hour < 8) {
            return 'Azja';
        }
        if ($hour < 13) {
            return 'Londyn';
        }
        if ($hour < 22) {
            return 'Nowy Jork';
        }

        return 'Po godzinach';
    }

    private function readinessScore(string $mood, int $energy): int
    {
        $mod = ['Spokój' => 10, 'Skupienie' => 7, 'Pewność' => 5, 'Nuda' => -3, 'Zmęczenie' => -8, 'Strach' => -10, 'FOMO' => -12, 'Złość' => -14, 'Chciwość' => -6][$mood] ?? 0;

        return (int) max(0, min(100, round(50 + ($energy - 3) * 10 + $mod)));
    }

    private function findTrade(array $state, string $id): ?array
    {
        foreach ((array) ($state['trades'] ?? []) as $trade) {
            if (is_array($trade) && (string) ($trade['id'] ?? '') === $id) {
                return $trade;
            }
        }

        return null;
    }

    private function formatNumber(float $value, int $decimals): string
    {
        $formatted = number_format($value, $decimals, '.', '');
        if (str_contains($formatted, '.')) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }

    private function decimals(float $value): int
    {
        if ($value < 1) return 4;
        if ($value < 20) return 3;
        if ($value < 1000) return 2;

        return 0;
    }

    private function shortId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
