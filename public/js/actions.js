/* Alpha Vault — interakcje i inicjalizacja */
(function () {
  'use strict';
  const AV = window.AV, $ = AV.$, $$ = AV.$$;

  /* ---------- routing ---------- */
  AV.views = ['pulpit', 'plan', 'trans', 'play', 'nowa', 'kalk', 'prop', 'akad', 'stat', 'tax', 'mentor', 'kal', 'cialo', 'gieldy', 'ust'];
  AV.currentView = 'pulpit';
  AV.showView = (v) => {
    if (!AV.views.includes(v)) v = 'pulpit';
    AV.currentView = v;
    $$('.view').forEach((s) => s.classList.toggle('on', s.id === v));
    $$('.navg button').forEach((b) => b.classList.toggle('on', b.dataset.v === v));
    $('#mw')?.classList.remove('open');
    window.scrollTo(0, 0);
    try { history.replaceState(null, '', '#/' + v); } catch {}
    if (v === 'trans') AV.renderJournal();
    if (v === 'stat') AV.renderStats();
    if (v === 'prop' && AV.renderProp) AV.renderProp();
    if (v === 'plan') { AV.renderPlan(); }
    if (v === 'ust') AV.renderSettings();
    if (v === 'kal') AV.renderCalendar();
    if (v === 'cialo') AV.renderBody();
    if (v === 'mentor') AV.renderMentor();
    if (v === 'gieldy') { AV.renderExchangeList(); AV.renderEx(AV.exKey); }
    if (v === 'nowa') { AV.renderNewTradeForm(); AV.renderLock(); }
  };

  /* ---------- ticker ---------- */
  AV.tickerData = [];
  AV.renderTicker = () => {
    const t = AV.tickerData;
    AV.setHTML('#ticker', t.map(([s, p, c]) => '<span><b>' + s + '</b>' + AV.fmt(p, p < 10 ? 4 : p < 500 ? 2 : 0) + (c === null || c === undefined ? '' : ' <span class="' + (c >= 0 ? 'win' : 'loss') + '">' + (c >= 0 ? '+' : '\u2212') + AV.fmt(Math.abs(c), 1) + '%</span>') + '</span>').join(''));
  };
  AV.loadQuotes = async () => {
    try {
      const res = await AV.apiFetch('/api/quotes');
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const data = await res.json();
      if (!Array.isArray(data.quotes)) throw new Error('bad_quotes');
      AV.tickerData = data.quotes
        .filter((q) => q && typeof q.symbol === 'string' && Number.isFinite(Number(q.price)))
        .map((q) => [q.symbol, Number(q.price), q.changePercent === null || q.changePercent === undefined ? null : Number(q.changePercent)]);
      AV.renderTicker();
      return true;
    } catch (err) {
      AV.api.lastError = err.message;
      return false;
    }
  };

  /* ---------- countdown ---------- */
  AV.tickCountdowns = () => {
    const L = AV.lockInfo(AV.state);
    $$('.cdt').forEach((e) => {
      if (L.active && L.until) {
        const sec = Math.max(0, Math.floor((L.until - new Date()) / 1000));
        e.textContent = [sec / 3600 | 0, (sec % 3600) / 60 | 0, sec % 60].map((x) => String(x).padStart(2, '0')).join(':');
      } else e.textContent = '--:--:--';
    });
  };

  AV.macroNotify = () => {
    const s = AV.state;
    if (!s.settings.macroBefore) return;
    const events = AV.buildEvents().filter((e) => e.imp === 3);
    const now = Date.now();
    events.forEach((e) => {
      const mins = (new Date(e.date) - now) / 60000;
      if (mins > 25 && mins <= 30) {
        const key = e.id + ':30';
        if (s.meta.notified[key]) return;
        s.meta.notified[key] = true;
        AV.save();
        AV.toast('Za 30 min: ' + e.name + ' (' + AV.timeFmt(e.date, s) + ')', 5000);
        AV.pushNotification('Makro za 30 min', e.name + ' · ' + AV.timeFmt(e.date, s));
      }
    });
  };

  AV.pushNotification = (title, body) => {
    try {
      if ('Notification' in window && Notification.permission === 'granted') {
        new Notification(title, { body, icon: undefined });
      }
    } catch {}
  };

  AV.ensurePermission = () => {
    try {
      if ('Notification' in window && Notification.permission === 'default') Notification.requestPermission();
    } catch {}
  };

  /* ---------- szybkie dodanie z telefonu ---------- */
  AV.pickQuickTrade = (tradeId) => {
    const trades = (AV.state.trades || []).slice()
      .sort((a, b) => new Date(b.date) - new Date(a.date));
    if (tradeId) {
      const exact = trades.find((t) => t.id === tradeId);
      if (exact) return exact;
    }
    return trades.find((t) => t.result != null && (!t.emotion || !t.grade))
      || trades.find((t) => t.result != null)
      || trades[0]
      || null;
  };

  AV.qaSyncSave = () => {
    const emotion = $('#qaEmotion .chip.on');
    const grade = $('#qaG .on');
    const ready = !!(emotion && grade);
    ['#qaSave', '#qaSaveFloat'].forEach((selector) => {
      const button = $(selector);
      if (button) {
        button.disabled = !ready;
        button.textContent = ready ? 'Zapisz' : 'Wybierz emocję i ocenę';
      }
    });
    const hint = $('#qaHint');
    if (hint) hint.textContent = ready ? 'Gotowe. Zapis zajmie chwilę.' : 'Wybierz emocję i ocenę.';
  };

  AV.openQuickAdd = (tradeId) => {
    const trade = AV.pickQuickTrade(tradeId);
    if (!trade) { AV.toast('Brak transakcji do uzupełnienia'); return false; }

    AV.ui.qaTradeId = trade.id;
    AV.ui.qaOpenedAt = performance.now();

    AV.setText('#qaPair', trade.pair || '');
    const direction = $('#qaDir');
    if (direction) {
      direction.textContent = trade.dir === 'l' ? 'LONG' : 'SHORT';
      direction.className = 'pill ' + (trade.dir === 'l' ? 'l' : 's');
    }
    const result = $('#qaResult');
    if (result) {
      result.textContent = trade.result == null ? '' : AV.signed(trade.result, 2);
      result.className = trade.result == null ? 'mut mono' : (trade.result > 0 ? 'win mono' : 'loss mono');
    }
    AV.setText('#qaMeta', [trade.source || '', AV.dateTimeShort(trade.date), trade.result == null ? 'pozycja otwarta' : 'zamknięta'].filter(Boolean).join(' · '));
    AV.setText('#qaPushText', trade.result == null
      ? 'Pozycja otwarta: ' + trade.pair + '. Uzupełnij po zamknięciu.'
      : 'Zamknięta: ' + trade.pair + ' ' + (trade.dir === 'l' ? 'long' : 'short') + ' ' + AV.signed(trade.result, 2) + '. Dodaj emocję i ocenę.');

    $$('#qaEmotion .chip').forEach((chip) => chip.classList.toggle('on', !!trade.emotion && chip.textContent === trade.emotion));
    $$('#qaError .chip').forEach((chip) => chip.classList.toggle('on', chip.textContent === 'Brak'
      ? !(trade.errors && trade.errors.length)
      : (trade.errors || []).includes(chip.textContent)));
    $$('#qaG div').forEach((grade) => grade.classList.toggle('on', !!trade.grade && grade.textContent.trim() === trade.grade));

    const photo = $('#qaPhotoPreview');
    const source = trade.screens && trade.screens.after;
    if (photo) {
      photo.hidden = !source;
      if (source) photo.src = source;
    }
    AV.setText('#qaPhoto', source ? '📷 Zmień zdjęcie wykresu' : '📷 Zdjęcie wykresu');

    AV.qaSyncSave();
    const modal = $('#qa');
    if (modal) modal.classList.add('on');
    return true;
  };

  AV.quickAddFromUrl = () => {
    let tradeId = null;
    try {
      const params = new URLSearchParams(location.search);
      if (!params.has('qa')) return false;
      const value = params.get('qa');
      if (value && value !== '1') tradeId = value;
    } catch {
      return false;
    }
    const opened = AV.openQuickAdd(tradeId);
    if (opened && history.replaceState) history.replaceState(null, '', location.pathname + location.hash);
    return opened;
  };

  /* ---------- auth ---------- */
  AV.localEnterApp = () => {
    if (AV._entering) return;
    AV._entering = true;
    const steps = $$('#asteps .astep');
    $('#asteps').classList.add('on');
    $('#enter').style.opacity = 0.6;
    steps.forEach((x, i) => setTimeout(() => x.classList.add('ok'), 350 + i * 450));
    setTimeout(() => {
      AV.login(AV.state.settings.remember);
      $('#auth').classList.add('hide');
      $('#enter').style.opacity = 1;
      $('#asteps').classList.remove('on');
      steps.forEach((x) => x.classList.remove('ok'));
      AV._entering = false;
      AV.renderAll();
      AV.showView(AV.viewFromHash());
      AV.quickAddFromUrl();
      AV.toast('Witaj z powrotem, ' + AV.state.user.name);
      setTimeout(() => AV.proactive(), 3200);
    }, 350 + steps.length * 450 + 250);
  };

  AV.openDemoLogin = () => {
    const body = AV.openModal('<div class="eyebrow">Logowanie</div><h1 style="font-size:26px;margin-top:6px">Discord nie jest jeszcze podłączony</h1>' +
      '<p class="mut" style="margin-top:10px;line-height:1.6">Aplikacja działa na MySQL i jest gotowa na OAuth Discorda. Administrator musi uzupełnić <code>DISCORD_CLIENT_ID</code>, <code>DISCORD_CLIENT_SECRET</code> i <code>DISCORD_REDIRECT_URI</code> w pliku <code>.env</code>.</p>' +
      '<p class="mut" style="margin-top:10px;line-height:1.6">Do tego czasu możesz wejść kontem demo. Wszystkie dane demo zapisują się w bazie MySQL.</p>' +
      '<div style="display:flex;gap:10px;margin-top:20px;flex-wrap:wrap"><button class="btn p" id="demoEnter">Wejdź jako Kasia (demo)</button><a class="btn g" href="/docs/API.md" target="_blank">Dokumentacja logowania</a><button class="btn g" data-gclose>Anuluj</button></div>', { maxWidth: '560px' });
    const btn = $('#demoEnter', body);
    if (!AV.api.demoAllowed) {
      btn.disabled = true;
      btn.textContent = 'Demo wyłączone';
    } else {
      btn.onclick = async () => {
        btn.disabled = true;
        btn.textContent = 'Łączę z bazą…';
        try {
          await AV.serverDemoLogin();
          $('#asteps').classList.add('on');
          $$('#asteps .astep').forEach((x, i) => setTimeout(() => x.classList.add('ok'), 200 + i * 250));
          setTimeout(() => { location.href = '/'; }, 900);
        } catch (err) {
          btn.disabled = false; btn.textContent = 'Wejdź jako Kasia (demo)';
          AV.toast(err.message || 'Błąd logowania demo');
        }
      };
    }
  };

  AV.enterApp = () => {
    if (AV.api.online) {
      if (AV.api.discordConfigured) {
        location.href = '/auth/discord?remember=' + (AV.state.settings.remember ? '1' : '0');
        return;
      }
      AV.openDemoLogin();
      return;
    }
    AV.localEnterApp();
  };

  AV.proactive = () => {
    if (!AV.lockInfo(AV.state).active) return;
    if (AV._proShown) return;
    AV._proShown = true;
    const txt = AV.PROACTIVE[Math.floor(Math.random() * AV.PROACTIVE.length)];
    AV.setHTML('#proTxt', AV.fill(txt, AV.state));
    $('#pro').classList.add('on');
  };

  /* ---------- plan ---------- */
  AV.savePlanDraft = () => {
    const p = AV.readPlanForm();
    AV.state.plans[AV.planDate] = p;
    AV.save();
    AV.renderPlanSide();
  };

  AV.bindPlan = () => {
    $('#planSeg')?.addEventListener('click', (e) => {
      const b = e.target.closest('button'); if (!b) return;
      if (b.dataset.day === 'hist') {
        AV.ui.planHistOpen = !AV.ui.planHistOpen;
        AV.renderPlanHistory();
        if (AV.ui.planHistOpen) $('#planHistoryCard')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      } else {
        AV.ui.planHistOpen = false;
        AV.planDate = b.dataset.day === '0' ? AV.dateKey(new Date()) : AV.dateKey(AV.addDays(new Date(), -1));
        AV.renderPlan();
      }
    });
    $('#addLvl')?.addEventListener('click', () => {
      $('#lvls').insertAdjacentHTML('beforeend', AV.levelRow({ type: 'Wsparcie' }));
      AV.savePlanDraft();
    });
    $('#lvls')?.addEventListener('click', (e) => {
      if (e.target.classList.contains('x')) { e.target.parentNode.remove(); AV.savePlanDraft(); }
    });
    $('#planEditor')?.addEventListener('input', AV.debounce(() => { AV.savePlanDraft(); }, 200));
    $('#planEditor')?.addEventListener('change', () => AV.savePlanDraft());
    $('#savePlan')?.addEventListener('click', () => {
      const p = AV.readPlanForm();
      if (!p.market || !p.why) { AV.toast('Uzupełnij rynek i uzasadnienie planu'); return; }
      p.approvedAt = Date.now();
      AV.state.plans[AV.planDate] = p;
      AV.saveNow();
      AV.renderPlan(); AV.renderDashboard(); AV.renderSettings();
      AV.toast('Plan zatwierdzony. Mentor sprawdzi, czy go trzymasz.');
    });
    $('#copyPlan')?.addEventListener('click', () => {
      const y = AV.state.plans[AV.dateKey(AV.addDays(new Date(AV.planDate + 'T12:00:00'), -1))];
      if (!y) { AV.toast('Brak planu z poprzedniego dnia'); return; }
      const copy = AV.clone(y);
      copy.date = AV.planDate; copy.approvedAt = null;
      AV.state.plans[AV.planDate] = copy;
      AV.saveNow(); AV.renderPlan();
      AV.toast('Skopiowano plan z poprzedniego dnia');
    });
    document.addEventListener('click', (e) => {
      const el = e.target.closest('[data-plan-open]');
      if (!el) return;
      AV.planDate = el.dataset.planOpen;
      AV.ui.planHistOpen = false;
      AV.renderPlan();
      $('#planEditor')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  };

  /* ---------- dziennik ---------- */
  AV.bindJournal = () => {
    $('#fRes')?.addEventListener('click', (e) => {
      const b = e.target.closest('button'); if (!b) return;
      AV.ui.jFilter = b.dataset.f; AV.renderJournal();
    });
    $('#fGrade')?.addEventListener('click', (e) => {
      const b = e.target.closest('button'); if (!b) return;
      AV.ui.jGrade = b.dataset.f; AV.renderJournal();
    });
    $('#jSearch')?.addEventListener('input', AV.debounce((e) => {
      AV.ui.jSearch = e.target.value.trim(); AV.renderJournal();
    }, 150));
    $('#csvTrades')?.addEventListener('click', () => AV.exportTradesCsv());
    $('#tbody')?.addEventListener('click', (e) => {
      const tr = e.target.closest('tr[data-id]');
      if (tr) AV.openTrade(tr.dataset.id);
    });
    $('#tm')?.addEventListener('click', (e) => {
      if (e.target.id === 'tm' || e.target.closest('#mc')) $('#tm').classList.remove('on');
    });
    document.addEventListener('click', (e) => {
      const ed = e.target.closest('[data-trade-edit]');
      if (ed) { e.preventDefault(); AV.editTrade(ed.dataset.tradeEdit); return; }
      const del = e.target.closest('[data-trade-del]');
      if (del) {
        e.preventDefault();
        const t = AV.state.trades.find((x) => x.id === del.dataset.tradeDel);
        AV.confirm({
          title: 'Usunąć transakcję?', text: (t ? t.pair + ' · ' + AV.dateTimeShort(t.date) : '') + '. Tej operacji nie da się cofnąć.',
          confirmLabel: 'Usuń', danger: true, onConfirm: () => {
            AV.state.trades = AV.state.trades.filter((x) => x.id !== del.dataset.tradeDel);
            AV.saveNow(); $('#tm').classList.remove('on'); AV.renderAll(); AV.toast('Transakcja usunięta');
          }
        });
      }
      const cl = e.target.closest('[data-trade-close]');
      if (cl) { e.preventDefault(); AV.closeTradeModal(cl.dataset.tradeClose); }
    });
  };

  AV.editTrade = (id) => {
    const t = AV.state.trades.find((x) => x.id === id);
    if (!t) return;
    AV.ui.editId = id;
    AV.ui.ntChecks = {};
    const points = AV.state.settings.checklist || [];
    points.forEach((p, i) => AV.ui.ntChecks[p] = i < (t.checklist || 0));
    AV.ui.ntGradeManual = true;
    AV.ui.ntScreens = AV.clone(t.screens || {});
    AV.setVal('#ntPair', t.pair);
    AV.setVal('#ntEntry', t.entry);
    AV.setVal('#ntSize', t.size);
    AV.setVal('#ntSl', t.sl);
    AV.setVal('#ntTp', t.tp);
    AV.setVal('#ntNote', t.note);
    $$('#ntDir .chip').forEach((c) => c.classList.toggle('on', c.dataset.d === t.dir));
    $$('#ntSetup .chip').forEach((c) => c.classList.toggle('on', c.dataset.s === t.setup));
    $$('#ntEmotion .chip').forEach((c) => c.classList.toggle('on', c.textContent === t.emotion));
    $$('#errChips .chip.err').forEach((c) => c.classList.toggle('on', (t.errors || []).includes(c.textContent)));
    $$('.gp').forEach((g) => g.classList.toggle('on', g.dataset.g === t.grade));
    $('#tm').classList.remove('on');
    AV.showView('nowa');
    AV.renderNewTradeForm();
    AV.ui.editId = id;
    AV.ui.ntGradeManual = true;
    AV.ui.ntChecks = {};
    points.forEach((p, i) => AV.ui.ntChecks[p] = i < (t.checklist || 0));
    AV.renderChecklist();
    AV.renderDrops(t.screens || {});
    $$('.gp').forEach((g) => g.classList.toggle('on', g.dataset.g === t.grade));
    AV.show('#cancelEdit', true);
    AV.setText('#saveTrade', 'Zapisz zmiany');
    AV.toast('Edytujesz transakcję z ' + AV.dateTimeShort(t.date));
  };

  AV.closeTradeModal = (id) => {
    const t = AV.state.trades.find((x) => x.id === id);
    if (!t) return;
    const body = AV.openModal('<div class="eyebrow">Zamknięcie pozycji</div><h1 style="font-size:26px;margin-top:6px">' + AV.esc(t.pair) + ' · ' + (t.dir === 'l' ? 'LONG' : 'SHORT') + '</h1>' +
      '<p class="mut" style="margin-top:8px;font-size:13.5px">Wpisz cenę wyjścia. Wynik policzę z wielkości pozycji. Możesz też podać wynik ręcznie.</p>' +
      '<div class="grid g2" style="margin-top:14px"><div><span class="label">Cena wyjścia</span><input class="input mono" id="ctExit" value="' + AV.attr(t.exit === 'otwarta' ? t.entry : t.exit) + '"></div><div><span class="label">Wynik USDT (opcjonalnie)</span><input class="input mono" id="ctResult" placeholder="np. 65,20"></div></div>' +
      '<div class="hint" id="ctHint" style="margin-top:10px">Wejście ' + AV.esc(t.entry) + ' · wielkość ' + AV.esc(t.size) + '</div>' +
      '<div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px"><button class="btn g" data-gclose>Anuluj</button><button class="btn p" id="ctSave">Zamknij pozycję</button></div>', { maxWidth: '520px' });
    const updateHint = () => {
      const exit = AV.pnum($('#ctExit', body).value), entry = AV.pnum(t.entry), size = AV.pnum(t.size);
      const calc = (exit - entry) * size * (t.dir === 'l' ? 1 : -1);
      AV.setText('#ctHint', 'Szacowany wynik: ' + (Number.isFinite(calc) ? AV.signed(calc, 2, ' USDT') : '—') + ' · SL ' + t.sl + ' · TP ' + t.tp);
    };
    $('#ctExit', body).addEventListener('input', updateHint);
    updateHint();
    $('#ctSave', body).onclick = async () => {
      const exit = AV.pnum($('#ctExit', body).value);
      let result = $('#ctResult', body).value.trim() ? AV.pnum($('#ctResult', body).value) : null;
      const entry = AV.pnum(t.entry), size = AV.pnum(t.size);
      if (result == null) result = (exit - entry) * size * (t.dir === 'l' ? 1 : -1);
      if (!Number.isFinite(result)) { AV.toast('Podaj cenę wyjścia albo wynik'); return; }
      const risk = t.sl !== 'brak' ? Math.abs(entry - AV.pnum(t.sl)) * size : Math.abs(entry * 0.01 * size);
      t.exit = AV.fmt(exit, AV.dec(exit));
      t.result = Math.round(result * 100) / 100;
      t.r = risk ? Math.round(t.result / risk * 100) / 100 : 0;
      t.mfe = Math.max(Number(t.mfe) || 0, Math.abs(t.r || 0));
      t.durationMin = Math.max(1, Math.round((Date.now() - new Date(t.date)) / 60000));
      t.mentor = t.result >= 0 ? 'Pozycja zamknięta z zyskiem. Sprawdź, czy wyszedłeś zgodnie z planem, a nie z emocji.' : 'Pozycja zamknięta na minusie. Sprawdź, czy SL był ustawiony przed wejściem.';
      AV.saveNow(); AV.closeModal(); $('#tm').classList.remove('on'); AV.renderAll();
      clearTimeout(AV._pushTimer);
      await AV.pushStateImmediate();
      AV.toast('Pozycja zamknięta: ' + AV.signed(t.result, 2, ' USDT'));
      if (AV.pwa && AV.pwa.notifyTradeClosed) AV.pwa.notifyTradeClosed(t);
    };
  };

  /* ---------- playbook ---------- */
  AV.bindPlaybook = () => {
    $('#addSetup')?.addEventListener('click', () => AV.openSetupForm());
    document.addEventListener('click', (e) => {
      if (e.target.closest('#addSetup2')) { AV.openSetupForm(); return; }
      const ed = e.target.closest('[data-setup-edit]');
      if (ed) { AV.openSetupForm(ed.dataset.setupEdit); return; }
      const tr = e.target.closest('[data-setup-trades]');
      if (tr) {
        AV.ui.jSearch = tr.dataset.setupTrades; AV.ui.jFilter = 'all'; AV.ui.jGrade = 'all';
        $('#jSearch').value = AV.ui.jSearch;
        AV.renderJournal(); AV.showView('trans');
      }
    });
  };

  /* ---------- nowa transakcja ---------- */
  AV.bindNewTrade = () => {
    const inputs = ['#ntPair', '#ntEntry', '#ntSize', '#ntSl', '#ntTp', '#ntNote'];
    inputs.forEach((sel) => $(sel)?.addEventListener('input', AV.debounce(() => {
      AV.renderNTMeta(); AV.renderChecklist(); AV.saveDraft();
    }, 180)));
    $('#ntDir')?.addEventListener('click', (e) => {
      const c = e.target.closest('.chip'); if (!c) return;
      $$('#ntDir .chip').forEach((x) => x.classList.toggle('on', x === c));
      AV.renderChecklist(); AV.renderNTMeta(); AV.saveDraft();
    });
    $('#ntSetup')?.addEventListener('click', (e) => {
      const c = e.target.closest('.chip'); if (!c) return;
      $$('#ntSetup .chip').forEach((x) => x.classList.toggle('on', x === c));
      AV.saveDraft();
    });
    $('#ntEmotion')?.addEventListener('click', (e) => {
      const c = e.target.closest('.chip'); if (!c) return;
      $$('#ntEmotion .chip').forEach((x) => x.classList.toggle('on', x === c));
      AV.saveDraft();
    });
    $('#clList')?.addEventListener('click', (e) => {
      const row = e.target.closest('[data-cl]'); if (!row) return;
      const p = row.dataset.cl;
      AV.ui.ntChecks[p] = !AV.ui.ntChecks[p];
      AV.renderChecklist(); AV.saveDraft();
    });
    $('#gp')?.addEventListener('click', (e) => {
      const g = e.target.closest('.gp'); if (!g) return;
      AV.ui.ntGradeManual = true;
      $$('.gp').forEach((x) => x.classList.toggle('on', x === g));
      AV.saveDraft();
    });
    $('#errChips')?.addEventListener('click', (e) => {
      const c = e.target.closest('.chip'); if (!c) return;
      if (c.textContent === '+ własny') {
        AV.promptText('Własny błąd', 'Nazwa błędu', (name) => {
          if (!name) return;
          const span = document.createElement('span');
          span.className = 'chip err on';
          span.textContent = name;
          c.before(span);
          AV.saveDraft();
        });
        return;
      }
      c.classList.toggle('on');
      AV.saveDraft();
    });
    $('#saveTrade')?.addEventListener('click', () => AV.saveTrade());
    $('#cancelEdit')?.addEventListener('click', () => {
      AV.ui.editId = null; AV.ui.ntScreens = {}; AV.ui.ntChecks = {}; AV.ui.ntGradeManual = false;
      AV.safeDel(AV.DRAFT_KEY);
      AV.renderNewTradeForm(); AV.showView('trans'); AV.toast('Edycja anulowana');
    });
    $('#pastTrade')?.addEventListener('click', () => {
      AV.addOverride('Wpisuję wcześniejszą transakcję');
      AV.renderLock(); AV.renderNewTradeForm();
      AV.toast('Odblokowane do wpisania wcześniejszej transakcji');
    });
    $('#override')?.addEventListener('click', () => { $('#ovBox').style.display = 'block'; });
    $('#ovGo')?.addEventListener('click', () => {
      const ta = $('#ovBox textarea');
      const reason = ta.value.trim();
      if (AV.state.settings.requireReason && !reason) { AV.toast('Napisz powód — mentor go zobaczy'); ta.focus(); return; }
      AV.addOverride(reason || 'bez podania powodu');
      AV.renderLock(); AV.renderNewTradeForm();
      AV.toast('Powód zapisany. Mentor go zobaczy.');
    });
    $$('#nowa [data-drop]').forEach((d) => {
      // bindDrops robi to centralnie; tu tylko klawiaturowe otwarcie
      d.setAttribute('tabindex', '0');
      d.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') d.querySelector('input')?.click(); });
    });
    AV.bindDrops($('#nowa'));
  };

  AV.saveTrade = () => {
    const s = AV.state;
    if (AV.lockInfo(s).active) { AV.toast('Tryb ochronny: odblokuj dziennik, żeby zapisać transakcję'); return; }
    const pair = $('#ntPair').value.trim().toUpperCase();
    const entry = AV.pnum($('#ntEntry').value);
    const sl = AV.pnum($('#ntSl').value);
    const tp = AV.pnum($('#ntTp').value);
    const size = AV.pnum($('#ntSize').value);
    if (!pair) { AV.toast('Podaj parę'); return; }
    if (!entry) { AV.toast('Podaj cenę wejścia'); return; }
    if (!size) { AV.toast('Podaj wielkość pozycji'); return; }
    if (sl && ((($('#ntDir .chip.on')?.dataset.d || 'l') === 'l') ? sl >= entry : sl <= entry)) { AV.toast('Stop loss jest po złej stronie wejścia'); return; }
    if (tp && ((($('#ntDir .chip.on')?.dataset.d || 'l') === 'l') ? tp <= entry : tp >= entry)) { AV.toast('Take profit jest po złej stronie wejścia'); return; }
    const checks = Object.values(AV.ui.ntChecks || {}).filter(Boolean).length;
    const total = Object.keys(AV.ui.ntChecks || {}).length || 7;
    const grade = $('.gp.on')?.dataset.g || (checks === total ? 'A' : checks >= Math.ceil(total * 0.7) ? 'B' : 'C');
    const errors = $$('#errChips .chip.err.on').map((c) => c.textContent);
    const emotion = $('#ntEmotion .chip.on')?.textContent || 'Spokój';
    const setup = $('#ntSetup .chip.on')?.dataset.s || 'Bez setupu';
    const note = $('#ntNote').value.trim();
    const plan = s.plans[AV.dateKey(new Date())];
    const risk = sl ? Math.abs(entry - sl) * size : 0;
    const payload = {
      pair, dir: $('#ntDir .chip.on')?.dataset.d || 'l',
      entry: AV.fmt(entry, AV.dec(entry)), sl: sl ? AV.fmt(sl, AV.dec(entry)) : 'brak',
      tp: tp ? AV.fmt(tp, AV.dec(entry)) : '—', size: AV.fmt(size, size < 1 ? 4 : 2),
      grade, checklist: checks, checkTotal: total, errors, emotion, setup, note,
      plan: !!(plan && plan.approvedAt && setup !== 'Bez setupu'),
      mentor: grade === 'A' ? 'Wzorowo: plan, pełna checklista, SL, spokojna głowa. Tak wyglądają Twoje najlepsze transakcje.' : grade === 'B' ? 'Setup z planu, drobne odstępstwa. Trzymaj się checklisty do końca.' : (errors.length ? 'Ocena C i ' + errors.length + ' ' + AV.plural(errors.length, 'błąd', 'błędy', 'błędów') + '. To nie pech, to decyzja. Wróć do planu.' : 'Ocena C: brak setupu albo pośpiech. Odczekaj i wróć z planem.')
    };
    if (AV.ui.editId) {
      const t = s.trades.find((x) => x.id === AV.ui.editId);
      if (t) Object.assign(t, payload, { screens: AV.ui.ntScreens || t.screens || {} });
      AV.toast('Zmiany zapisane');
    } else {
      const now = new Date();
      const session = now.getHours() < 8 ? 'Azja' : now.getHours() < 13 ? 'Londyn' : now.getHours() < 22 ? 'Nowy Jork' : 'Po godzinach';
      s.trades.push({
        id: AV.uid('manual'), date: now.toISOString(), exit: 'otwarta', result: null, r: null,
        mae: 0.3, mfe: 0.9, durationMin: 0, fee: 0, source: 'Ręcznie', account: s.accounts.find((a) => a.include)?.name || 'Konto główne',
        session, screens: AV.ui.ntScreens || {}, ...payload
      });
      AV.toast('Transakcja zapisana' + (AV.lockInfo(s).active ? ' — limit strat osiągnięty' : ''));
    }
    AV.ui.editId = null; AV.ui.ntScreens = {}; AV.saveNow(); AV.safeDel(AV.DRAFT_KEY);
    AV.renderAll(); AV.showView('trans');
  };

  AV.bindDrops = (root, onChange) => {
    root.querySelectorAll('[data-drop]').forEach((d, idx) => {
      if (d._bound) return;
      d._bound = true;
      const inp = d.querySelector('input');
      const key = d.dataset.shot || (root.id === 'nowa' ? (idx === 0 ? 'before' : 'after') : 'before');
      const set = async (file) => {
        try {
          const data = await AV.readImage(file);
          AV.showImage(d, data);
          if (onChange) onChange(data, key);
          else {
            AV.ui.ntScreens = AV.ui.ntScreens || {};
            AV.ui.ntScreens[key] = data;
            AV.saveDraft();
            AV.toast('Screen dodany');
          }
        } catch (err) { AV.toast(err.message || 'Nie udało się dodać obrazu'); }
      };
      inp.onchange = () => set(inp.files[0]);
      d.ondragover = (e) => { e.preventDefault(); d.classList.add('over'); };
      d.ondragleave = () => d.classList.remove('over');
      d.ondrop = (e) => { e.preventDefault(); d.classList.remove('over'); set(e.dataTransfer.files[0]); };
      d.querySelector('.x').onclick = (e) => {
        e.preventDefault(); e.stopPropagation();
        d.querySelector('img')?.remove(); d.classList.remove('has'); inp.value = '';
        if (onChange) onChange(null, key);
        else { AV.ui.ntScreens = AV.ui.ntScreens || {}; delete AV.ui.ntScreens[key]; AV.saveDraft(); }
        AV.toast('Screen usunięty');
      };
      d.onclick = (e) => {
        if (e.target.closest('.x') || e.target === inp) return;
        inp.click();
      };
    });
  };

  document.addEventListener('paste', (e) => {
    if (AV.currentView !== 'nowa' || !$('#nowa')?.classList.contains('on')) return;
    if (AV.lockInfo(AV.state).active) return;
    const f = [...(e.clipboardData?.files || [])][0];
    if (!f) return;
    AV.readImage(f).then((data) => {
      AV.ui.ntScreens = AV.ui.ntScreens || {};
      const key = !AV.ui.ntScreens.before ? 'before' : 'after';
      AV.ui.ntScreens[key] = data;
      AV.renderDrops(AV.ui.ntScreens); AV.saveDraft();
      AV.toast('Screen wklejony');
    }).catch((err) => AV.toast(err.message));
  });

  /* ---------- kalkulator ---------- */
  AV.calc = { mode: 'crypto', dir: 'long' };
  AV.fillInst = () => {
    const list = AV.INST[AV.calc.mode];
    AV.setHTML('#cInst', list.map((x, i) => '<option value="' + i + '">' + AV.esc(x[0]) + '</option>').join(''));
    AV.show('#levWrap', AV.calc.mode === 'crypto');
    AV.setPrice();
  };
  AV.setPrice = () => {
    const x = AV.INST[AV.calc.mode][+$('#cInst').value || 0];
    if (!x) return;
    AV.setHTML('#cPrice', '<b>' + AV.fmt(x[1], AV.dec(x[1])) + '</b><span class="win" style="font-size:11px;display:flex;align-items:center;gap:6px"><span class="pulse" style="width:6px;height:6px"></span>live</span>');
    AV.autoLv();
  };
  AV.autoLv = () => {
    const x = AV.INST[AV.calc.mode][+$('#cInst').value || 0];
    if (!x) return;
    const p = x[1], k = AV.calc.mode === 'crypto' ? 0.012 : x[2] === 'fx' ? 0.004 : 0.008;
    const s = AV.calc.dir === 'long' ? 1 : -1;
    AV.setVal('#cEn', AV.fmt(p, AV.dec(p)));
    AV.setVal('#cSl', AV.fmt(p * (1 - s * k), AV.dec(p)));
    AV.setVal('#cTp', AV.fmt(p * (1 + s * k * 3), AV.dec(p)));
    AV.calcResult();
  };
  AV.pipVal = (x, pr) => {
    if (x[4] === 'jpy') return 1000 / pr;
    if (x[4] === 'quote') return 10 / pr;
    if (x[4] === 'gbp') return 12.955;
    if (x[2] === 'fx') return 10;
    return x[4];
  };
  AV.stepsHTML = (a) => a.map((s, i) => '<li><span class="n">' + (i + 1) + '</span><span><b>' + s[0] + '</b> · ' + s[1] + ': ' + s[2] + '</span></li>').join('');
  AV.calcResult = () => {
    const bal = AV.pnum($('#cBal').value), risk = AV.pnum($('#cRisk').value), lev = Math.max(1, AV.pnum($('#cLev').value));
    const en = AV.pnum($('#cEn').value), sl = AV.pnum($('#cSl').value), tp = AV.pnum($('#cTp').value);
    const w = $('#cWarn');
    if (w) w.style.display = 'none';
    $('#cWarn2').style.display = risk > 5 ? 'block' : 'none';
    $('#rBar').style.left = Math.min(98, risk * 10) + '%';
    const set = (a, b, c, d, e, f) => {
      AV.setText('#rMain', a); AV.setText('#rLoss', b); AV.setText('#rWin', c);
      AV.setText('#rNot', d); AV.setText('#rRR', e); AV.setText('#rRRh', f || '');
    };
    if (!bal || !risk || !en || !sl) {
      set('—', '—', '—', '—', '—');
      AV.setHTML('#rSteps', '<li>Wpisz saldo, ryzyko, wejście i stop loss.</li>');
      return;
    }
    const bad = AV.calc.dir === 'long' ? (sl >= en || (tp && tp <= en)) : (sl <= en || (tp && tp >= en));
    if (bad) {
      if (w) {
        w.textContent = AV.calc.dir === 'long' ? 'Przy LONG stop loss musi być PONIŻEJ wejścia, a take profit POWYŻEJ.' : 'Przy SHORT stop loss musi być POWYŻEJ wejścia, a take profit PONIŻEJ.';
        w.style.display = 'block';
      }
      set('—', '—', '—', '—', '—');
      AV.setHTML('#rSteps', '');
      return;
    }
    const ml = bal * risk / 100, slP = Math.abs(en - sl) / en, tpP = tp ? Math.abs(tp - en) / en : 0;
    const rr = tpP / slP, rrh = rr >= 3 ? 'wystarczy ~25% skuteczności' : rr >= 2 ? 'wystarczy ~34% skuteczności' : rr > 0 ? 'poniżej 1:2, przemyśl cel' : '';
    if (AV.calc.mode === 'crypto') {
      const no = ml / slP, mg = no / lev, pf = no * tpP;
      AV.setText('#rMainL', 'Za tę kwotę wejdź w trade');
      AV.setText('#rMainH', 'depozyt przy dźwigni ' + lev + '× · ' + AV.fmt(mg / bal * 100, 1) + '% salda');
      AV.setText('#rNotL', 'Wartość pozycji');
      set(AV.fmt(mg) + ' $', '−' + AV.fmt(ml) + ' $', tp ? '+' + AV.fmt(pf) + ' $' : '—', AV.fmt(no) + ' $', tp ? '1 : ' + AV.fmt(rr, 2) : '—', rrh);
      AV.setHTML('#rSteps', AV.stepsHTML([
        ['Max strata', 'saldo × ryzyko', '<b>' + AV.fmt(ml) + ' $</b>'],
        ['Odległość SL', '|wejście − SL| ÷ wejście', '<b>' + AV.fmt(slP * 100, 2) + '%</b>'],
        ['Wartość pozycji', 'max strata ÷ SL%', '<b>' + AV.fmt(no) + ' $</b>'],
        ['Kwota wejścia', 'wartość ÷ dźwignia', '<b>' + AV.fmt(mg) + ' $</b>'],
        ['Zysk przy TP', 'wartość × TP%', tp ? '<b>' + AV.fmt(pf) + ' $</b>' : '—']
      ]));
    } else {
      const x = AV.INST[AV.calc.mode][+$('#cInst').value || 0];
      const pip = x[3], sp = Math.abs(en - sl) / pip, tpp = tp ? Math.abs(tp - en) / pip : 0;
      const pv = AV.pipVal(x, en), lots = ml / (sp * pv), pf = lots * tpp * pv;
      const u = x[2] === 'ind' ? 'pkt' : 'pips';
      AV.setText('#rMainL', 'Wielkość pozycji (MT4 / MT5)');
      AV.setText('#rMainH', '1 lot · ' + AV.fmt(pv, 2) + ' $ za ' + (u === 'pkt' ? 'punkt' : 'pip') + ' · sprawdź specyfikację u brokera');
      AV.setText('#rNotL', 'Odległość SL');
      set(AV.fmt(lots, 2) + ' lota', '−' + AV.fmt(ml) + ' $', tp ? '+' + AV.fmt(pf) + ' $' : '—', AV.fmt(sp, 1) + ' ' + u, tp ? '1 : ' + AV.fmt(rr, 2) : '—', rrh);
      AV.setHTML('#rSteps', AV.stepsHTML([
        ['Max strata', 'saldo × ryzyko', '<b>' + AV.fmt(ml) + ' $</b>'],
        ['SL w ' + u, '|wejście − SL| ÷ ' + pip, '<b>' + AV.fmt(sp, 1) + '</b>'],
        ['Wartość ' + (u === 'pkt' ? 'punktu' : 'pipsa'), 'na 1 lot', '<b>' + AV.fmt(pv, 2) + ' $</b>'],
        ['Loty', 'max strata ÷ (SL × wartość)', '<b>' + AV.fmt(lots, 2) + '</b>'],
        ['Zysk przy TP', 'loty × TP × wartość', tp ? '<b>' + AV.fmt(pf) + ' $</b>' : '—']
      ]));
    }
  };
  AV.bindCalc = () => {
    $$('#calcMode button').forEach((b) => b.addEventListener('click', () => {
      AV.calc.mode = b.dataset.m;
      $$('#calcMode button').forEach((x) => x.classList.toggle('on', x === b));
      AV.fillInst();
    }));
    $('#cInst')?.addEventListener('change', AV.setPrice);
    $$('#cDir .chip').forEach((c) => c.addEventListener('click', () => {
      AV.calc.dir = c.dataset.d;
      $$('#cDir .chip').forEach((x) => x.classList.toggle('on', x === c));
      AV.autoLv();
    }));
    $$('.qk').forEach((q) => q.querySelectorAll('.chip').forEach((c) => c.onclick = () => {
      $('#' + q.dataset.for).value = c.dataset.v;
      q.querySelectorAll('.chip').forEach((x) => x.classList.toggle('on', x === c));
      AV.calcResult();
    }));
    ['#cBal', '#cLev', '#cRisk', '#cEn', '#cSl', '#cTp'].forEach((i) => $(i)?.addEventListener('input', AV.calcResult));
    $('#cAuto')?.addEventListener('click', AV.autoLv);
    $('#cReset')?.addEventListener('click', () => {
      AV.setVal('#cBal', '1000'); AV.setVal('#cLev', '50'); AV.setVal('#cRisk', '1');
      $$('.qk .chip').forEach((x) => x.classList.remove('on'));
      AV.autoLv();
    });
    AV.fillInst();
  };

  /* ---------- mentor ---------- */
  AV._bags = [];
  AV.pickAnswer = (i) => {
    if (!AV._bags[i] || !AV._bags[i].length) AV._bags[i] = [...Array(AV.MENTOR[i].a.length).keys()].sort(() => Math.random() - 0.5);
    return AV._bags[i].pop();
  };
  AV.ensureChat = () => {
    if (AV.state.chat.length) return;
    AV.state.chat.push({ role: 'a', html: AV.fill('Cześć {imie}. Widzę dziś {straty} straty i gotowość {gotowosc}/100, więc jestem w pogotowiu. Wybierz poniżej, o czym chcesz pogadać.', AV.state), at: Date.now() });
    AV.saveNow();
  };
  AV.renderQ = () => {
    AV.setHTML('#mChips', AV.MENTOR.map((x, i) => '<span class="chip" data-q="' + i + '">' + AV.esc(x.q) + '</span>').join(''));
  };
  AV.ask = (i) => {
    if (AV._thinking) return;
    const q = AV.MENTOR[i]; if (!q) return;
    AV._thinking = true;
    AV.state.chat.push({ role: 'u', html: AV.esc(q.q), at: Date.now() });
    AV.renderMentor();
    const msgs = $('#msgs');
    msgs.insertAdjacentHTML('beforeend', '<div class="msg a" id="typ"><div class="who">Î± MENTOR</div><span class="typing"><i></i><i></i><i></i></span></div>');
    msgs.scrollTop = 1e6;
    $('#mChips').style.pointerEvents = 'none';
    setTimeout(() => {
      const idx = AV.pickAnswer(i);
      AV.state.chat.push({ role: 'a', html: AV.fill(q.a[idx], AV.state), at: Date.now() });
      AV.chatTrim();
      AV.saveNow();
      AV.renderMentor();
      AV._thinking = false;
      $('#mChips').style.pointerEvents = '';
    }, 950);
  };
  AV.chatTrim = () => {
    if (AV.state.chat.length > 60) AV.state.chat = AV.state.chat.slice(-60);
  };

  AV.bindMentor = () => {
    $('#mTabs')?.addEventListener('click', (e) => {
      const b = e.target.closest('button'); if (!b) return;
      $$('#mTabs button').forEach((x) => x.classList.toggle('on', x === b));
      $$('.mt').forEach((m) => m.style.display = m.id === b.dataset.t ? 'block' : 'none');
    });
    $('#mChips')?.addEventListener('click', (e) => {
      const c = e.target.closest('[data-q]'); if (c) AV.ask(+c.dataset.q);
    });
    document.addEventListener('click', (e) => {
      const b = e.target.closest('[data-ask]');
      if (b) AV.ask(+b.dataset.ask);
    });
    $('#mktInst')?.addEventListener('click', (e) => {
      const c = e.target.closest('.chip'); if (!c) return;
      AV.ui.mktInst = c.dataset.i;
      $$('#mktInst .chip').forEach((x) => x.classList.toggle('on', x === c));
      AV.renderMarketAnalysis();
    });
    $('#reAn')?.addEventListener('click', () => {
      AV.renderMarketAnalysis(); AV.renderWeekly(); AV.renderMentor();
      AV.toast('Analiza odświeżona');
    });
    $('#proClose')?.addEventListener('click', () => $('#pro').classList.remove('on'));
    $('#proGo')?.addEventListener('click', () => { $('#pro').classList.remove('on'); AV.showView('mentor'); AV.ask(0); });
  };

  /* ---------- akademia ---------- */
  AV.bindAcademy = () => {
    $('#liveRem')?.addEventListener('click', () => {
      AV.state.meta.liveReminder = !AV.state.meta.liveReminder;
      AV.saveNow();
      AV.ensurePermission();
      AV.toast(AV.state.meta.liveReminder ? 'Przypomnę 15 minut przed live' : 'Przypomnienie wyłączone');
    });
    $('#bookBtn')?.addEventListener('click', () => {
      const options = [];
      let d = AV.addDays(new Date(), 1);
      while (options.length < 6) {
        if (d.getDay() !== 0 && d.getDay() !== 6) options.push(new Date(d));
        d = AV.addDays(d, 1);
      }
      const body = AV.openModal('<div class="eyebrow">Konsultacje 1:1</div><h1 style="font-size:26px;margin-top:6px">Zarezerwuj kolejną</h1><p class="mut" style="margin-top:8px;font-size:13.5px">Wybierz termin. Rezerwacja jest lokalna — w wersji produkcyjnej potwierdzi się w kalendarzu.</p><div class="grid" style="margin-top:14px;gap:8px" id="bookSlots">' +
        options.map((x, i) => '<button class="btn g" data-slot="' + i + '" style="justify-content:flex-start">' + x.toLocaleDateString('pl-PL', { weekday: 'long', day: '2-digit', month: '2-digit' }) + ' · 18:00</button>').join('') + '</div>', { maxWidth: '520px' });
      $$('#bookSlots [data-slot]', body).forEach((b) => b.onclick = () => {
        const d2 = options[+b.dataset.slot];
        AV.state.meta.consultation = { date: AV.dateKey(d2), time: '18:00', who: 'Marcin' };
        AV.state.meta.consultationUsed = Math.min(3, (AV.state.meta.consultationUsed || 0) + 1);
        AV.saveNow(); AV.closeModal(); AV.renderAcademy(); AV.renderUser();
        AV.toast('Zarezerwowano: ' + d2.toLocaleDateString('pl-PL', { weekday: 'long', day: '2-digit', month: '2-digit' }) + ', 18:00');
      });
    });
    $('#shareTrade')?.addEventListener('click', () => { AV.showView('trans'); AV.toast('Kliknij transakcję, którą chcesz omówić'); });
    document.querySelector('#adminEvents')?.addEventListener('click', async (e) => {
      const btn = e.target.closest('[data-event-category]');
      if (!btn) return;
      const ok = await AV.setEventCategory(btn.dataset.eventId, btn.dataset.eventCategory);
      AV.toast(ok ? 'Kategoria wydarzenia zapisana' : 'Nie udało się zapisać kategorii');
    });
    $('#akBack')?.addEventListener('click', () => AV.setPlan(false));
    $('#recordsAll')?.addEventListener('click', () => AV.openRecordings());
    document.addEventListener('click', (e) => {
      const rec = e.target.closest('[data-recording]');
      if (rec) AV.openRecording(rec.dataset.recording);
    });
  };

  AV.openRecording = (title) => {
    AV.openModal('<div class="eyebrow">Nagranie</div><h1 style="font-size:26px;margin-top:6px">' + AV.esc(title) + '</h1><div style="aspect-ratio:16/9;background:radial-gradient(ellipse at 50% 30%,rgba(var(--a),.18),transparent 60%),#0b0a09;border:1px solid var(--line2);border-radius:16px;display:grid;place-items:center;margin-top:16px"><div style="text-align:center"><div style="width:60px;height:60px;border-radius:50%;background:var(--alpha);color:var(--ink);display:grid;place-items:center;margin:0 auto 10px">' + AV.ic('play', 'width:24px;height:24px') + '</div><span class="dim" style="font-size:12.5px">Odtwarzanie w wersji produkcyjnej</span></div></div><p class="mut" style="margin-top:14px;font-size:13.5px">Nagrania live\u2019ów są dostępne w bibliotece Akademii. W tym podglądzie panel odtwarzacza jest demonstracyjny.</p><div style="display:flex;gap:10px;margin-top:18px"><button class="btn p" data-gclose>Zamknij</button></div>', { maxWidth: '720px' });
  };
  AV.openRecordings = () => {
    const list = ['Najczęstsze błędy na starcie', 'Obsługa giełdy krok po kroku', 'Blockchain bez żargonu', 'Plan dnia od A do Z', 'Psychologia straty'];
    AV.openModal('<div class="eyebrow">Biblioteka</div><h1 style="font-size:26px;margin-top:6px">Wszystkie nagrania</h1><div class="grid" style="margin-top:16px;gap:8px">' + list.map((x) => '<div class="card flat" style="padding:14px;cursor:pointer" data-recording="' + AV.attr(x) + '"><b>' + AV.esc(x) + '</b><div class="hint">nagranie Akademii · kliknij, żeby otworzyć</div></div>').join('') + '</div>', { maxWidth: '620px' });
  };

  /* ---------- podatki ---------- */
  AV.bindTax = () => {
    $('#taxCsv')?.addEventListener('click', () => AV.exportTaxCsv());
    $('#taxPdf')?.addEventListener('click', () => AV.exportTaxPdf());
    $('#taxLossEdit')?.addEventListener('click', () => AV.editTaxCarry());
  };

  AV.editTaxCarry = () => {
    const t = AV.taxData(AV.state);
    AV.promptText('Nadwyżka kosztów z ' + (t.year - 1), 'Kwota w zł (wpisz 0, jeśli brak)', (value) => {
      const carryLoss = Math.max(0, AV.pnum(value));
      AV.state.tax = AV.normalizeTax({ ...AV.state.tax, carryLoss }, { migrateDemoSeed: false });
      AV.saveNow();
      AV.renderTax();
      AV.toast('Nadwyżka kosztów z ' + (t.year - 1) + ': ' + AV.fmt(carryLoss, 2) + ' zł');
    });
    const input = document.querySelector('#pmInput');
    if (input) input.value = t.carry ? String(t.carry).replace('.', ',') : '';
  };

  /* ---------- kalendarz ---------- */
  AV.bindCalendar = () => {
    $('#calSeg')?.addEventListener('click', (e) => {
      const b = e.target.closest('button'); if (!b) return;
      AV.ui.weekOffset = Number(b.dataset.w) || 0;
      AV.renderCalendar();
    });
    $('#mwPill')?.addEventListener('click', (e) => { e.stopPropagation(); $('#mw').classList.toggle('open'); });
    document.addEventListener('click', (e) => { if (!e.target.closest('#mw')) $('#mw').classList.remove('open'); });
  };

  /* ---------- ciało ---------- */
  AV.connectWearable = async (provider) => {
    if (!AV.api.online || !AV.api.authenticated) {
      AV.toast('Połącz urządzenie po zalogowaniu do Alpha Vault.');
      return;
    }
    if (provider === 'apple') {
      await AV.openApplePair();
      return;
    }
    location.href = '/auth/wearables/' + encodeURIComponent(provider) + '/start';
  };

  AV.disconnectWearable = (provider) => {
    const label = AV.api.wearables?.devices?.[provider]?.label || provider;
    AV.confirm({
      eyebrow: 'Zgoda na dane zdrowotne',
      title: 'Odłączyć ' + label + '?',
      text: 'Odłączenie usuwa z Alpha Vault dane o zdrowiu z tego urządzenia i wycofuje zgodę na ich przetwarzanie. Możesz połączyć je ponownie w każdej chwili.',
      confirmLabel: 'Odłącz i usuń dane',
      danger: true,
      onConfirm: async () => {
        try {
          const res = await AV.apiFetch('/api/wearables/' + encodeURIComponent(provider), { method: 'DELETE' });
          if (!res.ok) throw new Error('HTTP ' + res.status);
          await AV.loadWearables();
          AV.renderBody();
          AV.toast('Urządzenie odłączone. Dane zostały usunięte.');
        } catch (err) {
          AV.toast('Nie udało się odłączyć urządzenia.');
        }
      }
    });
  };

  AV.syncWearable = async (provider) => {
    if (!AV.api.authenticated) return;
    try {
      const res = await AV.apiFetch('/api/wearables/' + encodeURIComponent(provider) + '/sync', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(data.message || ('HTTP ' + res.status));
      await AV.loadWearables();
      AV.renderBody();
      if (data.pending === 'provider_spec') {
        AV.toast('Garmin jest połączony. Dane pojawią się po wdrożeniu odbioru push.');
      } else {
        AV.toast('Zsynchronizowano ' + (AV.api.wearables?.devices?.[provider]?.label || provider) + '.');
      }
    } catch (err) {
      AV.toast('Synchronizacja nie powiodła się: ' + err.message);
    }
  };

  AV.openApplePair = async () => {
    try {
      const res = await AV.apiFetch('/api/wearables/apple/pair', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(data.message || ('HTTP ' + res.status));
      const expires = new Date(data.expiresAt).toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Warsaw' });
      const body = AV.openModal(
        '<div class="eyebrow">Apple Watch · Zdrowie</div><h1 style="font-size:26px;margin-top:6px">Kod parowania</h1>' +
        '<p class="mut" style="margin-top:10px;line-height:1.6">Otwórz aplikację Alpha Vault na iPhone, wejdź w Ustawienia → Urządzenia i wpisz kod. Kod jest jednorazowy i wygasa o ' + AV.esc(expires) + '.</p>' +
        '<div class="mono" style="font-size:30px;font-weight:700;letter-spacing:.12em;text-align:center;margin:22px 0;padding:18px;border:1px dashed rgba(var(--a),.45);border-radius:16px;background:rgba(var(--a),.06)">' + AV.esc(data.code) + '</div>' +
        '<p class="dim" style="font-size:12.5px;line-height:1.5">Dane trafiają do Alpha Vault tylko z Twojego iPhone’a i tylko do liczenia gotowości. Po sparowaniu telefon zapisze token urządzenia; nie pokazujemy go w panelu.</p>' +
        '<div style="display:flex;gap:10px;margin-top:18px;justify-content:flex-end"><button class="btn g" data-gclose>Zamknij</button><button class="btn p" id="applePairCheck">Sprawdź połączenie</button></div>',
        { maxWidth: '520px' }
      );
      const check = async () => {
        await AV.loadWearables();
        AV.renderBody();
        if (AV.api.wearables?.devices?.apple?.connected) {
          AV.closeModal();
          AV.toast('Apple Watch połączony.');
          return true;
        }
        return false;
      };
      AV.$('#applePairCheck', body)?.addEventListener('click', check);
      let tries = 0;
      const timer = setInterval(async () => {
        tries++;
        if (tries > 60 || AV.api.wearables?.devices?.apple?.connected) { clearInterval(timer); return; }
        await check();
      }, 5000);
    } catch (err) {
      AV.toast('Nie udało się utworzyć kodu parowania: ' + err.message);
    }
  };

  AV.bindBody = () => {
    $('#devList')?.addEventListener('click', (e) => {
      const b = e.target.closest('[data-dev]'); if (!b) return;
      const row = b.closest('[data-provider]'), key = row?.dataset.provider;
      if (!key) return;
      if (b.dataset.mode === 'disconnect') AV.disconnectWearable(key);
      else AV.connectWearable(key);
    });
    $('#devList')?.addEventListener('click', (e) => {
      const b = e.target.closest('[data-sync]'); if (!b) return;
      const key = b.closest('[data-provider]')?.dataset.provider;
      if (key) AV.syncWearable(key);
    });
    $('#checkMood')?.addEventListener('click', (e) => {
      const c = e.target.closest('.chip'); if (!c) return;
      $$('#checkMood .chip').forEach((x) => x.classList.toggle('on', x === c));
    });
    $('#checkEnergy')?.addEventListener('click', (e) => {
      const c = e.target.closest('.chip'); if (!c) return;
      $$('#checkEnergy .chip').forEach((x) => x.classList.toggle('on', x === c));
    });
    $('#saveCheck')?.addEventListener('click', () => {
      const moodEl = $('#checkMood .chip.on');
      const energyEl = $('#checkEnergy .chip.on');
      if (!moodEl || !energyEl) {
        AV.toast('Wybierz nastrój i poziom energii.');
        return;
      }
      const mood = moodEl.textContent;
      const energy = Number(energyEl.textContent);
      const note = $('#checkNote').value.trim();
      const moodMod = { 'Spokój': 10, 'Skupienie': 7, 'Pewność': 5, 'Nuda': -3, 'Zmęczenie': -8, 'Strach': -10, 'FOMO': -12, 'Złość': -14, 'Chciwość': -6 }[mood] || 0;
      const score = Math.round(AV.clamp(50 + (energy - 3) * 10 + moodMod, 0, 100));
      const key = AV.dateKey(new Date());
      AV.state.checkins = AV.state.checkins.filter((c) => c.date !== key);
      AV.state.checkins.push({ date: key, mood, energy, note, score });
      AV.saveNow(); AV.renderBody(); AV.renderDashboard(); AV.renderMentor();
      AV.toast('Check-in zapisany. Mentor go uwzględni.');
    });
  };

  /* ---------- giełdy ---------- */
  AV.bindExchanges = () => {
    $$('.exl button').forEach((b) => b.addEventListener('click', () => AV.renderEx(b.dataset.ex)));
    $('#syncNow')?.addEventListener('click', async () => {
      const c = AV.state.settings.connections || {};
      const connected = Object.entries(c).filter(([k, v]) => v && v.connected && k !== 'file');
      if (!connected.length) { AV.toast('Połącz najpierw giełdę albo MetaTrader'); return; }
      const btn = $('#syncNow');
      const old = btn ? btn.innerHTML : '';
      if (btn) { btn.disabled = true; btn.textContent = 'Synchronizuję…'; }
      try {
        const data = await AV.syncConnections();
        AV.renderExchangeList();
        if (AV.renderEx) AV.renderEx(AV.exKey);
        AV.toast(data.imported > 0
          ? 'Pobrano ' + data.imported + ' ' + AV.plural(data.imported, 'transakcję', 'transakcje', 'transakcji') + ' z ' + connected.length + ' połączeń'
          : 'Synchronizacja gotowa · brak nowych transakcji', 5000);
      } catch (err) {
        AV.toast(err.message || 'Synchronizacja nie powiodła się.', 7000);
      } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = old; }
      }
    });
  };


  /* ---------- ustawienia ---------- */
  AV.bindSettings = () => {
    $('#nameIn')?.addEventListener('input', AV.debounce((e) => {
      AV.state.user.name = e.target.value.trim() || 'Trader';
      AV.save(); AV.renderUser(); AV.renderDashboard(); AV.renderMentor();
    }, 250));
    $('#goalIn')?.addEventListener('input', AV.debounce((e) => {
      AV.state.user.goal = e.target.value; AV.save(); AV.setText('#goalTxt', e.target.value || '—');
    }, 250));
    $('#currencyIn')?.addEventListener('change', (e) => {
      AV.state.user.currency = e.target.value; AV.saveNow(); AV.renderDashboard(); AV.renderStats(); AV.renderJournal(); AV.renderNewTradeForm();
      AV.toast('Waluta wyników: ' + e.target.value);
    });
    $('#tzIn')?.addEventListener('change', (e) => { AV.state.user.timezone = e.target.value; AV.saveNow(); AV.renderCalendar(); AV.renderAcademy(); AV.renderMentor(); AV.toast('Strefa czasowa: ' + e.target.value); });
    $('#hoursIn')?.addEventListener('change', (e) => { AV.state.user.hour12 = e.target.value.startsWith('12'); AV.saveNow(); AV.renderCalendar(); AV.renderAcademy(); AV.toast('Format godzin zapisany'); });
    $('#marketChips')?.addEventListener('click', (e) => {
      const c = e.target.closest('.chip'); if (!c) return;
      c.classList.toggle('on');
      AV.state.user.markets = $$('#marketChips .chip.on').map((x) => x.textContent);
      AV.saveNow(); AV.renderUser();
    });
    $('#accents')?.addEventListener('click', (e) => {
      const w = e.target.closest('.sw'); if (!w) return;
      $$('#accents .sw').forEach((x) => x.classList.toggle('on', x === w));
      AV.state.user.accent = { a: w.dataset.a, b: w.dataset.b };
      AV.saveNow(); AV.applyAccent(); AV.renderDashboard(); AV.renderStats(); AV.renderMentor();
      document.querySelector('meta[name="theme-color"]')?.setAttribute('content', '#080707');
      AV.toast('Kolor akcentu zmieniony');
    });
    $$('[data-setting]').forEach((el) => el.onclick = () => AV.toggleSetting(el));
    document.addEventListener('click', (e) => {
      const t = e.target.closest('[data-setting]');
      if (t && !t._h) { /* binding przez delegation dla dynamicznych */ }
    });
    $('#maxLosses')?.addEventListener('change', (e) => { AV.state.settings.maxLosses = Math.max(1, AV.pnum(e.target.value) || 2); AV.saveNow(); AV.renderLock(); AV.renderDashboard(); AV.toast('Limit strat: ' + AV.state.settings.maxLosses); });
    $('#maxDailyLoss')?.addEventListener('change', (e) => { AV.state.settings.maxDailyLoss = Math.max(0.1, AV.pnum(e.target.value) || 3); AV.saveNow(); AV.renderLock(); AV.renderDashboard(); AV.toast('Limit straty dziennej zapisany'); });
    $('#blockChips')?.addEventListener('click', (e) => {
      const c = e.target.closest('.chip'); if (!c) return;
      $$('#blockChips .chip').forEach((x) => x.classList.toggle('on', x === c));
      AV.state.settings.block = c.dataset.block; AV.saveNow(); AV.renderLock();
      AV.toast('Blokada: ' + c.textContent);
    });
    $('#maxRisk')?.addEventListener('change', (e) => { AV.state.settings.maxRisk = Math.max(0.1, AV.pnum(e.target.value) || 1); AV.saveNow(); AV.renderNewTradeForm(); AV.renderNewTradeForm(); AV.toast('Max ryzyko: ' + AV.state.settings.maxRisk + '%'); });
    $('#saveRules')?.addEventListener('click', () => {
      const rules = ['#rule1', '#rule2', '#rule3'].map((x) => $(x).value.trim());
      if (rules.some((r) => !r)) { AV.toast('Uzupełnij wszystkie 3 zasady'); return; }
      AV.state.settings.rules = rules;
      AV.state.settings.checklist = $$('#rulesChecklist .chip.on').map((c) => c.dataset.c).filter(Boolean);
      AV.state.settings.maxRisk = Math.max(0.1, AV.pnum($('#maxRisk').value) || 1);
      AV.saveNow(); AV.renderChecklist(); AV.toast('Zasady zapisane');
    });
    $('#rulesChecklist')?.addEventListener('click', (e) => {
      const c = e.target.closest('.chip'); if (!c) return;
      c.classList.toggle('on');
      AV.state.settings.checklist = $$('#rulesChecklist .chip.on').map((x) => x.dataset.c).filter(Boolean);
      AV.saveNow(); AV.renderChecklist();
    });
    // własny punkt checklisty
    const add = document.createElement('span');
    add.className = 'chip'; add.textContent = '+ własny'; add.dataset.addRule = '1';
    $('#rulesChecklist')?.appendChild(add);
    add.onclick = () => AV.promptText('Nowy punkt checklisty', 'Nazwa punktu', (name) => {
      if (!name) return;
      const s = AV.state;
      if (!s.settings.checklist.includes(name)) s.settings.checklist.push(name);
      const chip = document.createElement('span');
      chip.className = 'chip on'; chip.dataset.c = name; chip.textContent = name;
      $('#rulesChecklist').insertBefore(chip, add);
      AV.saveNow(); AV.renderChecklist(); AV.toast('Punkt dodany do checklisty');
    });
    $('#addAcc')?.addEventListener('click', () => { const f = $('#accForm'); f.style.display = f.style.display === 'none' ? 'block' : 'none'; });
    $('#saveAcc')?.addEventListener('click', () => {
      const name = $('#accName').value.trim();
      const start = AV.pnum($('#accStart').value);
      if (!name) { AV.toast('Podaj nazwę konta'); return; }
      AV.state.accounts.push({
        id: AV.uid('acc'), name, exchange: $('#accExchange').value, type: $('#accType').value,
        currency: $('#accCurrency').value, start, date: $('#accDate').value.trim() || AV.dateLabel(new Date()),
        lev: $('#accLev').value.trim() || '1×', goal: $('#accGoal').value.trim(), balance: start, include: true, primary: false
      });
      AV.state.meta.sessions = AV.state.meta.sessions;
      AV.saveNow(); $('#accForm').style.display = 'none';
      ['#accName', '#accStart', '#accDate', '#accLev', '#accGoal'].forEach((x) => $(x).value = '');
      AV.renderAccounts(); AV.renderSettings(); AV.renderDashboard(); AV.toast('Konto dodane');
    });
    $('#accBody')?.addEventListener('click', (e) => {
      const del = e.target.closest('[data-acc-del]');
      if (del) {
        AV.confirm({ title: 'Usunąć konto?', text: 'Transakcje przypisane do tego konta zostaną w dzienniku, ale przestaną wchodzić do statystyk.', confirmLabel: 'Usuń', danger: true, onConfirm: () => {
          AV.state.accounts = AV.state.accounts.filter((a) => a.id !== del.dataset.accDel);
          AV.saveNow(); AV.renderAll(); AV.toast('Konto usunięte');
        } });
        return;
      }
      const tog = e.target.closest('[data-acc-inc]');
      if (tog) {
        const a = AV.state.accounts.find((x) => x.id === tog.dataset.accInc);
        if (!a) return;
        a.include = !a.include; AV.saveNow(); AV.renderAccounts(); AV.renderDashboard(); AV.renderStats(); AV.renderTax(); AV.toast(a.include ? 'Konto w statystykach' : 'Konto poza statystykami');
      }
    });
    $('#addFlow')?.addEventListener('click', () => AV.flowModal());
    $('#flowBody')?.addEventListener('click', (e) => {
      const del = e.target.closest('[data-flow-del]');
      if (!del) return;
      const f = AV.state.flows.find((x) => x.id === del.dataset.flowDel);
      if (!f) return;
      AV.confirm({ title: 'Usunąć wpłatę/wypłatę?', text: 'Usunięcie zmieni saldo konta i zestawienie PIT-38.', confirmLabel: 'Usuń', danger: true, onConfirm: () => {
        AV.state.flows = AV.state.flows.filter((x) => x.id !== f.id);
        const acc = AV.state.accounts.find((a) => a.name === f.account);
        if (acc) acc.balance += f.type === 'Wpłata' ? -f.amount : f.amount;
        AV.saveNow(); AV.renderFlows(); AV.renderAccounts(); AV.renderTax(); AV.renderSettings(); AV.toast('Usunięto');
      } });
    });
    $('#outAll')?.addEventListener('click', () => {
      AV.state.meta.sessions = [{ device: 'MacBook · Chrome', place: 'Warszawa', when: 'teraz' }];
      AV.saveNow(); AV.renderSettings(); AV.toast('Wylogowano inne urządzenia');
    });
    $('#dlAll')?.addEventListener('click', () => AV.downloadAll());
    $('#downloadDataExpired')?.addEventListener('click', () => AV.downloadAll());
    $('#deleteAcc')?.addEventListener('click', () => {
      const demo = !!AV.api.me?.isDemo;
      AV.confirm({
        title: 'Usunąć konto i wszystkie dane?',
        html: 'To trwale usunie dziennik, screeny, plany, klucze API i dane z zegarka. Tej operacji nie da się cofnąć.' + (demo ? ' <b>Konto demo po ponownym logowaniu zostanie odtworzone z danymi przykładowymi.</b>' : ''),
        confirmLabel: 'Usuń wszystko', danger: true, input: 'USUŃ', inputLabel: 'Wpisz USUŃ, aby potwierdzić',
        onConfirm: async () => {
          if (AV.api.online) {
            try {
              await AV.serverDeleteAccount();
              location.href = '/';
            } catch (err) { AV.toast(err.message || 'Nie udało się usunąć konta'); }
            return;
          }
          AV.resetAll(); AV.logout(); AV.toast('Konto usunięte'); setTimeout(() => location.reload(), 600);
        }
      });
    });
    $('#simExp')?.addEventListener('click', () => { AV.state.subscription.expired = true; AV.saveNow(); AV.renderExpired(); AV.toast('Podgląd: subskrypcja wygasła'); });
    $('#expBack')?.addEventListener('click', () => { AV.state.subscription.expired = false; AV.saveNow(); AV.renderExpired(); AV.toast('Wrócono do panelu'); });
    $('#simSolo')?.addEventListener('click', () => AV.setPlan(true));
  };

  AV.toggleSetting = (el) => {
    const k = el.dataset.setting;
    el.classList.toggle('on');
    const on = el.classList.contains('on');
    AV.state.settings[k] = on;
    if (k === 'macroBefore' || k === 'pushLimit') { if (on) AV.ensurePermission(); }
    AV.saveNow();
    if (k === 'remember') AV.toast(on ? 'Zostaniesz zalogowana na tym urządzeniu' : 'Sesja wygaśnie po zamknięciu karty');
    if (k === 'macroTwo') AV.renderCalendar();
    if (k === 'safetyEnabled') AV.renderLock();
    if (k === 'requireReason' || k === 'apiCode') AV.toast('Ustawienie zapisane');
  };

  /* ---------- wpłaty ---------- */
  AV.flowModal = () => {
    const accounts = AV.state.accounts;
    const body = AV.openModal('<div class="eyebrow">Konta i kapitał</div><h1 style="font-size:26px;margin-top:6px">Dodaj wpłatę lub wypłatę</h1>' +
      '<div class="grid g2" style="margin-top:14px"><div><span class="label">Typ</span><select class="input" id="flType"><option>Wpłata</option><option>Wypłata</option></select></div><div><span class="label">Konto</span><select class="input" id="flAcc">' + accounts.map((a) => '<option value="' + AV.attr(a.name) + '">' + AV.esc(a.name) + '</option>').join('') + '</select></div>' +
      '<div><span class="label">Data</span><input class="input mono" id="flDate" value="' + AV.dateKey(new Date()) + '"></div><div><span class="label">Kwota USDT</span><input class="input mono" id="flAmount" placeholder="0,00"></div>' +
      '<div><span class="label">Waluta fiat</span><select class="input" id="flCur"><option>PLN</option><option>EUR</option><option>USD</option></select></div><div><span class="label">Kwota fiat</span><input class="input mono" id="flFiat" placeholder="0,00"></div>' +
      '<div><span class="label">Kurs NBP</span><input class="input mono" id="flFx" value="1"></div><div><span class="label">Opis</span><input class="input" id="flOp" value="P2P"></div></div>' +
      '<div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px"><button class="btn g" data-gclose>Anuluj</button><button class="btn p" id="flSave">Zapisz</button></div>', { maxWidth: '620px' });
    $('#flSave', body).onclick = () => {
      const type = $('#flType', body).value, acc = $('#flAcc', body).value;
      const amount = AV.pnum($('#flAmount', body).value);
      const fiat = AV.pnum($('#flFiat', body).value);
      const currency = $('#flCur', body).value;
      const fx = currency === 'PLN' ? 1 : AV.pnum($('#flFx', body).value);
      if (!amount || !fiat) { AV.toast('Podaj kwotę USDT i kwotę fiat'); return; }
      if (currency !== 'PLN' && (!fx || fx <= 1)) { AV.toast('Podaj kurs NBP z dnia roboczego przed transakcją'); return; }
      const date = $('#flDate', body).value || AV.dateKey(new Date());
      const flow = {
        id: AV.uid('flow'), date, account: acc,
        type, op: $('#flOp', body).value + (type === 'Wpłata' ? ' · zakup USDT' : ' · sprzedaż USDT'),
        amount, currency, fiat, fx, tax: type === 'Wpłata' ? 'cost' : 'income'
      };
      AV.state.flows.push(flow);
      const a = AV.state.accounts.find((x) => x.name === acc);
      if (a) a.balance += type === 'Wpłata' ? amount : -amount;
      AV.saveNow(); AV.closeModal(); AV.renderFlows(); AV.renderAccounts(); AV.renderTax(); AV.renderSettings(); AV.renderDashboard();
      if (Number(String(date).slice(0, 4)) !== AV.taxYear(AV.state)) {
        AV.toast(type + ' zapisana poza rokiem ' + AV.taxYear(AV.state) + ' — nie wejdzie do zestawienia PIT-38', 5000);
      } else {
        AV.toast(type + ' zapisana');
      }
    };
  };

  /* ---------- eksport ---------- */
  AV.exportTradesCsv = () => {
    const rows = [['Data', 'Para', 'Kierunek', 'Ocena', 'Checklista', 'Błędy', 'Emocja', 'Setup', 'Wejście', 'Wyjście', 'SL', 'TP', 'Wielkość', 'R', 'Wynik USDT', 'Opłata', 'Plan', 'Notatka']];
    AV.includedTrades(AV.state).slice().sort((a, b) => new Date(a.date) - new Date(b.date)).forEach((t) => rows.push([
      new Date(t.date).toLocaleString('pl-PL'), t.pair, t.dir === 'l' ? 'LONG' : 'SHORT', t.grade,
      t.checklist + '/7', (t.errors || []).join(' | '), t.emotion, t.setup, t.entry, t.exit, t.sl, t.tp, t.size,
      t.r == null ? '' : t.r, t.result == null ? '' : t.result, t.fee || 0, t.plan ? 'tak' : 'nie', t.note
    ]));
    AV.download('alpha-vault-transakcje.csv', AV.toCsv(rows));
    AV.toast('Eksport CSV gotowy');
  };

  AV.exportTaxCsv = () => {
    const t = AV.taxData(AV.state);
    const rows = [['Data', 'Operacja', 'Rodzaj', 'Waluta', 'Kwota', 'Kurs NBP', 'W PLN']];
    t.ev.forEach((e) => rows.push([new Date(e.date).toLocaleDateString('pl-PL'), e.op, e.kind === 'cost' ? 'koszt' : 'przychód', e.currency, e.amount, e.fx, e.pln]));
    rows.push([]);
    rows.push(['Przychód', '', '', '', '', '', t.revenue]);
    rows.push(['Koszty', '', '', '', '', '', t.costs]);
    rows.push(['Nadwyżka kosztów z ' + (t.year - 1), '', '', '', '', '', t.carry]);
    rows.push(['Dochód', '', '', '', '', '', t.income]);
    rows.push(['Podatek 19%', '', '', '', '', '', t.due]);
    rows.push(['Do przeniesienia na ' + (t.year + 1), '', '', '', '', '', t.carryForward]);
    rows.push(['Neutralne podatkowo (transakcje)', '', '', '', '', '', t.neutral]);
    rows.push(['Forex i CFD MT4/MT5 (transakcje)', '', '', '', '', '', t.forex]);
    AV.download('alpha-vault-PIT-38-' + t.year + '.csv', AV.toCsv(rows));
    AV.toast('CSV podatkowy gotowy');
  };

  AV.taxPrintHtml = () => {
    const t = AV.taxData(AV.state), s = AV.state;
    const rows = t.ev.map((e) => '<tr><td>' + new Date(e.date).toLocaleDateString('pl-PL') + '</td><td>' + AV.esc(e.op) + '</td><td>' + (e.kind === 'cost' ? 'koszt' : 'przychód') + '</td><td>' + AV.fmt(e.amount, 2) + ' ' + e.currency + '</td><td>' + (e.fx === 1 ? '–' : AV.fmt(e.fx, 4)) + '</td><td>' + AV.fmt(e.pln, 2) + ' zł</td></tr>').join('');
    const summary = [
      ['Przychód', t.revenue],
      ['Koszty', t.costs],
      ['Nadwyżka kosztów z ' + (t.year - 1), t.carry],
      ['Dochód', t.income],
      ['Podatek 19%', t.due],
      ['Do przeniesienia na ' + (t.year + 1), t.carryForward]
    ].map(([label, value]) => '<div><span>' + label + '</span><b>' + AV.fmt(value, 2) + ' zł</b></div>').join('');
    const neutral = t.neutral + ' ' + AV.plural(t.neutral, 'transakcja', 'transakcje', 'transakcji');
    const forex = t.forex + ' ' + AV.plural(t.forex, 'transakcja', 'transakcje', 'transakcji');
    return '<!doctype html><html lang="pl"><head><meta charset="utf-8"><title>PIT-38 · Alpha Vault</title><style>body{font-family:Arial,sans-serif;color:#111;margin:32px;font-size:13px}h1{font-size:22px}table{width:100%;border-collapse:collapse;margin-top:16px}th,td{border-bottom:1px solid #ddd;padding:8px;text-align:left}th{background:#f4f1ec}.sum{margin-top:20px;width:320px}.sum div{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #eee}.sum b{font-size:16px}.note{color:#666;font-size:11px;margin-top:24px;line-height:1.5}</style></head><body><h1>Zestawienie do PIT-38 · ' + t.year + '</h1><p>' + AV.esc(s.user.name) + ' · wygenerowano ' + AV.nowStamp() + '</p><table><thead><tr><th>Data</th><th>Operacja</th><th>Rodzaj</th><th>Kwota</th><th>Kurs</th><th>W PLN</th></tr></thead><tbody>' + rows + '</tbody></table><div class="sum">' + summary + '</div><p class="note">Neutralne podatkowo: ' + neutral + '. Forex i CFD (MT4/MT5): ' + forex + '.</p><p class="note">Zestawienie pomocnicze, nie porada podatkowa. Przed złożeniem PIT-38 zweryfikuj dane z księgową lub doradcą podatkowym. Alpha Vault · dane wyłącznie użytkownika.</p><script>window.print()<\/script></body></html>';
  };

  AV.exportTaxPdf = () => {
    const year = AV.taxYear(AV.state);
    const w = window.open('', '_blank');
    if (!w) { AV.download('alpha-vault-PIT-38-' + year + '.html', AV.taxPrintHtml(), 'text/html;charset=utf-8'); AV.toast('Popup zablokowany — pobrano plik do wydruku'); return; }
    w.document.open(); w.document.write(AV.taxPrintHtml()); w.document.close();
    AV.toast('Otwieram wydruk — wybierz „Zapisz jako PDF”');
  };

  AV.downloadAll = () => {
    const s = AV.state;
    const files = [];
    files.push({ name: 'dane.json', data: JSON.stringify(s, null, 2) });
    files.push({ name: 'transakcje.csv', data: AV.toCsv([['Data', 'Para', 'Kierunek', 'Ocena', 'Wynik', 'R', 'Emocja', 'Setup', 'Notatka']].concat(s.trades.map((t) => [new Date(t.date).toLocaleString('pl-PL'), t.pair, t.dir, t.grade, t.result == null ? '' : t.result, t.r == null ? '' : t.r, t.emotion, t.setup, t.note]))) });
    files.push({ name: 'podatki.csv', data: AV.toCsv([['Data', 'Operacja', 'Rodzaj', 'Kwota', 'Waluta', 'W PLN']].concat(AV.taxData(s).ev.map((e) => [new Date(e.date).toLocaleDateString('pl-PL'), e.op, e.kind, e.amount, e.currency, e.pln]))) });
    files.push({ name: 'plany.json', data: JSON.stringify(s.plans, null, 2) });
    files.push({ name: 'README.txt', data: 'Alpha Vault — eksport danych\nWygenerowano: ' + AV.nowStamp() + '\n\nZawartość:\n- dane.json: pełny stan aplikacji (profil, transakcje, plany, ustawienia, połączenia)\n- transakcje.csv: dziennik transakcji\n- podatki.csv: zdarzenia podatkowe\n- plany.json: plany dnia\n- screeny/: screeny wykresów (jeśli dodane)\n\nDane są prywatne. Klucze API są zapisane w postaci zamaskowanej.\n' });
    s.trades.forEach((t) => {
      ['before', 'after'].forEach((k) => {
        if (t.screens && t.screens[k]) {
          const bytes = AV.bytesFromDataUrl(t.screens[k]);
          if (bytes) files.push({ name: 'screeny/' + t.id + '-' + k + '.jpg', data: bytes });
        }
      });
    });
    if (s.user.avatar) {
      const bytes = AV.bytesFromDataUrl(s.user.avatar);
      if (bytes) files.push({ name: 'profil/avatar.jpg', data: bytes });
    }
    const blob = AV.zip(files);
    AV.download('alpha-vault-dane-' + AV.dateKey(new Date()) + '.zip', blob);
    AV.toast('Paczka ZIP z danymi gotowa (' + files.length + ' plików)');
  };

  /* ---------- dokumenty / zakup / QA ---------- */
  AV.bindDocs = () => {
    document.addEventListener('click', (e) => {
      const d = e.target.closest('[data-doc]');
      if (!d) return;
      e.preventDefault();
      const tpl = $('#t-' + d.dataset.doc);
      if (!tpl) return;
      AV.setHTML('#docB', '<span class="mclose" data-close="docM">' + AV.ic('x', 'width:16px;height:16px') + '</span>' + tpl.innerHTML);
      $('#docM').classList.add('on'); $('#docM').scrollTop = 0;
    });
    $('#docM')?.addEventListener('click', (e) => { if (e.target.id === 'docM' || e.target.closest('[data-close]')) $('#docM').classList.remove('on'); });
    $('#buyM')?.addEventListener('click', (e) => { if (e.target.id === 'buyM') $('#buyM').classList.remove('on'); });
    $('#qa')?.addEventListener('click', (e) => { if (e.target.id === 'qa') $('#qa').classList.remove('on'); });
    document.addEventListener('click', (e) => {
      const buy = e.target.closest('[data-buy]');
      if (buy) {
        AV.buyPlan = buy.dataset.buy;
        AV.setText('#buyT', AV.buyPlan === 'full' ? 'Akademia Alpha + Vault' : 'Alpha Vault');
        AV.setText('#buyP', AV.buyPlan === 'full' ? '168 $ / miesiąc' : '49 $ / miesiąc');
        $$('#buyM [data-ck]').forEach((c) => c.classList.remove('on'));
        $('#buyGo').disabled = true;
        $('#buyM').classList.add('on');
        return;
      }
      const ck = e.target.closest('#buyM [data-ck]');
      if (ck) { ck.classList.toggle('on'); $('#buyGo').disabled = $$('#buyM [data-ck]').some((x) => !x.classList.contains('on')); return; }
      const qaOpen = e.target.closest('[data-qa]');
      if (qaOpen) { AV.openQuickAdd(); return; }
      const close = e.target.closest('[data-close]');
      if (close) $('#' + close.dataset.close)?.classList.remove('on');
    });
    $('#buyGo')?.addEventListener('click', () => {
      if ($('#buyGo').disabled) return;
      const plan = AV.buyPlan || 'full';
      AV.openModal('<div class="eyebrow">Płatność · tryb demo</div><h1 style="font-size:26px;margin-top:6px">Stripe nie jest podłączony</h1><p class="mut" style="margin-top:10px;line-height:1.6">W tym podglądzie nie pobieramy prawdziwej płatności. Możesz aktywować plan demonstracyjnie, żeby sprawdzić cały panel.</p><div style="display:flex;gap:10px;justify-content:flex-end;margin-top:22px"><button class="btn g" data-gclose>Anuluj</button><button class="btn p" id="activateDemo">Aktywuj plan demo</button></div>', { maxWidth: '520px' });
      $('#activateDemo').onclick = () => {
        AV.state.subscription.plan = plan;
        AV.state.subscription.expired = false;
        AV.state.subscription.until = AV.dateKey(AV.addDays(new Date(), 30));
        AV.state.subscription.renewedAt = Date.now();
        AV.saveNow(); $('#buyM').classList.remove('on'); AV.closeModal();
        AV.setPlan(plan === 'solo', true); AV.renderAll();
        AV.toast('Plan demo aktywowany: ' + (plan === 'full' ? 'Akademia Alpha + Vault' : 'Alpha Vault'));
      };
    });
    const qaRoot = $('#qa');
    qaRoot?.addEventListener('click', (e) => {
      const chip = e.target.closest('#qaEmotion .chip, #qaError .chip');
      if (chip) {
        const group = chip.parentElement;
        group.querySelectorAll('.chip').forEach((x) => x.classList.toggle('on', x === chip));
        AV.qaSyncSave();
        return;
      }
      const grade = e.target.closest('#qaG div');
      if (grade) {
        $$('#qaG div').forEach((x) => x.classList.toggle('on', x === grade));
        AV.qaSyncSave();
      }
    });
    AV.qaSave = () => {
      const t = AV.state.trades.find((x) => x.id === AV.ui.qaTradeId) || AV.pickQuickTrade();
      if (!t) { AV.toast('Brak transakcji do uzupełnienia'); return; }
      const emotion = $('#qaEmotion .chip.on')?.textContent;
      const grade = $('#qaG .on')?.textContent.trim();
      if (!emotion || !grade) { AV.toast('Wybierz emocję i ocenę'); return; }
      t.emotion = emotion;
      t.grade = grade;
      const err = $('#qaError .chip.on')?.textContent;
      t.errors = t.errors || [];
      if (err && err !== 'Brak' && !t.errors.includes(err)) t.errors.push(err);
      if (err === 'Brak') t.errors = t.errors.filter((x) => x !== 'Brak');
      AV.saveNow();
      $('#qa').classList.remove('on');
      AV.renderAll();
      AV.ui.qaLastSeconds = Math.round((performance.now() - (AV.ui.qaOpenedAt || performance.now())) / 100) / 10;
      AV.toast('Zapisane w ' + String(AV.ui.qaLastSeconds).replace('.', ',') + ' s');
    };
    $('#qaSave')?.addEventListener('click', AV.qaSave);
    $('#qaSaveFloat')?.addEventListener('click', AV.qaSave);
    $('#qaPhoto')?.addEventListener('click', () => $('#qaPhotoInput')?.click());
    $('#qaPhotoInput')?.addEventListener('change', async (e) => {
      const file = e.target.files && e.target.files[0];
      if (!file) return;
      try {
        const data = await AV.readImage(file);
        const t = AV.state.trades.find((x) => x.id === AV.ui.qaTradeId) || AV.pickQuickTrade();
        if (!t) { AV.toast('Brak transakcji'); return; }
        t.screens = t.screens || {};
        t.screens.after = data;
        AV.saveNow();
        const preview = $('#qaPhotoPreview');
        if (preview) { preview.src = data; preview.hidden = false; }
        AV.setText('#qaPhoto', '📷 Zmień zdjęcie wykresu');
        AV.toast('Zdjęcie przypisane do transakcji');
      } catch (error) {
        AV.toast(error.message);
      }
      e.target.value = '';
    });
  };

  /* ---------- prompt ---------- */
  AV.promptText = (title, label, cb) => {
    const body = AV.openModal('<div class="eyebrow">Alpha Vault</div><h1 style="font-size:24px;margin-top:6px">' + AV.esc(title) + '</h1><span class="label">' + AV.esc(label) + '</span><input class="input" id="pmInput" autocomplete="off"><div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px"><button class="btn g" data-gclose>Anuluj</button><button class="btn p" id="pmOk">Zapisz</button></div>', { maxWidth: '460px' });
    setTimeout(() => $('#pmInput', body).focus(), 50);
    const ok = () => { const v = $('#pmInput', body).value.trim(); AV.closeModal(); cb(v); };
    $('#pmOk', body).onclick = ok;
    $('#pmInput', body).addEventListener('keydown', (e) => { if (e.key === 'Enter') ok(); });
  };

  /* ---------- wyceny / licznik ---------- */
  AV.bindMisc = () => {
    $('#qaG') && $$('#qaG div').forEach((d) => d.onclick = () => $$('#qaG div').forEach((x) => x.classList.toggle('on', x === d)));
    $$('#qa .sel').forEach((g) => g.querySelectorAll('.chip').forEach((c) => c.onclick = () => {
      g.querySelectorAll('.chip').forEach((x) => x.classList.remove('on')); c.classList.add('on');
    }));
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        $$('.modal').forEach((m) => m.classList.remove('on'));
        $('#mw')?.classList.remove('open');
        $('#pro')?.classList.remove('on');
      }
    });
    $('#mwAll')?.addEventListener('click', () => { AV.showView('kal'); $('#mw').classList.remove('open'); });
    $$('.navg button').forEach((b) => b.onclick = () => AV.showView(b.dataset.v));
    document.addEventListener('click', (e) => {
      const g = e.target.closest('[data-go]');
      if (g) AV.showView(g.dataset.go);
    });
    $('#remember')?.addEventListener('click', () => {
      const on = $('#remember').classList.toggle('on');
      AV.state.settings.remember = on; AV.saveNow();
    });
    $('#enter')?.addEventListener('click', AV.enterApp);
    $('#logout')?.addEventListener('click', async () => {
      if (AV.api.online) { await AV.serverLogout(); location.href = '/'; return; }
      AV.logout(); $('#auth').classList.remove('hide'); AV.toast('Wylogowano');
    });
    window.addEventListener('hashchange', () => { if (AV.isLoggedIn()) AV.showView(AV.viewFromHash()); });
    // akordeony w modalu QA
    $$('#qaG div').forEach((d) => d.onclick = () => $$('#qaG div').forEach((x) => x.classList.toggle('on', x === d)));
  };

  AV.viewFromHash = () => (location.hash || '').replace(/^#\/?/, '') || 'pulpit';

  /* ---------- init ---------- */
  AV.init = async () => {
    AV.state = AV.load();
    const boot = await AV.bootstrap();
    AV.state = AV.state || AV.load();
    const hadLocalSession = AV.hasLocalSession();
    if (boot.authenticated) $('#auth').classList.add('hide');
    else if (boot.denied) $('#auth').classList.add('hide');
    else if (boot.offline && AV.isLoggedIn()) $('#auth').classList.add('hide');
    else if (AV.api.online && hadLocalSession) {
      $('#auth').classList.add('hide');
      AV.showAccessGate('Sesja wygasła. Zaloguj się ponownie — dostęp wymaga aktywnej roli Alpha.');
    } else $('#auth').classList.remove('hide');
    $('#roleCheck')?.addEventListener('click', async () => {
      const btn = $('#roleCheck');
      if (btn) btn.disabled = true;
      try {
        const res = await AV.apiFetch('/api/me');
        if (res.ok) {
          const me = await res.json();
          if (me.authenticated && (me.user?.isDemo || !me.roleCheckConfigured || me.user?.subscription?.roleVerified)) {
            location.reload();
            return;
          }
        }
        AV.setText('#roleGateMsg', res.status === 401
          ? 'Sesja wygasła. Zaloguj się ponownie przyciskiem powyżej.'
          : 'Nadal brak aktywnej roli Alpha. Sprawdź rolę na Discordzie i spróbuj ponownie za chwilę.');
      } catch {
        AV.setText('#roleGateMsg', 'Brak połączenia z serwerem. Spróbuj ponownie za chwilę.');
      } finally {
        if (btn) btn.disabled = false;
      }
    });
    $('#roleLogout')?.addEventListener('click', async () => {
      try { await AV.serverLogout(); } catch {}
      AV.logout();
      location.href = '/';
    });
    AV.ensureChat();
    AV.setPlan(AV.state.subscription.plan === 'solo', true);
    AV.renderAll();
    AV.bindMisc(); AV.bindPlan(); AV.bindJournal(); AV.bindPlaybook(); AV.bindNewTrade();
    AV.bindCalc(); AV.bindMentor(); AV.bindAcademy(); AV.bindTax(); AV.bindCalendar();
    AV.bindBody(); AV.bindExchanges(); AV.bindSettings(); AV.bindDocs(); AV.bindStats();
    if (AV.bindProp) AV.bindProp();
    AV.renderTicker(); AV.loadQuotes(); AV.tickCountdowns();
    AV.updateEnvBadge();
    const params = new URLSearchParams(location.search);
    const connected = params.get('connected');
    if (connected && AV.api.authenticated) {
      const label = AV.api.wearables?.devices?.[connected]?.label || connected;
      AV.toast(params.get('sync') === 'error' ? label + ' połączony, ale pierwsza synchronizacja się nie udała.' : label + ' połączony.');
      history.replaceState(null, '', location.pathname + '#cialo');
    }
    setInterval(AV.tickCountdowns, 1000);
    setInterval(AV.loadQuotes, 60000);
    setInterval(() => { if (AV.isLoggedIn()) { AV.renderCalendar(); AV.macroNotify(); if (AV.propNotify) AV.propNotify(); } }, 60000);
    setInterval(() => { if (AV.isLoggedIn()) AV.loadEvents(); }, 300000);
    setInterval(() => { if (AV.isLoggedIn()) AV.loadMacroEvents(); }, 300000);
    setInterval(() => { if (AV.isLoggedIn()) AV.loadWearables(); }, 300000);
    const roleCheckEvery = Math.max(300, Number(AV.api.roleRecheckSeconds) || 300) * 1000 + 15000;
    setInterval(() => { if (AV.api.online && AV.api.authenticated) AV.checkAccess(); }, roleCheckEvery);
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'visible') AV.loadQuotes();
      if (document.visibilityState === 'visible' && AV.isLoggedIn()) {
        AV.loadEvents(); AV.loadMacroEvents(); AV.loadWearables();
        if (Date.now() - (AV.api.lastRoleCheckAt || 0) > roleCheckEvery) AV.checkAccess();
      }
    });
    setTimeout(() => { AV.macroNotify(); AV.checkLiveReminder(); if (AV.propNotify) AV.propNotify(); }, 4000);
    AV.showView(AV.viewFromHash());
    if (AV.state.subscription.expired) AV.renderExpired();
    AV.ready = true;
    window.AV = AV;
    if (AV.isLoggedIn()) AV.quickAddFromUrl();
  };

  window.addEventListener('pagehide', () => AV.flushState());
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') AV.flushState(); });

  AV.checkLiveReminder = () => {
    if (!AV.state.meta.liveReminder) return;
    const info = AV.liveInfo();
    if (!info.live || info.liveNow) return;
    if (info.sec <= 900 && info.sec > 840 && !AV.state.meta.liveNotified) {
      AV.state.meta.liveNotified = true; AV.save();
      AV.toast('Za 15 min live: ' + info.live.name, 5000);
      AV.pushNotification('Live za 15 min', info.live.name);
    }
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', AV.init);
  else AV.init();
})();
