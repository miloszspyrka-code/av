/* Alpha Vault — stan, zapis, metryki */
(function () {
  'use strict';
  const AV = window.AV;
  AV.KEY = 'alphavault.state.v2';
  AV.SESSION_KEY = 'alphavault.session.v2';
  AV.WIPED_KEY = 'alphavault.wiped.v2';
  AV.DRAFT_KEY = 'alphavault.draft.v2';

  AV.state = null;
  AV._saveTimer = null;
  AV.api = {
    online: false,
    authenticated: false,
    ready: false,
    revision: 0,
    me: null,
    discordConfigured: false,
    demoAllowed: false,
    pushing: false,
    dirty: false,
    lastError: null,
    lastSavedAt: null
  };

  const deepMerge = (base, patch) => {
    if (Array.isArray(base)) return Array.isArray(patch) ? patch : base;
    if (base && typeof base === 'object') {
      const out = { ...base };
      Object.keys(patch || {}).forEach((k) => {
        out[k] = k in base ? deepMerge(base[k], patch[k]) : patch[k];
      });
      return out;
    }
    return patch === undefined ? base : patch;
  };

  AV.normalize = (raw, fallback) => {
    const demo = fallback || AV.buildDemo();
    const s = deepMerge(demo, raw || {});
    ['plans', 'trades', 'setups', 'accounts', 'flows', 'checkins', 'chat', 'overrides'].forEach((k) => {
      if (!Array.isArray(s[k]) && k !== 'plans') s[k] = demo[k];
    });
    if (!s.plans || typeof s.plans !== 'object') s.plans = {};
    s.version = 2;
    return s;
  };
  AV.normalizeServer = (raw) => AV.normalize(raw, AV.buildEmpty());

  AV.load = () => {
    const raw = AV.safeGet(AV.KEY);
    if (raw) {
      try {
        AV.state = AV.normalize(JSON.parse(raw));
        return AV.state;
      } catch (e) {
        console.warn('Nie udało się odczytać stanu, tworzę nowy.', e);
      }
    }
    AV.state = AV.safeGet(AV.WIPED_KEY) ? AV.buildEmpty() : AV.normalize(AV.buildDemo());
    AV.saveNow();
    return AV.state;
  };

  AV.buildEmpty = () => {
    const s = AV.buildDemo();
    s.trades = [];
    s.plans = {};
    s.checkins = [];
    s.chat = [];
    s.flows = [];
    s.overrides = [];
    s.accounts = [{
      id: 'acc1', name: 'Konto główne', exchange: 'WEEX', type: 'Futures', currency: 'USDT',
      start: 0, date: AV.dateLabel(new Date()), lev: '10×', goal: '', balance: 0, include: true, primary: true
    }];
    s.setups = [];
    s.meta.consultation = { date: AV.dateKey(AV.addDays(new Date(), 5)), time: '18:00', who: 'Marcin' };
    s.meta.consultationUsed = 0;
    s.user.name = 'Trader';
    s.user.email = '';
    s.subscription.expired = false;
    return s;
  };

  AV.saveNow = () => {
    if (!AV.state) return;
    try {
      AV.safeSet(AV.KEY, JSON.stringify(AV.state));
    } catch (e) {
      console.warn('localStorage pełny — nie zapisano stanu.', e);
      if (!AV.api.online) AV.toast('Pamięć przeglądarki pełna. Usuń stare screeny, żeby zapisać zmiany.', 5000);
    }
    if (AV.api.online && AV.api.authenticated && AV.api.ready) AV.schedulePush();
  };

  AV.save = () => {
    clearTimeout(AV._saveTimer);
    AV._saveTimer = setTimeout(AV.saveNow, 120);
  };

  /* ---------- API / MySQL ---------- */
  AV.apiFetch = async (path, opts = {}) => {
    const res = await fetch(path, {
      credentials: 'same-origin',
      cache: 'no-store',
      ...opts,
      headers: { ...(opts.headers || {}) }
    });
    return res;
  };

  AV.applyServerUser = (user) => {
    if (!user) return;
    const u = AV.state.user;
    const name = user.globalName || user.username || '';
    if (!u.name || ['Trader', 'Kasia', 'Kasia (demo)'].includes(u.name)) {
      u.name = name === 'Kasia (demo)' ? 'Kasia' : (name || u.name);
    }
    if (!u.email) u.email = user.email || '';
    u.discordId = user.id || null;
    u.discordAvatar = user.avatarUrl || null;
    u.isDemo = !!user.isDemo;
    const sub = user.subscription || {};
    AV.state.subscription.plan = sub.plan || AV.state.subscription.plan || 'full';
    AV.state.subscription.until = sub.accessUntil || null;
    AV.state.subscription.expired = !!sub.expired;
    AV.state.subscription.role = sub.roleVerified ? 'Alpha' : 'Alpha (bez weryfikacji roli)';
    AV.api.me = user;
  };

  AV.bootstrap = async () => {
    try {
      const meRes = await AV.apiFetch('/api/me');
      if (!meRes.ok) throw new Error('HTTP ' + meRes.status);
      const me = await meRes.json();
      AV.api.online = true;
      AV.api.authenticated = !!me.authenticated;
      AV.api.discordConfigured = !!me.discordConfigured;
      AV.api.demoAllowed = !!me.demoAllowed;
      AV.api.roleCheckConfigured = !!me.roleCheckConfigured;
      AV.api.me = me.user || null;
      if (!me.authenticated) {
        AV.api.ready = true;
        return { authenticated: false, me };
      }
      const stRes = await AV.apiFetch('/api/state');
      if (stRes.status === 401) {
        AV.api.authenticated = false;
        AV.api.ready = true;
        return { authenticated: false, me };
      }
      if (!stRes.ok) throw new Error('state HTTP ' + stRes.status);
      const payload = await stRes.json();
      const hadState = !!payload.state;
      if (hadState) {
        AV.state = AV.normalizeServer(payload.state);
        AV.api.revision = payload.revision || 1;
      } else {
        AV.state = AV.normalizeServer(me.user?.isDemo ? AV.buildDemo() : AV.buildEmpty());
        AV.api.revision = 0;
      }
      AV.applyServerUser(me.user);
      AV.api.ready = true;
      if (!hadState || AV.state.subscription.expired !== !!me.user?.subscription?.expired) {
        await AV.pushStateImmediate();
      }
      return { authenticated: true, me };
    } catch (err) {
      AV.api.online = false;
      AV.api.authenticated = false;
      AV.api.ready = false;
      AV.api.lastError = err.message;
      return { offline: true, error: err.message };
    }
  };

  AV.schedulePush = () => {
    clearTimeout(AV._pushTimer);
    AV._pushTimer = setTimeout(() => AV.pushStateImmediate(), 300);
  };

  AV.pushStateImmediate = async () => {
    if (!AV.api.online || !AV.api.authenticated || !AV.api.ready || !AV.state) return false;
    if (AV.api.pushing) { AV.api.dirty = true; return false; }
    AV.api.pushing = true;
    try {
      const res = await AV.apiFetch('/api/state', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ state: AV.state, baseRevision: AV.api.revision })
      });
      if (res.status === 409) {
        AV.api.dirty = false;
        const latest = await AV.apiFetch('/api/state');
        if (latest.ok) {
          const payload = await latest.json();
          if (payload.state) {
            AV.state = AV.normalizeServer(payload.state);
            AV.applyServerUser(AV.api.me);
            AV.api.revision = payload.revision;
            AV.saveNow();
            if (AV.renderAll) AV.renderAll();
            AV.toast('Dane zostały zmienione w innej karcie. Wczytano najnowszą wersję.', 4000);
          }
        }
        return false;
      }
      if (res.status === 401) {
        AV.api.authenticated = false;
        AV.toast('Sesja wygasła. Zaloguj się ponownie.', 4000);
        return false;
      }
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const payload = await res.json();
      AV.api.revision = payload.revision;
      AV.api.lastSavedAt = Date.now();
      AV.api.lastError = null;
      AV.updateEnvBadge();
      return true;
    } catch (err) {
      AV.api.lastError = err.message;
      console.warn('Nie udało się zapisać stanu na serwerze:', err.message);
      AV.updateEnvBadge();
      return false;
    } finally {
      AV.api.pushing = false;
      if (AV.api.dirty) { AV.api.dirty = false; setTimeout(() => AV.pushStateImmediate(), 250); }
    }
  };

  AV.flushState = () => {
    if (!AV.api.online || !AV.api.authenticated || !AV.api.ready) return;
    try {
      fetch('/api/state', {
        method: 'PUT',
        credentials: 'same-origin',
        keepalive: true,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ state: AV.state, baseRevision: AV.api.revision })
      }).catch(() => {});
    } catch {}
  };

  AV.updateEnvBadge = () => {
    const el = document.getElementById('envBadge');
    if (!el) return;
    if (AV.api.online && AV.api.authenticated) {
      const plan = AV.state?.subscription?.plan === 'solo' ? 'Alpha Vault' : 'Alpha + Akademia';
      el.textContent = 'POŁĄCZONO Z MYSQL · ' + plan + (AV.api.lastError ? ' · ostatni zapis: błąd' : ' · zapis automatyczny');
    } else if (AV.api.online) {
      el.textContent = 'ALPHA VAULT · logowanie przez Discord';
    } else {
      el.textContent = 'TRYB OFFLINE · dane tylko w tej przeglądarce';
    }
  };

  AV.serverDemoLogin = async () => {
    const res = await AV.apiFetch('/auth/demo', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ remember: AV.state?.settings?.remember !== false })
    });
    if (!res.ok) {
      const err = await res.json().catch(() => ({}));
      throw new Error(err.message || 'Nie udało się zalogować demo');
    }
    return res.json();
  };

  AV.serverLogout = async () => {
    try { await AV.apiFetch('/auth/logout', { method: 'POST' }); } catch {}
  };

  AV.serverDeleteAccount = async () => {
    const res = await AV.apiFetch('/api/account', { method: 'DELETE' });
    if (!res.ok) throw new Error('Nie udało się usunąć konta');
    return res.json();
  };

  AV.resetAll = (keepSession = false) => {
    AV.safeDel(AV.KEY);
    AV.safeDel(AV.DRAFT_KEY);
    if (!keepSession) {
      AV.safeDel(AV.SESSION_KEY);
      try { sessionStorage.removeItem(AV.SESSION_KEY); } catch {}
    }
    AV.safeSet(AV.WIPED_KEY, '1');
    AV.state = AV.buildEmpty();
    AV.saveNow();
  };

  AV.resetDemo = () => {
    AV.safeDel(AV.WIPED_KEY);
    AV.safeDel(AV.DRAFT_KEY);
    AV.state = AV.normalize(AV.buildDemo());
    AV.saveNow();
    if (AV.renderAll) AV.renderAll();
  };

  /* ---------- sesja ---------- */
  AV.isLoggedIn = () => {
    if (AV.api.online) return !!AV.api.authenticated;
    if (AV.safeGet(AV.SESSION_KEY)) return true;
    try { return !!sessionStorage.getItem(AV.SESSION_KEY); } catch { return false; }
  };
  AV.login = (remember) => {
    const payload = JSON.stringify({ at: Date.now(), remember: !!remember });
    try { sessionStorage.removeItem(AV.SESSION_KEY); } catch {}
    AV.safeDel(AV.SESSION_KEY);
    if (remember) AV.safeSet(AV.SESSION_KEY, payload);
    else { try { sessionStorage.setItem(AV.SESSION_KEY, payload); } catch {} }
  };
  AV.logout = () => {
    AV.safeDel(AV.SESSION_KEY);
    try { sessionStorage.removeItem(AV.SESSION_KEY); } catch {}
  };

  /* ---------- kapitał / konta ---------- */
  AV.fxToUsdt = (cur) => cur === 'EUR' ? 1.08 : cur === 'PLN' ? 0.25 : 1;
  AV.totalCapital = (state, includeAll = false) => {
    return state.accounts
      .filter((a) => includeAll || a.include)
      .reduce((sum, a) => sum + (Number(a.balance) || 0) * AV.fxToUsdt(a.currency), 0);
  };
  AV.startCapital = (state) => state.accounts
    .filter((a) => a.include)
    .reduce((sum, a) => sum + (Number(a.start) || 0) * AV.fxToUsdt(a.currency), 0);

  AV.includedAccounts = (state) => state.accounts.filter((a) => a.include).map((a) => a.name);
  AV.includedTrades = (state) => {
    const names = AV.includedAccounts(state);
    return state.trades.filter((t) => !t.account || names.includes(t.account));
  };
  AV.closedTrades = (state) => AV.includedTrades(state).filter((t) => t.result != null);
  AV.openTrades = (state) => AV.includedTrades(state).filter((t) => t.result == null);

  AV.rangeTrades = (state, days) => {
    const all = AV.includedTrades(state).filter((t) => t.result != null);
    if (!days || days === 'all') return all;
    const from = AV.startOfDay(AV.addDays(new Date(), -days + 1));
    return all.filter((t) => new Date(t.date) >= from);
  };

  /* ---------- statystyki ---------- */
  AV.computeStats = (trades, state) => {
    const n = trades.length;
    const wins = trades.filter((t) => t.result > 0);
    const losses = trades.filter((t) => t.result < 0);
    const net = trades.reduce((s, t) => s + (Number(t.result) || 0), 0);
    const gp = wins.reduce((s, t) => s + t.result, 0);
    const gl = Math.abs(losses.reduce((s, t) => s + t.result, 0));
    const rs = trades.filter((t) => t.r != null);
    const expR = rs.length ? rs.reduce((s, t) => s + t.r, 0) / rs.length : 0;
    const avgWin = wins.length ? gp / wins.length : 0;
    const avgLoss = losses.length ? -gl / losses.length : 0;
    const start = AV.startCapital(state) || 2000;
    let eq = start, peak = start, maxDD = 0, maxDDpct = 0;
    const curve = [{ x: 0, eq: start, date: null }];
    trades.slice().sort((a, b) => new Date(a.date) - new Date(b.date)).forEach((t, i) => {
      eq += t.result;
      peak = Math.max(peak, eq);
      const dd = eq - peak;
      if (dd < maxDD) maxDD = dd;
      if (peak > 0) maxDDpct = Math.min(maxDDpct, dd / peak * 100);
      curve.push({ x: i + 1, eq, date: t.date });
    });
    const byGrade = AV.GRADES.map((g) => {
      const arr = trades.filter((t) => t.grade === g);
      const w = arr.filter((t) => t.result > 0).length;
      return { grade: g, n: arr.length, wr: arr.length ? w / arr.length * 100 : 0, net: arr.reduce((s, t) => s + t.result, 0) };
    });
    const group = (key) => {
      const map = {};
      trades.forEach((t) => {
        const k = t[key] || '—';
        map[k] = map[k] || { key: k, n: 0, net: 0, wins: 0 };
        map[k].n++;
        map[k].net += t.result;
        if (t.result > 0) map[k].wins++;
      });
      return Object.values(map).sort((a, b) => b.net - a.net);
    };
    const full = trades.filter((t) => t.checklist >= (t.checkTotal || 7));
    const part = trades.filter((t) => t.checklist < (t.checkTotal || 7));
    const slTrades = trades.filter((t) => t.sl && t.sl !== 'brak');
    const planTrades = trades.filter((t) => t.plan);
    const errMap = {};
    trades.forEach((t) => {
      (t.errors || []).forEach((e) => {
        errMap[e] = errMap[e] || { key: e, n: 0, cost: 0 };
        errMap[e].n++;
        if (e === 'Za wczesne wyjście' && t.result > 0 && t.r != null) {
          const risk = Math.abs(t.result / t.r);
          errMap[e].cost += t.result - t.mfe * risk;
        } else {
          errMap[e].cost += t.result;
        }
      });
    });
    const errors = Object.values(errMap).sort((a, b) => a.cost - b.cost);
    const avg = (arr, f) => arr.length ? arr.reduce((s, x) => s + f(x), 0) / arr.length : 0;
    return {
      n, wins: wins.length, losses: losses.length, winRate: n ? wins.length / n * 100 : 0,
      net, gp, gl, pf: gl ? gp / gl : gp ? 99 : 0, expR, avgWin, avgLoss,
      maxDD, maxDDpct, curve, byGrade, bySession: group('session'), byEmotion: group('emotion'),
      bySetup: group('setup'), errors,
      withSL: n ? slTrades.length / n * 100 : 0,
      withSLCount: slTrades.length,
      planRate: n ? planTrades.length / n * 100 : 0,
      planCount: planTrades.length,
      fees: trades.reduce((s, t) => s + (Number(t.fee) || 0), 0),
      full: { n: full.length, wr: full.length ? full.filter((t) => t.result > 0).length / full.length * 100 : 0, net: full.reduce((s, t) => s + t.result, 0) },
      part: { n: part.length, wr: part.length ? part.filter((t) => t.result > 0).length / part.length * 100 : 0, net: part.reduce((s, t) => s + t.result, 0) },
      avgMaeWin: avg(wins, (t) => t.mae), avgMfeWin: avg(wins, (t) => t.mfe), avgExitR: avg(wins, (t) => t.r),
      avgDurWin: avg(wins, (t) => t.durationMin), avgDurLoss: avg(losses, (t) => t.durationMin),
      shiftedSL: trades.filter((t) => (t.errors || []).includes('Przesunięty SL')).length,
      daily: AV.dailyMap(trades)
    };
  };

  AV.dailyMap = (trades) => {
    const map = {};
    trades.forEach((t) => {
      const k = AV.dateKey(new Date(t.date));
      map[k] = (map[k] || 0) + (Number(t.result) || 0);
    });
    return map;
  };

  AV.streak = (state) => {
    const days = new Set(AV.includedTrades(state).map((t) => AV.dateKey(new Date(t.date))));
    let d = AV.startOfDay(new Date()), n = 0;
    while (days.has(AV.dateKey(d)) && n < 400) { n++; d = AV.addDays(d, -1); }
    return n;
  };

  AV.todayTrades = (state) => AV.includedTrades(state).filter((t) => AV.dateKey(new Date(t.date)) === AV.dateKey(new Date()));
  AV.todayStats = (state) => {
    const arr = AV.todayTrades(state);
    const closed = arr.filter((t) => t.result != null);
    const pnl = closed.reduce((s, t) => s + t.result, 0);
    const losses = closed.filter((t) => t.result < 0).length;
    const risk = arr.reduce((s, t) => {
      if (t.result != null && t.r) return s + Math.abs(t.result / t.r);
      const entry = AV.pnum(t.entry), sl = AV.pnum(t.sl);
      const size = AV.pnum(t.size);
      return s + (sl ? Math.abs(entry - sl) * size : 0);
    }, 0);
    return { arr, closed, pnl, losses, count: arr.length, risk };
  };

  /* ---------- tryb ochronny ---------- */
  AV.lockInfo = (state) => {
    const today = AV.todayStats(state);
    const set = state.settings;
    const limit = Math.max(1, Number(set.maxLosses) || 2);
    const dailyLimit = Math.max(0.1, Number(set.maxDailyLoss) || 3);
    const capital = AV.totalCapital(state) || 1;
    const lossPct = Math.max(0, -Math.min(0, today.pnl)) / capital * 100;
    const triggered = set.safetyEnabled && (today.losses >= limit || lossPct >= dailyLimit);
    let until = null;
    if (triggered) {
      const lastLoss = today.closed.filter((t) => t.result < 0).sort((a, b) => new Date(b.date) - new Date(a.date))[0];
      const base = lastLoss ? new Date(lastLoss.date) : new Date();
      if (set.block === '1h') until = new Date(base.getTime() + 3600e3);
      else if (set.block === '4h') until = new Date(base.getTime() + 4 * 3600e3);
      else { until = AV.startOfDay(AV.addDays(new Date(), 1)); }
      if (until <= new Date()) until = AV.startOfDay(AV.addDays(new Date(), 1));
    }
    const override = (state.overrides || []).filter((o) => o.date === AV.dateKey(new Date())).sort((a, b) => b.at - a.at)[0];
    const overridden = !!(override && (!triggered || override.at >= (until ? until.getTime() - 24 * 3600e3 : 0)));
    return {
      active: triggered && !overridden,
      triggered, overridden, until, override,
      losses: today.losses, limit, lossPct, dailyLimit,
      reason: triggered ? (today.losses >= limit ? 'limit strat' : 'limit straty dziennej') : null
    };
  };

  AV.addOverride = (reason) => {
    AV.state.overrides = AV.state.overrides || [];
    AV.state.overrides.push({ date: AV.dateKey(new Date()), at: Date.now(), reason: reason || 'bez podania powodu' });
    AV.saveNow();
  };

  /* ---------- forma ---------- */
  AV.readiness = (state) => {
    const h = state.health;
    const check = (state.checkins || []).filter((c) => c.date === AV.dateKey(new Date())).sort((a, b) => b.score - a.score)[0];
    const moodMod = { 'Spokój': 10, 'Skupienie': 7, 'Pewność': 5, 'Nuda': -3, 'Zmęczenie': -8, 'Strach': -10, 'FOMO': -12, 'Złość': -14, 'Chciwość': -6 }[check?.mood] || 0;
    const checkScore = check ? AV.clamp(50 + ((Number(check.energy) || 3) - 3) * 10 + moodMod, 0, 100) : 55;
    const garmin = !!(state.settings.devices && state.settings.devices.garmin);
    const sleepScore = AV.clamp(h.sleepH / 8 * 100, 0, 100);
    const hrvScore = AV.clamp(h.hrv / (h.hrvBase || 52) * 100, 0, 100);
    const hrScore = AV.clamp((h.hrBase || 56) / h.hr * 100, 0, 100);
    const score = garmin
      ? Math.round(sleepScore * 0.35 + hrvScore * 0.2 + hrScore * 0.1 + checkScore * 0.35)
      : Math.round(checkScore);
    let title = 'Świetna forma', cls = 'win';
    if (score < 80) { title = 'Dobra forma'; cls = 'win'; }
    if (score < 65) { title = 'Ostrożnie dziś'; cls = 'warnc'; }
    if (score < 50) { title = 'Lepiej odpuścić'; cls = 'loss'; }
    const desc = garmin
      ? 'Spał[aś|eś] ' + String(Math.floor(h.sleepH)) + ' h ' + String(Math.round((h.sleepH % 1) * 60)).padStart(2, '0') + ' min, HRV ' + h.hrv + ' ms, tętno ' + h.hr + '. ' + (score < 60 ? 'Połowa normalnej pozycji albo odpuść.' : 'Możesz grać normalnie, ale trzymaj się planu.')
      : 'Brak danych z zegarka — gotowość liczona tylko z check-inu. Podłącz Garmina, żeby dodać sen i HRV.';
    return { score, title, cls, desc, garmin, checkScore, sleepScore, hrvScore, hrScore, check };
  };

  /* ---------- podatki ---------- */
  AV.taxEvents = (state) => {
    return (state.flows || []).map((f) => {
      const pln = Number(f.fiat) * (Number(f.fx) || 1);
      return {
        id: f.id, date: f.date, op: (f.type === 'Wpłata' ? 'Zakup USDT za ' : 'Sprzedaż USDT za ') + (f.currency === 'PLN' ? 'PLN' : f.currency) + (f.op && f.op.includes('P2P') ? ' (P2P)' : ''),
        kind: f.tax, currency: f.currency, amount: Number(f.fiat), fx: Number(f.fx) || 1, pln
      };
    }).sort((a, b) => new Date(a.date) - new Date(b.date));
  };
  AV.taxData = (state) => {
    const ev = AV.taxEvents(state);
    const revenue = ev.filter((e) => e.kind === 'income').reduce((s, e) => s + e.pln, 0);
    const costs = ev.filter((e) => e.kind === 'cost').reduce((s, e) => s + e.pln, 0);
    const carry = Number(state.tax.carryLoss) || 0;
    const income = Math.max(0, revenue - costs - carry);
    const due = income * 0.19;
    return { ev, revenue, costs, carry, income, due };
  };

  /* ---------- mentor ---------- */
  AV.mentorData = (state) => {
    const closed = AV.closedTrades(state);
    const today = AV.todayStats(state);
    const lock = AV.lockInfo(state);
    const todayKey = AV.dateKey(new Date());
    const todayPlan = state.plans[todayKey];
    const todayPlanTrades = today.arr;
    const planOK = todayPlan ? todayPlanTrades.filter((t) => t.plan).length : 0;
    const afterLoss = [];
    closed.slice().sort((a, b) => new Date(a.date) - new Date(b.date)).forEach((t, i, arr) => {
      if (i === 0) return;
      const prev = arr[i - 1];
      const gap = (new Date(t.date) - new Date(prev.date)) / 60000;
      if (prev.result < 0 && gap >= 0 && gap <= 30) afterLoss.push(t);
    });
    const fomo = closed.filter((t) => t.emotion === 'FOMO');
    const c = closed.filter((t) => t.grade === 'C');
    const a = closed.filter((t) => t.grade === 'A');
    const byPair = {};
    closed.forEach((t) => { byPair[t.pair] = (byPair[t.pair] || 0) + t.result; });
    const pairSorted = Object.entries(byPair).sort((x, y) => x[1] - y[1]);
    const worstPair = pairSorted[0] || ['—', 0];
    const bestPair = pairSorted[pairSorted.length - 1] || ['—', 0];
    const hours = {};
    closed.forEach((t) => {
      const h = new Date(t.date).getHours();
      const bucket = h >= 21 || h < 5 ? 'po 21:00' : h < 9 ? 'przed 9:00' : h < 13 ? '9:00–13:00' : h < 18 ? '13:00–18:00' : '18:00–21:00';
      hours[bucket] = hours[bucket] || { n: 0, w: 0 };
      hours[bucket].n++;
      if (t.result > 0) hours[bucket].w++;
    });
    const worstHour = Object.entries(hours).sort((x, y) => (x[1].w / x[1].n) - (y[1].w / y[1].n))[0];
    const full = closed.filter((t) => t.checklist >= 7);
    const part = closed.filter((t) => t.checklist < 7);
    const events = AV.buildEvents();
    const next = events.filter((e) => e.imp === 3 && new Date(e.date) > new Date()).sort((a, b) => new Date(a.date) - new Date(b.date))[0];
    const macroDays = new Set(events.filter((e) => e.imp === 3).map((e) => AV.dateKey(new Date(e.date))));
    const macroTrades = closed.filter((t) => macroDays.has(AV.dateKey(new Date(t.date))));
    const macroNet = macroTrades.reduce((s, t) => s + t.result, 0);
    const r = AV.readiness(state);
    return {
      imie: state.user.name || 'Trader',
      straty: today.losses, limit: lock.limit, wynikDzis: AV.money(today.pnl, 2, ''),
      poStracie: afterLoss.filter((t) => t.result < 0).length + ' z ' + afterLoss.length,
      poStracieWynik: AV.money(afterLoss.reduce((s, t) => s + t.result, 0), 2, ''),
      fomoWr: fomo.length ? Math.round(fomo.filter((t) => t.result > 0).length / fomo.length * 100) : 0,
      fomoN: fomo.length, fomoWynik: AV.money(fomo.reduce((s, t) => s + t.result, 0), 2, ''),
      wr: closed.length ? Math.round(closed.filter((t) => t.result > 0).length / closed.length * 100) : 0,
      para: worstPair[0], paraWynik: AV.money(worstPair[1], 2, ''),
      dobraPara: bestPair[0], dobraWynik: AV.money(bestPair[1], 2, ''),
      godz: worstHour ? worstHour[0] : 'po 21:00', godzWr: worstHour ? Math.round(worstHour[1].w / worstHour[1].n * 100) : 0,
      cWynik: AV.money(c.reduce((s, t) => s + t.result, 0), 2, ''), cN: c.length,
      aWr: a.length ? Math.round(a.filter((t) => t.result > 0).length / a.length * 100) : 0,
      planZgodne: planOK + ' z ' + todayPlanTrades.length,
      gotowosc: r.score, sen: Math.floor(state.health.sleepH) + ' h ' + String(Math.round((state.health.sleepH % 1) * 60)).padStart(2, '0') + ' min',
      clFull: full.length ? Math.round(full.filter((t) => t.result > 0).length / full.length * 100) : 0,
      clNo: part.length ? Math.round(part.filter((t) => t.result > 0).length / part.length * 100) : 0,
      tr: today.count, event: next ? next.name : 'brak ważnych publikacji',
      eventTime: next ? AV.timeFmt(next.date, state) : '—',
      eventWhen: next ? AV.eventStatus(next.date)[1] : 'do końca tygodnia',
      macroWynik: AV.money(macroNet, 2, ''), macroN: macroTrades.length,
      kapital: AV.fmt(AV.totalCapital(state), 2), ryzyko1: AV.fmt(AV.totalCapital(state) * (Number(state.settings.maxRisk) || 1) / 100, 2)
    };
  };

  AV.genderize = (text, g) => String(text).replace(/\[([^|\]]*)\|([^\]]*)\]/g, (_, f, m) => g === 'm' ? m : f);
  AV.fill = (text, state, extra = {}) => {
    const d = { ...AV.mentorData(state), ...extra };
    return AV.genderize(text, state.user.gender).replace(/\{(\w+)\}/g, (_, k) => d[k] != null ? d[k] : '');
  };

  /* ---------- playbook ---------- */
  AV.setupStats = (state, name) => {
    const trades = AV.closedTrades(state).filter((t) => t.setup === name);
    const wins = trades.filter((t) => t.result > 0);
    const net = trades.reduce((s, t) => s + t.result, 0);
    const avgR = trades.filter((t) => t.r != null).length
      ? trades.filter((t) => t.r != null).reduce((s, t) => s + t.r, 0) / trades.filter((t) => t.r != null).length : 0;
    return { n: trades.length, wr: trades.length ? wins.length / trades.length * 100 : 0, net, avgR };
  };

  /* ---------- badge ---------- */
  AV.badges = (state) => {
    const closed = AV.closedTrades(state);
    const plans = Object.values(state.plans);
    const approved = plans.filter((p) => p.approvedAt).length;
    const days = new Set(AV.includedTrades(state).map((t) => AV.dateKey(new Date(t.date)))).size;
    const sl = closed.filter((t) => t.sl && t.sl !== 'brak').length;
    const aTrades = closed.filter((t) => t.grade === 'A').length;
    const fomoFree = (() => {
      let n = 0, d = AV.startOfDay(new Date());
      for (let i = 0; i < 10; i++) {
        const k = AV.dateKey(d);
        const day = closed.filter((t) => AV.dateKey(new Date(t.date)) === k);
        if (day.length && !day.some((t) => t.emotion === 'FOMO')) n++;
        d = AV.addDays(d, -1);
      }
      return n;
    })();
    const cFree = (() => {
      const now = new Date();
      const month = closed.filter((t) => new Date(t.date).getMonth() === now.getMonth() && new Date(t.date).getFullYear() === now.getFullYear());
      const net = month.reduce((s, t) => s + t.result, 0);
      return { net, hasC: month.some((t) => t.grade === 'C') };
    })();
    return [
      { icon: 'plan', name: 'Pierwszy plan dnia', done: approved > 0, hint: approved > 0 ? AV.dateShort(new Date(plans.find((p) => p.approvedAt)?.approvedAt || Date.now())) : '0 / 1' },
      { icon: 'book', name: '7 dni z dziennikiem', done: days >= 7, hint: 'teraz: ' + days + ' dni', progress: AV.clamp(days / 7 * 100, 0, 100) },
      { icon: 'shield', name: '50 × ze stop lossem', done: sl >= 50, hint: sl + ' / 50', progress: AV.clamp(sl / 50 * 100, 0, 100) },
      { icon: 'check', name: 'Tydzień bez złamania zasad', done: plans.filter((p) => p.approvedAt).length >= 4, hint: approved + ' planów' },
      { icon: 'plan', name: '30 planów dnia', done: approved >= 30, hint: approved + ' / 30', progress: AV.clamp(approved / 30 * 100, 0, 100) },
      { icon: 'star', name: '100 transakcji A', done: aTrades >= 100, hint: aTrades + ' / 100', progress: AV.clamp(aTrades / 100 * 100, 0, 100) },
      { icon: 'pulse', name: '10 dni bez FOMO', done: fomoFree >= 10, hint: fomoFree + ' / 10', progress: fomoFree * 10 },
      { icon: 'stats', name: 'Miesiąc na plusie bez C', done: cFree.net > 0 && !cFree.hasC, hint: cFree.net > 0 && !cFree.hasC ? 'zaliczone' : 'w trakcie', progress: cFree.hasC ? 15 : 60 }
    ];
  };

  /* ---------- plan dnia ---------- */
  AV.planTemplate = (dateKey) => ({
    date: dateKey, market: 'BTC', bias: 'long', why: '',
    levels: [{ type: 'Opór', price: '', note: '' }],
    ifMain: '', thenMain: '', ifAlt: '', thenAlt: '',
    risk: '3%', maxTrades: '5', until: '21:00', notDo: '',
    approvedAt: null
  });
  AV.planAdherence = (state) => {
    const days = [];
    let approved = 0;
    for (let i = 1; i <= 5; i++) {
      const d = AV.addDays(new Date(), -i);
      const p = state.plans[AV.dateKey(d)];
      const ok = !!(p && p.approvedAt);
      if (ok) approved++;
      days.push({ d, ok });
    }
    return { approved, total: 5, days: days.reverse() };
  };
})();
