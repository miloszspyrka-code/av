/* Alpha Vault — renderowanie widoków (część 2) */
(function () {
  'use strict';
  const AV = window.AV, $ = AV.$, $$ = AV.$$;

  /* ---------- statystyki ---------- */
  AV.bindStats = () => {
    $('#statSeg')?.addEventListener('click', (e) => {
      const b = e.target.closest('button'); if (!b) return;
      AV.ui.statRange = b.dataset.r === 'all' ? 'all' : Number(b.dataset.r);
      AV.renderStats();
    });
  };
  AV.ui.statRange = 90;
  AV.renderStats = () => {
    const s = AV.state;
    const trades = AV.rangeTrades(s, AV.ui.statRange);
    const st = AV.computeStats(trades, s);
    $$('#statSeg button').forEach((b) => b.classList.toggle('on', Number(b.dataset.r) === AV.ui.statRange || b.dataset.r === 'all' && AV.ui.statRange === 'all'));
    const setVal = (id, txt, cls) => { const el = $('#' + id); if (!el) return; el.textContent = txt; if (cls) el.className = 'val ' + cls; };
    setVal('stResult', AV.dispMoney(st.net, 2), st.net >= 0 ? 'win' : 'loss');
    AV.setText('#stResultHint', st.n + ' ' + AV.plural(st.n, 'transakcja', 'transakcje', 'transakcji'));
    setVal('stWr', Math.round(st.winRate) + '%', '');
    AV.setText('#stWrHint', st.wins + ' zyski · ' + st.losses + ' straty');
    setVal('stPf', st.pf >= 99 ? '∞' : AV.fmt(st.pf, 2), '');
    AV.setText('#stPfHint', AV.fmt(st.gp, 2) + ' ÷ ' + AV.fmt(st.gl, 2));
    setVal('stExp', AV.signed(st.expR, 2, 'R'), 'al');
    AV.setText('#stExpHint', '≈ ' + AV.dispMoney(st.n ? st.net / st.n : 0, 2) + ' na transakcję');
    setVal('stDd', AV.fmt(st.maxDDpct, 1) + '%', 'loss');
    AV.setText('#stDdHint', AV.dispMoney(st.maxDD, 2));
    setVal('stSl', Math.round(st.withSL) + '%', st.withSL >= 80 ? 'win' : 'warnc');
    AV.setText('#stSlHint', st.withSLCount + ' z ' + st.n + ' transakcji');
    setVal('stPlan', Math.round(st.planRate) + '%', '');
    AV.setText('#stPlanHint', 'cel: 85% · ' + st.planCount + ' z planem');
    setVal('stFees', '−' + AV.fmt(st.fees, 2), 'warnc');
    AV.setText('#stFeesHint', st.gp ? Math.round(st.fees / st.gp * 100) + '% zysków brutto' : 'brak zysków');
    AV.renderEquity(st);
    AV.renderPnlCal(st);
    AV.renderGradeStats(st);
    AV.renderChecklistStats(st);
    AV.renderErrorCost(st);
    AV.renderMae(st);
    AV.renderGroups(st);
    AV.setText('#eqLabel', AV.dispMoney(AV.startCapital(s), 0) + ' → ' + AV.dispMoney(AV.startCapital(s) + st.net, 0));
    AV.setText('#pnlCalLabel', AV.ui.statRange === 'all' ? 'wszystko' : AV.ui.statRange + ' dni');
  };

  AV.renderEquity = (st) => {
    const svg = $('#eqSvg');
    if (!svg) return;
    const pts = st.curve.length ? st.curve : [{ x: 0, eq: AV.startCapital(AV.state) || 2000 }];
    const vals = pts.map((p) => p.eq), mn = Math.min(...vals), mx = Math.max(...vals), span = mx - mn || 1;
    const X = (i) => i / Math.max(1, pts.length - 1) * 600;
    const Y = (v) => 200 - (v - mn) / span * 180;
    let g = '<defs><linearGradient id="eqg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" style="stop-color:var(--alpha)" stop-opacity=".4"/><stop offset="1" style="stop-color:var(--alpha)" stop-opacity="0"/></linearGradient></defs>';
    [55, 110, 165].forEach((yy) => { g += '<line x1="0" x2="600" y1="' + yy + '" y2="' + yy + '" stroke="rgba(255,255,255,.06)"/>'; });
    const path = pts.map((p, i) => (i ? 'L' : 'M') + X(i).toFixed(1) + ',' + Y(p.eq).toFixed(1)).join(' ');
    g += '<path d="' + path + ' L600,220 L0,220 Z" fill="url(#eqg)"/>';
    g += '<path d="' + path + '" fill="none" style="stroke:var(--alpha)" stroke-width="2.5"/>';
    g += '<text x="0" y="214" fill="#7f786f" font-size="10" font-family="JetBrains Mono">' + AV.fmt(mn, 0) + '</text>';
    g += '<text x="600" y="12" fill="#7f786f" font-size="10" text-anchor="end" font-family="JetBrains Mono">' + AV.fmt(mx, 0) + '</text>';
    svg.innerHTML = g;
  };

  AV.renderPnlCal = (st) => {
    const el = $('#pnlCal');
    if (!el) return;
    const today = AV.startOfDay(new Date());
    const start = AV.addDays(AV.monday(today), -28);
    let h = ['pn', 'wt', 'śr', 'czw', 'pt', 'sb', 'nd'].map((d) => '<i class="h">' + d + '</i>').join('');
    for (let i = 0; i < 35; i++) {
      const d = AV.addDays(start, i), k = AV.dateKey(d);
      const v = st.daily[k] || 0;
      const future = d > today;
      const a = Math.min(0.85, 0.12 + Math.abs(v) / 80);
      const bg = future ? 'rgba(255,255,255,.015)' : v === 0 ? 'rgba(255,255,255,.04)' : v > 0 ? 'rgba(79,207,138,' + a + ')' : 'rgba(255,93,98,' + a + ')';
      h += '<i style="background:' + bg + '" title="' + k + ': ' + (v ? AV.money(v, 2) : 'bez handlu') + '"></i>';
    }
    el.innerHTML = h;
  };

  AV.renderGradeStats = (st) => {
    const max = Math.max(1, ...st.byGrade.map((g) => Math.abs(g.net)));
    const rows = st.byGrade.map((g) => {
      const w = Math.abs(g.net) / max * 50;
      const left = g.net >= 0 ? 50 : 50 - w;
      const col = g.net >= 0 ? 'var(--win)' : 'var(--loss)';
      return '<div class="hbar"><span><span class="grade ' + g.grade + '" style="width:24px;height:24px;font-size:12px">' + g.grade + '</span> <span class="mut" style="font-size:13px">' + g.n + ' tr. · ' + Math.round(g.wr) + '%</span></span><div class="tr"><i style="left:' + left + '%;width:' + w + '%;background:' + col + '"></i></div><b class="' + (g.net >= 0 ? 'win' : 'loss') + ' mono" style="text-align:right">' + AV.signed(g.net, 2, '') + '</b></div>';
    }).join('');
    AV.setHTML('#gradeStats', rows || '<div class="dim">Brak danych w wybranym okresie.</div>');
    const a = st.byGrade.find((g) => g.grade === 'A'), b = st.byGrade.find((g) => g.grade === 'B'), c = st.byGrade.find((g) => g.grade === 'C');
    AV.setHTML('#gradeInsight', c && c.net < 0
      ? 'Gdybyś grała tylko transakcje A i B, wynik wynosiłby <b>' + AV.signed((a?.net || 0) + (b?.net || 0), 2, '') + '</b> zamiast ' + AV.signed(st.net, 2, '') + '.'
      : 'Nie masz w tym okresie stratnych transakcji C. Tak trzymaj.');
  };

  AV.renderChecklistStats = (st) => {
    const total = st.n || 1;
    const fullW = Math.abs(st.full.net) / Math.max(1, Math.abs(st.full.net), Math.abs(st.part.net)) * 50;
    AV.setHTML('#checklistStats',
      '<div class="hbar"><span class="mut" style="font-size:13px">7/7 · ' + st.full.n + ' tr.</span><div class="tr"><i style="left:50%;width:' + (st.full.net >= 0 ? fullW : 0) + '%;background:var(--win)"></i></div><b class="win mono" style="text-align:right">' + AV.signed(st.full.net, 2, '') + '</b></div>' +
      '<div class="hbar"><span class="mut" style="font-size:13px">≤6/7 · ' + st.part.n + ' tr.</span><div class="tr"><i style="left:50%;width:' + Math.abs(st.part.net) / Math.max(1, Math.abs(st.full.net), Math.abs(st.part.net)) * 50 + '%;background:' + (st.part.net >= 0 ? 'var(--warn)' : 'var(--loss)') + '"></i></div><b class="' + (st.part.net >= 0 ? 'warnc' : 'loss') + ' mono" style="text-align:right">' + AV.signed(st.part.net, 2, '') + '</b></div>' +
      '<div class="kv" style="margin-top:8px"><span>Skuteczność z pełną checklistą</span><b class="win">' + Math.round(st.full.wr) + '%</b></div>' +
      '<div class="kv"><span>Skuteczność bez niej</span><b class="loss">' + Math.round(st.part.wr) + '%</b></div>' +
      '<div class="kv"><span>Transakcje bez SL</span><b class="' + (st.withSL >= 90 ? 'win' : 'loss') + '">' + (total - st.withSLCount) + '</b></div>');
  };

  AV.renderErrorCost = (st) => {
    const el = $('#errCost');
    if (!el) return;
    const arr = st.errors.slice(0, 7);
    const mx = Math.max(1, ...arr.map((e) => Math.abs(e.cost)));
    const tot = arr.reduce((s, e) => s + e.cost, 0);
    const rows = arr.length ? arr.map((e) => '<div class="hbar" style="grid-template-columns:170px 1fr 90px"><span style="font-size:13.5px">' + AV.esc(e.key) + ' <span class="dim mono" style="font-size:11.5px">' + e.n + '×</span></span><div class="tr" style="background:rgba(255,255,255,.04)"><i style="left:0;width:' + Math.min(100, Math.abs(e.cost) / mx * 100) + '%;background:linear-gradient(90deg,rgba(255,93,98,.5),var(--loss))"></i></div><b class="' + (e.cost >= 0 ? 'win' : 'loss') + ' mono" style="text-align:right">' + AV.signed(e.cost, 2, '') + '</b></div>').join('') : '<div class="dim">Brak błędów w tym okresie. Tak trzymaj.</div>';
    const worst = arr[0];
    el.innerHTML = '<div>' + rows + '</div><div><div class="lbl">Błędy razem</div><div class="val ' + (tot >= 0 ? 'win' : 'loss') + '" style="font-size:36px">' + AV.signed(tot, 2, '') + '</div><div class="hint">Suma kosztu błędów w wybranym okresie. To kwota, którą możesz odzyskać samą dyscypliną.</div>' + (worst ? '<div class="step"><b>Najdroższy błąd: ' + AV.esc(worst.key.toLowerCase()) + '.</b> ' + worst.n + ' ' + AV.plural(worst.n, 'raz', 'razy', 'razy') + ', łącznie ' + AV.signed(worst.cost, 2, '') + '. Twoje zasady celują dokładnie w niego.</div>' : '') + '</div>';
  };

  AV.renderMae = (st) => {
    const svg = $('#maeSvg');
    if (!svg) return;
    const trades = AV.rangeTrades(AV.state, AV.ui.statRange).filter((t) => t.mae != null);
    const X = (v) => 50 + Math.min(2.95, v) / 3 * 450;
    const Y = (v) => 260 - Math.min(3.9, v) / 4 * 240;
    let g = '';
    for (let v = 0; v <= 3; v++) g += '<line x1="' + X(v) + '" x2="' + X(v) + '" y1="20" y2="260" stroke="rgba(255,255,255,.05)"/><text x="' + X(v) + '" y="282" fill="#7f786f" font-size="11" text-anchor="middle" font-family="JetBrains Mono">' + v + 'R</text>';
    for (let v = 0; v <= 4; v++) g += '<line x1="50" x2="500" y1="' + Y(v) + '" y2="' + Y(v) + '" stroke="rgba(255,255,255,.05)"/><text x="40" y="' + (Y(v) + 4) + '" fill="#7f786f" font-size="11" text-anchor="end" font-family="JetBrains Mono">' + v + 'R</text>';
    g += '<line x1="' + X(1) + '" x2="' + X(1) + '" y1="20" y2="260" stroke="#ff5d62" stroke-dasharray="4 4" opacity=".6"/><text x="' + (X(1) + 6) + '" y="32" fill="#ff5d62" font-size="10.5" font-family="JetBrains Mono">SL 1R</text>';
    if (st.avgExitR) g += '<line x1="50" x2="500" y1="' + Y(st.avgExitR) + '" y2="' + Y(st.avgExitR) + '" style="stroke:var(--alpha)" stroke-dasharray="4 4" opacity=".7"/><text x="496" y="' + (Y(st.avgExitR) - 6) + '" style="fill:var(--alpha)" font-size="10.5" text-anchor="end" font-family="JetBrains Mono">śr. wyjście ' + AV.fmt(st.avgExitR, 1) + 'R</text>';
    trades.forEach((t) => {
      g += '<circle cx="' + X(t.mae) + '" cy="' + Y(t.mfe) + '" r="5.5" fill="' + (t.result > 0 ? '#4fcf8a' : '#ff5d62') + '" fill-opacity=".75" stroke="#0c0b0a"><title>' + AV.esc(t.pair) + '</title></circle>';
    });
    g += '<text x="275" y="298" fill="#b7b0a6" font-size="11" text-anchor="middle" font-family="Archivo">MAE · ruch przeciw Tobie</text><text x="12" y="140" fill="#b7b0a6" font-size="11" text-anchor="middle" font-family="Archivo" transform="rotate(-90 12 140)">MFE · ruch na Twoją korzyść</text>';
    svg.innerHTML = g;
    const left = st.avgMfeWin - st.avgExitR;
    AV.setHTML('#maeStats', '<div class="kv"><span>Śr. MAE zyskownych</span><b class="mono">' + AV.fmt(st.avgMaeWin, 2) + 'R</b></div>' +
      '<div class="kv"><span>Śr. MFE zyskownych</span><b class="mono win">' + AV.fmt(st.avgMfeWin, 2) + 'R</b></div>' +
      '<div class="kv"><span>Śr. faktyczne wyjście</span><b class="mono">' + AV.fmt(st.avgExitR, 2) + 'R</b></div>' +
      '<div class="step" style="margin-top:6px"><b>Wniosek:</b> zyskowne transakcje rzadko schodzą niżej niż ' + AV.fmt(st.avgMaeWin, 2) + 'R, więc SL na 1R jest bezpieczny. Zamykasz średnio przy ' + AV.fmt(st.avgExitR, 2) + 'R, a cena idzie do ' + AV.fmt(st.avgMfeWin, 2) + 'R. <b>Zostawiasz na stole około ' + AV.fmt(Math.max(0, left), 2) + 'R na transakcji.</b> Spróbuj zamykać połowę pozycji na 1,5R, a resztę prowadzić.</div>');
  };

  AV.renderGroups = (st) => {
    const card = (title, rows, fmt) => '<h3 style="margin-bottom:12px">' + title + '</h3>' + rows.map((r) => '<div class="kv"><span>' + AV.esc(r.key) + '</span><b class="' + (r.net >= 0 ? 'win' : 'loss') + ' mono">' + (fmt ? fmt(r) : AV.signed(r.net, 2, '')) + '</b></div>').join('');
    AV.setHTML('#sessionStats', card('Wynik wg sesji', st.bySession));
    AV.setHTML('#emotionStats', card('Wynik wg emocji', st.byEmotion));
    AV.setHTML('#holdingStats', '<h3 style="margin-bottom:12px">Czas trzymania</h3>' +
      '<div class="kv"><span>Zyskowne</span><b class="mono">' + Math.round(st.avgDurWin) + ' min</b></div>' +
      '<div class="kv"><span>Stratne</span><b class="mono loss">' + Math.round(st.avgDurLoss) + ' min</b></div>' +
      '<div class="kv"><span>Przesunięty SL</span><b class="mono loss">' + st.shiftedSL + '×</b></div>');
  };

  /* ---------- mentor ---------- */
  AV.renderMentor = () => {
    const s = AV.state;
    const d = AV.mentorData(s);
    AV.setText('#notesCount', AV.mentorNotes(s).length);
    AV.setText('#mentorSees', '');
    AV.setHTML('#mentorSees',
      '<div class="kv" style="margin-top:6px"><span>Transakcje</span><b>' + AV.includedTrades(s).length + ' · 90 dni</b></div>' +
      '<div class="kv"><span>Plany dnia</span><b>' + Object.keys(s.plans).length + '</b></div>' +
      '<div class="kv"><span>Emocje i check-iny</span><b>' + s.checkins.length + '</b></div>' +
      '<div class="kv"><span>Kalendarz makro</span><b>ten tydzień</b></div>');
    const msgs = $('#msgs');
    if (msgs) {
      msgs.innerHTML = s.chat.map((m) => m.role === 'u'
        ? '<div class="msg u">' + m.html + '</div>'
        : '<div class="msg a"><div class="who">α MENTOR</div>' + m.html + '</div>').join('');
      msgs.scrollTop = 1e6;
    }
    AV.renderNotes();
    AV.renderMarketAnalysis();
    AV.renderWeekly();
    AV.setText('#analysisWhen', new Date().toLocaleDateString('pl-PL', { weekday: 'short', day: '2-digit', month: '2-digit' }) + ' · ' + new Date().toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' }));
    AV.renderQ();
    $$('#mChips .chip').forEach((c) => { c.style.pointerEvents = ''; });
  };

  AV.mentorNotes = (s) => {
    const notes = [];
    const today = AV.todayStats(s), L = AV.lockInfo(s);
    if (today.losses >= L.limit) notes.push({
      tag: 'alert', when: AV.dateTimeShort(new Date()), title: 'Złamana zasada: limit strat',
      text: 'Po ' + L.limit + ' stratach handel trwał dalej (' + today.closed.filter((t) => t.result < 0).length + ' strat, ' + AV.money(today.pnl, 2, '') + '). Tryb ochronny blokuje dziennik po limicie.',
      step: 'Jeśli chcesz obejść blokadę, musisz napisać dlaczego.'
    });
    const revenge = today.arr.filter((t) => (t.errors || []).includes('Wejście po stracie'));
    if (revenge.length) notes.push({
      tag: 'alert', when: AV.dateTimeShort(revenge[0].date), title: 'To wygląda na odgrywanie się',
      text: 'Po stracie pojawiła się ' + AV.esc(revenge[0].pair) + ' z błędem „wejście po stracie”. To emocja próbuje odrobić, nie plan.',
      step: 'Po każdej stracie 30 minut od wykresu.'
    });
    const st = AV.computeStats(AV.closedTrades(s), s);
    if (st.avgMfeWin > st.avgExitR + 0.4) notes.push({
      tag: 'warn', when: 'analiza 90 dni', title: 'Zostawiasz pieniądze na stole',
      text: 'Zamykasz zyski średnio przy ' + AV.fmt(st.avgExitR, 2) + 'R, a cena idzie do ' + AV.fmt(st.avgMfeWin, 2) + 'R. Stratę trzymasz ' + Math.round(st.avgDurLoss) + ' min, zysk ' + Math.round(st.avgDurWin) + ' min.',
      step: 'Połowa na 1,5R, reszta z SL na wejściu.'
    });
    const a = st.byGrade.find((g) => g.grade === 'A');
    if (a && a.n) notes.push({
      tag: 'praise', when: 'analiza 90 dni', title: 'Transakcje A to Twoja przewaga',
      text: a.n + ' transakcji z oceną A dało ' + AV.signed(a.net, 2, '') + ' przy ' + Math.round(a.wr) + '% skuteczności. Twoja strategia działa, kiedy jej słuchasz.',
      step: 'Graj tylko setupy z oceną A i B.'
    });
    const worst = st.bySession.filter((x) => x.n >= 2).sort((a, b) => a.net - b.net)[0];
    if (worst && worst.net < 0) notes.push({
      tag: 'warn', when: 'analiza sesji', title: 'Najsłabsza sesja: ' + worst.key,
      text: worst.n + ' transakcji w sesji ' + worst.key + ' dało ' + AV.signed(worst.net, 2, '') + '. To ' + Math.round(worst.n / Math.max(1, st.n) * 100) + '% Twoich transakcji.',
      step: 'W tej sesji graj połową pozycji albo obserwuj.'
    });
    return notes.slice(0, 5);
  };

  AV.renderNotes = () => {
    const notes = AV.mentorNotes(AV.state);
    AV.setHTML('#notesList', notes.length ? notes.map((n) => '<div class="card ins"><span class="tag ' + n.tag + '">' + (n.tag === 'alert' ? 'Alarm' : n.tag === 'warn' ? 'Uwaga' : 'Brawo') + ' · ' + AV.esc(n.when) + '</span><h3>' + AV.esc(n.title) + '</h3><p>' + AV.esc(n.text) + '</p><div class="step"><b>Krok:</b> ' + AV.esc(n.step) + '</div></div>').join('') : '<div class="card"><div class="dim">Brak uwag. Dziennik jest czysty.</div></div>');
  };

  AV.renderMarketAnalysis = () => {
    const s = AV.state;
    const events = AV.buildEvents();
    const next = events.filter((e) => e.imp === 3 && new Date(e.date) > new Date()).sort((a, b) => new Date(a.date) - new Date(b.date))[0];
    const inst = AV.ui.mktInst || 'BTC';
    const trades = AV.closedTrades(s).filter((t) => t.pair.includes(inst));
    const wins = trades.filter((t) => t.result > 0);
    const net = trades.reduce((a, t) => a + t.result, 0);
    const weekEvents = events.filter((e) => e.imp === 3 && new Date(e.date) >= AV.monday(new Date()) && new Date(e.date) <= AV.addDays(AV.monday(new Date()), 7));
    const base = AV.INST.crypto.find((x) => x[0].startsWith(inst)) || AV.INST.crypto[0];
    AV.setHTML('#marketAnalysis',
      '<h2 style="font-size:24px;margin-top:8px;letter-spacing:-.5px">' + (next ? AV.esc(next.name) : 'Spokojny tydzień bez ★★★') + '</h2>' +
      '<p class="mut" style="margin-top:12px;line-height:1.65">' + (next ? 'Najbliższa publikacja: ' + AV.esc(next.name) + ', ' + AV.eventStatus(next.date)[1] + ' o ' + AV.timeFmt(next.date, s) + '. W minutach publikacji zmienność skacze, a spready się rozszerzają. W takie dni wygrywa ten, kto robi mniej.' : 'W kalendarzu nie ma w najbliższych dniach publikacji ★★★. To dobry moment na spokojne trzymanie się planu.') + '</p>' +
      '<h3 style="margin-top:20px">Na co patrzeć</h3><ul class="steps" style="margin-top:4px">' +
      '<li><span class="n">1</span><span><b>' + (next ? AV.esc(next.name) + ' — ' + AV.esc(next.desc) + '.' : 'Brak wydarzeń ★★★.') + '</b> ' + (next ? '15 minut przed i po to strefa losowych knotów.' : 'Skup się na swoich poziomach z planu.') + '</span></li>' +
      '<li><span class="n">2</span><span><b>Dolar i rentowności.</b> Mocny dolar zwykle ciąży BTC i Nasdaqowi. Patrz na DXY przed wejściem.</span></li>' +
      '<li><span class="n">3</span><span><b>Fundament ' + AV.esc(inst) + '.</b> Zmienność 7D jest podwyższona. Zmniejsz pozycję, jeśli nie masz potwierdzenia.</span></li></ul>' +
      '<div class="step"><b>Dla Ciebie:</b> w dni z danymi makro masz ' + AV.mentorData(s).macroWynik + ' na ' + trades.length + ' transakcjach w tym instrumencie. ' + (next ? 'Najbliższe okno: ' + AV.eventStatus(next.date)[1] + ' ' + AV.timeFmt(next.date, s) + '.' : '') + '</div>' +
      '<p class="dim" style="font-size:12px;margin-top:14px">Analiza edukacyjna, nie porada inwestycyjna ani sygnał.</p>');
    AV.setHTML('#marketInstStats',
      '<div class="kv" style="margin-top:10px"><span>Trend D1</span><b class="win">wzrostowy</b></div>' +
      '<div class="kv"><span>Zmienność 7D</span><b class="warnc">podwyższona</b></div>' +
      '<div class="kv"><span>Ważne poziomy</span><b class="mono">67 800 · 71 200</b></div>' +
      '<div class="kv"><span>Twoja skuteczność</span><b>' + (trades.length ? Math.round(wins.length / trades.length * 100) + '% · ' + trades.length + ' tr.' : 'brak danych') + '</b></div>' +
      '<div class="kv"><span>Wynik</span><b class="' + (net >= 0 ? 'win' : 'loss') + ' mono">' + AV.signed(net, 2, '') + '</b></div>');
    AV.setText('#weekRisk', weekEvents.length >= 4 ? 'Wysokie' : weekEvents.length ? 'Średnie' : 'Niskie');
    AV.setText('#weekRiskHint', weekEvents.length + ' ' + AV.plural(weekEvents.length, 'wydarzenie ★★★', 'wydarzenia ★★★', 'wydarzeń ★★★') + ' w tym tygodniu. ' + (weekEvents.length ? 'Mniejsze pozycje w dni publikacji.' : 'Możesz grać normalnie.'));
  };

  AV.renderWeekly = () => {
    const s = AV.state;
    const from = AV.startOfDay(AV.addDays(new Date(), -7));
    const trades = AV.closedTrades(s).filter((t) => new Date(t.date) >= from);
    const st = AV.computeStats(trades, s);
    const best = st.byEmotion[0], worst = st.byEmotion[st.byEmotion.length - 1];
    const a = st.byGrade.find((g) => g.grade === 'A');
    const title = st.net >= 0 ? 'Dobry tydzień' : 'Tydzień do przeanalizowania';
    AV.setHTML('#weeklyReport',
      '<span class="tag al">Raport tygodnia · ' + AV.dateShort(from) + '–' + AV.dateShort(new Date()) + '</span>' +
      '<h2 style="font-size:28px;margin-top:12px;letter-spacing:-.6px">' + title + ', który ' + (st.net >= 0 ? 'można było zrobić jeszcze lepszym.' : 'daje jasną lekcję.') + '</h2>' +
      '<div class="grid g3" style="margin-top:18px"><div><div class="lbl">Wynik</div><div class="val ' + (st.net >= 0 ? 'win' : 'loss') + '">' + AV.signed(st.net, 2, '') + '</div></div><div><div class="lbl">Transakcje</div><div class="val">' + st.n + '</div></div><div><div class="lbl">Ocena A</div><div class="val">' + a.n + ' z ' + st.n + '</div></div></div>' +
      '<h3 style="margin-top:24px">Co poszło dobrze</h3><p class="mut" style="margin-top:6px;line-height:1.65">' + (best ? 'Najlepsza emocja: ' + AV.esc(best.key) + ' dała ' + AV.signed(best.net, 2, '') + ' na ' + best.n + ' transakcjach.' : 'Brak danych.') + ' ' + (st.full.n ? 'Transakcje z pełną checklistą: ' + Math.round(st.full.wr) + '% skuteczności.' : 'Uzupełnij checklisty, żeby zobaczyć przewagę.') + '</p>' +
      '<h3 style="margin-top:18px">Co Cię kosztowało</h3><p class="mut" style="margin-top:6px;line-height:1.65">' + (worst ? 'Najsłabsza emocja: ' + AV.esc(worst.key) + ' dała ' + AV.signed(worst.net, 2, '') + '.' : 'Brak stratnych transakcji.') + ' ' + (st.shiftedSL ? 'Przesunięty SL: ' + st.shiftedSL + '×.' : 'SL nie był przesuwany.') + '</p>' +
      '<div class="step"><b>Jedna zmiana na ten tydzień:</b> ' + (st.part.n > st.full.n ? 'żadnej transakcji bez pełnej checklisty.' : st.shiftedSL ? 'nigdy nie przesuwaj SL w złą stronę.' : 'po 21:00 tylko obserwujesz.') + '</div>');
  };

  /* ---------- akademia ---------- */
  AV.warsawNow = () => new Date(new Date().toLocaleString('en-US', { timeZone: 'Europe/Warsaw' }));
  AV.liveInfo = () => {
    const now = new Date();
    const live = AV.nextLiveEvent();
    if (!live) return { now, target: null, sec: null, liveNow: false, live: null };
    const start = new Date(live.startAt);
    const end = live.endAt ? new Date(live.endAt) : null;
    const liveNow = start <= now && (!end || now < end);
    const sec = Math.max(0, Math.floor((start - now) / 1000));
    return { now, target: start, sec, liveNow, live };
  };

  AV.renderAcademy = () => {
    const s = AV.state;
    const data = AV.api.events || { live: [], consultations: [], unassigned: [], nextConsultation: null, sync: null };
    const live = AV.nextLiveEvent();
    const info = AV.liveInfo();
    const todayKey = AV.warsawDateKey(new Date());
    const set = (sel, text) => AV.setText(sel, text || '');
    const showEl = (sel, visible) => {
      const el = document.querySelector(sel);
      if (el) el.style.display = visible ? '' : 'none';
    };

    set('#liveTitle', live ? live.name : '');
    set('#liveWho', live ? (live.host || '') : '');
    set('#liveWhen', live ? (live.startLabel || '') : '');
    set('#liveCd', live
      ? (info.liveNow ? 'LIVE' : [info.sec / 3600 | 0, (info.sec % 3600) / 60 | 0, info.sec % 60].map((x) => String(x).padStart(2, '0')).join(':'))
      : '');
    $$('.liveIn').forEach((e) => {
      e.textContent = live
        ? (info.liveNow ? 'teraz' : 'za ' + (info.sec >= 3600 ? (info.sec / 3600 | 0) + ' h ' + ((info.sec % 3600) / 60 | 0) + ' min' : (info.sec / 60 | 0) + ' min'))
        : '';
    });
    const isToday = !!(live && live.localDate === todayKey);
    set('#liveBadge', live ? (isToday ? 'Live dziś' : 'Najbliższy live') : '');
    set('#liveTop', live ? (isToday ? 'Live dziś ' + live.localTime : live.startLabel) : '');
    showEl('#liveBadge', !!live);
    showEl('#liveWhen', !!live);
    showEl('#liveJoin', !!live);
    showEl('#liveRem', !!live);

    const c = data.nextConsultation;
    if (c) {
      set('#nextConsult', c.startLabel || '');
      const bits = [];
      if (c.host) bits.push('z ' + c.host);
      if (c.durationMinutes !== null && c.durationMinutes !== undefined) bits.push(c.durationMinutes + ' min');
      bits.push('Discord');
      set('#nextConsultWho', bits.join(' · '));
      let month = '';
      try {
        month = new Intl.DateTimeFormat('pl-PL', { month: 'long', timeZone: 'Europe/Warsaw' }).format(new Date(c.startAt));
      } catch (e) {
        month = '';
      }
      set('#consultMonth', month);
      AV.setHTML('#consultTopWrap', 'Twoja konsultacja: <b>' + AV.esc(c.startLabel || '') + '</b>' + (c.host ? ' z ' + AV.esc(c.host) : ''));
    } else {
      set('#nextConsult', '');
      set('#nextConsultWho', '');
      set('#consultMonth', '');
      set('#consultTopWrap', '');
    }

    const used = Number(s.meta.consultationUsed || 0);
    set('#consultUsed', used + ' z 3 h');
    const bar = document.querySelector('#consultBar');
    if (bar) bar.style.width = Math.min(100, (used / 3) * 100) + '%';

    const labels = ['PN', 'WT', 'ŚR', 'CZW', 'PT', 'SB', 'ND'];
    const [y, m, d] = todayKey.split('-').map(Number);
    const monday = new Date(Date.UTC(y, m - 1, d, 12));
    const dow = monday.getUTCDay() || 7;
    monday.setUTCDate(monday.getUTCDate() - dow + 1);

    const days = [];
    for (let i = 0; i < 7; i++) {
      const dt = new Date(monday);
      dt.setUTCDate(monday.getUTCDate() + i);
      const key = dt.toISOString().slice(0, 10);
      days.push({ key, label: labels[i], today: key === todayKey });
    }
    const liveEvents = (data.live || []).slice().sort((a, b) => new Date(a.startAt) - new Date(b.startAt));
    AV.setHTML('#acad', days.map((day) => {
      const events = liveEvents.filter((e) => e.localDate === day.key);
      return '<div class="aday ' + (day.today ? 'today' : '') + '"><div class="d">' + day.label + (day.today ? ' · DZIŚ' : '') + '</div>' +
        events.map((e) => '<b>' + AV.esc(e.name) + '</b><span>' + AV.esc(e.host || '') + '</span><span class="mono" style="margin-top:8px">' +
          AV.esc(e.localTime || '') + (e.userCount !== null && e.userCount !== undefined ? ' · ' + e.userCount + ' os.' : '') + '</span>').join('') +
        '</div>';
    }).join(''));

    const sync = data.sync;
    if (sync && sync.lastSuccessAt) {
      try {
        const dt = new Date(String(sync.lastSuccessAt).replace(' ', 'T') + 'Z');
        const label = new Intl.DateTimeFormat('pl-PL', {
          timeZone: 'Europe/Warsaw', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit'
        }).format(dt);
        set('#acadUpdated', (sync.status === 'error' ? 'błąd synchronizacji · ' : (sync.stale ? 'nieaktualne · ' : '')) + label);
      } catch (e) {
        set('#acadUpdated', '');
      }
    } else {
      set('#acadUpdated', '');
    }

    const adminCard = document.querySelector('#adminEventsCard');
    const unassigned = data.unassigned || [];
    if (adminCard) {
      const visible = !!(AV.api.isAdmin && unassigned.length);
      adminCard.style.display = visible ? '' : 'none';
      if (visible) {
        AV.setHTML('#adminEvents', unassigned.map((e) =>
          '<div class="row" style="margin-top:8px;align-items:center"><div><b>' + AV.esc(e.name) + '</b><span class="dim" style="display:block;font-size:12px">' +
          AV.esc(e.startLabel || '') + '</span></div><div style="display:flex;gap:6px">' +
          '<button class="btn g sm" data-event-category="live" data-event-id="' + AV.esc(e.id) + '">live</button>' +
          '<button class="btn g sm" data-event-category="consultation" data-event-id="' + AV.esc(e.id) + '">konsultacja</button>' +
          '</div></div>').join(''));
        set('#adminSyncState', sync && sync.stale ? 'synchronizacja nieaktualna' : (sync && sync.lastSuccessAt ? 'zsynchronizowano' : ''));
      }
    }

    AV.show('#akUp', s.subscription.plan === 'solo');
    AV.show('#akFull', s.subscription.plan !== 'solo');
    $$('.strip').forEach((e) => e.style.display = s.subscription.plan === 'solo' ? 'none' : 'flex');
    const nb = $('.navg button[data-v="akad"] .badge');
    if (nb) { nb.innerHTML = s.subscription.plan === 'solo' ? AV.ic('lock') : 'LIVE'; nb.className = s.subscription.plan === 'solo' ? 'badge g lk' : 'badge'; }
    const mb = $('.navg button[data-v="mentor"] .badge');
    if (mb) { mb.textContent = '3'; }
  };

  /* ---------- podatki ---------- */
  AV.renderTax = () => {
    const s = AV.state, t = AV.taxData(s);
    AV.setText('#taxRevenue', AV.fmt(t.revenue, 2) + ' zł');
    AV.setText('#taxCosts', AV.fmt(t.costs, 2) + ' zł');
    AV.setText('#taxLoss', AV.fmt(t.carry, 2) + ' zł');
    AV.setText('#taxDue', '≈ ' + AV.fmt(t.due, 0) + ' zł');
    AV.setText('#taxDueHint', 'od dochodu ' + AV.fmt(t.income, 2) + ' zł');
    AV.setText('#taxYearEyebrow', 'Podatki · rok ' + s.tax.year);
    AV.setHTML('#taxBody', t.ev.map((e) => '<tr><td class="mono dim">' + new Date(e.date).toLocaleDateString('pl-PL') + '</td><td>' + AV.esc(e.op) + '</td><td><span class="pill ' + (e.kind === 'cost' ? 'k' : 'p') + '">' + (e.kind === 'cost' ? 'koszt' : 'przychód') + '</span></td><td class="mono">' + e.currency + '</td><td class="mono" style="text-align:right">' + AV.fmt(e.amount, 2) + '</td><td class="mono ' + (e.fx === 1 ? 'dim' : '') + '" style="text-align:right">' + (e.fx === 1 ? '–' : AV.fmt(e.fx, 4)) + '</td><td class="mono" style="text-align:right">' + AV.fmt(e.pln, 2) + '</td></tr>').join('') || '<tr><td colspan="7" class="dim" style="text-align:center;padding:24px">Brak zdarzeń podatkowych. Dodaj wpłatę lub wypłatę w Koncie.</td></tr>');
    AV.setText('#taxNeutral', AV.closedTrades(s).length + ' transakcji');
    const mt = AV.closedTrades(s).filter((x) => x.source === 'MT5' || x.source === 'MT4').length;
    AV.setText('#taxForex', mt ? mt + ' transakcji' : 'brak w ' + s.tax.year);
    AV.setText('#taxCarry', (t.income > 0 ? '0,00 zł' : AV.fmt(Math.max(0, t.costs + t.carry - t.revenue), 2) + ' zł'));
  };

  /* ---------- kalendarz ---------- */
  AV.ui.weekOffset = 0;
  AV.renderCalendar = () => {
    const s = AV.state;
    const events = AV.buildEvents();
    const monday = AV.addDays(AV.monday(new Date()), AV.ui.weekOffset * 7);
    const sunday = AV.addDays(monday, 6);
    const list = events.filter((e) => {
      const d = new Date(e.date);
      return d >= AV.startOfDay(monday) && d <= new Date(sunday.getFullYear(), sunday.getMonth(), sunday.getDate(), 23, 59, 59);
    }).filter((e) => s.settings.macroTwo ? true : e.imp === 3);
    let h = '', day = '';
    list.forEach((e) => {
      const d = new Date(e.date), st = AV.eventStatus(e.date);
      const dl = d.toLocaleDateString('pl-PL', { weekday: 'long', day: 'numeric', month: 'long' });
      if (dl !== day) { h += '<div class="dayh">' + dl + '</div>'; day = dl; }
      h += '<div class="ev ' + (st[0] === 'done' ? 'past' : '') + '"><div class="t"><b>' + AV.timeFmt(e.date, s) + '</b>' + d.toLocaleDateString('pl-PL', { weekday: 'short', day: '2-digit', month: '2-digit' }) + '</div><div class="flag"><b>' + (AV.SYM[e.cur] || e.cur) + '</b>' + e.cur + '</div><div class="n">' + AV.esc(e.name) + '<small>' + AV.esc(e.desc) + ' · ' + AV.stars(e.imp) + '</small></div><span class="st ' + st[0] + '">' + st[1] + '</span></div>';
    });
    h += '<p class="dim" style="font-size:12px;margin-top:16px">Godziny w strefie: ' + AV.esc(s.user.timezone) + '. W podglądzie dane są lokalne; w wersji produkcyjnej kalendarz pobiera się sam.</p>';
    AV.setHTML('#evFull', h || '<div class="dim" style="padding:20px">Brak wydarzeń w tym tygodniu dla wybranego filtra.</div>');
    const next = events.filter((e) => e.imp === 3 && new Date(e.date) > new Date()).sort((a, b) => new Date(a.date) - new Date(b.date))[0];
    if (next) {
      const d = new Date(next.date), st = AV.eventStatus(next.date);
      AV.setHTML('#evNext', '<h2 style="font-size:22px;margin-top:10px;letter-spacing:-.4px">' + AV.esc(next.name) + '</h2><div class="mono al" style="margin-top:8px">' + d.toLocaleDateString('pl-PL', { weekday: 'long', timeZone: AV.tzMap[s.user.timezone] || 'Europe/Warsaw' }) + ' · ' + AV.timeFmt(next.date, s) + '</div><div class="hint">' + AV.esc(next.desc) + ' · ' + st[1] + '</div>');
    } else {
      AV.setHTML('#evNext', '<h2 style="font-size:22px;margin-top:10px">Brak ważnych publikacji</h2><div class="hint">spokojnie do końca tygodnia</div>');
    }
    AV.renderMacroStats();
    AV.renderMacroWidget(events);
    $$('#calSeg button').forEach((b) => b.classList.toggle('on', Number(b.dataset.w) === AV.ui.weekOffset));
    $('[data-setting="macroTwo"]')?.classList.toggle('on', !!s.settings.macroTwo);
    $('[data-setting="macroBefore"]')?.classList.toggle('on', !!s.settings.macroBefore);
    $('[data-setting="macroChecklist"]')?.classList.toggle('on', !!s.settings.macroChecklist);
  };

  AV.renderMacroStats = () => {
    const s = AV.state;
    const events = AV.buildEvents().filter((e) => e.imp === 3);
    const days = new Set(events.map((e) => AV.dateKey(new Date(e.date))));
    const trades = AV.closedTrades(s).filter((t) => days.has(AV.dateKey(new Date(t.date))));
    const net = trades.reduce((a, t) => a + t.result, 0);
    const wr = trades.length ? trades.filter((t) => t.result > 0).length / trades.length * 100 : 0;
    const noSL = trades.filter((t) => t.sl === 'brak').length;
    AV.setText('#macroStats', AV.signed(net, 2, ''));
    AV.setText('#macroStatsHint', events.length + ' dni z danymi ★★★ · skuteczność ' + Math.round(wr) + '% · ' + noSL + ' transakcje bez SL');
  };

  AV.renderMacroWidget = (events) => {
    const s = AV.state;
    const top = events.filter((e) => e.imp === 3).sort((a, b) => new Date(a.date) - new Date(b.date));
    const upcoming = top.filter((e) => new Date(e.date) > new Date(Date.now() - 36e5));
    const list = upcoming.slice(0, 5);
    AV.setHTML('#mwList', list.map((e) => {
      const d = new Date(e.date), st = AV.eventStatus(e.date);
      return '<div class="mev ' + (st[0] === 'done' ? 'past' : '') + '"><span class="cur">' + (AV.SYM[e.cur] || e.cur) + '</span><div class="t"><b>' + AV.timeFmt(e.date, s) + '</b>' + d.toLocaleDateString('pl-PL', { weekday: 'short' }) + '</div><div class="n">' + AV.esc(e.name) + '<small>' + e.cur + ' · ' + AV.stars(e.imp) + '</small></div><span class="st ' + st[0] + '">' + st[1] + '</span></div>';
    }).join('') || '<div class="dim" style="padding:12px">Brak wydarzeń.</div>');
    const n = list[0];
    const dot = $('#mwDot');
    if (n) {
      const d = new Date(n.date), m = (d - Date.now()) / 6e4, st = AV.eventStatus(n.date);
      AV.setText('#mwTxt', n.name.split(':')[0] + ' · ' + st[1] + (st[0] === 'done' || st[0] === 'live' ? '' : ', ' + AV.timeFmt(n.date, s)));
      if (dot) dot.className = m <= 15 ? 'pulse rd' : m < 120 ? 'pulse am' : 'pulse';
    } else {
      AV.setText('#mwTxt', 'spokojnie do końca tygodnia');
      if (dot) dot.className = 'pulse';
    }
  };

  /* ---------- ciało ---------- */
  AV.renderBody = () => {
    const s = AV.state;
    const c = (s.checkins || []).find((x) => x.date === AV.dateKey(new Date()));
    if (c) {
      $$('#checkMood .chip').forEach((ch) => ch.classList.toggle('on', ch.textContent === c.mood));
      $$('#checkEnergy .chip').forEach((ch) => ch.classList.toggle('on', ch.textContent === String(c.energy)));
      AV.setVal('#checkNote', c.note || '');
    }
  };

  /* ---------- giełdy ---------- */
  AV.exKey = 'weex';
  AV.renderExchangeList = () => {
    const c = AV.state.settings.connections;
    const set = (k, txt, cls) => { const el = $('[data-exstate="' + k + '"]'); if (el) { el.textContent = txt; el.className = cls || ''; } };
    set('weex', c.weex.connected ? '● połączona' : 'niepodłączona', c.weex.connected ? 'win' : '');
    set('okx', c.okx.connected ? '● połączona' : 'niepodłączona', c.okx.connected ? 'win' : '');
    set('bingx', c.bingx.connected ? '● połączona' : 'niepodłączona', c.bingx.connected ? 'win' : '');
    set('mt', c.mt.connected ? '● połączony' : 'forex · indeksy · złoto', c.mt.connected ? 'win' : 'warnc');
    set('file', 'CSV · XLS', '');
    const last = c.weex.lastSync || c.okx.lastSync || c.bingx.lastSync;
    const badge = $('#syncBadge');
    if (badge) badge.innerHTML = '<span class="dot"></span>' + (c.weex.connected ? 'WEEX' : c.okx.connected ? 'OKX' : c.bingx.connected ? 'BingX' : 'brak połączeń') + (last ? ' · ' + new Date(last).toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' }) : '');
  };

  AV.renderEx = (k) => {
    AV.exKey = k;
    $$('.exl button').forEach((b) => b.classList.toggle('on', b.dataset.ex === k));
    if (k === 'mt') { AV.renderMT(); return; }
    if (k === 'file') { AV.renderImport(); return; }
    const c = AV.state.settings.connections[k];
    const meta = {
      weex: { n: 'WEEX', f: ['API Key', 'Secret Key', 'Passphrase'], steps: ['Zaloguj się na <b>weex.com</b> i kliknij ikonę profilu w prawym górnym rogu.', 'Wejdź w <b>Zarządzanie API</b> i kliknij <b>Utwórz klucz API</b>.', 'Nazwij klucz, np. <b>Alpha Vault</b>, i ustaw passphrase. Zapisz go.', 'W uprawnieniach zaznacz <b>tylko odczyt</b>. <b>Nie zaznaczaj</b> handlu ani wypłat.', 'Potwierdź kodem 2FA i skopiuj <b>API Key</b> oraz <b>Secret Key</b>. Secret pokazuje się tylko raz.', 'Wklej trzy wartości obok i kliknij <b>Połącz</b>. Pierwsza synchronizacja pobierze 90 dni historii.'] },
      okx: { n: 'OKX', f: ['API Key', 'Secret Key', 'Passphrase'], steps: ['Zaloguj się na <b>okx.com</b>, kliknij profil i wybierz <b>API</b>.', 'Kliknij <b>Utwórz klucz API v5</b>.', 'Wpisz nazwę, np. <b>Alpha Vault</b>, i ustaw passphrase.', 'Uprawnienia: <b>tylko Odczyt</b>. Handel i wypłaty wyłączone.', 'Potwierdź i skopiuj <b>API Key</b> oraz <b>Secret Key</b>.', 'Wklej obok razem z passphrase i kliknij <b>Połącz</b>.'] },
      bingx: { n: 'BingX', f: ['API Key', 'Secret Key'], steps: ['Zaloguj się na <b>bingx.com</b>, kliknij profil i wybierz <b>Zarządzanie API</b>.', 'Kliknij <b>Utwórz API</b> i nazwij klucz, np. <b>Alpha Vault</b>.', 'Zostaw <b>tylko Odczyt</b>. Nie włączaj handlu ani wypłat.', 'Potwierdź kodem z maila lub 2FA.', 'Skopiuj <b>API Key</b> i <b>Secret Key</b>, wklej obok i kliknij <b>Połącz</b>.'] }
    }[k];
    const connected = c.connected;
    AV.setHTML('#exBody', '<div class="sech"><h2>' + meta.n + (connected ? ' <span class="win" style="font-size:13px;font-weight:600;margin-left:6px">● połączona</span>' : '') + '</h2>' + (connected ? '<button class="btn g sm" data-disconnect="' + k + '">Odłącz</button>' : '') + '</div>' +
      (connected ? '<div class="kv"><span>Ostatnia synchronizacja</span><b>' + new Date(c.lastSync).toLocaleString('pl-PL') + '</b></div><div class="kv"><span>Zaimportowane transakcje</span><b>' + (c.imported || 0) + '</b></div><div class="kv"><span>Automatyczny import</span><b>co 30 minut</b></div><div class="kv"><span>Uprawnienia klucza</span><b class="win">tylko odczyt ✓</b></div><div class="kv"><span>Klucz</span><b class="mono">' + AV.esc(c.masked || '••••') + '</b></div>' : '') +
      '<div class="grid g2" style="margin-top:' + (connected ? 20 : 4) + 'px;align-items:start"><div><h3>Jak zdobyć klucz</h3><ol class="howto" style="margin-top:6px">' + meta.steps.map((s) => '<li><span>' + s + '</span></li>').join('') + '</ol></div>' +
      '<div><div class="card flat"><h3>' + (connected ? 'Zmień klucz' : 'Wklej klucz') + '</h3>' + meta.f.map((f, i) => '<span class="label">' + f + '</span><input class="input mono" id="exF' + i + '" placeholder="' + f + '" type="' + (/Secret|Pass/.test(f) ? 'password' : 'text') + '">').join('') +
      '<button class="btn p" style="width:100%;margin-top:18px" data-conn="' + k + '">' + (connected ? 'Zapisz nowy klucz' : 'Połącz ' + meta.n) + '</button></div>' +
      '<div class="note r" style="margin-top:12px">' + AV.ic('shield') + '<div>Jeśli klucz ma prawo do handlu albo wypłat, Alpha Vault go odrzuci. Tak chronimy Twoje środki.</div></div></div></div>');
    AV.bindExchangeActions(k, meta);
  };

  AV.bindExchangeActions = (k, meta) => {
    $('#exBody [data-conn]') && ($('#exBody [data-conn]').onclick = () => {
      const vals = meta.f.map((_, i) => $('#exF' + i).value.trim());
      if (vals.some((v) => !v)) { AV.toast('Uzupełnij wszystkie pola klucza'); return; }
      if (vals.some((v) => v.length < 8)) { AV.toast('Klucz wygląda na niepełny (min. 8 znaków)'); return; }
      const c = AV.state.settings.connections[k];
      c.connected = true;
      c.lastSync = new Date().toISOString();
      c.imported = c.imported || 0;
      c.masked = vals[0].slice(0, 4) + '••••' + vals[0].slice(-4);
      c.perms = 'read-only';
      AV.saveNow();
      AV.renderExchangeList(); AV.renderEx(k);
      AV.toast(meta.n + ': klucz tylko do odczytu zapisany. Synchronizacja gotowa.');
    });
    $('#exBody [data-disconnect]') && ($('#exBody [data-disconnect]').onclick = () => {
      AV.confirm({ title: 'Odłączyć ' + meta.n + '?', text: 'Historia transakcji zostanie w dzienniku, ale nowe nie będą już importowane.', confirmLabel: 'Odłącz', danger: true, onConfirm: () => {
        const c = AV.state.settings.connections[k];
        c.connected = false; c.lastSync = null; c.masked = ''; c.imported = 0;
        AV.saveNow(); AV.renderExchangeList(); AV.renderEx(k); AV.toast(meta.n + ' odłączona');
      } });
    });
  };

  AV.renderMT = () => {
    const c = AV.state.settings.connections.mt;
    const code = c.code || 'AV-7K3Q-92XD';
    AV.setHTML('#exBody', '<div class="sech"><h2>MetaTrader 4 / 5</h2><div class="seg" id="mtTabs"><button class="on" data-m="a">Hasło inwestora</button><button data-m="b">Alpha Vault EA</button></div></div>' +
      '<p class="mut" style="font-size:14px;line-height:1.55">Forex, indeksy (NAS100, US500, DAX), złoto i ropa u dowolnego brokera z MetaTraderem, np. VT Markets, IC Markets, XM czy konta prop firm. Transakcje, loty, swapy i prowizje kopiują się do dziennika same.</p>' +
      '<div id="mtA" class="grid g2" style="margin-top:16px;align-items:start"><div><h3>Jak podłączyć</h3><ol class="howto" style="margin-top:6px">' +
      '<li><span>Sprawdź <b>numer konta</b> i <b>nazwę serwera</b>. W MetaTraderze: <b>Plik → Zaloguj się do konta handlowego</b>. Serwer wygląda np. tak: <b>VTMarkets-Live 3</b>.</span></li>' +
      '<li><span>Zdobądź <b>hasło inwestora</b>. Znajdziesz je w panelu klienta u brokera. W MT5 możesz je też ustawić sam: <b>Narzędzia → Opcje → Serwer → Zmień</b> i zaznacz hasło inwestora.</span></li>' +
      '<li><span>Wybierz platformę, wpisz dane obok i kliknij <b>Połącz</b>. Pierwsza synchronizacja pobierze 90 dni historii.</span></li>' +
      '<li><span>Gotowe. Nowe transakcje pojawią się w dzienniku w ciągu kilku minut od zamknięcia.</span></li></ol></div>' +
      '<div><div class="card flat"><h3>Dane konta</h3><span class="label">Platforma</span><div class="chips sel" id="mtPlatform"><span class="chip">MT4</span><span class="chip on">MT5</span></div>' +
      '<span class="label">Serwer brokera</span><input class="input" id="mtServer" placeholder="np. VTMarkets-Live 3" value="' + AV.attr(c.server || '') + '">' +
      '<span class="label">Numer konta (login)</span><input class="input mono" id="mtLogin" placeholder="np. 81234567" value="' + AV.attr(c.login || '') + '">' +
      '<span class="label">Hasło inwestora</span><input class="input mono" type="password" id="mtPass" placeholder="hasło tylko do odczytu">' +
      '<span class="label">Typ konta</span><div class="chips sel" id="mtType"><span class="chip on">Live</span><span class="chip">Demo</span><span class="chip">Prop challenge</span></div>' +
      '<button class="btn p" style="width:100%;margin-top:18px" data-mtconnect>Połącz MetaTrader</button></div>' +
      '<div class="note" style="margin-top:12px">' + AV.ic('shield') + '<div><b>Hasło inwestora pozwala tylko oglądać konto.</b> Nie da się nim otworzyć pozycji ani wypłacić środków. Nigdy nie wpisuj tu hasła głównego.</div></div></div></div>' +
      '<div id="mtB" class="grid g2" style="margin-top:16px;align-items:start;display:none"><div><h3>Jeśli broker nie daje hasła inwestora</h3><ol class="howto" style="margin-top:6px">' +
      '<li><span>Pobierz plik <b>AlphaVault.ex5</b> (dla MT4: <b>AlphaVault.ex4</b>).</span></li><li><span>W MetaTraderze: <b>Plik → Otwórz folder danych → MQL5 → Experts</b>. Wrzuć tam plik i odśwież Nawigator.</span></li>' +
      '<li><span><b>Narzędzia → Opcje → Doradcy</b>: zaznacz „Zezwalaj na WebRequest” i dodaj adres <b>https://api.alphavault.pl</b>.</span></li>' +
      '<li><span>Przeciągnij <b>AlphaVault</b> na dowolny wykres i wklej swój kod parowania. EA wysyła tylko historię zamkniętych transakcji. Nie handluje.</span></li>' +
      '<li><span>MetaTrader musi być włączony, żeby nowe transakcje się kopiowały. Na VPS działa 24/7.</span></li></ol></div>' +
      '<div><div class="card flat" style="text-align:center;padding:26px"><div class="lbl">Twój kod parowania</div><div class="mono" style="font-size:30px;font-weight:700;letter-spacing:.12em;margin:12px 0">' + code + '</div><div class="hint">ważny 24 godziny</div>' +
      '<div style="display:flex;gap:10px;justify-content:center;margin-top:18px;flex-wrap:wrap"><button class="btn p sm" data-eadl>Pobierz instrukcję EA</button><button class="btn g sm" data-copycode>Kopiuj kod</button></div></div></div></div>');
    $$('#mtTabs button').forEach((b) => b.onclick = () => {
      $$('#mtTabs button').forEach((x) => x.classList.toggle('on', x === b));
      $('#mtA').style.display = b.dataset.m === 'a' ? 'grid' : 'none';
      $('#mtB').style.display = b.dataset.m === 'b' ? 'grid' : 'none';
    });
    $$('#mtPlatform .chip, #mtType .chip').forEach((c2) => c2.onclick = () => {
      c2.parentNode.querySelectorAll('.chip').forEach((x) => x.classList.remove('on'));
      c2.classList.add('on');
    });
    $('#exBody [data-mtconnect]').onclick = () => {
      const server = $('#mtServer').value.trim(), login = $('#mtLogin').value.trim(), pass = $('#mtPass').value.trim();
      if (!server || !login || !pass) { AV.toast('Podaj serwer, login i hasło inwestora'); return; }
      c.connected = true; c.server = server; c.login = login; c.masked = 'login ••' + login.slice(-3); c.lastSync = new Date().toISOString();
      AV.saveNow(); AV.renderExchangeList(); AV.renderMT(); AV.toast('MetaTrader podłączony (symulacja odczytu).');
    };
    $('#exBody [data-copycode]').onclick = () => {
      navigator.clipboard?.writeText(code).then(() => AV.toast('Kod skopiowany: ' + code)).catch(() => AV.toast('Kod: ' + code));
    };
    $('#exBody [data-eadl]').onclick = () => AV.download('AlphaVault-EA-instrukcja.txt',
      'Alpha Vault — instrukcja podłączenia EA\n\nKod parowania: ' + code + '\nWażny: 24 godziny\n\n1. Pobierz AlphaVault.ex5 (MT5) lub AlphaVault.ex4 (MT4) z panelu Alpha Vault.\n2. Plik → Otwórz folder danych → MQL5 → Experts. Wrzuć plik i odśwież Nawigator.\n3. Narzędzia → Opcje → Doradcy: zezwól na WebRequest dla https://api.alphavault.pl.\n4. Przeciągnij AlphaVault na wykres i wklej kod parowania.\n5. EA wysyła wyłącznie historię zamkniętych transakcji. Nie handluje.\n\nWersja demonstracyjna: plik EA nie jest dołączony do tego podglądu.\n', 'text/plain;charset=utf-8');
  };

  AV.renderImport = () => {
    AV.setHTML('#exBody', '<div class="sech"><h2>Import z pliku</h2><span class="tag info">CSV</span></div>' +
      '<p class="mut" style="font-size:14px;line-height:1.55">Wyeksportuj historię pozycji z giełdy (CSV). Alpha Vault sam rozpozna kolumny i pokaże podgląd przed importem. Pliki XLS/XLSX zapisz najpierw jako CSV.</p>' +
      '<div class="grid g2" style="margin-top:16px;align-items:start"><div><h3>Jak to zrobić</h3><ol class="howto" style="margin-top:6px"><li><span>Na giełdzie wejdź w <b>Historię pozycji</b> futures.</span></li><li><span>Wybierz zakres dat i kliknij <b>Eksportuj</b> (CSV).</span></li><li><span>Przeciągnij plik obok. Zobaczysz podgląd przed importem.</span></li><li><span>Po zaimportowaniu uzupełnij emocje, ocenę i screeny w dzienniku.</span></li></ol>' +
      '<div class="note" style="margin-top:12px">' + AV.ic('shield') + '<div>Plik jest czytany tylko w Twojej przeglądarce. Nie wysyłamy go na serwer.</div></div></div>' +
      '<div><label class="drop" data-import style="min-height:220px"><input type="file" accept=".csv,.txt,.xls,.xlsx" hidden><div class="ic">' + AV.ic('file') + '</div><b>Upuść plik CSV</b><span>WEEX, OKX, BingX, Bybit, raport MetaTrader</span></label><div id="importInfo" class="hint" style="margin-top:10px"></div></div></div>');
    const drop = $('#exBody [data-import]');
    if (!drop) return;
    const input = drop.querySelector('input');
    input.onchange = () => AV.previewImport(input.files[0]);
    drop.ondragover = (e) => { e.preventDefault(); drop.classList.add('over'); };
    drop.ondragleave = () => drop.classList.remove('over');
    drop.ondrop = (e) => { e.preventDefault(); drop.classList.remove('over'); AV.previewImport(e.dataTransfer.files[0]); };
  };

  AV.previewImport = (file) => {
    if (!file) return;
    if (/\.(xls|xlsx)$/i.test(file.name)) { AV.toast('Zapisz plik jako CSV — XLS nie jest czytany w podglądzie.'); return; }
    const r = new FileReader();
    r.onload = () => {
      const rows = AV.parseCsv(String(r.result));
      const parsed = AV.mapCsvTrades(rows);
      if (!parsed.length) { AV.toast('Nie udało się rozpoznać transakcji w tym pliku.'); return; }
      AV.openModal('<div class="eyebrow">Import · podgląd</div><h1 style="font-size:26px;margin-top:6px">' + parsed.length + ' ' + AV.plural(parsed.length, 'transakcja', 'transakcje', 'transakcji') + '</h1>' +
        '<p class="mut" style="margin-top:8px;font-size:13.5px">Sprawdź pierwsze wiersze. Duplikaty (data + para + wejście) zostaną pominięte.</p>' +
        '<div style="overflow-x:auto;margin-top:14px"><table><thead><tr><th>Data</th><th>Para</th><th>Kier.</th><th>Wejście</th><th>Wyjście</th><th>Wynik</th></tr></thead><tbody>' +
        parsed.slice(0, 8).map((t) => '<tr><td class="mono dim">' + AV.dateTimeShort(t.date) + '</td><td><b>' + AV.esc(t.pair) + '</b></td><td>' + (t.dir === 'l' ? 'LONG' : 'SHORT') + '</td><td class="mono">' + AV.esc(t.entry) + '</td><td class="mono">' + AV.esc(t.exit) + '</td><td class="mono ' + (t.result > 0 ? 'win' : t.result < 0 ? 'loss' : '') + '">' + (t.result == null ? '—' : AV.signed(t.result, 2, '')) + '</td></tr>').join('') +
        '</tbody></table></div><div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px"><button class="btn g" data-gclose>Anuluj</button><button class="btn p" id="importGo">Importuj ' + parsed.length + '</button></div>');
      $('#importGo').onclick = () => {
        let added = 0;
        const existing = AV.state.trades;
        parsed.forEach((t) => {
          if (existing.some((x) => AV.dateKey(new Date(x.date)) === AV.dateKey(new Date(t.date)) && x.pair === t.pair && x.entry === t.entry)) return;
          t.id = AV.uid('imp'); t.source = 'CSV'; t.account = AV.state.accounts.find((a) => a.include)?.name || 'Konto główne';
          t.checkTotal = 7; t.screens = {}; t.note = t.note || 'Zaimportowane z pliku. Uzupełnij emocję i ocenę.';
          t.mentor = 'Transakcja z importu. Uzupełnij kontekst, żeby mentor mógł ją ocenić.';
          existing.push(t); added++;
        });
        AV.saveNow(); AV.closeModal(); AV.renderJournal(); AV.renderAll(); AV.toast('Zaimportowano ' + added + ' ' + AV.plural(added, 'transakcję', 'transakcje', 'transakcji'));
      };
    };
    r.readAsText(file, 'utf-8');
  };

  AV.mapCsvTrades = (rows) => {
    if (!rows.length) return [];
    const header = rows[0].map((h) => String(h).toLowerCase().trim());
    const find = (names) => header.findIndex((h) => names.some((n) => h.includes(n)));
    const iSymbol = find(['symbol', 'pair', 'instrument', 'para']);
    const iSide = find(['side', 'direction', 'type', 'kierunek']);
    const iEntry = find(['entry', 'open', 'wejście']);
    const iExit = find(['exit', 'close', 'wyjście']);
    const iPnl = find(['pnl', 'profit', 'realized', 'wynik']);
    const iQty = find(['qty', 'quantity', 'size', 'amount', 'wolumen']);
    const iDate = find(['date', 'time', 'data', 'closed']);
    const iFee = find(['fee', 'commission', 'prowizja']);
    const start = iSymbol >= 0 && iDate >= 0 ? 1 : 0;
    return rows.slice(start).map((r) => {
      const get = (i) => i >= 0 ? r[i] : '';
      const pair = String(get(iSymbol) || '').trim().toUpperCase().replace(/-/g, '/');
      if (!pair) return null;
      const side = String(get(iSide) || '').toLowerCase();
      const dir = /sell|short|s/.test(side) ? 's' : 'l';
      const entryN = AV.pnum(get(iEntry)), exitN = AV.pnum(get(iExit)), qty = AV.pnum(get(iQty));
      let result = get(iPnl) !== '' ? AV.pnum(get(iPnl)) : (entryN && exitN && qty ? (exitN - entryN) * qty * (dir === 'l' ? 1 : -1) : null);
      let date = new Date(String(get(iDate) || Date.now()));
      if (isNaN(date)) {
        const m = String(get(iDate)).match(/(\d{2})[.\-/](\d{2})[.\-/](\d{4})/);
        date = m ? new Date(+m[3], +m[2] - 1, +m[1], 12, 0) : new Date();
      }
      return {
        date: date.toISOString(), pair, dir,
        entry: entryN ? AV.fmt(entryN, AV.dec(entryN)) : String(get(iEntry) || '—'),
        exit: exitN ? AV.fmt(exitN, AV.dec(exitN)) : String(get(iExit) || '—'),
        sl: 'brak', tp: '—', size: qty ? AV.fmt(qty, 4) : '—',
        result: result == null ? null : Math.round(result * 100) / 100,
        r: null, grade: 'B', checklist: 5, errors: [], emotion: 'Spokój',
        setup: 'Bez setupu', session: 'Nowy Jork', mae: 0.3, mfe: 0.5,
        durationMin: 60, fee: AV.pnum(get(iFee)), plan: false
      };
    }).filter(Boolean);
  };

  /* ---------- ustawienia ---------- */
  AV.renderSettings = () => {
    const s = AV.state, u = s.user;
    AV.setVal('#nameIn', u.name);
    AV.setVal('#currencyIn', u.currency);
    AV.setVal('#goalIn', u.goal);
    AV.setVal('#mottoIn', u.motto);
    AV.setVal('#tzIn', u.timezone);
    AV.setVal('#hoursIn', u.hour12 ? '12h · 2:30 PM' : '24h · 14:30');
    $$('#styleChips .chip').forEach((c) => c.classList.toggle('on', c.textContent === u.style));
    $$('#marketChips .chip').forEach((c) => c.classList.toggle('on', (u.markets || []).includes(c.textContent)));
    $$('#accents .sw').forEach((w) => w.classList.toggle('on', w.dataset.a === u.accent.a));
    $$('[data-setting]').forEach((el) => {
      const k = el.dataset.setting;
      let v = s.settings[k];
      if (k === 'greeting') v = u.greeting;
      if (k === 'remember') v = s.settings.remember;
      el.classList.toggle('on', !!v);
    });
    AV.setVal('#maxLosses', s.settings.maxLosses);
    AV.setVal('#maxDailyLoss', s.settings.maxDailyLoss);
    $$('#blockChips .chip').forEach((c) => c.classList.toggle('on', c.dataset.block === s.settings.block));
    AV.setVal('#maxRisk', s.settings.maxRisk + '%');
    AV.setVal('#rule1', s.settings.rules[0] || '');
    AV.setVal('#rule2', s.settings.rules[1] || '');
    AV.setVal('#rule3', s.settings.rules[2] || '');
    $$('#rulesChecklist .chip').forEach((c) => c.classList.toggle('on', s.settings.checklist.includes(c.dataset.c)));
    AV.setText('#totalCapital', AV.fmt(AV.totalCapital(s), 2) + ' USDT');
    AV.setText('#sessions', s.meta.sessions.map((x) => x.device + ' · ' + x.when + (x.place ? ' · ' + x.place : '')).join('<br>'));
    $('#sessions').innerHTML = s.meta.sessions.map((x) => AV.esc(x.device) + ' · ' + AV.esc(x.when) + (x.place ? ' · ' + AV.esc(x.place) : '')).join('<br>');
    AV.renderAccounts();
    AV.renderFlows();
    AV.renderBadges();
    AV.renderExpired();
  };

  AV.renderAccounts = () => {
    const s = AV.state;
    AV.setHTML('#accBody', s.accounts.map((a) => {
      const pct = a.start ? (a.balance - a.start) / a.start * 100 : 0;
      return '<tr><td><b>' + AV.esc(a.name) + '</b> <span class="dim" style="font-size:12px">' + (a.primary ? '· główne' : '') + (a.primary ? '' : ' <span class="link" data-acc-del="' + a.id + '">usuń</span>') + '</span></td><td class="mut">' + AV.esc(a.type) + '</td><td class="mono">' + AV.esc(a.currency) + '</td><td class="mono" style="text-align:right">' + AV.fmt(a.start, 2) + '</td><td class="mono dim">' + AV.esc(a.date) + '</td><td class="mono" style="text-align:right">' + AV.fmt(a.balance, 2) + '</td><td class="mono ' + (pct >= 0 ? 'win' : 'loss') + '" style="text-align:right">' + AV.signed(pct, 1, '%') + '</td><td class="mono">' + AV.esc(a.lev) + '</td><td><div class="toggle ' + (a.include ? 'on' : '') + '" data-acc-inc="' + a.id + '"></div></td></tr>';
    }).join(''));
  };

  AV.renderFlows = () => {
    const s = AV.state;
    AV.setHTML('#flowBody', s.flows.length ? s.flows.map((f) => '<tr><td class="mono dim">' + new Date(f.date).toLocaleDateString('pl-PL') + '</td><td>' + AV.esc(f.account) + '</td><td>' + AV.esc(f.type) + ' · ' + AV.esc(f.op) + '</td><td class="mono" style="text-align:right">' + (f.type === 'Wpłata' ? '+' : '−') + AV.fmt(f.amount, 2) + ' USDT</td><td class="mono" style="text-align:right">' + AV.fmt(f.fiat, 2) + ' ' + (f.currency === 'PLN' ? 'zł' : f.currency) + '</td><td><span class="pill ' + (f.tax === 'cost' ? 'k' : 'p') + '">' + (f.tax === 'cost' ? 'koszt' : 'przychód') + '</span> <span class="link" data-flow-del="' + f.id + '" style="font-size:11px">✕</span></td></tr>').join('') : '<tr><td colspan="6" class="dim" style="text-align:center;padding:20px">Brak wpłat i wypłat.</td></tr>');
  };

  AV.renderBadges = () => {
    const b = AV.badges(AV.state);
    AV.setHTML('#medals', b.map((x) => '<div class="medal ' + (x.done ? '' : 'lk') + '"><div class="mc">' + AV.ic(x.icon) + '</div><b>' + AV.esc(x.name) + '</b><span>' + AV.esc(x.hint) + '</span>' + (x.progress != null ? '<div class="bar"><i style="width:' + x.progress + '%;background:var(--alpha)"></i></div>' : '') + '</div>').join(''));
  };

  AV.renderExpired = () => {
    const s = AV.state;
    $('#expired')?.classList.toggle('on', !!s.subscription.expired);
    AV.setText('#expTrades', AV.includedTrades(s).length);
    AV.setText('#expDays', new Set(AV.includedTrades(s).map((t) => AV.dateKey(new Date(t.date)))).size);
    AV.setText('#expBadges', AV.badges(s).filter((b) => b.done).length);
  };

  /* ---------- wszystko ---------- */
  AV.renderAll = () => {
    if (!AV.state) return;
    AV.renderUser();
    AV.renderLock();
    AV.renderDashboard();
    AV.renderPlan();
    AV.renderJournal();
    AV.renderPlaybook();
    AV.renderChecklist();
    AV.renderStats();
    AV.renderMentor();
    AV.renderAcademy();
    AV.renderTax();
    AV.renderCalendar();
    AV.renderBody();
    AV.renderExchangeList();
    AV.renderSettings();
    AV.renderNewTradeForm();
  };
})();
