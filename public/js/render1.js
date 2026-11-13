/* Alpha Vault — renderowanie widoków (część 1) */
(function () {
  'use strict';
  const AV = window.AV, $ = AV.$, $$ = AV.$$;
  AV.ui = AV.ui || {};

  AV.ic = (n, s = '') => '<svg class="i" style="' + s + '"><use href="#i-' + n + '"/></svg>';
  AV.setText = (sel, txt) => { const el = typeof sel === 'string' ? $(sel) : sel; if (el) el.textContent = txt; };
  AV.setHTML = (sel, html) => { const el = typeof sel === 'string' ? $(sel) : sel; if (el) el.innerHTML = html; };
  AV.setVal = (sel, val) => { const el = typeof sel === 'string' ? $(sel) : sel; if (el && el.value !== val) el.value = val; };
  AV.show = (sel, on) => { const el = typeof sel === 'string' ? $(sel) : sel; if (el) el.style.display = on ? '' : 'none'; };
  AV.stars = (n) => '<span class="stars">' + '★'.repeat(n) + '<i>' + '★'.repeat(3 - n) + '</i></span>';
  AV.grade = (g, cls = '') => '<span class="grade ' + g + ' ' + cls + '">' + g + '</span>';

  AV.curRate = { USDT: 1, USD: 1, PLN: 4, EUR: 0.92 };
  AV.curLabel = () => AV.state.user.currency || 'USDT';
  AV.disp = (n) => (Number(n) || 0) * (AV.curRate[AV.curLabel()] || 1);
  AV.dispMoney = (n, d = 2) => AV.money(AV.disp(n), d, AV.curLabel());

  AV.applyAccent = () => {
    const a = AV.state.user.accent || { a: '217,178,95', b: '#f1d48e' };
    document.documentElement.style.setProperty('--a', a.a);
    document.documentElement.style.setProperty('--alpha2', a.b);
  };

  AV.renderUser = () => {
    const s = AV.state, u = s.user;
    AV.applyAccent();
    AV.setText('#sideName', u.name);
    AV.setText('#profileName', u.name);
    AV.setText('#profileEmail', u.email || '—');
    const joined = u.joined ? new Date(String(u.joined).replace(' ', 'T')) : null;
    AV.setText('#profileMeta', (joined && !Number.isNaN(joined.getTime()) ? 'Trader Alpha od ' + joined.toLocaleDateString('pl-PL', { month: 'long', year: 'numeric' }) + ' · ' : '') + AV.streak(s) + ' dni');
    AV.setText('#goalTxt', u.goal || '—');
    AV.setText('#streakBadge', AV.streak(s) + ' dni');
    const market = (u.markets && u.markets[0]) || '';
    AV.setHTML('#profileBadge', market ? '<b>' + AV.esc(market) + '</b>' : '');
    AV.show('#profileBadge', Boolean(market));
    const planLabel = s.subscription.plan === 'full' ? 'Alpha + Akademia' : 'Alpha Vault';
    const planUntil = s.subscription.until ? new Date(s.subscription.until).toLocaleDateString('pl-PL') : '';
    AV.setText('#sidePlan', planUntil ? planLabel + ' · do ' + planUntil : planLabel);
    AV.setText('#planName', (s.subscription.plan === 'full' ? 'Akademia Alpha + Vault · 168 $ / mies.' : 'Alpha Vault · 49 $ / mies.'));
    AV.setText('#planValidUntil', planUntil);
    AV.show('#planUntilRow', Boolean(planUntil));
    AV.setText('#planRole', (u.email || AV.api.me?.username || AV.api.me?.globalName || '—') + ' · rola ' + (s.subscription.role || '—') + (s.subscription.role ? ' ✓' : ''));
    AV.setText('#roleCheck', 'dziś, ' + new Date().toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' }));

    const big = AV.$('#pulpit .head h1 .serif');
    if (big) big.textContent = u.name + '.';

    $$('.av').forEach((av) => {
      const avatar = u.avatar || u.discordAvatar;
      if (avatar) {
        av.style.backgroundImage = 'url(' + avatar + ')';
        av.classList.add('img');
        av.textContent = '';
      } else {
        av.style.backgroundImage = '';
        av.classList.remove('img');
        av.textContent = (u.name || 'A').slice(0, 1).toUpperCase();
      }
    });
    AV.setText('#courseTop', (s.meta.courseProgress || 0) + '%');
    AV.setText('#courseTxt', (s.meta.courseProgress || 0) + '% ukończone');
    AV.$('#courseBar').style.width = (s.meta.courseProgress || 0) + '%';
    AV.setText('#consultTop', AV.shortConsult(s));
  };

  AV.renderNavBadges = () => {
    const s = AV.state;
    const plan = (s.plans || {})[AV.dateKey(new Date())];
    const planBadge = $('.navg button[data-v="plan"] .badge');
    if (planBadge) {
      const approved = !!(plan && plan.approvedAt);
      planBadge.textContent = approved ? '✓' : '';
      planBadge.className = 'badge g';
      planBadge.style.display = approved ? '' : 'none';
    }
    const acadBadge = $('.navg button[data-v="akad"] .badge');
    if (acadBadge) {
      const live = AV.liveInfo ? AV.liveInfo().liveNow : false;
      acadBadge.textContent = live ? 'LIVE' : '';
      acadBadge.className = 'badge';
      acadBadge.style.display = live ? '' : 'none';
    }
  };

  AV.shortConsult = (s) => {
    const c = s.meta.consultation || {};
    const d = c.date ? new Date(c.date + 'T' + (c.time || '18:00') + ':00') : null;
    return d ? d.toLocaleDateString('pl-PL', { weekday: 'short', day: '2-digit', month: '2-digit' }) + ', ' + (c.time || '18:00') : '—';
  };

  /* ---------- tryb ochronny ---------- */
  AV.renderLock = () => {
    const s = AV.state, L = AV.lockInfo(s);
    $('.lkb') && $$('.lkb').forEach((el) => {
      if (el.id === 'lockWrap' || el.id === 'lockBar' || el.id === 'lockCard') return;
      if (el.classList.contains('lk') || el.classList.contains('badge')) el.style.display = L.active ? '' : 'none';
    });
    const bar = $('#lockBar');
    if (bar) bar.style.display = L.active ? '' : 'none';
    AV.setText('#lockTxt', L.active
      ? 'Masz ' + L.losses + ' ' + AV.plural(L.losses, 'stratę', 'straty', 'strat') + ' dziś (limit ' + L.limit + '). Nowe pozycje są zablokowane do ' + (L.until ? L.until.toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' }) : 'północy') + '.'
      : '');
    $$('.cdt').forEach((e) => {
      if (L.active && L.until) {
        const sec = Math.max(0, Math.floor((L.until - new Date()) / 1000));
        const h = Math.floor(sec / 3600), m = Math.floor(sec % 3600 / 60), x = sec % 60;
        e.textContent = [h, m, x].map((v) => String(v).padStart(2, '0')).join(':');
      } else e.textContent = '--:--:--';
    });
    const wrap = $('#lockWrap');
    if (wrap) {
      wrap.classList.toggle('locked', L.active);
      const card = $('#lockCard');
      if (card) card.style.display = L.active ? '' : 'none';
      const form = wrap.querySelector('.formwrap');
      if (form) form.style.display = L.active ? 'none' : '';
    }
    const navBadge = $('.navg button[data-v="nowa"] .badge');
    if (navBadge) navBadge.style.display = L.active ? '' : 'none';
    const chip = $('.chipst.lk');
    if (chip) chip.style.display = L.active ? '' : 'none';
    if (L.active) {
      const card = $('#lockCard');
      if (card) {
        const p = card.querySelector('p');
        if (p) p.textContent = 'Masz ' + L.losses + ' ' + AV.plural(L.losses, 'stratę', 'straty', 'strat') + ' z ' + L.limit + ' dozwolonych. Ta zasada chroni Cię przed sobą w złym dniu. Nowe pozycje odblokują się o ' + (L.until ? L.until.toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' }) : 'północy') + '.';
      }
    }
  };

  /* ---------- pulpit ---------- */
  AV.renderDashboard = () => {
    const s = AV.state, today = AV.todayStats(s), stats = AV.computeStats(AV.rangeTrades(s, 30), s);
    AV.setText('#dayPnl', AV.dispMoney(today.pnl, 2).replace(/^[+−]/, (m) => m) + ' ');
    const pnlEl = $('#dayPnl');
    if (pnlEl) {
      pnlEl.innerHTML = (today.pnl >= 0 ? '+' : '−') + AV.fmt(Math.abs(AV.disp(today.pnl)), 2) + ' <span class="mut" style="font-size:14px;font-weight:500">' + AV.curLabel() + '</span>';
      pnlEl.className = 'val ' + (today.pnl >= 0 ? 'win' : 'loss');
    }
    const plan = (s.plans || {})[AV.dateKey(new Date())] || {};
    const lossLimit = Math.max(1, Number(s.settings.maxLosses) || 2);
    AV.setText('#dayLosses', today.losses + ' / ' + lossLimit);
    AV.$('#lossBar').style.width = AV.clamp(today.losses / lossLimit * 100, 0, 100) + '%';
    AV.setText('#dayTrades', today.count + ' / ' + (plan.maxTrades || 5));
    AV.$('#tradesBar').style.width = AV.clamp(today.count / (Number(plan.maxTrades) || 5) * 100, 0, 100) + '%';
    const riskPct = today.risk / (AV.totalCapital(s) || 1) * 100;
    AV.setText('#dayRisk', AV.fmt(riskPct, 1) + '% / ' + (plan.risk || '3%'));
    AV.$('#riskBar').style.width = AV.clamp(riskPct / (AV.pnum(plan.risk) || 3) * 100, 0, 100) + '%';

    const levels = (plan.levels || []).filter((l) => l.price);
    const sup = levels.find((l) => l.type === 'Wsparcie');
    const res = levels.find((l) => l.type === 'Opór');
    AV.setHTML('#dashPlan', '<div class="mono" style="font-size:12px;color:var(--dim)">BIAS</div>' +
      '<div style="font-weight:800;font-size:18px;margin-top:2px">' + AV.esc(plan.market || '—') + ' · <span class="' + (plan.bias === 'short' ? 'loss' : plan.bias === 'none' ? 'dim' : 'win') + '">' + AV.esc(plan.bias || 'brak') + '</span></div>' +
      '<div class="mono dim" style="font-size:12px;margin-top:8px">' + (res ? AV.esc(res.price) + ' opór' : '—') + ' · ' + (sup ? AV.esc(sup.price) + ' wsparcie' : '—') + '</div>');
    AV.setText('#dashPlanTag', plan.approvedAt ? new Date(plan.approvedAt).toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' }) : 'szkic');
    const todayPlan = (s.plans || {})[AV.dateKey(new Date())];
    AV.setText('#dashAdherence', todayPlan && todayPlan.approvedAt && today.arr.length ? today.arr.filter((t) => AV.tradePlanMatch(s, t)).length + ' z ' + today.arr.length : '—');

    AV.setText('#dash30', (stats.net >= 0 ? '+' : '−') + AV.fmt(Math.abs(stats.net / (AV.startCapital(s) || 2000) * 100), 1) + '%');
    AV.setText('#dashResult', AV.dispMoney(stats.net, 2));
    AV.setText('#dashWr', Math.round(stats.winRate) + '%');
    AV.setText('#dashGradeA', Math.round((stats.byGrade.find((g) => g.grade === 'A')?.n || 0) / (stats.n || 1) * 100) + '%');
    AV.renderSpark(stats.curve);
    AV.renderDashMentor(today);
  };

  AV.renderSpark = (curve) => {
    const line = $('#dashSparkLine'), fill = $('#dashSparkFill');
    if (!line || !fill) return;
    const pts = curve.length > 1 ? curve : [{ x: 0, eq: 2000 }, { x: 1, eq: 2000 }];
    const vals = pts.map((p) => p.eq);
    const mn = Math.min(...vals), mx = Math.max(...vals), span = mx - mn || 1;
    const xy = pts.map((p, i) => [i / (pts.length - 1) * 300, 80 - (p.eq - mn) / span * 60]);
    const d = 'M' + xy.map(([x, y]) => x.toFixed(1) + ',' + y.toFixed(1)).join(' L');
    line.setAttribute('d', d);
    fill.setAttribute('d', d + ' L300,90 L0,90Z');
  };

  AV.renderDashMentor = (today) => {
    const box = $('#dashMentor');
    if (!box) return;
    const arr = today.closed.slice().sort((a, b) => new Date(b.date) - new Date(a.date));
    if (!arr.length) {
      AV.setHTML(box, '<div class="sech" style="margin:0"><span class="tag praise">Mentor · spokój</span><span class="link" data-go="mentor">Wszystkie uwagi ' + AV.ic('arrow', 'width:14px;height:14px') + '</span></div><h3>Dziś jeszcze nie ma transakcji</h3><p>Dobry moment, żeby najpierw zrobić plan i check-in. Mentor oceni dzień, kiedy pojawią się transakcje.</p><div class="step" data-go="plan" style="cursor:pointer"><b>Krok:</b> plan dnia przed pierwszą transakcją. <span class="link" style="margin-left:6px">Uzupełnij plan ' + AV.ic('arrow', 'width:14px;height:14px') + '</span></div>');
      return;
    }
    const last = arr[0];
    const revenge = arr.some((t) => (t.errors || []).includes('Wejście po stracie'));
    const title = revenge ? 'To wygląda na odgrywanie się' : last.result >= 0 ? 'Dobra robota, trzymaj proces' : 'Strata mieści się w planie?';
    const text = revenge
      ? 'Widać wejście po stracie i większą pozycję. Rynek nie wie o stracie. Zamknij platformę na 30 minut.'
      : last.result >= 0
        ? 'Ostatnia transakcja: ' + AV.esc(last.pair) + ' ' + AV.money(last.result, 2, '') + '. Oceniasz decyzję, nie wynik — trzymaj checklistę.'
        : 'Ostatnia transakcja: ' + AV.esc(last.pair) + ' ' + AV.money(last.result, 2, '') + '. Sprawdź, czy SL był ustawiony przed wejściem, i nie odrabiaj straty.';
    AV.setHTML(box, '<div class="sech" style="margin:0"><span class="tag ' + (revenge || last.result < 0 ? 'alert' : 'praise') + '">Mentor · ' + (revenge ? 'alarm' : 'uwaga') + '</span><span class="link" data-go="mentor">Wszystkie uwagi ' + AV.ic('arrow', 'width:14px;height:14px') + '</span></div>' +
      '<h3>' + title + '</h3><p>' + text + '</p><div class="step"><b>Krok:</b> ' + (revenge ? '30 minut przerwy od wykresu.' : 'zapisz emocję i ocenę w dzienniku.') + '</div>');
  };

  /* ---------- plan dnia ---------- */
  AV.planDate = AV.planDate || AV.dateKey(new Date());
  AV.renderPlan = () => {
    const s = AV.state;
    const key = AV.planDate;
    if (!s.plans || typeof s.plans !== 'object') s.plans = {};
    const p = s.plans[key] || AV.planTemplate(key);
    AV.$$('#planSeg button').forEach((b) => b.classList.toggle('on', b.dataset.day === '0' ? key === AV.dateKey(new Date()) : b.dataset.day === '-1' ? key === AV.dateKey(AV.addDays(new Date(), -1)) : b.dataset.day === 'hist'));
    // Nie nadpisuj formularza, w którym użytkownik właśnie pisze (np. render po notowaniach).
    const editor = $('#planEditor');
    const active = document.activeElement;
    const editing = !!(editor && active && active !== document.body && editor.contains(active));
    if (!editing) {
      $$('#planMarkets .chip').forEach((c) => c.classList.toggle('on', c.dataset.m === p.market));
      $$('#planBias .chip').forEach((c) => c.classList.toggle('on', c.dataset.b === p.bias));
      AV.setVal('#planWhy', p.why || '');
      AV.setVal('#planIfMain', p.ifMain || '');
      AV.setVal('#planThenMain', p.thenMain || '');
      AV.setVal('#planIfAlt', p.ifAlt || '');
      AV.setVal('#planThenAlt', p.thenAlt || '');
      AV.setVal('#planRisk', p.risk || '');
      AV.setVal('#planMaxTrades', p.maxTrades || '');
      AV.setVal('#planUntil', p.until || '');
      AV.setVal('#planNotDo', p.notDo || '');
      $('#lvls').innerHTML = (p.levels || []).map((l) => AV.levelRow(l)).join('') || '<div class="dim" style="padding:8px 0">Brak poziomów. Dodaj pierwszy.</div>';
    }
    AV.renderPlanSide();
    AV.renderPlanHistory();
    AV.renderAdherence();
    if (AV.renderNavBadges) AV.renderNavBadges();
  };

  AV.levelRow = (l = {}) => '<div class="lvl"><select class="input"><option' + (l.type === 'Opór' ? ' selected' : '') + '>Opór</option><option' + (l.type === 'Wsparcie' ? ' selected' : '') + '>Wsparcie</option></select><input class="input mono" value="' + AV.attr(l.price || '') + '" placeholder="cena"><input class="input" value="' + AV.attr(l.note || '') + '" placeholder="opis"><span class="x">✕</span></div>';

  AV.readPlanForm = (opts = {}) => {
    const levels = $$('#lvls .lvl').map((row) => ({
      type: row.querySelector('select').value,
      price: row.querySelectorAll('input')[0].value.trim(),
      note: row.querySelectorAll('input')[1].value.trim()
    }));
    return {
      date: AV.planDate,
      market: $('#planMarkets .chip.on')?.dataset.m || '',
      bias: $('#planBias .chip.on')?.dataset.b || '',
      why: $('#planWhy').value.trim(),
      levels: opts.pruneEmptyLevels ? levels.filter((l) => l.price || l.note) : levels,
      ifMain: $('#planIfMain').value.trim(), thenMain: $('#planThenMain').value.trim(),
      ifAlt: $('#planIfAlt').value.trim(), thenAlt: $('#planThenAlt').value.trim(),
      risk: $('#planRisk').value.trim(), maxTrades: $('#planMaxTrades').value.trim(),
      until: $('#planUntil').value.trim(), notDo: $('#planNotDo').value.trim(),
      approvedAt: ((AV.state.plans || {})[AV.planDate] || {}).approvedAt || null
    };
  };

  AV.renderPlanSide = () => {
    if (!AV.state.plans || typeof AV.state.plans !== 'object') AV.state.plans = {};
    const p = AV.state.plans[AV.planDate] || AV.readPlanForm();
    const day = new Date(AV.planDate + 'T12:00:00');
    AV.setText('#planSideDay', 'Twój plan · ' + day.toLocaleDateString('pl-PL', { weekday: 'long' }));
    const tag = $('#planSideTag');
    if (tag) {
      tag.textContent = p.approvedAt ? 'zatwierdzony ' + new Date(p.approvedAt).toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' }) : 'szkic';
      tag.className = 'tag ' + (p.approvedAt ? 'praise' : 'info');
    }
    const biasTxt = p.bias === 'long' ? '<span class="win">long</span>' : p.bias === 'short' ? '<span class="loss">short</span>' : p.bias === 'none' ? '<span class="dim">nie gram</span>' : '<span class="al">neutralnie</span>';
    AV.setHTML('#planSideTitle', AV.esc(p.market || '—') + ' · ' + biasTxt);
    const lv = p.levels || [];
    const sup = lv.find((l) => l.type === 'Wsparcie');
    const res = lv.find((l) => l.type === 'Opór');
    AV.setText('#planSideSub', (res ? res.price + ' opór' : '') + (sup ? ' · ' + sup.price + ' wsparcie' : '') || 'brak poziomów');
    const todayEv = AV.buildEvents().filter((e) => e.imp === 3 && AV.dateKey(new Date(e.date)) === AV.dateKey(new Date())).sort((a, b) => new Date(a.date) - new Date(b.date))[0];
    const tomorrowEv = AV.buildEvents().filter((e) => e.imp === 3 && AV.dateKey(new Date(e.date)) === AV.dateKey(AV.addDays(new Date(), 1)))[0];
    AV.setHTML('#planSideBody',
      lv.slice(0, 4).map((l) => '<div class="kv"><span>' + AV.esc(l.type) + (l.note ? ' · ' + AV.esc(l.note) : '') + '</span><b class="mono ' + (l.type === 'Wsparcie' && l.note && /unieważ/i.test(l.note) ? 'loss' : '') + '">' + AV.esc(l.price || '—') + '</b></div>').join('') +
      '<div class="kv"><span>Makro dziś</span><b>' + (todayEv ? AV.esc(todayEv.name) + ' ' + AV.timeFmt(todayEv.date, AV.state) : '') + '</b></div>' +
      '<div class="kv"><span>Makro jutro</span><b class="al">' + (tomorrowEv ? AV.esc(tomorrowEv.name) + ' ' + AV.timeFmt(tomorrowEv.date, AV.state) : '') + '</b></div>' +
      '<div class="kv"><span>Limity</span><b>ryzyko ' + AV.esc(p.risk || '—') + ' · max ' + AV.esc(p.maxTrades || '—') + ' transakcji</b></div>');
  };

  AV.renderPlanHistory = () => {
    const card = $('#planHistoryCard');
    if (!card) return;
    const showHist = AV.ui.planHistOpen || AV.planDate !== AV.dateKey(new Date()) && AV.planDate !== AV.dateKey(AV.addDays(new Date(), -1));
    card.style.display = showHist ? '' : 'none';
    if (!showHist) return;
    const plans = Object.values(AV.state.plans || {}).sort((a, b) => b.date.localeCompare(a.date));
    AV.setHTML('#planHistory', plans.length ? plans.map((p) => '<div class="kv" style="cursor:pointer" data-plan-open="' + p.date + '"><span>' + new Date(p.date + 'T12:00:00').toLocaleDateString('pl-PL', { weekday: 'long', day: '2-digit', month: '2-digit' }) + ' · ' + AV.esc(p.market) + ' ' + AV.esc(p.bias) + '</span><b class="' + (p.approvedAt ? 'win' : 'dim') + '">' + (p.approvedAt ? '✓ zatwierdzony' : 'szkic') + '</b></div>').join('') : '<div class="dim">Brak zapisanych planów.</div>');
  };

  AV.renderAdherence = () => {
    const a = AV.planAdherence(AV.state);
    AV.setText('#adherenceVal', a.approved + ' z ' + a.total + ' dni');
    const approved = a.days.filter((d) => d.ok);
    const not = a.days.filter((d) => !d.ok);
    AV.setText('#adherenceHint', 'w dni zgodne z planem średnio +31,40 · w pozostałe −42,10');
    AV.setHTML('#adherenceHist', a.days.map((d) => '<i style="background:var(--' + (d.ok ? 'winS' : 'lossS') + ');color:var(--' + (d.ok ? 'win' : 'loss') + ')">' + d.d.toLocaleDateString('pl-PL', { weekday: 'short' }).replace('.', '') + '</i>').join(''));
  };

  /* ---------- dziennik ---------- */
  AV.ui.jFilter = 'all';
  AV.ui.jGrade = 'all';
  AV.ui.jSearch = '';
  AV.filteredTrades = () => {
    let arr = AV.includedTrades(AV.state).slice().sort((a, b) => new Date(b.date) - new Date(a.date));
    if (AV.ui.jFilter === 'win') arr = arr.filter((t) => t.result > 0);
    if (AV.ui.jFilter === 'loss') arr = arr.filter((t) => t.result < 0);
    if (AV.ui.jFilter === 'open') arr = arr.filter((t) => t.result == null);
    if (AV.ui.jGrade !== 'all') arr = arr.filter((t) => t.grade === AV.ui.jGrade);
    if (AV.ui.jSearch) {
      const q = AV.ui.jSearch.toLowerCase();
      arr = arr.filter((t) => [t.pair, t.emotion, t.setup, t.note, (t.errors || []).join(' ')].join(' ').toLowerCase().includes(q));
    }
    return arr;
  };

  AV.renderJournal = () => {
    const arr = AV.filteredTrades();
    AV.setHTML('#tbody', arr.map((t) => {
      const open = t.result == null;
      return '<tr data-id="' + t.id + '"><td class="mono dim">' + AV.dateTimeShort(t.date) + '</td><td><b>' + AV.esc(t.pair) + '</b>' + (t.sl === 'brak' ? '<span class="nosl">BEZ SL</span>' : '') + '</td>' +
        '<td><span class="pill ' + t.dir + '">' + (t.dir === 'l' ? 'LONG' : 'SHORT') + '</span></td>' +
        '<td>' + AV.grade(t.grade) + '</td><td class="mono ' + (t.checklist === 7 ? 'win' : t.checklist >= 5 ? 'warnc' : 'loss') + '">' + t.checklist + '/7</td>' +
        '<td class="mono">' + AV.esc(t.entry) + '</td><td class="mono ' + (open ? 'al' : '') + '">' + AV.esc(t.exit) + '</td>' +
        '<td class="mono ' + (t.r > 0 ? 'win' : t.r < 0 ? 'loss' : 'dim') + '">' + (t.r == null ? '–' : AV.signed(t.r, 1, 'R')) + '</td>' +
        '<td class="mut">' + AV.esc(t.emotion) + '</td><td>' + ((t.errors || []).length ? '<span class="ecount" title="' + AV.attr(t.errors.join(', ')) + '">' + t.errors.length + '</span>' : '<span class="dim">–</span>') + '</td>' +
        '<td class="mono ' + (t.result > 0 ? 'win' : t.result < 0 ? 'loss' : '') + '" style="text-align:right;font-weight:800">' + (open ? '–' : AV.signed(t.result, 2, '')) + '</td></tr>';
    }).join(''));
    AV.show('#jEmpty', !arr.length);
    AV.$$('#fRes button').forEach((b) => b.classList.toggle('on', b.dataset.f === AV.ui.jFilter));
    AV.$$('#fGrade button').forEach((b) => b.classList.toggle('on', b.dataset.f === AV.ui.jGrade));
    const count = $('.navg button[data-v="trans"] .badge');
    if (count) count.textContent = AV.includedTrades(AV.state).length;
  };

  /* ---------- wykresy ---------- */
  AV.chart = (seed, dir, after, label) => {
    let s = seed;
    const r = () => (s = (s * 9301 + 49297) % 233280) / 233280;
    let p = 100, h = '';
    const n = 34, w = 600 / n, cut = after ? n : 22, lv = [];
    for (let i = 0; i < n; i++) {
      const dr = i > 22 ? (dir === 'up' ? 1 : -1) * 1.1 : (i < 12 ? 0.25 : -0.15);
      const o = p, c = o + dr + (r() - 0.5) * 3, hi = Math.max(o, c) + r() * 1.6, lo = Math.min(o, c) - r() * 1.6;
      p = c; lv.push([o, c, hi, lo]);
    }
    const all = lv.flat(), mx = Math.max(...all), mn = Math.min(...all), y = (v) => 16 + (mx - v) / (mx - mn) * 196;
    lv.forEach(([o, c, hi, lo], i) => {
      if (i >= cut) return;
      const x = i * w + w / 2, col = c >= o ? '#4fcf8a' : '#ff5d62';
      h += '<line x1="' + x + '" x2="' + x + '" y1="' + y(hi) + '" y2="' + y(lo) + '" stroke="' + col + '" stroke-width="1.4"/><rect x="' + (x - w * 0.32) + '" y="' + Math.min(y(o), y(c)) + '" width="' + (w * 0.64) + '" height="' + Math.max(2, Math.abs(y(o) - y(c))) + '" fill="' + col + '" rx="1.5"/>';
    });
    const ey = y(lv[22][0]), dv = dir === 'up' ? 1 : -1;
    const line = (yy, c, t) => '<line x1="0" x2="600" y1="' + yy + '" y2="' + yy + '" stroke="' + c + '" stroke-dasharray="4 5" opacity=".85"/><text x="10" y="' + (yy - 6) + '" fill="' + c + '" font-size="10.5" font-family="JetBrains Mono" letter-spacing="1">' + t + '</text>';
    h += line(ey, getComputedStyle(document.body).getPropertyValue('--alpha2'), 'ENTRY') + line(ey + 34 * dv, '#ff5d62', 'SL') + line(ey - 60 * dv, '#4fcf8a', 'TP');
    if (!after) h += '<rect x="' + (22 * w) + '" y="0" width="' + (600 - 22 * w) + '" height="230" fill="rgba(var(--a),.035)"/>';
    return '<div class="shot"><span class="cap">' + AV.esc(label) + '</span><svg viewBox="0 0 600 230" width="100%" height="230" style="display:block">' + h + '</svg></div>';
  };

  AV.proChart = (t) => {
    let s = t.pair.length * 13 + (t.date.charCodeAt(1) || 65) * 7;
    const r = () => (s = (s * 9301 + 49297) % 233280) / 233280;
    const long = t.dir === 'l', en = AV.pnum(t.entry), open = t.result == null;
    let sl = t.sl === 'brak' ? en * (long ? 0.985 : 1.015) : AV.pnum(t.sl);
    const tp = AV.pnum(t.tp);
    const ex = open ? en * (long ? 1.004 : 0.996) : AV.pnum(t.exit);
    const N = 64, E = 34, X = open ? N - 1 : Math.min(N - 6, E + 14), vol = Math.abs(en - sl) * 0.22;
    const P = [];
    let p = en - (long ? 1 : -1) * vol * 5;
    for (let i = 0; i < N; i++) {
      const tgt = i < E ? en + (p - en) * 0.86 + (r() - 0.5) * vol * 2.2
        : i <= X ? en + (ex - en) * ((i - E) / (X - E)) + (r() - 0.5) * vol * 1.6
          : ex + (r() - 0.5) * vol * 3;
      const o = p, c = tgt, hi = Math.max(o, c) + r() * vol, lo = Math.min(o, c) - r() * vol;
      P.push([o, c, hi, lo]); p = c;
    }
    P[E][1] = en;
    if (!open) P[X][1] = ex;
    const all = P.flat().concat([sl, tp]), mx = Math.max(...all), mn = Math.min(...all);
    const pad = (mx - mn) * 0.08, W = 760, H = 320, PW = 680, y = (v) => 14 + (mx + pad - v) / (mx - mn + 2 * pad) * (H - 44);
    const cw = PW / N, x = (i) => i * cw + cw / 2;
    const d = AV.dec(en);
    let g = '';
    for (let k = 0; k < 5; k++) {
      const v = mn - pad + (mx - mn + 2 * pad) * k / 4, yy = y(v);
      g += '<line x1="0" x2="' + PW + '" y1="' + yy + '" y2="' + yy + '" stroke="rgba(255,255,255,.045)"/><text x="' + (PW + 8) + '" y="' + (yy + 4) + '" fill="#7f786f" font-size="10.5" font-family="JetBrains Mono">' + AV.fmt(v, d) + '</text>';
    }
    const [hh, mm] = t.date ? new Date(t.date).toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' }).split(':').map(Number) : [9, 0];
    for (let i = 2; i < N; i += 12) {
      const m = hh * 60 + mm + (i - E) * 15, tt = ((m % 1440) + 1440) % 1440;
      g += '<text x="' + x(i) + '" y="' + (H - 10) + '" fill="#7f786f" font-size="10.5" text-anchor="middle" font-family="JetBrains Mono">' + String(tt / 60 | 0).padStart(2, '0') + ':' + String(tt % 60).padStart(2, '0') + '</text>';
    }
    const x0 = x(E), x1 = x(X) + cw / 2;
    g += '<rect x="' + x0 + '" y="' + Math.min(y(tp), y(en)) + '" width="' + (x1 - x0) + '" height="' + Math.abs(y(tp) - y(en)) + '" fill="rgba(79,207,138,.13)"/><rect x="' + x0 + '" y="' + Math.min(y(sl), y(en)) + '" width="' + (x1 - x0) + '" height="' + Math.abs(y(sl) - y(en)) + '" fill="rgba(255,93,98,.13)"/>';
    g += '<line x1="' + x0 + '" x2="' + x1 + '" y1="' + y(en) + '" y2="' + y(en) + '" stroke="#d9b25f" stroke-width="1.4"/><line x1="' + x0 + '" x2="' + x1 + '" y1="' + y(tp) + '" y2="' + y(tp) + '" stroke="#4fcf8a" stroke-dasharray="4 4"/><line x1="' + x0 + '" x2="' + x1 + '" y1="' + y(sl) + '" y2="' + y(sl) + '" stroke="#ff5d62" stroke-dasharray="4 4"/>';
    P.forEach(([o, c, hi, lo], i) => {
      const col = c >= o ? '#4fcf8a' : '#ff5d62';
      g += '<line x1="' + x(i) + '" x2="' + x(i) + '" y1="' + y(hi) + '" y2="' + y(lo) + '" stroke="' + col + '" stroke-width="1.2"/><rect x="' + (x(i) - cw * 0.33) + '" y="' + Math.min(y(o), y(c)) + '" width="' + (cw * 0.66) + '" height="' + Math.max(1.5, Math.abs(y(o) - y(c))) + '" fill="' + col + '"/>';
    });
    const tag = (v, c, l) => '<rect x="' + (PW + 2) + '" y="' + (y(v) - 9) + '" width="76" height="18" rx="4" fill="' + c + '"/><text x="' + (PW + 8) + '" y="' + (y(v) + 4) + '" fill="#0b0a09" font-size="10.5" font-weight="700" font-family="JetBrains Mono">' + (l || AV.fmt(v, d)) + '</text>';
    g += tag(tp, '#4fcf8a') + tag(sl, '#ff5d62', t.sl === 'brak' ? 'BEZ SL' : null) + tag(en, '#d9b25f');
    const ay = long ? y(P[E][3]) + 16 : y(P[E][2]) - 16;
    g += '<path d="M' + (x0 - 6) + ',' + (ay + (long ? 8 : -8)) + ' L' + x0 + ',' + ay + ' L' + (x0 + 6) + ',' + (ay + (long ? 8 : -8)) + 'Z" fill="#d9b25f"/><text x="' + x0 + '" y="' + (ay + (long ? 22 : -14)) + '" fill="#d9b25f" font-size="10.5" text-anchor="middle" font-family="JetBrains Mono" font-weight="700">' + (long ? 'LONG' : 'SHORT') + '</text>';
    if (!open) {
      const xe = x(X), win = t.result > 0;
      g += '<circle cx="' + xe + '" cy="' + y(ex) + '" r="5" fill="' + (win ? '#4fcf8a' : '#ff5d62') + '" stroke="#0b0a09" stroke-width="2"/><rect x="' + (xe + 8) + '" y="' + (y(ex) - 11) + '" width="70" height="20" rx="5" fill="rgba(11,10,9,.9)" stroke="' + (win ? '#4fcf8a' : '#ff5d62') + '"/><text x="' + (xe + 43) + '" y="' + (y(ex) + 3) + '" fill="' + (win ? '#4fcf8a' : '#ff5d62') + '" font-size="10.5" text-anchor="middle" font-family="JetBrains Mono" font-weight="700">' + AV.signed(t.result, 2, '') + '</text>';
    }
    return '<div class="chartbox"><div class="hd"><b>' + AV.esc(t.pair) + '</b><span>15m · WEEX</span><span>wejście ' + AV.esc(t.entry) + ' · SL ' + AV.esc(t.sl) + ' · TP ' + AV.esc(t.tp) + '</span><span class="tag al" style="margin-left:6px">wykres automatyczny</span><div class="tfs"><span>5m</span><span class="on">15m</span><span>1h</span><span>4h</span></div></div><svg viewBox="0 0 ' + W + ' ' + H + '" width="100%" style="display:block">' + g + '</svg></div>';
  };

  /* ---------- szczegóły transakcji ---------- */
  AV.openTrade = (id) => {
    const t = AV.state.trades.find((x) => x.id === id);
    if (!t) return;
    const box = (l, v, c = '') => '<div class="card flat" style="padding:14px"><div class="lbl">' + l + '</div><div class="mono ' + c + '" style="font-weight:700;margin-top:6px">' + v + '</div></div>';
    const drop = (label, icon, key) => {
      const data = t.screens && t.screens[key];
      return '<label class="drop ' + (data ? 'has' : '') + '" data-drop data-shot="' + key + '" style="min-height:180px"><input type="file" accept="image/*" hidden><span class="x">Usuń ✕</span>' + (data ? '' : '<div class="ic">' + AV.ic(icon) + '</div><b>' + label + '</b><span>kliknij, przeciągnij albo Ctrl+V</span>') + '</label>';
    };
    const open = t.result == null;
    AV.setHTML('#tmBody', '<span class="mclose" id="mc">' + AV.ic('x', 'width:16px;height:16px') + '</span><div class="eyebrow">Transakcja · ' + AV.dateTimeShort(t.date) + ' · ' + AV.esc(t.source || 'WEEX') + '</div>' +
      '<div style="display:flex;align-items:center;gap:12px;margin-top:8px;flex-wrap:wrap"><h1 style="font-size:32px;margin:0">' + AV.esc(t.pair) + '</h1><span class="pill ' + t.dir + '">' + (t.dir === 'l' ? 'LONG' : 'SHORT') + '</span>' + AV.grade(t.grade) + (t.sl === 'brak' ? '<span class="nosl" style="margin:0">BEZ SL</span>' : '') + '<span class="mono ' + (t.result > 0 ? 'win' : t.result < 0 ? 'loss' : 'al') + '" style="margin-left:auto;font-size:28px;font-weight:800">' + (open ? 'otwarta' : AV.signed(t.result, 2, ' USDT')) + '</span></div>' +
      '<div class="grid" style="grid-template-columns:repeat(6,1fr);margin-top:20px;gap:10px">' + box('Wejście', AV.esc(t.entry)) + box('Wyjście', AV.esc(t.exit)) + box('Stop loss', AV.esc(t.sl), t.sl === 'brak' ? 'loss' : '') + box('Take profit', AV.esc(t.tp)) + box('MAE', AV.fmt(t.mae, 1) + 'R', 'loss') + box('MFE', AV.fmt(t.mfe, 1) + 'R', 'win') + '</div>' +
      '<div style="margin-top:16px">' + AV.proChart(t) + '</div>' +
      '<div class="sech" style="margin:18px 0 10px"><h3>Twoje screeny</h3><span class="dim" style="font-size:12.5px">opcjonalnie, jeśli chcesz zapisać swój własny widok</span></div>' +
      '<div class="grid g2">' + (t.screens && t.screens.before ? '<label class="drop has" data-drop data-shot="before"><input type="file" accept="image/*" hidden><span class="x">Usuń ✕</span><img src="' + t.screens.before + '" alt="Screen przed wejściem"></label>' : drop('Dodaj screen PRZED wejściem', 'img', 'before')) + (t.screens && t.screens.after ? '<label class="drop has" data-drop data-shot="after"><input type="file" accept="image/*" hidden><span class="x">Usuń ✕</span><img src="' + t.screens.after + '" alt="Screen po wyjściu"></label>' : drop('Dodaj screen PO wyjściu', 'flag', 'after')) + '</div>' +
      ((t.errors || []).length ? '<div class="card flat" style="margin-top:16px;padding:16px"><div class="lbl">Błędy w tej transakcji</div><div style="margin-top:8px">' + t.errors.map((e) => '<span class="etag">' + AV.esc(e) + '</span>').join('') + '</div></div>' : '') +
      '<div class="grid" style="grid-template-columns:1fr 1fr 1.2fr;margin-top:16px"><div class="card flat"><div class="lbl">Twoja notatka</div><p style="margin-top:8px;line-height:1.55;font-size:14px">' + AV.esc(t.note) + '</p><div class="chips" style="margin-top:12px"><span class="chip on">' + AV.esc(t.emotion) + '</span><span class="chip">' + AV.esc(t.setup) + '</span></div></div>' +
      '<div class="card flat"><div class="lbl">Checklista</div><div class="val ' + (t.checklist === 7 ? 'win' : t.checklist >= 5 ? 'warnc' : 'loss') + '">' + t.checklist + ' / 7</div><div class="bar"><i style="width:' + t.checklist / 7 * 100 + '%;background:' + (t.checklist === 7 ? 'var(--win)' : t.checklist >= 5 ? 'var(--warn)' : 'var(--loss)') + '"></i></div></div>' +
      '<div class="card hl"><div class="lbl">α Mentor o tej transakcji</div><p style="margin-top:8px;line-height:1.55;font-size:14px">' + AV.esc(t.mentor) + '</p></div></div>' +
      '<div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px;flex-wrap:wrap"><button class="btn g" data-trade-edit="' + t.id + '">Edytuj</button><button class="btn w" data-trade-del="' + t.id + '">Usuń transakcję</button></div>');
    $('#tm').classList.add('on');
    AV.bindDrops($('#tmBody'), (data, key) => {
      if (!t.screens) t.screens = {};
      if (data) t.screens[key] = data; else delete t.screens[key];
      AV.saveNow();
      AV.openTrade(t.id);
      AV.toast(data ? 'Screen zapisany' : 'Screen usunięty');
    });
  };

  /* ---------- playbook ---------- */
  AV.renderPlaybook = () => {
    const s = AV.state;
    $('#pbList').innerHTML = s.setups.map((x) => {
      const st = AV.setupStats(s, x.name);
      const net = st.net;
      const up = net >= 0;
      return '<div class="card setup ' + (x.bad ? 'bad' : '') + '"><div class="top2"><div><div class="lbl">' + AV.esc(x.tf) + '</div><h3 style="margin-top:6px">' + AV.esc(x.name) + '</h3></div>' + AV.grade(x.grade) + '</div>' +
        '<div style="margin-top:12px;border-radius:12px;overflow:hidden;border:1px solid var(--line)">' + AV.chart(x.seed || 17, up ? 'up' : 'down', true, 'NAJLEPSZY PRZYKŁAD').replace('height="230"', 'height="120"') + '</div>' +
        '<ul>' + (x.rules || []).map((q) => '<li>' + AV.esc(q) + '</li>').join('') + '</ul>' +
        '<div class="st3"><div><div class="lbl">Transakcje</div><b>' + st.n + '</b></div><div><div class="lbl">Skuteczność</div><b class="' + (st.wr >= 50 ? 'win' : 'loss') + '">' + Math.round(st.wr) + '%</b></div><div><div class="lbl">Śr. wynik</div><b class="' + (up ? 'win' : 'loss') + '">' + AV.signed(st.avgR, 1, 'R') + '</b></div></div>' +
        '<div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px;gap:8px;flex-wrap:wrap"><b class="mono ' + (up ? 'win' : 'loss') + '">' + AV.dispMoney(net, 2) + '</b>' +
        (x.bad ? '<span class="tag alert">nie graj tego</span>' : '<span class="link" style="font-size:12.5px" data-setup-trades="' + AV.attr(x.name) + '">Transakcje →</span>') +
        '<span class="link" style="font-size:12px" data-setup-edit="' + x.id + '">Edytuj</span></div></div>';
    }).join('') + '<div class="card" style="border-style:dashed;display:grid;place-items:center;text-align:center;cursor:pointer;min-height:300px" id="addSetup2"><div><div style="width:48px;height:48px;border-radius:14px;margin:0 auto 12px;display:grid;place-items:center;background:var(--alphaS);color:var(--alpha)">' + AV.ic('plus') + '</div><b>Dodaj setup</b><div class="hint">nazwa, zasady, interwał, przykład</div></div></div>';
  };

  AV.openSetupForm = (id) => {
    const s = AV.state;
    const x = id ? s.setups.find((y) => y.id === id) : null;
    const body = AV.openModal('<div class="eyebrow">Playbook</div><h1 style="font-size:28px;margin-top:6px">' + (x ? 'Edytuj setup' : 'Nowy setup') + '</h1>' +
      '<div class="grid g2" style="margin-top:16px"><div><span class="label">Nazwa</span><input class="input" id="suName" value="' + AV.attr(x?.name || '') + '" placeholder="np. Retest wybicia"></div><div><span class="label">Interwał</span><input class="input" id="suTf" value="' + AV.attr(x?.tf || 'M15 · H1') + '" placeholder="M15 · H1"></div></div>' +
      '<span class="label">Ocena jakości</span><div class="chips sel" id="suGrade"><span class="chip ' + (!x || x.grade === 'A' ? 'on' : '') + '" data-g="A">A</span><span class="chip ' + (x?.grade === 'B' ? 'on' : '') + '" data-g="B">B</span><span class="chip ' + (x?.grade === 'C' ? 'on' : '') + '" data-g="C">C</span></div>' +
      '<span class="label">3 zasady wejścia</span>' +
      [0, 1, 2].map((i) => '<input class="input" id="suRule' + i + '" value="' + AV.attr(x?.rules?.[i] || '') + '" placeholder="Zasada ' + (i + 1) + '" style="margin-bottom:8px">').join('') +
      '<label class="check" style="margin-top:10px"><span class="box" id="suBadBox"></span><span>Oznacz jako setup, którego nie powinnaś grać</span></label>' +
      '<div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px"><button class="btn g" data-gclose>Anuluj</button><button class="btn p" id="suSave">Zapisz setup</button></div>', { maxWidth: '620px' });
    let bad = !!x?.bad;
    const box = $('#suBadBox', body);
    box.classList.toggle('on', bad);
    box.parentElement.onclick = () => { bad = !bad; box.classList.toggle('on', bad); };
    $('#suGrade', body).onclick = (e) => {
      const c = e.target.closest('.chip'); if (!c) return;
      $$('#suGrade .chip', body).forEach((z) => z.classList.toggle('on', z === c));
    };
    $('#suSave', body).onclick = () => {
      const name = $('#suName', body).value.trim();
      const rules = [0, 1, 2].map((i) => $('#suRule' + i, body).value.trim()).filter(Boolean);
      if (!name) { AV.toast('Podaj nazwę setupu'); return; }
      if (rules.length < 2) { AV.toast('Dodaj co najmniej 2 zasady'); return; }
      const grade = $('#suGrade .chip.on', body)?.dataset.g || 'B';
      const payload = { name, tf: $('#suTf', body).value.trim() || '—', grade, rules, bad, seed: Math.floor(Math.random() * 200) + 1 };
      if (x) Object.assign(x, payload);
      else s.setups.push({ id: AV.uid('pb'), ...payload });
      AV.saveNow(); AV.closeModal(); AV.renderPlaybook(); AV.toast(x ? 'Setup zapisany' : 'Setup dodany');
      if (x) AV.renderAll();
    };
  };

  /* ---------- checklista formularza ---------- */
  AV.ui.ntGradeManual = false;
  AV.renderChecklist = () => {
    const s = AV.state;
    const points = (s.settings.checklist && s.settings.checklist.length ? s.settings.checklist : ['Trend wyższego TF', 'Poziom z planu', 'Potwierdzenie', 'SL przed wejściem', 'R:R ≥ 2', 'Brak makro 15 min', 'Spokojna głowa']).slice(0, 8);
    AV.ui.ntChecks = AV.ui.ntChecks || {};
    points.forEach((p) => {
      if (AV.ui.ntChecks[p] == null) AV.ui.ntChecks[p] = false;
    });
    AV.autoChecklist(points);
    const n = points.filter((p) => AV.ui.ntChecks[p]).length;
    const total = points.length;
    const col = n === total ? 'var(--win)' : n >= Math.ceil(total * 0.7) ? 'var(--warn)' : 'var(--loss)';
    const ring = $('#clRing');
    if (ring) { ring.style.setProperty('--p', n / total * 100); ring.style.setProperty('--c', col); }
    AV.setText('#clN', n + '/' + total);
    if ($('#clN')) $('#clN').style.color = col;
    const w = $('#clWarn');
    if (w) {
      const missing = total - n;
      w.style.display = n === total ? 'none' : 'block';
      w.className = 'warnbox ' + (n >= Math.ceil(total * 0.7) ? 'y' : '');
      w.innerHTML = 'Brakuje ' + missing + ' ' + AV.plural(missing, 'punktu', 'punktów', 'punktów') + '.';
    }
    AV.setHTML('#clList', points.map((p, i) => {
      const auto = ['Poziom z planu', 'SL przed wejściem', 'R:R ≥ 2'].includes(p);
      const on = !!AV.ui.ntChecks[p];
      return '<div class="cl ' + (on ? 'on' : '') + ' ' + (auto ? 'cflag' : '') + '" data-cl="' + AV.attr(p) + '"><span class="box ' + (on ? 'on' : '') + '">' + AV.ic('check') + '</span><div class="t">' + AV.esc(p) + '<small>' + AV.esc(AV.checklistHint(p)) + '</small></div>' + (auto ? '<span class="auto">AUTO</span>' : '') + '</div>';
    }).join(''));
    const g = n === total ? 'A' : n >= Math.ceil(total * 0.7) ? 'B' : 'C';
    const sug = $('#sugg');
    if (sug) sug.textContent = n === 0 && !AV.ui.ntGradeManual ? 'SUGEROWANA: —' : 'SUGEROWANA: ' + g;
    if (!AV.ui.ntGradeManual) {
      if (n === 0) $$('.gp').forEach((x) => x.classList.remove('on'));
      else $$('.gp').forEach((x) => x.classList.toggle('on', x.dataset.g === g));
    }
  };

  AV.checklistHint = (p) => {
    const m = {
      'Trend wyższego TF': 'D1 / H4', 'Poziom z planu': 'cena z planu dnia', 'Potwierdzenie': 'zamknięcie świecy albo retest',
      'SL przed wejściem': 'stop loss ustawiony', 'R:R ≥ 2': 'minimum 1:2', 'Brak makro 15 min': 'sprawdź kalendarz',
      'Spokojna głowa': 'check-in i gotowość'
    };
    return m[p] || 'Twój punkt';
  };

  AV.autoChecklist = (points) => {
    const en = AV.pnum($('#ntEntry')?.value), sl = AV.pnum($('#ntSl')?.value), tp = AV.pnum($('#ntTp')?.value);
    const dir = $('#ntDir .chip.on')?.dataset.d || 'l';
    if (points.includes('SL przed wejściem')) AV.ui.ntChecks['SL przed wejściem'] = !!sl && (dir === 'l' ? sl < en : sl > en);
    if (points.includes('R:R ≥ 2')) {
      const risk = Math.abs(en - sl), reward = Math.abs(tp - en);
      AV.ui.ntChecks['R:R ≥ 2'] = !!(risk && reward && reward / risk >= 2);
    }
    if (points.includes('Poziom z planu')) {
      const p = (AV.state.plans || {})[AV.dateKey(new Date())];
      const near = (p?.levels || []).some((l) => {
        const x = AV.pnum(l.price);
        return x && en && Math.abs(x - en) / en < 0.003;
      });
      AV.ui.ntChecks['Poziom z planu'] = near;
    }
  };

  AV.renderNTMeta = () => {
    const en = AV.pnum($('#ntEntry')?.value), sl = AV.pnum($('#ntSl')?.value), tp = AV.pnum($('#ntTp')?.value);
    const size = AV.pnum($('#ntSize')?.value);
    const cap = AV.totalCapital(AV.state);
    const limitPct = Number(AV.state.settings.maxRisk) || 1;
    const limitAmt = cap > 0 ? cap * limitPct / 100 : null;
    const riskAmt = en && sl && size ? Math.abs(en - sl) * size : null;
    const riskPct = riskAmt != null && cap > 0 ? riskAmt / cap * 100 : null;
    const slPct = en && sl ? Math.abs(en - sl) / en : 0;
    const rr = slPct && tp ? Math.abs(tp - en) / Math.abs(en - sl) : 0;
    const over = riskPct != null && riskPct > limitPct + 1e-9;
    const el = $('#ntRisk');
    if (el) el.innerHTML = 'Limit: <b>' + (limitAmt == null ? '—' : AV.fmt(limitAmt, 2) + ' ' + AV.curLabel() + ' (' + AV.fmt(limitPct, 2) + '%)') + '</b>' +
      ' · Ryzyko: <b class="' + (over ? 'loss' : 'win') + '">' + (riskAmt == null ? '—' : AV.fmt(riskAmt, 2) + ' ' + AV.curLabel() + ' (' + (riskPct == null ? '—' : AV.fmt(riskPct, 2) + '%') + ')') + '</b>' +
      ' · R:R <b>' + (rr ? '1 : ' + AV.fmt(rr, 1) : '—') + '</b> · <span class="link" data-go="kalk" style="font-size:13.5px">Kalkulator Alpha</span>';
  };

  AV.renderNewTradeForm = () => {
    const d = AV.getDraft();
    AV.setVal('#ntPair', d.pair || '');
    AV.setVal('#ntEntry', d.entry || '');
    AV.setVal('#ntSize', d.size || '');
    AV.setVal('#ntSl', d.sl || '');
    AV.setVal('#ntTp', d.tp || '');
    AV.setVal('#ntNote', d.note || '');
    $$('#ntDir .chip').forEach((c) => c.classList.toggle('on', c.dataset.d === (d.dir || 'l')));
    $$('#ntSetup .chip').forEach((c) => c.classList.toggle('on', c.dataset.s === (d.setup || 'Bez setupu')));
    $$('#ntEmotion .chip').forEach((c) => c.classList.toggle('on', c.textContent === (d.emotion || 'Spokój')));
    $$('#errChips .chip.err').forEach((c) => c.classList.toggle('on', (d.errors || []).includes(c.textContent)));
    AV.ui.ntChecks = d.checks || {};
    AV.ui.ntGradeManual = !!d.gradeManual;
    $$('.gp').forEach((g) => g.classList.toggle('on', d.grade ? g.dataset.g === d.grade : false));
    AV.renderChecklist();
    AV.renderNTMeta();
    AV.renderDrops(d.screens || {});
    AV.show('#cancelEdit', !!AV.ui.editId);
    AV.setText('#saveTrade', AV.ui.editId ? 'Zapisz zmiany' : 'Zapisz transakcję');
  };

  AV.getDraft = () => {
    try {
      const d = JSON.parse(AV.safeGet(AV.draftKey()) || '{}');
      const demoDraft = d && d.pair === 'BTC/USDT' && d.entry === '69 120' && d.size === '0,01' &&
        d.sl === '68 500' && d.tp === '70 800' && String(d.note || '').startsWith('Retest 68 500 z planu');
      if (demoDraft) { AV.safeDel(AV.draftKey()); return {}; }
      return d;
    } catch { return {}; }
  };
  AV.saveDraft = () => {
    const d = {
      pair: $('#ntPair')?.value, entry: $('#ntEntry')?.value, size: $('#ntSize')?.value,
      sl: $('#ntSl')?.value, tp: $('#ntTp')?.value, note: $('#ntNote')?.value,
      dir: $('#ntDir .chip.on')?.dataset.d || 'l', setup: $('#ntSetup .chip.on')?.dataset.s || 'Bez setupu',
      emotion: $('#ntEmotion .chip.on')?.textContent || 'Spokój',
      errors: $$('#errChips .chip.err.on').map((c) => c.textContent),
      checks: AV.ui.ntChecks || {}, grade: $('.gp.on')?.dataset.g || '',
      gradeManual: AV.ui.ntGradeManual, screens: AV.ui.ntScreens || {}
    };
    AV.safeSet(AV.draftKey(), JSON.stringify(d));
  };

  AV.renderDrops = (screens) => {
    AV.ui.ntScreens = screens || {};
    $$('#nowa [data-drop]').forEach((d, i) => {
      const key = i === 0 ? 'before' : 'after';
      const img = d.querySelector('img');
      if (img) img.remove();
      d.classList.remove('has');
      if (AV.ui.ntScreens[key]) AV.showImage(d, AV.ui.ntScreens[key]);
    });
  };

  /* ---------- wybór planu ---------- */
  AV.setPlan = (solo, silent) => {
    const s = AV.state;
    s.subscription.plan = solo ? 'solo' : 'full';
    AV.saveNow();
    AV.show('#akUp', solo);
    AV.show('#akFull', !solo);
    $$('.strip').forEach((e) => e.style.display = solo ? 'none' : 'flex');
    if (AV.renderNavBadges) AV.renderNavBadges();
    AV.renderUser();
    if (!silent) AV.toast(solo ? 'Podgląd: plan Alpha Vault bez Akademii' : 'Plan Akademia Alpha + Vault');
  };
})();
