/* Alpha Vault — silnik reguł prop challenge
   Czysta logika: szablony firm, normalizacja konfiguracji, limity, statystyki.
   Działa w przeglądarce (window.AV.prop) i w Node (module.exports) — testy jednostkowe. */
(function (root, factory) {
  'use strict';
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root && root.AV) root.AV.prop = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  'use strict';

  const MS_DAY = 86400000;
  const pad = (n) => String(n).padStart(2, '0');

  const toNum = (v, fallback = 0) => {
    if (typeof v === 'number') return Number.isFinite(v) ? v : fallback;
    const raw = String(v == null ? '' : v).replace(/\u00a0/g, '').replace(/\s/g, '').replace('%', '').replace(',', '.');
    const n = parseFloat(raw);
    return Number.isFinite(n) ? n : fallback;
  };
  const clamp = (n, a, b) => Math.min(b, Math.max(a, n));
  const startOfDay = (d) => {
    const x = new Date(d);
    x.setHours(0, 0, 0, 0);
    return x;
  };
  const addDays = (d, n) => {
    const x = new Date(d);
    x.setDate(x.getDate() + n);
    return x;
  };
  const dateKey = (d) => {
    const x = new Date(d);
    return x.getFullYear() + '-' + pad(x.getMonth() + 1) + '-' + pad(x.getDate());
  };
  const diffDays = (a, b) => Math.round((startOfDay(b) - startOfDay(a)) / MS_DAY);

  const parseDay = (value, fallback = null) => {
    if (value instanceof Date) return Number.isNaN(value.getTime()) ? fallback : startOfDay(value);
    if (typeof value === 'string') {
      const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(value.trim());
      if (m) {
        const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]), 0, 0, 0, 0);
        return Number.isNaN(d.getTime()) ? fallback : d;
      }
      const parsed = new Date(value);
      return Number.isNaN(parsed.getTime()) ? fallback : startOfDay(parsed);
    }
    return fallback;
  };

  const uid = () => 'prop_' + Date.now().toString(36) + Math.random().toString(36).slice(2, 7);

  const DRAWDOWN_TYPES = ['static', 'trailing'];
  const DAILY_BASES = ['initial', 'dayStart'];
  const CURRENCIES = ['USD', 'USDT', 'EUR', 'PLN'];

  /* Szablony startowe. Limity prop firm zmieniają się — każdy szablon jest edytowalny,
     a UI pokazuje wyraźne zastrzeżenie: potwierdź warunki w aktualnym regulaminie firmy. */
  const TEMPLATES = [
    {
      id: 'ftmo-2step',
      firm: 'FTMO',
      label: 'FTMO · 2-Step',
      phase: 'Phase 1',
      currency: 'USD',
      accountSize: 10000,
      targetPct: 10,
      dailyLossPct: 5,
      maxLossPct: 10,
      drawdownType: 'static',
      dailyBasis: 'initial',
      minDays: 4,
      durationDays: null,
      note: 'Faza 1: cel 10%. Faza 2: cel 5%. Dzienny limit 5%, całkowity 10%, min. 4 dni handlowe. FTMO nie limituje czasu — ustaw własny horyzont.'
    },
    {
      id: 'ftmo-1step',
      firm: 'FTMO',
      label: 'FTMO · 1-Step',
      phase: 'Challenge',
      currency: 'USD',
      accountSize: 10000,
      targetPct: 10,
      dailyLossPct: 3,
      maxLossPct: 6,
      drawdownType: 'static',
      dailyBasis: 'initial',
      minDays: 4,
      durationDays: null,
      note: 'Cel 10%, dzienny limit 3%, maksymalne obsunięcie 6%, min. 4 dni handlowe.'
    },
    {
      id: 'ftmo-swing',
      firm: 'FTMO',
      label: 'FTMO · Swing',
      phase: 'Phase 1',
      currency: 'USD',
      accountSize: 10000,
      targetPct: 10,
      dailyLossPct: 5,
      maxLossPct: 10,
      drawdownType: 'static',
      dailyBasis: 'initial',
      minDays: 4,
      durationDays: null,
      note: 'Wariant Swing: pozycje przez weekend, cel 10% / 5%, dzienny 5%, całkowity 10%.'
    },
    {
      id: 'fundednext-stellar',
      firm: 'FundedNext',
      label: 'FundedNext · Stellar 2-Step',
      phase: 'Phase 1',
      currency: 'USD',
      accountSize: 10000,
      targetPct: 8,
      dailyLossPct: 5,
      maxLossPct: 10,
      drawdownType: 'static',
      dailyBasis: 'initial',
      minDays: 5,
      durationDays: 30,
      note: 'Faza 1: 8%, faza 2: 5%. Dzienny 5%, całkowity 10%, min. 5 dni handlowych.'
    },
    {
      id: 'fundingpips-2step',
      firm: 'FundingPips',
      label: 'FundingPips · 2-Step',
      phase: 'Phase 1',
      currency: 'USD',
      accountSize: 10000,
      targetPct: 8,
      dailyLossPct: 5,
      maxLossPct: 10,
      drawdownType: 'static',
      dailyBasis: 'initial',
      minDays: 5,
      durationDays: 30,
      note: 'Cel 8% / 5%, dzienny 5%, całkowity 10%, min. 5 dni handlowych.'
    },
    {
      id: 'topstep-50k',
      firm: 'Topstep',
      label: 'Topstep · 50K Combine',
      phase: 'Combine',
      currency: 'USD',
      accountSize: 50000,
      targetPct: 6,
      dailyLossPct: 2,
      maxLossPct: 4,
      drawdownType: 'trailing',
      dailyBasis: 'initial',
      minDays: 5,
      durationDays: 30,
      note: 'Cel 6% (3 000 USD), dzienny 2% (1 000 USD), trailing max loss 4% (2 000 USD), min. 5 dni. Sprawdź aktualny wariant konta.'
    },
    {
      id: 'apex-50k',
      firm: 'Apex',
      label: 'Apex · 50K Evaluation',
      phase: 'Evaluation',
      currency: 'USD',
      accountSize: 50000,
      targetPct: 6,
      dailyLossPct: 0,
      maxLossPct: 5,
      drawdownType: 'trailing',
      dailyBasis: 'initial',
      minDays: 7,
      durationDays: null,
      note: 'Cel 6% (3 000 USD), trailing drawdown 5% (2 500 USD), brak dziennego limitu. Trailing zatrzymuje się po osiągnięciu progu — sprawdź regulamin.'
    },
    {
      id: 'breakout-10k',
      firm: 'Breakout',
      label: 'Breakout · 10K Challenge',
      phase: 'Challenge',
      currency: 'USD',
      accountSize: 10000,
      targetPct: 10,
      dailyLossPct: 5,
      maxLossPct: 10,
      drawdownType: 'static',
      dailyBasis: 'initial',
      minDays: 5,
      durationDays: 30,
      note: 'Krypto prop: cel 10%, dzienny 5%, całkowity 10%, min. 5 dni, 30 dni. Potwierdź aktualne zasady.'
    },
    {
      id: 'custom',
      firm: '',
      label: 'Własne zasady',
      phase: 'Phase 1',
      currency: 'USD',
      accountSize: 10000,
      targetPct: 10,
      dailyLossPct: 5,
      maxLossPct: 10,
      drawdownType: 'static',
      dailyBasis: 'initial',
      minDays: 0,
      durationDays: 30,
      note: 'Wpisz limity z regulaminu swojej firmy.'
    }
  ];

  const templateById = (id) => TEMPLATES.find((t) => t.id === id) || null;

  function normalize(input) {
    const src = input && typeof input === 'object' ? input : {};
    const template = templateById(src.templateId) || {};
    const accountSize = Math.max(0, toNum(src.accountSize, toNum(template.accountSize, 10000)));
    const startBalance = Math.max(0, toNum(src.startBalance, accountSize));
    const startDate = parseDay(src.startDate, startOfDay(new Date()));
    const durationDays = src.durationDays != null ? toNum(src.durationDays, 0) : toNum(template.durationDays, 0);
    const fallbackEnd = durationDays > 0 ? addDays(startDate, Math.round(durationDays)) : null;
    const endDate = src.endDate === undefined ? fallbackEnd : parseDay(src.endDate, null);
    const currency = CURRENCIES.includes(String(src.currency || '').toUpperCase())
      ? String(src.currency).toUpperCase()
      : (template.currency || 'USD');
    return {
      id: typeof src.id === 'string' && src.id.trim() ? src.id.trim() : uid(),
      name: String(src.name != null ? src.name : (template.label || 'Challenge')).trim() || 'Challenge',
      firm: String(src.firm != null ? src.firm : (template.firm || '')).trim(),
      templateId: templateById(src.templateId) ? src.templateId : 'custom',
      phase: String(src.phase != null ? src.phase : (template.phase || 'Phase 1')).trim() || 'Phase 1',
      account: String(src.account || '').trim(),
      currency,
      accountSize,
      startBalance: startBalance > 0 ? startBalance : accountSize,
      targetPct: clamp(toNum(src.targetPct, toNum(template.targetPct, 10)), 0, 1000),
      dailyLossPct: clamp(toNum(src.dailyLossPct, toNum(template.dailyLossPct, 5)), 0, 100),
      maxLossPct: clamp(toNum(src.maxLossPct, toNum(template.maxLossPct, 10)), 0, 100),
      drawdownType: DRAWDOWN_TYPES.includes(src.drawdownType) ? src.drawdownType : (template.drawdownType || 'static'),
      dailyBasis: DAILY_BASES.includes(src.dailyBasis) ? src.dailyBasis : (template.dailyBasis || 'initial'),
      minDays: Math.round(clamp(toNum(src.minDays, toNum(template.minDays, 0)), 0, 60)),
      startDate: dateKey(startDate),
      endDate: endDate ? dateKey(endDate) : '',
      notes: String(src.notes || '').trim(),
      archived: !!src.archived,
      createdAt: src.createdAt || new Date().toISOString()
    };
  }

  function compute(challenge, trades, now = new Date(), accountNames = null) {
    const c = normalize(challenge);
    const today = startOfDay(now instanceof Date && !Number.isNaN(now.getTime()) ? now : new Date());
    const start = parseDay(c.startDate, today);
    const end = parseDay(c.endDate, null);
    const startBalance = c.startBalance > 0 ? c.startBalance : c.accountSize;
    const targetAmount = startBalance * c.targetPct / 100;
    const maxLossAmount = startBalance * c.maxLossPct / 100;
    const accountKey = String(c.account || '').trim().toLowerCase();

    const list = (Array.isArray(trades) ? trades : [])
      .filter((t) => {
        if (!t || t.result == null) return false;
        const value = Number(t.result);
        if (!Number.isFinite(value)) return false;
        if (accountKey && String(t.account || '').trim().toLowerCase() !== accountKey) return false;
        const raw = new Date(t.date);
        if (Number.isNaN(raw.getTime())) return false;
        const day = startOfDay(raw);
        if (start && day < start) return false;
        if (end && day > end) return false;
        return true;
      })
      .map((t) => ({ ...t, _day: startOfDay(t.date), _value: Number(t.result) }))
      .sort((a, b) => a._day - b._day);

    let equity = startBalance;
    let peak = startBalance;
    let minEquity = startBalance;
    let maxDrawdownAmount = 0;
    const curve = [{ x: 0, date: start.toISOString(), eq: startBalance }];
    list.forEach((t, i) => {
      equity += t._value;
      peak = Math.max(peak, equity);
      minEquity = Math.min(minEquity, equity);
      const used = c.drawdownType === 'trailing' ? peak - equity : startBalance - equity;
      if (used > maxDrawdownAmount) maxDrawdownAmount = used;
      curve.push({ x: i + 1, date: t._day.toISOString(), eq: equity, result: t._value, tradeId: t.id });
    });

    const profitAmount = equity - startBalance;
    const targetReached = targetAmount > 0 && profitAmount >= targetAmount;
    const targetProgressPct = targetAmount > 0 ? profitAmount / targetAmount * 100 : 0;

    const todayKey = dateKey(today);
    let pnlBeforeToday = 0;
    list.forEach((t) => { if (startOfDay(t._day) < today) pnlBeforeToday += t._value; });
    const todayPnl = list.filter((t) => dateKey(t._day) === todayKey).reduce((s, t) => s + t._value, 0);
    const dayStartBalance = startBalance + pnlBeforeToday;
    const basisAmount = c.dailyBasis === 'dayStart' ? Math.max(0, dayStartBalance) : startBalance;
    const dailyLimitAmount = basisAmount * c.dailyLossPct / 100;
    const dailyLossAmount = Math.max(0, -todayPnl);
    const dailyUsedPct = dailyLimitAmount > 0 ? dailyLossAmount / dailyLimitAmount * 100 : 0;

    const drawdownUsedPct = maxLossAmount > 0 ? maxDrawdownAmount / maxLossAmount * 100 : 0;
    const currentDrawdown = Math.max(0, c.drawdownType === 'trailing' ? peak - equity : startBalance - equity);
    const drawdownBuffer = Math.max(0, maxLossAmount - currentDrawdown);
    const drawdownFloor = (c.drawdownType === 'trailing' ? peak : startBalance) - maxLossAmount;

    const hasDeadline = !!end;
    const daysLeft = hasDeadline ? Math.max(0, diffDays(today, end) + 1) : null;
    const daysTotal = hasDeadline ? Math.max(1, diffDays(start, end) + 1) : null;
    const expired = hasDeadline && today > end;

    const tradingDayKeys = new Set(list.map((t) => dateKey(t._day)));
    const tradingDays = tradingDayKeys.size;
    const minDays = c.minDays;
    const minDaysMet = tradingDays >= minDays;

    const dailyBreached = dailyLimitAmount > 0 && dailyLossAmount >= dailyLimitAmount;
    const drawdownBreached = maxLossAmount > 0 && maxDrawdownAmount >= maxLossAmount;
    let status = 'active';
    if (drawdownBreached || dailyBreached) status = 'failed';
    else if (targetReached) status = minDaysMet ? 'passed' : 'target';
    else if (expired) status = 'expired';

    const riskPct = Math.max(dailyUsedPct, drawdownUsedPct);
    let riskLevel = 'low';
    if (status === 'failed' || dailyBreached || drawdownBreached) riskLevel = 'breached';
    else if (riskPct >= 90) riskLevel = 'critical';
    else if (riskPct >= 75) riskLevel = 'high';
    else if (riskPct >= 50) riskLevel = 'medium';

    const warnings = [];
    if (dailyLimitAmount > 0) {
      if (dailyUsedPct >= 100) {
        warnings.push({ level: 'danger', code: 'daily-breach', title: 'Dzienny limit straty przekroczony', text: 'Dziś wykorzystano ' + round1(dailyUsedPct) + '% dziennego limitu. Nowe pozycje łamią zasady challenge\'u.' });
      } else if (dailyUsedPct >= 90) {
        warnings.push({ level: 'danger', code: 'daily-90', title: 'Prawie dzienny limit', text: 'Wykorzystano ' + round1(dailyUsedPct) + '% dziennego limitu. Zostało ' + round2(Math.max(0, dailyLimitAmount - dailyLossAmount)) + ' ' + c.currency + '.' });
      } else if (dailyUsedPct >= 75) {
        warnings.push({ level: 'warn', code: 'daily-75', title: 'Dzienny limit blisko', text: 'Wykorzystano ' + round1(dailyUsedPct) + '% dziennego limitu. Zostało ' + round2(Math.max(0, dailyLimitAmount - dailyLossAmount)) + ' ' + c.currency + '.' });
      }
    }
    if (maxLossAmount > 0) {
      if (drawdownUsedPct >= 100) {
        warnings.push({ level: 'danger', code: 'dd-breach', title: 'Maksymalne obsunięcie przekroczone', text: 'Obsunięcie ' + round2(maxDrawdownAmount) + ' ' + c.currency + ' przekroczyło limit ' + round2(maxLossAmount) + ' ' + c.currency + '.' });
      } else if (drawdownUsedPct >= 90) {
        warnings.push({ level: 'danger', code: 'dd-90', title: 'Prawie maksymalne obsunięcie', text: 'Wykorzystano ' + round1(drawdownUsedPct) + '% limitu obsunięcia. Bufor: ' + round2(drawdownBuffer) + ' ' + c.currency + '.' });
      } else if (drawdownUsedPct >= 75) {
        warnings.push({ level: 'warn', code: 'dd-75', title: 'Obsunięcie blisko limitu', text: 'Wykorzystano ' + round1(drawdownUsedPct) + '% limitu obsunięcia. Bufor: ' + round2(drawdownBuffer) + ' ' + c.currency + '.' });
      }
    }
    if (targetReached && status === 'passed') {
      warnings.push({ level: 'info', code: 'target', title: 'Cel zysku osiągnięty', text: 'Wynik ' + round2(profitAmount) + ' ' + c.currency + ' przy celu ' + round2(targetAmount) + ' ' + c.currency + '.' });
    } else if (targetReached && status === 'target') {
      warnings.push({ level: 'warn', code: 'mindays', title: 'Cel osiągnięty, brakuje dni handlowych', text: 'Masz ' + tradingDays + ' z ' + minDays + ' wymaganych dni handlowych. Nie zamykaj challenge\'u przed uzupełnieniem.' });
    }
    if (expired && status === 'expired') {
      warnings.push({ level: 'warn', code: 'expired', title: 'Czas challenge\'u minął', text: 'Termin upłynął, a cel nie został osiągnięty. Sprawdź zasady przedłużenia u firmy.' });
    }
    if (!expired && daysLeft != null && daysLeft <= 3 && status === 'active') {
      warnings.push({ level: 'warn', code: 'days', title: 'Kończy się czas', text: 'Zostało ' + daysLeft + ' ' + plural(daysLeft, 'dzień', 'dni', 'dni') + ' do końca challenge\'u.' });
    }
    if (!expired && daysLeft != null && status === 'active' && minDays > tradingDays && (minDays - tradingDays) > daysLeft) {
      warnings.push({ level: 'warn', code: 'mindays-deadline', title: 'Nie zdążysz z min. dniami', text: 'Brakuje ' + (minDays - tradingDays) + ' dni handlowych, a zostało ' + daysLeft + ' dni kalendarzowych.' });
    }
    if (!warnings.length) {
      warnings.push({ level: 'ok', code: 'ok', title: 'Limity w normie', text: 'Challenge nie zbliża się do żadnego limitu.' });
    }
    const order = { danger: 0, warn: 1, info: 2, ok: 3 };
    warnings.sort((a, b) => order[a.level] - order[b.level]);

    const wins = list.filter((t) => t._value > 0);
    const losses = list.filter((t) => t._value < 0);
    const grossProfit = wins.reduce((s, t) => s + t._value, 0);
    const grossLoss = Math.abs(losses.reduce((s, t) => s + t._value, 0));
    const rValues = list.map((t) => Number(t.r)).filter((r) => Number.isFinite(r));
    let maxConsecutiveLosses = 0;
    let streak = 0;
    list.forEach((t) => {
      if (t._value < 0) { streak++; if (streak > maxConsecutiveLosses) maxConsecutiveLosses = streak; }
      else if (t._value > 0) streak = 0;
    });
    const dayMap = new Map();
    list.forEach((t) => {
      const key = dateKey(t._day);
      const row = dayMap.get(key) || { date: key, pnl: 0, n: 0, wins: 0 };
      row.pnl += t._value;
      row.n++;
      if (t._value > 0) row.wins++;
      dayMap.set(key, row);
    });
    let running = startBalance;
    const dailyRows = [...dayMap.values()].sort((a, b) => a.date.localeCompare(b.date)).map((row) => {
      running += row.pnl;
      return { ...row, equity: running };
    });
    const sortedDays = dailyRows.slice().sort((a, b) => a.pnl - b.pnl);
    const bestDay = sortedDays.length ? sortedDays[sortedDays.length - 1] : null;
    const worstDay = sortedDays.length ? sortedDays[0] : null;

    const accountMissing = !!(accountKey && Array.isArray(accountNames) && accountNames.length &&
      !accountNames.some((name) => String(name || '').trim().toLowerCase() === accountKey));

    return {
      challenge: c,
      currency: c.currency,
      startBalance,
      balance: equity,
      profitAmount,
      targetPct: c.targetPct,
      targetAmount,
      targetProgressPct,
      targetRemaining: Math.max(0, targetAmount - profitAmount),
      targetReached,
      dailyLossPct: c.dailyLossPct,
      dailyLimitAmount,
      dailyLossAmount,
      dailyUsedPct,
      dailyBuffer: Math.max(0, dailyLimitAmount - dailyLossAmount),
      todayPnl,
      dayStartBalance,
      dailyBasisAmount: basisAmount,
      maxLossPct: c.maxLossPct,
      maxLossAmount,
      maxDrawdownAmount,
      drawdownUsedPct,
      drawdownBuffer,
      drawdownFloor,
      peak,
      minEquity,
      hasDeadline,
      daysLeft,
      daysTotal,
      expired,
      tradingDays,
      minDays,
      minDaysMet,
      status,
      riskLevel,
      riskPct,
      alerts: warnings,
      n: list.length,
      wins: wins.length,
      losses: losses.length,
      winRate: list.length ? wins.length / list.length * 100 : 0,
      pf: grossLoss > 0 ? grossProfit / grossLoss : grossProfit > 0 ? 99 : 0,
      net: profitAmount,
      avgR: rValues.length ? rValues.reduce((s, r) => s + r, 0) / rValues.length : 0,
      maxConsecutiveLosses,
      bestDay,
      worstDay,
      dailyRows,
      curve,
      accountMissing,
      startDate: c.startDate,
      endDate: c.endDate,
      todayKey
    };
  }

  function portfolio(challenges, trades, now = new Date(), accountNames = null) {
    const metrics = (Array.isArray(challenges) ? challenges : [])
      .filter((c) => c && !c.archived)
      .map((c) => compute(c, trades, now, accountNames));
    const active = metrics.filter((m) => m.status === 'active' || m.status === 'target');
    const worst = metrics.slice().sort((a, b) => b.riskPct - a.riskPct)[0] || null;
    const alerts = [];
    metrics.forEach((m) => {
      m.alerts.filter((a) => a.level === 'danger' || a.level === 'warn').forEach((a) => alerts.push({ ...a, challengeId: m.challenge.id, challengeName: m.challenge.name }));
    });
    return { metrics, active, worst, alerts, passed: metrics.filter((m) => m.status === 'passed'), failed: metrics.filter((m) => m.status === 'failed') };
  }

  const fmtNum = (n, d = 2) => Number(n).toLocaleString('pl-PL', { minimumFractionDigits: d, maximumFractionDigits: d });
  const round1 = (n) => fmtNum(n, 1);
  const round2 = (n) => fmtNum(n, 2);
  const plural = (n, one, few, many) => {
    const a = Math.abs(n) % 100;
    const b = a % 10;
    if (a === 1) return one;
    if (b >= 2 && b <= 4 && (a < 12 || a > 14)) return few;
    return many;
  };

  return {
    TEMPLATES,
    DRAWDOWN_TYPES,
    DAILY_BASES,
    CURRENCIES,
    templateById,
    normalize,
    compute,
    portfolio,
    parseDay,
    dateKey,
    startOfDay,
    addDays,
    diffDays,
    toNum
  };
});
