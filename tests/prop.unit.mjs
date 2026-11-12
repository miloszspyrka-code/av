#!/usr/bin/env node
/**
 * Alpha Vault — testy jednostkowe silnika Prop challenge.
 * Uruchom: npm run test:prop
 * Nie wymaga serwera ani bazy.
 */
import assert from 'node:assert/strict';
import prop from '../public/js/prop.js';

const results = [];
function check(name, fn) {
  try {
    fn();
    results.push({ name, ok: true });
    console.log('PASS  ' + name);
  } catch (err) {
    results.push({ name, ok: false, info: err.message });
    console.log('FAIL  ' + name + '  · ' + err.message);
  }
}

const NOW = new Date(2026, 10, 12, 12, 0, 0); // 12.11.2026, lokalny czas
const day = (offset, hour = 12) => new Date(2026, 10, 12 + offset, hour, 0, 0).toISOString();
const key = (offset) => prop.dateKey(new Date(2026, 10, 12 + offset, 12, 0, 0));
const trade = (offset, result, account = 'WEEX', extra = {}) => ({
  id: 't_' + offset + '_' + String(result).replace(/[^0-9-]/g, ''),
  date: day(offset),
  result,
  r: result == null ? null : result / 100,
  account,
  ...extra
});
const base = (over = {}) => prop.normalize({
  id: 'challenge', name: 'Test', account: 'WEEX', currency: 'USD',
  accountSize: 10000, startBalance: 10000,
  targetPct: 10, dailyLossPct: 5, maxLossPct: 10,
  drawdownType: 'static', dailyBasis: 'initial', minDays: 0,
  startDate: key(-30), endDate: key(10),
  ...over
});
const compute = (over, trades, now = NOW) => prop.compute(base(over), trades, now, ['WEEX', 'OKX', 'MT5 · demo']);

/* ---------- katalog szablonów ---------- */
check('katalog: min. 8 szablonów z unikalnym id i poprawnymi limitami', () => {
  assert.ok(prop.TEMPLATES.length >= 8);
  const ids = prop.TEMPLATES.map((t) => t.id);
  assert.equal(new Set(ids).size, ids.length);
  prop.TEMPLATES.forEach((t) => {
    assert.ok(t.label && t.firm !== undefined);
    assert.ok(t.targetPct > 0);
    assert.ok(t.dailyLossPct >= 0);
    assert.ok(t.maxLossPct > 0);
    assert.ok(['static', 'trailing'].includes(t.drawdownType));
    assert.ok(['initial', 'dayStart'].includes(t.dailyBasis));
  });
  assert.equal(prop.templateById('ftmo-2step').targetPct, 10);
  assert.equal(prop.templateById('nie-ma'), null);
});

/* ---------- puste dane i normalizacja ---------- */
check('puste transakcje: zero postępu, brak fałszywego alarmu', () => {
  const m = compute({}, []);
  assert.equal(m.n, 0);
  assert.equal(m.balance, 10000);
  assert.equal(m.profitAmount, 0);
  assert.equal(m.targetProgressPct, 0);
  assert.equal(m.status, 'active');
  assert.equal(m.curve.length, 1);
  assert.equal(m.dailyRows.length, 0);
  assert.equal(m.alerts[0].code, 'ok');
  assert.equal(m.daysLeft, 11);
  assert.equal(m.daysTotal, 41);
});

check('normalizacja: brak danych nie wywala się, wartości są przycięte', () => {
  const c = prop.normalize(null);
  assert.ok(c.id.startsWith('prop_'));
  assert.equal(c.name, 'Challenge');
  const c2 = prop.normalize({
    targetPct: -5, dailyLossPct: -1, maxLossPct: 0, accountSize: 'abc', startBalance: 'xyz',
    minDays: 999, drawdownType: 'złe', dailyBasis: 'złe', currency: 'GBP', endDate: key(-10), startDate: key(0)
  });
  assert.equal(c2.targetPct, 0);
  assert.equal(c2.dailyLossPct, 0);
  assert.equal(c2.maxLossPct, 0);
  assert.equal(c2.accountSize, 10000); // nieczytelna kwota → bezpieczny default
  assert.equal(c2.startBalance, 10000);
  assert.equal(c2.minDays, 60);
  assert.equal(c2.drawdownType, 'static');
  assert.equal(c2.dailyBasis, 'initial');
  assert.equal(c2.currency, 'USD');
  assert.equal(c2.endDate, key(-10));
  const m = prop.compute(c2, [], NOW);
  assert.equal(m.balance, 10000);
  assert.equal(m.maxLossAmount, 0);
  assert.equal(m.drawdownUsedPct, 0);
  assert.ok(Number.isFinite(m.dailyUsedPct));
});

/* ---------- filtrowanie transakcji ---------- */
check('filtr dat: transakcje przed startem i po końcu są pomijane', () => {
  const m = compute({}, [trade(-31, 1000), trade(-30, 100), trade(10, 50), trade(11, 999)]);
  assert.equal(m.n, 2);
  assert.equal(m.profitAmount, 150);
});

check('filtr konta: tylko wybrane konto, bez względu na wielkość liter', () => {
  const m = compute({ account: 'WEEX' }, [trade(-5, 100), trade(-4, 500, 'weex'), trade(-3, 1000, 'OKX')]);
  assert.equal(m.n, 2);
  assert.equal(m.profitAmount, 600);
});

check('transakcje otwarte (result=null) nie wchodzą do limitu ani statystyk', () => {
  const m = compute({}, [trade(-2, 100), { ...trade(-1, null), result: null }]);
  assert.equal(m.n, 1);
  assert.equal(m.balance, 10100);
});

check('brakujące konto jest raportowane, ale nie wywala liczenia', () => {
  const m = compute({ account: 'Nie ma takiego' }, [trade(-1, 100)]);
  assert.equal(m.accountMissing, true);
  assert.equal(m.n, 0);
});

/* ---------- cel zysku ---------- */
check('cel zysku: dokładne trafienie = achieved i passed', () => {
  const m = compute({ minDays: 0 }, [trade(-2, 500), trade(-1, 500)]);
  assert.equal(m.targetReached, true);
  assert.equal(m.targetProgressPct, 100);
  assert.equal(m.status, 'passed');
  assert.equal(m.alerts[0].code, 'target');
});

check('cel zysku: brakuje 1 centa = nieosiągnięty', () => {
  const m = compute({ minDays: 0 }, [trade(-1, 999.99)]);
  assert.equal(m.targetReached, false);
  assert.equal(m.status, 'active');
  assert.equal(m.targetRemaining.toFixed(2), '0.01');
});

check('cel osiągnięty, ale brak min. dni = status target (nie passed)', () => {
  const m = compute({ minDays: 4 }, [trade(-1, 1000)]);
  assert.equal(m.targetReached, true);
  assert.equal(m.minDaysMet, false);
  assert.equal(m.status, 'target');
  assert.ok(m.alerts.some((a) => a.code === 'mindays'));
});

check('min. dni handlowe: liczone jako unikalne dni, nie transakcje', () => {
  const oneDay = compute({ minDays: 4, targetPct: 0.1 }, [trade(-2, 10), trade(-2, 10), trade(-2, 10), trade(-2, 10)]);
  assert.equal(oneDay.tradingDays, 1);
  assert.equal(oneDay.status, 'target');
  const fourDays = compute({ minDays: 4, targetPct: 0.1 }, [trade(-4, 10), trade(-3, 10), trade(-2, 10), trade(-1, 10)]);
  assert.equal(fourDays.tradingDays, 4);
  assert.equal(fourDays.minDaysMet, true);
  assert.equal(fourDays.status, 'passed');
});

/* ---------- dzienny limit ---------- */
check('dzienny limit: 75% = ostrzeżenie, nie porażka', () => {
  const m = compute({ dailyLossPct: 5 }, [trade(0, -375)]);
  assert.equal(m.dailyUsedPct, 75);
  assert.equal(m.status, 'active');
  assert.equal(m.alerts[0].code, 'daily-75');
  assert.match(m.alerts[0].text, /75,0%/);
});

check('dzienny limit: 90% = alarm, 100% = przekroczenie', () => {
  const warn = compute({ dailyLossPct: 5 }, [trade(0, -450)]);
  assert.equal(warn.alerts[0].code, 'daily-90');
  assert.equal(warn.status, 'active');
  const fail = compute({ dailyLossPct: 5 }, [trade(0, -500)]);
  assert.equal(fail.alerts[0].code, 'daily-breach');
  assert.equal(fail.status, 'failed');
  assert.equal(fail.dailyBuffer, 0);
});

check('dzienny limit liczony od salda startowego vs początku dnia', () => {
  const trades = [trade(-1, -1000), trade(0, -400)];
  const initial = compute({ dailyLossPct: 5, dailyBasis: 'initial' }, trades);
  const dayStart = compute({ dailyLossPct: 5, dailyBasis: 'dayStart' }, trades);
  assert.equal(initial.dailyLimitAmount, 500);
  assert.equal(initial.dailyUsedPct, 80);
  assert.equal(dayStart.dayStartBalance, 9000);
  assert.equal(dayStart.dailyLimitAmount, 450);
  assert.ok(dayStart.dailyUsedPct > 88 && dayStart.dailyUsedPct < 89);
});

check('dzienny limit 0 = brak limitu, strata dzienna nie blokuje', () => {
  const m = compute({ dailyLossPct: 0, maxLossPct: 10 }, [trade(0, -900)]);
  assert.equal(m.dailyLimitAmount, 0);
  assert.equal(m.dailyUsedPct, 0);
  assert.ok(!m.alerts.some((a) => a.code.startsWith('daily')));
  assert.equal(m.status, 'active');
  assert.ok(m.alerts.some((a) => a.code === 'dd-90'));
});

/* ---------- obsunięcie ---------- */
check('obsunięcie statyczne: 10% = porażka, także po odbiciu', () => {
  const m = compute({}, [trade(-5, -600), trade(-4, -400), trade(-3, 300)]);
  assert.equal(m.maxDrawdownAmount, 1000);
  assert.equal(m.drawdownUsedPct, 100);
  assert.equal(m.status, 'failed');
  assert.ok(m.alerts.some((a) => a.code === 'dd-breach'));
});

check('obsunięcie statyczne: bieżący bufor rośnie po odbiciu, ale max pozostaje', () => {
  const m = compute({}, [trade(-5, -500), trade(-4, 300)]);
  assert.equal(m.maxDrawdownAmount, 500);
  assert.equal(m.drawdownBuffer, 800);
  assert.equal(m.status, 'active');
});

check('trailing: szczyt podnosi linię stopu i łamie limit szybciej niż static', () => {
  const trades = [trade(-5, 1000), trade(-4, -1500)];
  const trailing = compute({ drawdownType: 'trailing' }, trades);
  const staticDd = compute({ drawdownType: 'static' }, trades);
  assert.equal(trailing.maxDrawdownAmount, 1500);
  assert.equal(trailing.status, 'failed');
  assert.equal(trailing.drawdownFloor, 10000);
  assert.equal(staticDd.maxDrawdownAmount, 500);
  assert.equal(staticDd.status, 'active');
  assert.equal(staticDd.drawdownFloor, 9000);
});

check('trailing: floor podąża za szczytem, bufor liczony od bieżącego kapitału', () => {
  const m = compute({ drawdownType: 'trailing' }, [trade(-3, 1000), trade(-2, -500)]);
  assert.equal(m.peak, 11000);
  assert.equal(m.balance, 10500);
  assert.equal(m.maxDrawdownAmount, 500);
  assert.equal(m.drawdownFloor, 10000);
  assert.equal(m.drawdownBuffer, 500);
});

/* ---------- termin ---------- */
check('termin: dzień końcowy włącznie, dzień po = expired', () => {
  const lastDay = compute({ endDate: key(0) }, []);
  assert.equal(lastDay.daysLeft, 1);
  assert.equal(lastDay.expired, false);
  assert.ok(lastDay.alerts.some((a) => a.code === 'days'));
  const after = compute({ endDate: key(-1) }, [trade(-10, 100)]);
  assert.equal(after.daysLeft, 0);
  assert.equal(after.expired, true);
  assert.equal(after.status, 'expired');
  assert.ok(after.alerts.some((a) => a.code === 'expired'));
});

check('brak daty końca = bez terminu, nigdy expired', () => {
  const m = compute({ endDate: '' }, [trade(-10, 100)], new Date(2027, 5, 1, 12));
  assert.equal(m.hasDeadline, false);
  assert.equal(m.daysLeft, null);
  assert.equal(m.expired, false);
});

check('min. dni nie zdążą przed terminem = ostrzeżenie', () => {
  const m = compute({ minDays: 10, endDate: key(3) }, [trade(-2, 10), trade(-1, 10)]);
  assert.equal(m.daysLeft, 4);
  assert.ok(m.alerts.some((a) => a.code === 'mindays-deadline'));
});

/* ---------- statusy i statystyki ---------- */
check('cel osiągnięty, ale dzienny limit złamany tego samego dnia = failed', () => {
  const m = compute({ targetPct: 5, dailyLossPct: 5, minDays: 0 }, [trade(-1, 1000), trade(0, -500)]);
  assert.equal(m.targetReached, true); // 10 500 przy celu 10 500
  assert.equal(m.status, 'failed');
  assert.ok(m.alerts.some((a) => a.code === 'daily-breach'));
  assert.ok(!m.alerts.some((a) => a.code === 'target'));
});

check('statystyki: win rate, PF, avg R, seria strat, najlepszy i najgorszy dzień', () => {
  const m = compute({}, [
    trade(-5, 200), trade(-4, -100), trade(-3, -50), trade(-2, 100), trade(-1, -25)
  ]);
  assert.equal(m.n, 5);
  assert.equal(m.wins, 2);
  assert.equal(m.losses, 3);
  assert.equal(m.winRate, 40);
  assert.ok(Math.abs(m.pf - 300 / 175) < 0.0001);
  assert.equal(m.avgR, 0.25);
  assert.equal(m.maxConsecutiveLosses, 2);
  assert.equal(m.bestDay.pnl, 200);
  assert.equal(m.worstDay.pnl, -100);
  assert.equal(m.dailyRows.length, 5);
  assert.equal(m.dailyRows[m.dailyRows.length - 1].equity, 10125);
});

check('profit factor bez strat = 99 (UI pokazuje ∞), bez transakcji = 0', () => {
  assert.equal(compute({}, [trade(-1, 100)]).pf, 99);
  assert.equal(compute({}, []).pf, 0);
});

check('portfolio: archiwum nie wchodzi do metryk, aktywne są zliczane', () => {
  const a = base({ id: 'a', name: 'A' });
  const b = base({ id: 'b', name: 'B', archived: true });
  const p = prop.portfolio([a, b], [trade(-1, 100)], NOW, ['WEEX']);
  assert.equal(p.metrics.length, 1);
  assert.equal(p.metrics[0].challenge.id, 'a');
  assert.equal(p.active.length, 1);
  assert.equal(p.failed.length, 0);
});

check('waluta jest zachowana w metrykach i alertach', () => {
  const m = compute({ currency: 'USDT' }, [trade(-1, 100)]);
  assert.equal(m.currency, 'USDT');
  const c = prop.normalize({ currency: 'usdt' });
  assert.equal(c.currency, 'USDT');
});

const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} PASS`);
if (failed.length) {
  console.log('Nieudane:', failed.map((f) => f.name + ' — ' + f.info).join('\n'));
  process.exitCode = 1;
}

