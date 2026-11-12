/* Alpha Vault — widok Prop challenge: render, edytor, szablony, alarmy. */
(function () {
  'use strict';
  const AV = window.AV;
  if (!AV || !AV.prop) return;
  const $ = AV.$, $$ = AV.$$;
  const esc = AV.esc;

  const STATUS = {
    active: { label: 'Challenge aktywny', short: 'AKTYWNY', tag: 'info', color: 'var(--cream)', desc: 'Liczysz się do celu. Alpha Vault pilnuje limitów na bieżąco.' },
    target: { label: 'Cel osiągnięty', short: 'CEL', tag: 'warn', color: 'var(--warn)', desc: 'Cel zysku jest osiągnięty. Dokończ wymagane dni handlowe, zanim zamkniesz challenge.' },
    passed: { label: 'Challenge zaliczony', short: 'ZALICZONY', tag: 'praise', color: 'var(--win)', desc: 'Cel i minimalna liczba dni handlowych są spełnione. Możesz przechodzić do kolejnej fazy.' },
    failed: { label: 'Limit złamany', short: 'LIMIT', tag: 'alert', color: 'var(--loss)', desc: 'Challenge został zamknięty przez przekroczenie limitu. Zapisz wniosek na przyszłość.' },
    expired: { label: 'Czas minął', short: 'CZAS', tag: 'warn', color: 'var(--warn)', desc: 'Termin upłynął, a cel nie został osiągnięty. Sprawdź zasady przedłużenia u firmy.' }
  };

  const money = (n, cur, d = 2) => AV.fmt(n, d) + ' ' + cur;
  const signed = (n, cur, d = 2) => AV.money(n, d, cur);
  const pct = (n, d = 1) => AV.fmt(n, d) + '%';
  const bar = (value, color) => '<div class="bar"><i style="width:' + AV.clamp(Number(value) || 0, 0, 100) + '%;background:' + color + '"></i></div>';
  const pluralDays = (n) => n === 1 ? 'dzień' : 'dni';
  const statusOf = (m) => STATUS[m.status] || STATUS.active;

  /* ---------- dane pomocnicze ---------- */
  AV.propChallenges = () => (AV.state && AV.state.prop && Array.isArray(AV.state.prop.challenges)) ? AV.state.prop.challenges : [];
  AV.propVisible = () => AV.propChallenges().filter((c) => c && !c.archived);
  AV.propAccountNames = () => (AV.state.accounts || []).map((a) => a.name);
  AV.propMetrics = (ch) => AV.prop.compute(ch, AV.state.trades || [], new Date(), AV.propAccountNames());
  AV.propAllMetrics = () => AV.prop.portfolio(AV.propChallenges(), AV.state.trades || [], new Date(), AV.propAccountNames()).metrics;
  AV.propSelected = () => {
    const list = AV._propShowArchived ? AV.propChallenges() : AV.propVisible();
    const id = AV.state.prop && AV.state.prop.selectedId;
    return list.find((c) => c.id === id) || list[0] || null;
  };
  AV.propSetSelected = (id) => {
    if (!AV.state.prop) AV.state.prop = { challenges: [], selectedId: null };
    AV.state.prop.selectedId = id;
    AV.save();
  };

  /* ---------- render główny ---------- */
  AV.renderProp = () => {
    if (!AV.state) return;
    const all = AV.propChallenges();
    const list = AV._propShowArchived ? all : all.filter((c) => !c.archived);
    const selected = AV.propSelected();
    const empty = $('#propEmpty');
    const body = $('#propBody');
    const select = $('#propSelect');
    const nav = $('#propNavBadge');
    const archToggle = $('#propArchToggle');
    const toolbar = $('#propToolbar');
    if (toolbar) toolbar.style.display = all.length ? '' : 'none';

    if (archToggle) {
      archToggle.classList.toggle('on', !!AV._propShowArchived);
      const archivedCount = all.filter((c) => c.archived).length;
      archToggle.textContent = archivedCount ? 'Archiwum · ' + archivedCount : 'Archiwum';
      archToggle.style.display = (archivedCount || AV._propShowArchived) ? '' : 'none';
    }
    if (nav) {
      const activeCount = AV.propAllMetrics().filter((m) => m.status === 'active' || m.status === 'target').length;
      nav.textContent = String(activeCount);
      nav.style.display = activeCount ? '' : 'none';
    }
    const visible = all.filter((c) => !c.archived);
    AV.setText('#propHeaderTag', visible.length ? visible.length + ' ' + AV.plural(visible.length, 'challenge', 'challenge\'e', 'challenge\'ów') : 'brak challenge\'u');

    if (select) {
      select.disabled = !list.length;
      select.innerHTML = list.length
        ? list.map((c) => '<option value="' + esc(c.id) + '"' + (selected && c.id === selected.id ? ' selected' : '') + '>' + esc(c.name) + (c.archived ? ' · archiwum' : '') + '</option>').join('')
        : '<option>Brak challenge\'u</option>';
    }

    if (!list.length || !selected) {
      if (empty) empty.style.display = '';
      if (body) body.style.display = 'none';
      return;
    }
    if (empty) empty.style.display = 'none';
    if (body) body.style.display = '';

    const m = AV.propMetrics(selected);
    AV.renderPropAlerts(m);
    AV.renderPropKpis(m);
    AV.renderPropLimits(m);
    AV.renderPropCurve(m);
    AV.renderPropDaily(m);
    AV.renderPropStatus(m);
    AV.renderPropStats(m);
    const archiveBtn = $('[data-prop-act="archive"]');
    if (archiveBtn) archiveBtn.textContent = selected.archived ? 'Przywróć' : 'Archiwizuj';
  };

  AV.renderPropAlerts = (m) => {
    AV.setHTML('#propAlerts', m.alerts.map((a) => {
      const style = a.level === 'danger'
        ? { border: 'rgba(255,93,98,.32)', bg: 'linear-gradient(90deg,rgba(255,93,98,.13),rgba(255,93,98,.02))', ic: 'var(--lossS)', ink: 'var(--loss)', icon: 'shield' }
        : a.level === 'warn'
          ? { border: 'rgba(255,171,64,.3)', bg: 'linear-gradient(90deg,rgba(255,171,64,.11),rgba(255,171,64,.02))', ic: 'var(--warnS)', ink: 'var(--warn)', icon: 'bell' }
          : a.level === 'info'
            ? { border: 'rgba(var(--a),.34)', bg: 'linear-gradient(90deg,rgba(var(--a),.1),rgba(var(--a),.02))', ic: 'var(--alphaS)', ink: 'var(--alpha)', icon: 'flag' }
            : { border: 'rgba(79,207,138,.3)', bg: 'linear-gradient(90deg,rgba(79,207,138,.1),rgba(79,207,138,.02))', ic: 'var(--winS)', ink: 'var(--win)', icon: 'check' };
      return '<div class="card" style="border-color:' + style.border + ';background:' + style.bg + ';padding:14px 18px;display:flex;gap:14px;align-items:center;margin-bottom:10px">' +
        '<div style="width:38px;height:38px;border-radius:11px;background:' + style.ic + ';color:' + style.ink + ';display:grid;place-items:center;flex-shrink:0">' + AV.ic(style.icon) + '</div>' +
        '<div style="flex:1;min-width:0"><b style="font-size:14.5px">' + esc(a.title) + '</b><div class="mut" style="font-size:13px;line-height:1.45;margin-top:2px">' + esc(a.text) + '</div></div>' +
        '<span class="tag ' + (a.level === 'danger' ? 'alert' : a.level === 'warn' ? 'warn' : a.level === 'info' ? 'al' : 'praise') + '">' + (a.level === 'danger' ? 'ALARM' : a.level === 'warn' ? 'UWAGA' : a.level === 'info' ? 'INFO' : 'OK') + '</span></div>';
    }).join(''));
  };

  AV.renderPropKpis = (m) => {
    const cur = m.currency;
    const targetCls = m.targetReached ? 'win' : '';
    const dailyCls = m.dailyLossPct <= 0 ? 'mut' : m.dailyUsedPct >= 90 ? 'loss' : m.dailyUsedPct >= 75 ? 'warnc' : '';
    const ddCls = m.drawdownUsedPct >= 90 ? 'loss' : m.drawdownUsedPct >= 75 ? 'warnc' : '';
    AV.setHTML('#propKpis',
      '<div class="card"><div class="lbl">Cel zysku</div><div class="val ' + targetCls + '" style="font-size:22px">' + signed(m.profitAmount, cur) + ' / ' + money(m.targetAmount, cur) + '</div>' +
      bar(m.targetProgressPct, 'var(--win)') +
      '<div class="hint">' + (m.targetReached ? 'Cel osiągnięty ✓' : pct(m.targetProgressPct) + ' celu · brakuje ' + money(m.targetRemaining, cur)) + '</div></div>' +

      '<div class="card"><div class="lbl">Dzienny limit straty</div>' +
      (m.dailyLossPct > 0
        ? '<div class="val ' + dailyCls + '" style="font-size:22px">' + money(m.dailyLossAmount, cur) + ' / ' + money(m.dailyLimitAmount, cur) + '</div>' + bar(m.dailyUsedPct, dailyCls === 'loss' ? 'var(--loss)' : dailyCls === 'warnc' ? 'var(--warn)' : 'var(--cream)') + '<div class="hint">' + (m.dailyUsedPct >= 100 ? 'Limit przekroczony' : pct(m.dailyUsedPct) + ' limitu · bufor ' + money(m.dailyBuffer, cur)) + '</div>'
        : '<div class="val mut" style="font-size:22px">brak limitu</div><div class="hint">Firma nie limituje dziennej straty.</div>') + '</div>' +

      '<div class="card"><div class="lbl">Max obsunięcie</div><div class="val ' + ddCls + '" style="font-size:22px">' + money(m.maxDrawdownAmount, cur) + ' / ' + money(m.maxLossAmount, cur) + '</div>' +
      bar(m.drawdownUsedPct, ddCls === 'loss' ? 'var(--loss)' : ddCls === 'warnc' ? 'var(--warn)' : 'var(--cream)') +
      '<div class="hint">' + pct(m.drawdownUsedPct) + ' · bufor ' + money(m.drawdownBuffer, cur) + ' · ' + (m.challenge.drawdownType === 'trailing' ? 'trailing' : 'statyczne') + '</div></div>' +

      '<div class="card"><div class="lbl">Dni do końca</div>' +
      (m.hasDeadline
        ? '<div class="val ' + (m.daysLeft <= 3 ? 'loss' : '') + '">' + m.daysLeft + ' <span class="mut" style="font-size:14px;font-weight:500">' + pluralDays(m.daysLeft) + '</span></div><div class="hint">min. ' + m.minDays + ' dni handlowych · masz ' + m.tradingDays + '</div>'
        : '<div class="val" style="font-size:24px">bez terminu</div><div class="hint">Firma nie limituje czasu. Horyzont ustawiasz sam.</div>') + '</div>'
    );
  };

  AV.renderPropLimits = (m) => {
    const cur = m.currency;
    const rows = [];
    rows.push('<div style="margin-top:4px"><div style="display:flex;justify-content:space-between;gap:10px;font-size:13.5px"><span class="mut">Cel zysku</span><b>' + signed(m.profitAmount, cur) + ' / ' + money(m.targetAmount, cur) + '</b></div>' + bar(m.targetProgressPct, 'var(--win)') + '<div class="hint">' + (m.targetReached ? 'Cel osiągnięty' : 'Zostało ' + money(m.targetRemaining, cur)) + '</div></div>');
    if (m.dailyLossPct > 0) {
      rows.push('<div style="margin-top:16px"><div style="display:flex;justify-content:space-between;gap:10px;font-size:13.5px"><span class="mut">Dzienny limit straty</span><b>' + money(m.dailyLossAmount, cur) + ' / ' + money(m.dailyLimitAmount, cur) + '</b></div>' + bar(m.dailyUsedPct, m.dailyUsedPct >= 90 ? 'var(--loss)' : m.dailyUsedPct >= 75 ? 'var(--warn)' : 'var(--cream)') + '<div class="hint">Dziś: ' + signed(m.todayPnl, cur) + ' · podstawa: ' + (m.challenge.dailyBasis === 'dayStart' ? 'saldo z początku dnia' : 'saldo startowe') + '</div></div>');
    } else {
      rows.push('<div style="margin-top:16px"><div style="display:flex;justify-content:space-between;gap:10px;font-size:13.5px"><span class="mut">Dzienny limit straty</span><b class="dim">brak</b></div><div class="hint">Ta firma nie ma dziennego limitu.</div></div>');
    }
    rows.push('<div style="margin-top:16px"><div style="display:flex;justify-content:space-between;gap:10px;font-size:13.5px"><span class="mut">Max obsunięcie</span><b>' + money(m.maxDrawdownAmount, cur) + ' / ' + money(m.maxLossAmount, cur) + '</b></div>' + bar(m.drawdownUsedPct, m.drawdownUsedPct >= 90 ? 'var(--loss)' : m.drawdownUsedPct >= 75 ? 'var(--warn)' : 'var(--cream)') + '<div class="hint">' + (m.challenge.drawdownType === 'trailing' ? 'Trailing od szczytu kapitału' : 'Statyczne od salda startowego') + ' · linia stopu ' + money(m.drawdownFloor, cur) + '</div></div>');
    AV.setHTML('#propLimits', rows.join(''));
  };

  AV.renderPropCurve = (m) => {
    const cur = m.currency;
    const svg = $('#propCurve');
    if (!svg) return;
    const points = m.curve || [];
    const w = 600, h = 190, padX = 10, padY = 18;
    const targetEq = m.startBalance + m.targetAmount;
    const floorEq = m.drawdownFloor;
    const values = points.map((p) => p.eq).concat([targetEq, floorEq]);
    let min = Math.min(...values);
    let max = Math.max(...values);
    if (!Number.isFinite(min) || !Number.isFinite(max)) { min = m.startBalance; max = m.startBalance + 1; }
    if (max - min < 1) { max += 1; min -= 1; }
    const span = max - min;
    const x = (i) => padX + (points.length <= 1 ? 0 : (i / (points.length - 1)) * (w - padX * 2));
    const y = (v) => padY + (1 - (v - min) / span) * (h - padY * 2);
    const line = points.map((p, i) => (i ? 'L' : 'M') + x(i).toFixed(1) + ',' + y(p.eq).toFixed(1)).join(' ');
    const area = points.length > 1 ? line + ' L' + x(points.length - 1).toFixed(1) + ',' + (h - padY) + ' L' + x(0).toFixed(1) + ',' + (h - padY) + ' Z' : '';
    const targetY = y(targetEq);
    const floorY = y(floorEq);
    svg.setAttribute('viewBox', '0 0 ' + w + ' ' + h);
    svg.innerHTML = '<defs><linearGradient id="propGrad" x1="0" y1="0" x2="0" y2="1"><stop offset="0" style="stop-color:var(--alpha)" stop-opacity=".32"/><stop offset="1" style="stop-color:var(--alpha)" stop-opacity="0"/></linearGradient></defs>' +
      '<line x1="' + padX + '" y1="' + targetY.toFixed(1) + '" x2="' + (w - padX) + '" y2="' + targetY.toFixed(1) + '" stroke="var(--win)" stroke-width="1" stroke-dasharray="5 5" opacity=".55"/>' +
      '<line x1="' + padX + '" y1="' + floorY.toFixed(1) + '" x2="' + (w - padX) + '" y2="' + floorY.toFixed(1) + '" stroke="var(--loss)" stroke-width="1" stroke-dasharray="5 5" opacity=".55"/>' +
      (area ? '<path d="' + area + '" fill="url(#propGrad)"/>' : '') +
      (points.length > 1 ? '<path d="' + line + '" fill="none" stroke="var(--alpha)" stroke-width="2.2" stroke-linejoin="round"/>' : '') +
      '<circle cx="' + x(points.length - 1).toFixed(1) + '" cy="' + y(points[points.length - 1].eq).toFixed(1) + '" r="4" fill="var(--alpha)"/>';
    AV.setHTML('#propCurveMeta',
      '<span>Start: <b>' + money(m.startBalance, cur) + '</b></span>' +
      '<span>Teraz: <b class="' + (m.profitAmount >= 0 ? 'win' : 'loss') + '">' + money(m.balance, cur) + '</b></span>' +
      '<span>Szczyt: <b>' + money(m.peak, cur) + '</b></span>' +
      '<span>Min: <b>' + money(m.minEquity, cur) + '</b></span>' +
      '<span class="mut">cel ' + money(targetEq, cur) + ' · stop ' + money(floorEq, cur) + '</span>');
  };

  AV.renderPropDaily = (m) => {
    const cur = m.currency;
    const rows = (m.dailyRows || []).slice(-10).reverse();
    if (!rows.length) {
      AV.setHTML('#propDaily', '<div class="dim" style="text-align:center;padding:22px">Brak zamkniętych transakcji w okresie challenge\'u.</div>');
      return;
    }
    AV.setHTML('#propDaily', '<div style="overflow-x:auto"><table><thead><tr><th>Dzień</th><th>Transakcje</th><th style="text-align:right">Wynik dnia</th><th style="text-align:right">Kapitał</th></tr></thead><tbody>' +
      rows.map((r) => '<tr style="cursor:default"><td class="mono dim">' + esc(r.date) + '</td><td>' + r.n + ' <span class="dim">· ' + r.wins + ' W</span></td><td class="mono ' + (r.pnl > 0 ? 'win' : r.pnl < 0 ? 'loss' : '') + '" style="text-align:right">' + signed(r.pnl, cur) + '</td><td class="mono" style="text-align:right">' + money(r.equity, cur) + '</td></tr>').join('') +
      '</tbody></table></div>');
  };

  AV.renderPropStatus = (m) => {
    const st = statusOf(m);
    const cur = m.currency;
    AV.setText('#propStatus', st.label);
    const statusEl = $('#propStatus');
    if (statusEl) statusEl.style.color = st.color;
    AV.setText('#propStatusDesc', st.desc);
    AV.setHTML('#propStatusTag', '<span class="tag ' + st.tag + '">' + st.short + '</span>');
    const rows = [
      ['Cel zysku', m.targetReached ? '✓ ' + money(m.targetAmount, cur) : pct(m.targetProgressPct) + ' z ' + money(m.targetAmount, cur), m.targetReached ? 'win' : ''],
      ['Dzienny limit', m.dailyLossPct <= 0 ? 'brak limitu' : pct(m.dailyUsedPct) + ' z ' + money(m.dailyLimitAmount, cur), m.dailyLossPct <= 0 ? '' : m.dailyUsedPct >= 100 ? 'loss' : m.dailyUsedPct >= 75 ? 'warnc' : 'win'],
      ['Max obsunięcie', pct(m.drawdownUsedPct) + ' z ' + money(m.maxLossAmount, cur), m.drawdownUsedPct >= 100 ? 'loss' : m.drawdownUsedPct >= 75 ? 'warnc' : 'win'],
      ['Min. dni handlowe', m.tradingDays + ' z ' + m.minDays, m.minDaysMet ? 'win' : 'warnc'],
      ['Termin', m.hasDeadline ? (m.expired ? 'minął' : m.daysLeft + ' ' + pluralDays(m.daysLeft)) : 'bez limitu', m.expired ? 'loss' : m.hasDeadline && m.daysLeft <= 3 ? 'warnc' : 'win']
    ];
    if (m.accountMissing) rows.push(['Konto', 'nie ma na liście kont', 'loss']);
    AV.setHTML('#propChecklist', rows.map((r) => '<div style="display:flex;justify-content:space-between;gap:10px;padding:8px 0;border-bottom:1px solid var(--line);font-size:13px"><span class="mut">' + esc(r[0]) + '</span><b class="' + r[2] + '" style="text-align:right">' + esc(r[1]) + '</b></div>').join(''));
  };

  AV.renderPropStats = (m) => {
    const cur = m.currency;
    const row = (label, value, cls = '') => '<div style="display:flex;justify-content:space-between;gap:10px;padding:7px 0;border-bottom:1px solid var(--line);font-size:13px"><span class="mut">' + esc(label) + '</span><b class="' + cls + '">' + value + '</b></div>';
    const best = m.bestDay ? signed(m.bestDay.pnl, cur) + ' <span class="dim">' + esc(m.bestDay.date.slice(5)) + '</span>' : '—';
    const worst = m.worstDay ? signed(m.worstDay.pnl, cur) + ' <span class="dim">' + esc(m.worstDay.date.slice(5)) + '</span>' : '—';
    AV.setHTML('#propStats',
      row('Transakcje', String(m.n)) +
      row('Skuteczność', pct(m.winRate), m.winRate >= 50 ? 'win' : '') +
      row('Profit factor', m.pf >= 99 ? '∞' : AV.fmt(m.pf, 2), m.pf >= 1.5 ? 'win' : m.pf && m.pf < 1 ? 'loss' : '') +
      row('Średni R', m.avgR ? AV.signed(m.avgR, 2) : '—', m.avgR > 0 ? 'win' : m.avgR < 0 ? 'loss' : '') +
      row('Najlepszy dzień', best, 'win') +
      row('Najgorszy dzień', worst, 'loss') +
      row('Max seria strat', String(m.maxConsecutiveLosses), m.maxConsecutiveLosses >= 3 ? 'loss' : '') +
      row('Dni handlowe', m.tradingDays + (m.minDays ? ' / ' + m.minDays : ''), m.minDaysMet ? 'win' : ''));
  };

  /* ---------- dashboard ---------- */
  AV.renderPropDashboard = () => {
    const el = $('#propDash');
    if (!el || !AV.state) return;
    const metrics = AV.propAllMetrics();
    if (!metrics.length) { el.style.display = 'none'; return; }
    let chosen = metrics.find((m) => m.alerts.some((a) => a.level === 'danger'));
    if (!chosen) chosen = metrics.find((m) => m.alerts.some((a) => a.level === 'warn'));
    if (!chosen) chosen = metrics.find((m) => m.status === 'passed');
    if (!chosen) { el.style.display = 'none'; return; }
    const alert = chosen.alerts.find((a) => a.level === 'danger') || chosen.alerts.find((a) => a.level === 'warn') || chosen.alerts[0];
    const level = alert.level;
    const palette = level === 'danger'
      ? { border: 'rgba(255,93,98,.32)', bg: 'linear-gradient(90deg,rgba(255,93,98,.12),rgba(255,93,98,.02))', ic: 'var(--lossS)', ink: 'var(--loss)' }
      : level === 'warn'
        ? { border: 'rgba(255,171,64,.3)', bg: 'linear-gradient(90deg,rgba(255,171,64,.1),rgba(255,171,64,.02))', ic: 'var(--warnS)', ink: 'var(--warn)' }
        : { border: 'rgba(79,207,138,.3)', bg: 'linear-gradient(90deg,rgba(79,207,138,.1),rgba(79,207,138,.02))', ic: 'var(--winS)', ink: 'var(--win)' };
    el.style.display = '';
    el.style.borderColor = palette.border;
    el.style.background = palette.bg;
    const ic = $('#propDashIc');
    if (ic) { ic.style.background = palette.ic; ic.style.color = palette.ink; }
    AV.setText('#propDashTitle', alert.title);
    AV.setText('#propDashDesc', chosen.challenge.name + ' · ' + alert.text);
    AV.setText('#propDashVal', level === 'danger' || level === 'warn' ? pct(chosen.riskPct, 0) + ' limitu' : 'cel ✓');
  };

  /* ---------- empty state + szablony ---------- */
  AV.openPropTemplates = () => {
    const cards = AV.prop.TEMPLATES.map((t) => {
      const limits = t.dailyLossPct > 0
        ? 'cel ' + t.targetPct + '% · dzienny ' + t.dailyLossPct + '% · max ' + t.maxLossPct + '%'
        : 'cel ' + t.targetPct + '% · brak dziennego · max ' + t.maxLossPct + '%';
      return '<div class="card" style="padding:18px">' +
        '<div class="sech" style="margin:0"><div><div class="lbl">' + esc(t.firm || 'Własne') + '</div><b style="font-size:15px">' + esc(t.label) + '</b></div>' +
        '<span class="tag ' + (t.drawdownType === 'trailing' ? 'warn' : 'info') + '">' + (t.drawdownType === 'trailing' ? 'TRAILING' : 'STATIC') + '</span></div>' +
        '<div class="hint" style="margin-top:10px">' + limits + '<br>min. ' + t.minDays + ' dni · ' + (t.durationDays ? t.durationDays + ' dni' : 'bez terminu') + '</div>' +
        '<p class="mut" style="font-size:12.5px;line-height:1.45;margin-top:8px">' + esc(t.note) + '</p>' +
        '<button class="btn p sm" style="margin-top:12px" data-prop-template="' + esc(t.id) + '">Użyj szablonu</button></div>';
    }).join('');
    const body = AV.openModal('<div class="eyebrow">Prop challenge</div><h1 style="font-size:26px;margin-top:6px">Szablony zasad.</h1>' +
      '<p class="mut" style="margin-top:8px;line-height:1.55;font-size:13.5px">Punkt startowy dla popularnych firm. Limity zmieniają się — przed startem potwierdź je w aktualnym regulaminie firmy. Każde pole możesz edytować.</p>' +
      '<div class="grid g2" style="margin-top:16px">' + cards + '</div>' +
      '<div style="display:flex;justify-content:flex-end;margin-top:16px"><button class="btn g" data-gclose>Zamknij</button></div>',
      { maxWidth: '900px' });
    body.querySelectorAll('[data-prop-template]').forEach((btn) => {
      btn.onclick = () => { AV.closeModal(); AV.openPropEditor(null, btn.dataset.propTemplate); };
    });
  };

  /* ---------- edytor ---------- */
  AV.openPropEditor = (id, templateId) => {
    const editing = !!(id && AV.propChallenges().some((c) => c.id === id));
    const existing = editing ? AV.propChallenges().find((c) => c.id === id) : null;
    const tpl = AV.prop.templateById(templateId) || (existing ? AV.prop.templateById(existing.templateId) : null) || AV.prop.templateById('custom');
    const accounts = AV.state.accounts || [];
    const fallbackAccount = (accounts.find((a) => a.include) || accounts[0] || {}).name || '';
    const src = existing ? AV.clone(existing) : AV.prop.normalize({
      templateId: tpl.id,
      name: tpl.id === 'custom' ? '' : tpl.label,
      firm: tpl.firm,
      phase: tpl.phase,
      currency: tpl.currency,
      accountSize: tpl.accountSize,
      startBalance: tpl.accountSize,
      targetPct: tpl.targetPct,
      dailyLossPct: tpl.dailyLossPct,
      maxLossPct: tpl.maxLossPct,
      drawdownType: tpl.drawdownType,
      dailyBasis: tpl.dailyBasis,
      minDays: tpl.minDays,
      startDate: AV.dateKey(new Date()),
      endDate: tpl.durationDays ? AV.dateKey(AV.addDays(new Date(), tpl.durationDays)) : '',
      account: fallbackAccount
    });

    const option = (value, label, selected) => '<option value="' + esc(value) + '"' + (value === selected ? ' selected' : '') + '>' + esc(label) + '</option>';
    const accountOptions = accounts.map((a) => option(a.name, a.name + ' · ' + (a.type || a.exchange || '') , src.account)).join('');
    const hasAccountOption = accounts.some((a) => a.name === src.account);
    const extraAccount = src.account && !hasAccountOption ? option(src.account, src.account + ' · poza listą kont', src.account) : '';
    const templateOptions = AV.prop.TEMPLATES.map((t) => option(t.id, t.label, src.templateId)).join('');
    const curOptions = AV.prop.CURRENCIES.map((c) => option(c, c, src.currency)).join('');

    const body = AV.openModal('<div class="eyebrow">Prop challenge</div><h1 style="font-size:26px;margin-top:6px">' + (editing ? 'Edytuj challenge' : 'Nowy challenge') + '</h1>' +
      '<p class="mut" style="margin-top:6px;font-size:13px;line-height:1.5">Transakcje są przypisywane po koncie i dacie startu. Alpha Vault liczy limity z zamkniętych transakcji.</p>' +
      '<div class="grid g2" style="margin-top:18px">' +
      '<div style="grid-column:1/-1"><span class="label">Nazwa challenge\'u</span><input class="input" id="peName" value="' + esc(src.name) + '" placeholder="np. FTMO 10K · Phase 1" autocomplete="off"></div>' +
      '<div style="grid-column:1/-1"><span class="label">Szablon zasad</span><select class="input" id="peTemplate">' + templateOptions + '</select><div class="hint" id="peTemplateNote">' + esc(tpl.note) + '</div></div>' +
      '<div><span class="label">Firma</span><input class="input" id="peFirm" value="' + esc(src.firm) + '" placeholder="FTMO, Topstep…"></div>' +
      '<div><span class="label">Faza</span><input class="input" id="pePhase" value="' + esc(src.phase) + '" placeholder="Phase 1"></div>' +
      '<div><span class="label">Konto źródłowe</span><select class="input" id="peAccount">' + (accounts.length ? '<option value="">— wybierz konto —</option>' : '<option value="">Brak kont — dodaj w Ustawieniach</option>') + extraAccount + accountOptions + '</select></div>' +
      '<div><span class="label">Waluta</span><select class="input" id="peCurrency">' + curOptions + '</select></div>' +
      '<div><span class="label">Rozmiar konta</span><input class="input mono" id="peSize" value="' + esc(src.accountSize) + '" inputmode="decimal"></div>' +
      '<div><span class="label">Saldo startowe challenge\'u</span><input class="input mono" id="peStartBalance" value="' + esc(src.startBalance) + '" inputmode="decimal"></div>' +
      '<div><span class="label">Cel zysku (%)</span><input class="input mono" id="peTarget" value="' + esc(src.targetPct) + '" inputmode="decimal"></div>' +
      '<div><span class="label">Dzienny limit straty (%)</span><input class="input mono" id="peDaily" value="' + esc(src.dailyLossPct) + '" inputmode="decimal"></div>' +
      '<div><span class="label">Max obsunięcie (%)</span><input class="input mono" id="peMax" value="' + esc(src.maxLossPct) + '" inputmode="decimal"></div>' +
      '<div><span class="label">Typ obsunięcia</span><select class="input" id="peDrawdown">' + option('static', 'Statyczne — od salda startowego', src.drawdownType) + option('trailing', 'Trailing — od szczytu kapitału', src.drawdownType) + '</select></div>' +
      '<div><span class="label">Podstawa dziennego limitu</span><select class="input" id="peBasis">' + option('initial', 'Saldo startowe challenge\'u', src.dailyBasis) + option('dayStart', 'Saldo z początku dnia', src.dailyBasis) + '</select></div>' +
      '<div><span class="label">Min. dni handlowych</span><input class="input mono" id="peMinDays" value="' + esc(src.minDays) + '" inputmode="numeric"></div>' +
      '<div><span class="label">Start challenge\'u</span><input class="input" type="date" id="peStart" value="' + esc(src.startDate) + '"></div>' +
      '<div><span class="label">Koniec (opcjonalnie)</span><input class="input" type="date" id="peEnd" value="' + esc(src.endDate) + '"><div class="hint">Zostaw puste, jeśli firma nie limituje czasu.</div></div>' +
      '<div style="grid-column:1/-1"><span class="label">Notatka</span><textarea class="input" rows="2" id="peNotes" placeholder="Wariant konta, dodatkowe zasady, numer challenge\'u…">' + esc(src.notes) + '</textarea></div>' +
      '</div>' +
      '<div id="peError" class="loss" style="display:none;font-size:13px;margin-top:12px;line-height:1.5"></div>' +
      '<div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;flex-wrap:wrap"><button class="btn g" data-gclose>Anuluj</button><button class="btn p" id="peSave">' + (editing ? 'Zapisz zmiany' : 'Dodaj challenge') + '</button></div>',
      { maxWidth: '760px' });

    const val = (sel) => { const el = $(sel, body); return el ? String(el.value || '').trim() : ''; };
    const setVal = (sel, value) => { const el = $(sel, body); if (el) el.value = value == null ? '' : value; };
    const setErr = (msg) => {
      const box = $('#peError', body);
      if (!box) return;
      if (!msg) { box.style.display = 'none'; box.textContent = ''; return; }
      box.style.display = '';
      box.textContent = msg;
    };

    const templateSelect = $('#peTemplate', body);
    templateSelect.onchange = () => {
      const t = AV.prop.templateById(templateSelect.value);
      if (!t) return;
      setVal('#peFirm', t.firm);
      setVal('#pePhase', t.phase);
      setVal('#peCurrency', t.currency);
      setVal('#peTarget', t.targetPct);
      setVal('#peDaily', t.dailyLossPct);
      setVal('#peMax', t.maxLossPct);
      setVal('#peDrawdown', t.drawdownType);
      setVal('#peBasis', t.dailyBasis);
      setVal('#peMinDays', t.minDays);
      if (!editing || !val('#peName')) setVal('#peName', t.id === 'custom' ? '' : t.label);
      const start = val('#peStart') || AV.dateKey(new Date());
      setVal('#peEnd', t.durationDays ? AV.dateKey(AV.addDays(AV.prop.parseDay(start, new Date()), t.durationDays)) : '');
      AV.setText('#peTemplateNote', t.note);
      setErr('');
    };

    $('#peSave', body).onclick = () => {
      const name = val('#peName');
      const account = val('#peAccount');
      const accountSize = AV.prop.toNum(val('#peSize'), 0);
      const startBalance = AV.prop.toNum(val('#peStartBalance'), 0);
      const targetPct = AV.prop.toNum(val('#peTarget'), 0);
      const dailyLossPct = AV.prop.toNum(val('#peDaily'), 0);
      const maxLossPct = AV.prop.toNum(val('#peMax'), 0);
      const minDays = Math.round(AV.prop.toNum(val('#peMinDays'), 0));
      const startDate = val('#peStart');
      const endDate = val('#peEnd');
      const errs = [];
      if (!name) errs.push('Podaj nazwę challenge\'u.');
      if (!account) errs.push('Wybierz konto źródłowe — bez niego nie da się przypisać transakcji.');
      if (accountSize <= 0) errs.push('Rozmiar konta musi być większy od 0.');
      if (startBalance <= 0) errs.push('Saldo startowe musi być większe od 0.');
      if (targetPct <= 0) errs.push('Cel zysku musi być większy od 0.');
      if (dailyLossPct < 0) errs.push('Dzienny limit nie może być ujemny (0 = brak limitu).');
      if (maxLossPct <= 0) errs.push('Max obsunięcie musi być większe od 0.');
      if (!startDate) errs.push('Podaj datę startu challenge\'u.');
      if (endDate && endDate < startDate) errs.push('Data końca nie może być wcześniejsza niż data startu.');
      if (endDate && minDays > 0) {
        const total = AV.prop.diffDays(AV.prop.parseDay(startDate, new Date()), AV.prop.parseDay(endDate, new Date())) + 1;
        if (minDays > total) errs.push('Min. dni handlowych (' + minDays + ') przekracza liczbę dni challenge\'u (' + total + ').');
      }
      if (errs.length) { setErr(errs.join(' ')); return; }
      const data = AV.prop.normalize({
        id: existing ? existing.id : undefined,
        createdAt: existing ? existing.createdAt : undefined,
        archived: existing ? existing.archived : false,
        name, firm: val('#peFirm'), templateId: templateSelect.value, phase: val('#pePhase'),
        account, currency: val('#peCurrency'), accountSize, startBalance,
        targetPct, dailyLossPct, maxLossPct,
        drawdownType: val('#peDrawdown'), dailyBasis: val('#peBasis'),
        minDays, startDate, endDate, notes: val('#peNotes')
      });
      AV.state.prop = AV.state.prop || { challenges: [], selectedId: null };
      if (!Array.isArray(AV.state.prop.challenges)) AV.state.prop.challenges = [];
      if (existing) Object.assign(existing, data);
      else AV.state.prop.challenges.push(data);
      AV.propSetSelected(data.id);
      AV.saveNow();
      AV.closeModal();
      AV.renderProp();
      AV.renderPropDashboard();
      AV.toast(existing ? 'Challenge zaktualizowany' : 'Challenge dodany');
    };
  };

  /* ---------- akcje ---------- */
  AV.bindProp = () => {
    const view = $('#prop');
    if (!view) return;
    view.addEventListener('click', (e) => {
      if (e.target.closest('#propArchToggle')) {
        AV._propShowArchived = !AV._propShowArchived;
        AV.renderProp();
        return;
      }
      const tpl = e.target.closest('[data-prop-template]');
      if (tpl) { AV.closeModal(); AV.openPropEditor(null, tpl.dataset.propTemplate); return; }
      if (e.target.closest('[data-prop-templates]')) { AV.openPropTemplates(); return; }
      if (e.target.closest('[data-prop-new]')) { AV.openPropEditor(); return; }
      const actEl = e.target.closest('[data-prop-act]');
      if (!actEl) return;
      const ch = AV.propSelected();
      if (!ch) return;
      const action = actEl.dataset.propAct;
      if (action === 'edit') { AV.openPropEditor(ch.id); return; }
      if (action === 'duplicate') {
        const copy = AV.prop.normalize({ ...AV.clone(ch), id: undefined, name: ch.name + ' (kopia)', createdAt: undefined });
        AV.state.prop.challenges.push(copy);
        AV.propSetSelected(copy.id);
        AV.saveNow(); AV.renderProp(); AV.renderPropDashboard();
        AV.toast('Challenge zduplikowany');
        return;
      }
      if (action === 'archive') {
        ch.archived = !ch.archived;
        if (ch.archived && !AV._propShowArchived) {
          const next = AV.propVisible()[0];
          if (next) AV.propSetSelected(next.id);
        }
        AV.saveNow(); AV.renderProp(); AV.renderPropDashboard();
        AV.toast(ch.archived ? 'Challenge zarchiwizowany' : 'Challenge przywrócony');
        return;
      }
      if (action === 'delete') {
        AV.confirm({
          eyebrow: 'Prop challenge', title: 'Usunąć challenge?',
          text: 'Usuniemy konfigurację „' + ch.name + '”. Transakcje w dzienniku zostaną. Operacji nie da się cofnąć.',
          confirmLabel: 'Usuń challenge', danger: true,
          onConfirm: () => {
            const i = AV.state.prop.challenges.findIndex((c) => c.id === ch.id);
            if (i >= 0) AV.state.prop.challenges.splice(i, 1);
            const next = AV.propVisible()[0];
            AV.state.prop.selectedId = next ? next.id : null;
            AV.saveNow(); AV.renderProp(); AV.renderPropDashboard();
            AV.toast('Challenge usunięty');
          }
        });
      }
    });
    const select = $('#propSelect');
    if (select) {
      select.addEventListener('change', () => {
        AV.propSetSelected(select.value);
        AV.renderProp();
      });
    }
  };

  /* ---------- alarmy / powiadomienia ---------- */
  AV.propNotify = () => {
    if (!AV.state || !AV.state.prop || !AV.isLoggedIn()) return;
    const metrics = AV.propAllMetrics();
    if (!metrics.length) return;
    const today = AV.dateKey(new Date());
    const meta = AV.state.meta = AV.state.meta || {};
    const store = meta.propNotified = meta.propNotified || {};
    Object.keys(store).forEach((k) => { if (!k.includes(today)) delete store[k]; });
    const allowed = ['daily-75', 'daily-90', 'daily-breach', 'dd-75', 'dd-90', 'dd-breach', 'days', 'mindays', 'mindays-deadline', 'expired'];
    let changed = false;
    metrics.forEach((m) => {
      const alert = m.alerts.find((a) => allowed.includes(a.code));
      if (!alert) return;
      const key = m.challenge.id + ':' + today + ':' + alert.code;
      if (store[key]) return;
      store[key] = 1;
      changed = true;
      AV.toast(m.challenge.name + ' · ' + alert.title, 6000);
      if (AV.state.settings.pushLimit !== false) AV.pushNotification(m.challenge.name, alert.title + ' — ' + alert.text);
    });
    if (changed) AV.save();
  };
})();
