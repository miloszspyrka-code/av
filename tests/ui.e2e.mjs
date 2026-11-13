#!/usr/bin/env node
/**
 * Alpha Vault — testy UI e2e (Playwright + systemowy Chrome).
 * Uruchom: npm run test:ui
 * Wymaga serwera: npm start
 */
import assert from 'node:assert/strict';
import { chromium } from 'playwright-core';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const BASE = process.env.APP_URL || 'http://127.0.0.1:4173';
const candidates = [
  process.env.CHROME_PATH,
  path.join(process.env.LOCALAPPDATA || '', 'Google', 'Chrome', 'Application', 'chrome.exe'),
  'C:/Program Files/Google/Chrome/Application/chrome.exe',
  'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
  'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
  '/usr/bin/google-chrome',
  '/usr/bin/google-chrome-stable',
  '/usr/bin/chromium',
  '/usr/bin/chromium-browser'
].filter(Boolean);
const executablePath = candidates.find((p) => fs.existsSync(p));
if (!executablePath) {
  console.error('Brak Chrome/Edge. Ustaw CHROME_PATH.');
  process.exit(2);
}

const results = [];
const consoleErrors = [];
const pageErrors = [];
function log(name, ok, info = '') {
  results.push({ name, ok, info });
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${info ? '  · ' + info : ''}`);
}

const browser = await chromium.launch({ executablePath, headless: true, args: ['--no-sandbox'] });
const context = await browser.newContext({ baseURL: BASE, viewport: { width: 1440, height: 1000 } });
const page = await context.newPage();
page.on('console', (msg) => { if (msg.type() === 'error') consoleErrors.push(msg.text()); });
page.on('pageerror', (err) => pageErrors.push(err.message));

// Deterministyczne notowania: test nie zależy od sieci ani od dostawcy.
const QUOTE_STUB = {
  quotes: [
    { symbol: 'BTC', price: 71411.5, changePercent: 1.2 },
    { symbol: 'ETH', price: 3407.25, changePercent: -0.7 },
    { symbol: 'SOL', price: 81.48, changePercent: -2.1 },
    { symbol: 'NAS100', price: 21426, changePercent: 0.4 },
    { symbol: 'US500', price: 6192, changePercent: 0.2 },
    { symbol: 'XAUUSD', price: 3881.6, changePercent: 0.7 },
    { symbol: 'EUR/USD', price: 1.1057, changePercent: -0.3 },
    { symbol: 'OIL', price: 71.78, changePercent: 1.1 },
    { symbol: 'BTC/USDT', price: 71411.5, changePercent: 1.2 },
    { symbol: 'SOL/USDT', price: 81.48, changePercent: -2.1 }
  ],
  updatedAt: '2026-11-12T12:00:00Z',
  stale: false
};
let quoteRequests = 0;
await context.route('**/api/quotes**', (route) => {
  quoteRequests++;
  return route.fulfill({
    status: 200,
    contentType: 'application/json; charset=utf-8',
    body: JSON.stringify(QUOTE_STUB)
  });
});

try {
  await page.goto('/', { waitUntil: 'load' });
  await page.waitForFunction(() => window.AV && AV.ready);
  log('UI start', await page.locator('#auth').isVisible(), 'ekran logowania');

  await page.click('#enter');
  await page.waitForSelector('#demoEnter');
  log('modal logowania', await page.locator('#demoEnter').isVisible(), 'Discord niepodłączony → demo');

  await page.click('#demoEnter');
  await page.waitForFunction(() => document.querySelector('#auth')?.classList.contains('hide'), null, { timeout: 15000 });
  await page.waitForFunction(() => window.AV?.ready && AV.api.authenticated);
  log('logowanie demo + sesja', await page.locator('#auth').evaluate((el) => el.classList.contains('hide')), 'cookie + sesja');
  log('start: jedno zapytanie o notowania', quoteRequests <= 1, quoteRequests + ' requestów');

  // linki prawne w modalu Akademii oraz na ekranach wygaśnięcia i braku roli
  await page.evaluate(() => document.querySelector('[data-doc="ak"]').click());
  await page.waitForSelector('#docM.on');
  const docLinks = await page.evaluate(() => ({
    privacy: !!document.querySelector('#docB a[href="/polityka-prywatnosci"]'),
    terms: !!document.querySelector('#docB a[href="/regulamin"]'),
    contact: !!document.querySelector('#docB a[href^="mailto:kontakt@alphamoves.pl"]')
  }));
  log('modal Akademii: linki prawne', docLinks.privacy && docLinks.terms && docLinks.contact, JSON.stringify(docLinks));
  await page.evaluate(() => document.querySelector('#docM [data-close]').click());
  const gateLinks = await page.evaluate(() => ({
    expired: !!document.querySelector('#expired a[href="/polityka-prywatnosci"]') && !!document.querySelector('#expired a[href="/regulamin"]'),
    role: !!document.querySelector('#roleGate a[href="/polityka-prywatnosci"]') && !!document.querySelector('#roleGate a[href="/regulamin"]')
  }));
  log('ekrany wygaśnięcia i roli: linki prawne', gateLinks.expired && gateLinks.role, JSON.stringify(gateLinks));

  const policy = await page.evaluate(async () => {
    const html = await (await fetch('/polityka-prywatnosci')).text();
    return {
      mcp: html.includes('13.2. Zewnętrzni asystenci przez MCP'),
      provider: html.includes('OpenAI (ChatGPT), Anthropic (Claude), xAI (Grok)'),
      readOnly: html.includes('trybie tylko do odczytu'),
      revoke: html.includes('oauth/connected')
    };
  });
  log('polityka prywatności: sekcja MCP i wycofanie zgody', policy.mcp && policy.provider && policy.readOnly && policy.revoke, JSON.stringify(policy));

  const rows = await page.locator('#tbody tr').count();
  assert.equal(rows, 47);
  log('dziennik z bazy', rows === 47, rows + ' transakcji');

  await page.click('.navg button[data-v="akad"]');
  await page.waitForTimeout(600);
  const liveTitle = (await page.locator('#liveTitle').innerText()).trim();
  const liveWho = (await page.locator('#liveWho').innerText()).trim();
  const nextConsult = (await page.locator('#nextConsult').innerText()).trim();
  const acadText = (await page.locator('#acad').innerText()).trim();
  const liveTop = (await page.locator('#liveTop').innerText()).trim();
  const liveHref = await page.locator('#liveJoin').getAttribute('href');
  const liveCardText = await page.locator('#liveCard').innerText();
  log('Discord live z API', liveTitle.includes('AT - Marcin') && liveWho.includes('Marcin'), liveTitle + ' / ' + liveWho);
  log('Discord live link', /^https:\/\/discord\.com\/events\/\d{17,20}\/\d{17,20}$/.test(liveHref || ''), liveHref || 'brak');
  log('brak countdownu Start za', !liveCardText.includes('Start za'), liveCardText.replace(/\s+/g, ' ').slice(0, 80));
  log('Discord konsultacja z API', nextConsult.length > 0, nextConsult);
  log('Discord harmonogram tygodnia', acadText.includes('AT - Marcin'), acadText.slice(0, 120));
  log('Discord strip live', liveTop.length > 0, liveTop);

  // nawigacja po wszystkich widokach
  const views = ['plan', 'trans', 'play', 'nowa', 'kalk', 'prop', 'stat', 'mentor', 'akad', 'tax', 'kal', 'cialo', 'gieldy', 'ust', 'pulpit'];
  for (const v of views) {
    await page.click(`.navg button[data-v="${v}"]`);
    const on = await page.locator('.view.on').getAttribute('id');
    assert.equal(on, v, 'widok ' + v);
  }
  log('nawigacja wszystkich widoków', true, views.length + ' widoków');

  const aliases = await page.evaluate(() => {
    const out = {};
    ['plan3', 'dziennik3', 'playbook3', 'akad3', 'stat3'].forEach((slug) => {
      location.hash = '#/' + slug;
      out[slug] = AV.viewFromHash();
    });
    location.hash = '#/pulpit';
    return out;
  });
  log('stare trasy *3 działają', aliases.plan3 === 'plan' && aliases.dziennik3 === 'trans' && aliases.playbook3 === 'play' && aliases.akad3 === 'akad' && aliases.stat3 === 'stat', JSON.stringify(aliases));

  // zaufanie: brak deklaracji 2FA/sesji/kodu API, uczciwe copy integracji
  await page.click('.navg button[data-v="ust"]');
  const trust = await page.evaluate(() => {
    const ust = document.querySelector('#ust')?.innerText || '';
    return {
      twofa: ust.includes('2FA na Discordzie'),
      sessions: ust.includes('Aktywne sesje') || !!document.querySelector('#outAll'),
      apiCode: !!document.querySelector('[data-setting="apiCode"]'),
      deviceSession: ust.includes('Sesja na tym urządzeniu')
    };
  });
  log('konto: brak fałszywego 2FA/sesji/kodu API', !trust.twofa && !trust.sessions && !trust.apiCode && trust.deviceSession, JSON.stringify(trust));
  await page.click('.navg button[data-v="gieldy"]');
  const exCopy = await page.evaluate(() => document.querySelector('#gieldy')?.innerText || '');
  log('giełdy: klucz tylko do odczytu', exCopy.includes('tylko odczyt') && !exCopy.includes('dowolne (odczyt wystarcza)'), exCopy.slice(0, 120));
  const landingCopy = await page.evaluate(() => document.body.innerText);
  log('landing: bez dowolnych uprawnień', !landingCopy.includes('dowolnymi uprawnieniami'), 'ok');
  await page.click('.navg button[data-v="trans"]');
  const journalCopy = await page.evaluate(() => document.querySelector('#trans')?.innerText || '');
  log('dziennik: import do 25 minut', journalCopy.includes('do 25 minut') && !journalCopy.includes('co 30 minut'), 'ok');
  await page.click('.navg button[data-v="mentor"]');
  await page.waitForTimeout(300);
  const mentorCopy = await page.evaluate(() => document.querySelector('#mentor')?.innerText || '');
  log('MCP: status z API bez fałszywego live', /MCP · (TYLKO ODCZYT|ZAPIS|WYŁĄCZONY|STATUS NIEDOSTĘPNY)/.test(mentorCopy) && !mentorCopy.includes('SERWER MCP GOTOWY'), 'ok');

  // statystyki: zero transakcji nie może pokazywać fikcyjnych liczb
  const emptyStats = await page.evaluate(() => {
    const backup = AV.state.trades;
    AV.state.trades = [];
    AV.renderStats();
    const out = {
      pf: document.querySelector('#stPf')?.textContent.trim() || '',
      wr: document.querySelector('#stWr')?.textContent.trim() || '',
      plan: document.querySelector('#stPlan')?.textContent.trim() || '',
      fees: document.querySelector('#stFees')?.textContent.trim() || '',
      checklist: document.querySelector('#checklistStats')?.textContent || '',
      insight: document.querySelector('#gradeInsight')?.textContent || ''
    };
    AV.state.trades = backup;
    AV.renderStats();
    return out;
  });
  log('statystyki: puste dane bez fikcji', emptyStats.pf === '—' && emptyStats.wr === '—' && emptyStats.plan === '—' && emptyStats.fees === '0,00' && emptyStats.checklist.includes('Brak danych') && !emptyStats.checklist.includes('7/7') && !emptyStats.insight.includes('Tak trzymaj'), JSON.stringify(emptyStats));

  const fresh = await page.evaluate(() => {
    const s = AV.buildEmpty();
    const p = { approvedAt: 1, market: 'ETH', bias: 'short' };
    return {
      match: AV.planMatchesTrade(p, 'ETH/USDT', 's'),
      wrongPair: AV.planMatchesTrade(p, 'BTC/USDT', 's'),
      wrongDir: AV.planMatchesTrade(p, 'ETH/USDT', 'l'),
      draft: AV.planMatchesTrade({ ...p, approvedAt: null }, 'ETH/USDT', 's'),
      joined: s.user.joined,
      progress: s.meta.courseProgress,
      sessions: s.meta.sessions.length,
      weex: s.settings.connections.weex.connected,
      plan: s.subscription.plan
    };
  });
  log('plan: zgodność po parze i kierunku', fresh.match && !fresh.wrongPair && !fresh.wrongDir && !fresh.draft, JSON.stringify(fresh));
  log('świeże konto: bez demo-seedu', fresh.joined === null && fresh.progress === 0 && fresh.sessions === 0 && fresh.weex === false, JSON.stringify(fresh));

  // Mentor AI: integracje MCP
  await page.click('.navg button[data-v="mentor"]');
  await page.waitForSelector('.mcp-endpoint-card');
  assert.equal((await page.locator('#mcpUrl').innerText()).trim(), 'https://mcp.alphavault.pl/mcp');
  assert.equal(await page.locator('#mcpTabs button[aria-selected="true"]').getAttribute('data-mcp-client'), 'chatgpt');
  assert.match(await page.locator('#mcpSetupTitle').innerText(), /ChatGPT/);
  assert.equal(await page.locator('#msgs').count(), 0);
  await page.click('#mcpTabs button[data-mcp-client="claude"]');
  assert.match(await page.locator('#mcpSetupTitle').innerText(), /Claude/);
  assert.match(await page.locator('#mcpSteps').innerText(), /niestandardowy konektor/);
  await page.locator('#mcpTabs button[data-mcp-client="claude"]').focus();
  await page.keyboard.press('ArrowRight');
  assert.equal(await page.locator('#mcpTabs button[aria-selected="true"]').getAttribute('data-mcp-client'), 'grok');
  await page.click('#mcpCopy');
  await page.waitForTimeout(150);
  assert.match(await page.locator('#toast').innerText(), /skopiowany/i);
  assert.equal(await page.locator('.mcp-permission-card').isVisible(), true);
  log('Mentor AI: integracje MCP + zakładki + kopiowanie', true);

  // backup stanu po stronie klienta (do przywrócenia po testach zapisu)
  const backup = await page.evaluate(() => JSON.stringify(AV.state));

  // plan: chipy rynku/biasu, poziomy, zatwierdzenie i trwały zapis w MySQL
  await page.click('.navg button[data-v="plan"]');
  const beforeLevels = await page.locator('#lvls .lvl').count();
  await page.click('#addLvl');
  const afterAdd = await page.locator('#lvls .lvl').count();
  const levelRow = page.locator('#lvls .lvl').last();
  await levelRow.locator('input').first().fill('70 123');
  await levelRow.locator('input').nth(1).fill('E2E poziom');
  await page.click('#planMarkets .chip[data-m="ETH"]');
  await page.click('#planBias .chip[data-b="short"]');
  const chips = await page.evaluate(() => ({
    market: document.querySelector('#planMarkets .chip.on')?.dataset.m,
    bias: document.querySelector('#planBias .chip.on')?.dataset.b
  }));
  const why = 'E2E: plan dnia ' + Date.now();
  await page.fill('#planWhy', why);
  await page.click('#savePlan');
  await page.waitForFunction(async () => {
    const res = await fetch('/api/state', { cache: 'no-store' });
    if (!res.ok) return false;
    const data = await res.json();
    return !!data.state?.plans?.[AV.dateKey(new Date())]?.approvedAt;
  }, null, { timeout: 15000 });
  const approved = await page.evaluate(() => {
    const p = AV.state.plans[AV.dateKey(new Date())];
    return { ok: !!p?.approvedAt, market: p?.market, bias: p?.bias, levels: p?.levels?.length, why: p?.why };
  });
  log('plan dnia: chipy rynku i biasu', chips.market === 'ETH' && chips.bias === 'short', JSON.stringify(chips));
  log('plan dnia: poziomy + zatwierdzenie', afterAdd === beforeLevels + 1 && approved.ok && approved.levels >= 1, JSON.stringify(approved));
  const navBadges = await page.evaluate(() => {
    const p = document.querySelector('.navg button[data-v="plan"] .badge');
    const a = document.querySelector('.navg button[data-v="akad"] .badge');
    const live = AV.liveInfo ? AV.liveInfo().liveNow : false;
    return { plan: p?.textContent.trim(), planVisible: p ? getComputedStyle(p).display !== 'none' : false, acad: a?.textContent.trim(), acadVisible: a ? getComputedStyle(a).display !== 'none' : false, live };
  });
  log('badge planu zależy od zatwierdzenia', navBadges.plan === '✓' && navBadges.planVisible, JSON.stringify(navBadges));
  log('badge LIVE tylko podczas live', navBadges.acadVisible === navBadges.live && (navBadges.live ? navBadges.acad === 'LIVE' : navBadges.acad === ''), JSON.stringify(navBadges));

  // trwałość: przeładowanie czyta plan z MySQL
  await page.reload({ waitUntil: 'load' });
  await page.waitForFunction(() => window.AV?.ready && AV.api.authenticated, null, { timeout: 15000 });
  await page.click('.navg button[data-v="plan"]');
  const planReload = await page.evaluate(() => {
    const p = AV.state.plans[AV.dateKey(new Date())];
    return {
      market: document.querySelector('#planMarkets .chip.on')?.dataset.m,
      bias: document.querySelector('#planBias .chip.on')?.dataset.b,
      why: document.querySelector('#planWhy')?.value,
      levels: document.querySelectorAll('#lvls .lvl').length,
      approved: !!p?.approvedAt
    };
  });
  log('plan dnia: zapis w bazie po reloadzie', planReload.market === 'ETH' && planReload.bias === 'short' && planReload.why === why && planReload.levels >= 1 && planReload.approved, JSON.stringify(planReload));

  // dziennik: filtry
  await page.click('.navg button[data-v="trans"]');
  await page.click('#fRes [data-f="win"]');
  const winRows = await page.locator('#tbody tr').count();
  await page.click('#fRes [data-f="open"]');
  const openRows = await page.locator('#tbody tr').count();
  await page.click('#fRes [data-f="all"]');
  await page.click('#fGrade [data-f="A"]');
  const aRows = await page.locator('#tbody tr').count();
  await page.click('#fGrade [data-f="all"]');
  log('filtry dziennika', winRows === 24 && openRows === 1 && aRows === 21, `${winRows}/${openRows}/${aRows}`);

  // szczegóły transakcji
  await page.locator('#tbody tr').first().click();
  await page.waitForSelector('#tm.on');
  log('szczegóły transakcji', await page.locator('#tmBody').innerText().then((t) => t.includes('SOL/USDT')));
  await page.locator('#tm #mc').click();

  // kalkulator
  await page.click('.navg button[data-v="kalk"]');
  await page.waitForFunction(() => document.querySelector('#rMain')?.textContent !== '—', null, { timeout: 15000 });
  const calc = await page.locator('#rMain').innerText();
  await page.click('#calcMode [data-m="fx"]');
  const calcFx = await page.locator('#rMainL').innerText();
  log('kalkulator krypto + FX', calc !== '—' && calcFx.includes('MT4'), calc + ' / ' + calcFx);

  // cena live z API: bez etykiety demo i bez technicznych etykiet w nagłówku
  await page.click('#calcMode [data-m="crypto"]');
  await page.selectOption('#cInst', { label: 'SOL/USDT' });
  await page.waitForFunction(() => document.querySelector('#cPrice b')?.textContent === '81,48', null, { timeout: 15000 });
  const liveCalc = await page.evaluate(() => ({
    shown: document.querySelector('#cPrice b').textContent,
    label: document.querySelector('#cPrice').parentElement.querySelector('.label').textContent,
    envHidden: document.querySelector('#envBadge').hidden,
    envText: document.querySelector('#envBadge').textContent,
    syncHidden: document.querySelector('#syncBadge').hidden,
    syncText: document.querySelector('#syncBadge').textContent,
    ticker: document.querySelector('#ticker').textContent
  }));
  assert.equal(liveCalc.shown, '81,48');
  assert.ok(!/demo/i.test(liveCalc.label), 'etykieta bez demo: ' + liveCalc.label);
  assert.ok(liveCalc.envHidden && !/mysql|połączono/i.test(liveCalc.envText), 'pasek bez statusu bazy: ' + liveCalc.envText);
  assert.ok(liveCalc.syncHidden && !/brak połączeń/i.test(liveCalc.syncText), 'chip giełd ukryty bez połączeń: ' + liveCalc.syncText);
  assert.ok(liveCalc.ticker.includes('BTC') && liveCalc.ticker.includes('NAS100'), 'ticker z API: ' + liveCalc.ticker);
  log('cena live z API + czysty nagłówek', true, 'SOL/USDT ' + liveCalc.shown + ' · 8 notowań w tickerze');

  // statystyki
  await page.click('.navg button[data-v="stat"]');
  const st90 = await page.locator('#stResult').innerText();
  await page.click('#statSeg [data-r="7"]');
  const st7 = await page.locator('#stResult').innerText();
  await page.click('#statSeg [data-r="90"]');
  log('statystyki: zakresy', st90 !== st7, st90 + ' → ' + st7);

  // prop challenge: szablony, create, walidacja, XSS, alert, archiwum, reload, delete
  const dayKeyLocal = (d) => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  const dStart = new Date(); dStart.setDate(dStart.getDate() - 20);
  const dEnd = new Date(); dEnd.setDate(dEnd.getDate() + 10);
  await page.click('.navg button[data-v="prop"]');
  assert.equal(await page.locator('.view.on').getAttribute('id'), 'prop');
  await page.click('#prop .head [data-prop-templates]');
  await page.waitForSelector('#gModal.on');
  const templateCount = await page.locator('#gModal [data-prop-template]').count();
  log('prop: katalog szablonów', templateCount >= 8, templateCount + ' szablonów');
  await page.evaluate(() => AV.closeModal());

  await page.click('#prop .head [data-prop-new]');
  await page.waitForSelector('#peSave');
  await page.selectOption('#peTemplate', 'ftmo-2step');
  await page.fill('#peName', 'E2E Prop 10K');
  await page.selectOption('#peAccount', 'WEEX');
  await page.fill('#peSize', '10000');
  await page.fill('#peStartBalance', '10000');
  await page.fill('#peTarget', '10');
  await page.fill('#peDaily', '5');
  await page.fill('#peMax', '10');
  await page.fill('#peStart', dayKeyLocal(dStart));
  await page.fill('#peEnd', dayKeyLocal(dEnd));
  await page.click('#peSave');
  await page.waitForTimeout(350);
  const createdProp = await page.evaluate(() => AV.state.prop.challenges.find((c) => c.name === 'E2E Prop 10K'));
  assert.ok(createdProp, 'challenge nie zapisany');
  assert.equal(createdProp.account, 'WEEX');
  assert.equal(createdProp.targetPct, 10);
  const kpiCount = await page.locator('#propKpis .card').count();
  const propText = await page.locator('#prop').innerText();
  log('prop: nowy challenge + 4 KPI', kpiCount === 4 && !propText.includes('WKRÓTCE'), kpiCount + ' KPI');

  // walidacja: pusta nazwa nie zapisuje
  await page.click('[data-prop-act="edit"]');
  await page.waitForSelector('#peSave');
  await page.fill('#peName', '');
  await page.click('#peSave');
  const validationVisible = await page.locator('#peError').isVisible();
  assert.ok(validationVisible, 'brak komunikatu walidacji');
  await page.fill('#peName', 'E2E Prop Edited');
  await page.click('#peSave');
  await page.waitForTimeout(300);
  log('prop: walidacja formularza', validationVisible, 'pusta nazwa zablokowana');

  // XSS w nazwie: literal w stanie, zero wykonania
  await page.click('[data-prop-act="edit"]');
  await page.fill('#peName', '<img src=x onerror="window.__xss=1">');
  await page.click('#peSave');
  await page.waitForTimeout(250);
  const xssState = await page.evaluate(() => ({
    literal: AV.state.prop.challenges.some((c) => c.name === '<img src=x onerror="window.__xss=1">'),
    fired: window.__xss === 1,
    injected: document.querySelectorAll('#prop img[src="x"]').length
  }));
  assert.ok(xssState.literal && !xssState.fired && xssState.injected === 0);
  await page.click('[data-prop-act="edit"]');
  await page.fill('#peName', 'E2E Prop Edited');
  await page.click('#peSave');
  await page.waitForTimeout(250);
  log('prop: XSS w nazwie', true, 'escaped');

  // alarm: tymczasowa strata ponad dzienny limit + banner na pulpicie
  await page.evaluate(() => {
    const c = AV.state.prop.challenges.find((x) => x.name === 'E2E Prop Edited');
    c.dailyLossPct = 0.01;
    AV.state.trades.push({ id: 'e2e_prop_tmp', date: new Date().toISOString(), result: -100, r: -1, account: 'WEEX', pair: 'E2E/USDT' });
    AV.propSetSelected(c.id);
    AV.renderProp();
    AV.renderPropDashboard();
  });
  await page.waitForTimeout(120);
  const alarmText = await page.locator('#propAlerts').innerText();
  const dashState = await page.evaluate(() => ({
    display: document.querySelector('#propDash')?.style.display,
    title: document.querySelector('#propDashTitle')?.textContent,
    desc: document.querySelector('#propDashDesc')?.textContent
  }));
  assert.ok(/dzienny limit/i.test(alarmText), 'brak alarmu dziennego limitu');
  assert.notEqual(dashState.display, 'none', 'banner prop nie został pokazany');
  log('prop: alarm limitu + pulpit', true, dashState.title + ' / ' + dashState.desc);
  await page.evaluate(() => {
    AV.state.trades = AV.state.trades.filter((t) => t.id !== 'e2e_prop_tmp');
    const c = AV.state.prop.challenges.find((x) => x.name === 'E2E Prop Edited');
    c.dailyLossPct = 5;
    AV.renderProp();
    AV.renderPropDashboard();
    AV.saveNow();
  });

  // archiwum: ukrycie, podgląd, przywrócenie
  await page.click('[data-prop-act="archive"]');
  await page.waitForTimeout(150);
  const archived = await page.evaluate(() => AV.state.prop.challenges.find((c) => c.name === 'E2E Prop Edited')?.archived);
  assert.equal(archived, true);
  await page.waitForTimeout(400);
  await page.locator('#propArchToggle').scrollIntoViewIfNeeded();
  await page.click('#propArchToggle');
  await page.waitForTimeout(150);
  const archivedOption = await page.locator('#propSelect option').allTextContents();
  assert.ok(archivedOption.some((t) => t.includes('E2E Prop Edited') && t.includes('archiwum')));
  await page.selectOption('#propSelect', { label: 'E2E Prop Edited · archiwum' });
  await page.click('[data-prop-act="archive"]');
  await page.waitForTimeout(120);
  const restored = await page.evaluate(() => AV.state.prop.challenges.find((c) => c.name === 'E2E Prop Edited')?.archived);
  assert.equal(restored, false);
  await page.waitForTimeout(200);
  await page.locator('#propArchToggle').scrollIntoViewIfNeeded();
  await page.click('#propArchToggle');
  log('prop: archiwum + przywrócenie', true);

  // empty state po zarchiwizowaniu wszystkiego (i powrót)
  const archivedBefore = await page.evaluate(() => AV.state.prop.challenges.map((c) => c.archived));
  await page.evaluate(() => {
    AV.state.prop.challenges.forEach((c) => { c.archived = true; });
    AV._propShowArchived = false;
    AV.renderProp();
  });
  const emptyVisible = await page.locator('#propEmpty').isVisible();
  assert.ok(emptyVisible, 'brak empty state');
  const archiveOnEmpty = await page.locator('#propArchToggle').isVisible();
  assert.ok(archiveOnEmpty, 'brak dostępu do archiwum w empty state');
  await page.click('#propArchToggle');
  await page.waitForTimeout(100);
  const archivedView = await page.locator('#propBody').isVisible();
  assert.ok(archivedView, 'archiwum nie otwiera się z empty state');
  await page.evaluate((before) => {
    AV.state.prop.challenges.forEach((c, i) => { c.archived = before[i]; });
    AV._propShowArchived = false;
    AV.renderProp();
  }, archivedBefore);
  log('prop: empty state + archiwum', emptyVisible && archiveOnEmpty && archivedView);

  // trwałość: zapis do MySQL i reload
  await page.waitForTimeout(900);
  await page.reload({ waitUntil: 'load' });
  await page.waitForFunction(() => window.AV && AV.ready);
  await page.waitForFunction(() => window.AV?.api?.authenticated);
  const persisted = await page.evaluate(() => AV.state.prop.challenges.some((c) => c.name === 'E2E Prop Edited' && !c.archived));
  assert.ok(persisted, 'challenge nie przeżył reloadu');
  log('prop: przeżył reload (MySQL)', persisted);

  // usunięcie challenge'u przez UI
  await page.click('.navg button[data-v="prop"]');
  await page.selectOption('#propSelect', { label: 'E2E Prop Edited' });
  await page.click('[data-prop-act="delete"]');
  await page.waitForSelector('#gModal.on');
  await page.click('#gConfirmOk');
  await page.waitForTimeout(200);
  const deleted = await page.evaluate(() => !AV.state.prop.challenges.some((c) => c.name === 'E2E Prop Edited'));
  assert.ok(deleted, 'challenge nie usunięty');
  log('prop: usuwanie challenge', deleted);

  // mentor: karta MCP nadal dostępna po zapisach
  await page.click('.navg button[data-v="mentor"]');
  await page.waitForSelector('.mcp-card');
  assert.equal((await page.locator('#mcpUrl').innerText()).trim(), 'https://mcp.alphavault.pl/mcp');
  assert.equal(await page.locator('#msgs').count(), 0);
  log('mentor: karta MCP po zapisach', true);

  // krok mentora prowadzi do planu dnia
  await page.evaluate(() => { AV.renderDashMentor({ closed: [] }); AV.showView('pulpit'); });
  await page.click('#dashMentor .step');
  const planFromStep = await page.evaluate(() => ({ on: document.querySelector('#plan').classList.contains('on'), hash: location.hash }));
  log('mentor: krok plan dnia → zakładka', planFromStep.on && planFromStep.hash === '#/plan', JSON.stringify(planFromStep));

  // akademia
  await page.click('.navg button[data-v="akad"]');
  const liveHrefAgain = await page.locator('#liveJoin').getAttribute('href');
  assert.match(liveHrefAgain || '', /^https:\/\/discord\.com\/events\/\d{17,20}\/\d{17,20}$/);
  log('akademia: link wydarzenia Discord', true, liveHrefAgain);

  const recording = await page.evaluate(() => {
    AV.openRecording('Test nagrania');
    const modal = document.querySelector('.modal.on');
    const out = { text: modal?.innerText || '', discord: !!modal?.querySelector('a[href^="https://discord.com"]') };
    AV.closeModal();
    return out;
  });
  log('nagrania: link do Discorda bez fałszywego playera', recording.discord && !recording.text.includes('Odtwarzanie w wersji produkcyjnej'), JSON.stringify(recording).slice(0, 160));

  const emptyJournal = await page.evaluate(() => {
    const backup = AV.state.trades;
    AV.state.trades = [];
    AV.renderJournal();
    const text = document.querySelector('#jEmpty')?.textContent || '';
    AV.state.trades = backup;
    AV.renderJournal();
    return text;
  });
  log('dziennik: pusty stan bez filtrów', emptyJournal.includes('Brak transakcji') && !emptyJournal.includes('spełniających filtry'), emptyJournal);

  // kalendarz
  await page.click('.navg button[data-v="kal"]');
  const ev0 = await page.locator('#evFull .ev').count();
  await page.click('#calSeg [data-w="1"]');
  const ev1 = await page.locator('#evFull .ev').count();
  await page.click('#calSeg [data-w="0"]');
  const widget = await page.locator('#mwList .mev').count();
  log('kalendarz: tygodnie', ev1 > 0, `${ev0} → ${ev1}`);
  log('makro: widget z API', widget > 0, `${widget} wydarzeń`);

  // ciało: check-in
  await page.click('.navg button[data-v="cialo"]');
  await page.locator('#checkMood .chip', { hasText: 'Spokój' }).click();
  await page.locator('#checkEnergy .chip', { hasText: '5' }).click();
  await page.click('#saveCheck');
  const savedCheck = await page.evaluate(() => {
    const key = AV.dateKey(new Date());
    const c = (AV.state.checkins || []).find((x) => x.date === key);
    return c ? { mood: c.mood, energy: c.energy, score: c.score } : null;
  });
  log('check-in: zapis', !!savedCheck && savedCheck.mood === 'Spokój' && savedCheck.energy === 5, JSON.stringify(savedCheck));

  // ustawienia: profil
  await page.click('.navg button[data-v="ust"]');
  await page.fill('#nameIn', 'E2E Tester');
  await page.waitForTimeout(400);
  const sideName = await page.locator('#sideName').innerText();
  log('profil: zapis imienia', sideName === 'E2E Tester', sideName);
  const planDates = await page.evaluate(() => {
    const prev = AV.state.subscription.until;
    const expected = new Date(prev).toLocaleDateString('pl-PL');
    const read = () => ({
      side: document.querySelector('#sidePlan').textContent.trim(),
      row: document.querySelector('#planUntilRow').style.display,
      until: document.querySelector('#planValidUntil').textContent.trim()
    });
    AV.state.subscription.until = null;
    AV.renderUser();
    const none = read();
    AV.state.subscription.until = prev;
    AV.renderUser();
    return { none, restored: read(), expected };
  });
  log('profil: bez daty bez adnotacji', planDates.none.side === 'Alpha + Akademia' && planDates.none.row === 'none', JSON.stringify(planDates.none));
  log('profil: data z API w miejscu daty', planDates.restored.until === planDates.expected && planDates.restored.side.endsWith(planDates.expected), planDates.restored.until + ' = ' + planDates.expected);
  const removedFields = await page.evaluate(() => ({
    motto: !!document.querySelector('#mottoIn'),
    style: !!document.querySelector('#styleChips'),
    greeting: !!document.querySelector('[data-setting="greeting"]'),
    gender: !!document.querySelector('#gender'),
    greet: !!document.querySelector('#greet'),
    badge: document.querySelector('#profileBadge').textContent.trim()
  }));
  log('personalizacja: usunięte pola', !removedFields.motto && !removedFields.style && !removedFields.greeting && !removedFields.gender && !removedFields.greet && removedFields.badge === 'Krypto', JSON.stringify(removedFields));
  const h1 = await page.locator('#pulpit .head h1').innerText();
  log('pulpit: nazwa bez powitania', h1.trim() === 'E2E Tester.', h1);

  // nowa transakcja: obejście limitu + zapis + zamknięcie
  await page.click('.navg button[data-v="nowa"]');
  // Fixture demo ma stałą datę; wymuś 2 straty dziś, żeby tryb ochronny był deterministyczny.
  await page.evaluate(() => {
    const today = AV.dateKey(new Date());
    const mk = (id) => ({
      id, date: new Date().toISOString(), pair: 'E2E/LOCK', dir: 'l', entry: '100', exit: '99', sl: '98', tp: '104',
      size: '1', result: -10, r: -1, grade: 'B', checklist: 6, checkTotal: 7, errors: [], emotion: 'Spokój',
      note: '', mentor: '', setup: 'E2E', session: 'Londyn', mae: 0, mfe: 0, durationMin: 20, fee: 0,
      plan: true, source: 'E2E', screens: {}
    });
    AV.state.settings.safetyEnabled = true;
    AV.state.trades = AV.state.trades
      .filter((t) => AV.dateKey(new Date(t.date)) !== today)
      .concat([mk('e2e_lock_a'), mk('e2e_lock_b')]);
    AV.renderLock();
  });
  await page.evaluate(() => document.getElementById('pastTrade').click());
  const emptyForm = await page.evaluate(() => ({
    pair: document.querySelector('#ntPair')?.value || '',
    entry: document.querySelector('#ntEntry')?.value || '',
    size: document.querySelector('#ntSize')?.value || '',
    sl: document.querySelector('#ntSl')?.value || '',
    tp: document.querySelector('#ntTp')?.value || '',
    note: document.querySelector('#ntNote')?.value || '',
    checklist: document.querySelector('#clN')?.textContent.trim() || '',
    grades: document.querySelectorAll('.gp.on').length,
    risk: document.querySelector('#ntRisk')?.textContent || ''
  }));
  log('nowa transakcja: formularz pusty', !emptyForm.pair && !emptyForm.entry && !emptyForm.size && !emptyForm.sl && !emptyForm.tp && !emptyForm.note, JSON.stringify(emptyForm));
  log('nowa transakcja: checklista 0 i brak oceny', emptyForm.checklist === '0/7' && emptyForm.grades === 0, emptyForm.checklist + ' / ' + emptyForm.grades);
  log('nowa transakcja: limit i ryzyko osobno', emptyForm.risk.includes('Limit') && emptyForm.risk.includes('Ryzyko'), emptyForm.risk.slice(0, 100));

  const beforeTrades = await page.evaluate(() => AV.state.trades.length);
  await page.fill('#ntPair', 'E2E/USDT');
  await page.fill('#ntEntry', '100');
  await page.fill('#ntSl', '99');
  await page.fill('#ntTp', '103');
  await page.fill('#ntSize', '1');
  await page.click('#saveTrade');
  await page.waitForTimeout(500);
  const afterTrades = await page.evaluate(() => AV.state.trades.length);
  log('nowa transakcja: zapis', afterTrades === beforeTrades + 1, beforeTrades + ' → ' + afterTrades);
  const addedId = await page.evaluate(() => AV.state.trades.find((t) => t.pair === 'E2E/USDT')?.id);
  const addedTrade = await page.evaluate(() => {
    const t = AV.state.trades.find((x) => x.pair === 'E2E/USDT');
    return t ? { risk: t.risk, riskPct: t.riskPct, mae: t.mae, mfe: t.mfe } : null;
  });
  log('nowa transakcja: ryzyko zapisane, MAE/MFE puste', !!addedTrade && addedTrade.risk === 1 && addedTrade.mae === null && addedTrade.mfe === null, JSON.stringify(addedTrade));
  if (addedId) {
    await page.evaluate((id) => AV.closeTradeModal(id), addedId);
    await page.fill('#ctExit', '105');
    await page.click('#ctSave');
    const closed = await page.evaluate((id) => AV.state.trades.find((t) => t.id === id)?.result, addedId);
    log('zamknięcie pozycji', closed === 5, String(closed));
  }

  // odznaki: każda z 8 reguł niezależnie + dynamiczny licznik i poziom
  const badges = await page.evaluate(() => {
    const base = () => ({ accounts: [{ name: 'A', include: true }], trades: [], plans: {}, checkins: [] });
    const iso = (offset) => new Date(AV.addDays(new Date(), offset)).toISOString();
    const key = (offset) => AV.dateKey(AV.addDays(new Date(), offset));
    const trade = (offset, extra = {}) => ({ date: iso(offset), pair: 'BTC/USDT', dir: 'l', result: 10, sl: '100', grade: 'A', emotion: 'Spokój', plan: true, errors: [], ...extra });
    const plan = (state, offset, approved = true) => {
      const k = key(offset);
      state.plans[k] = { date: k, market: 'BTC', bias: 'long', approvedAt: approved ? new Date(AV.addDays(new Date(), offset)).setHours(8, 0, 0, 0) : null };
    };
    const badge = (state, index) => AV.badges(state)[index];
    const out = {};
    const empty = base();
    out.count = AV.badges(empty).length;
    out.empty = AV.badges(empty).every((b) => !b.done && b.progress === 0);
    out.emptySummary = AV.badgeSummary(AV.badges(empty));

    let s = base(); plan(s, 0); out.plan = badge(s, 0);
    s = base(); for (let i = 0; i < 7; i++) s.trades.push(trade(-i)); out.seven = badge(s, 1);
    s = base(); for (let i = 0; i < 50; i++) s.trades.push(trade(0)); out.sl50 = badge(s, 2);
    s = base(); for (let i = 0; i < 49; i++) s.trades.push(trade(0)); out.sl49 = badge(s, 2);
    s = base(); for (let i = 0; i < 5; i++) { plan(s, -i); s.trades.push(trade(-i)); } out.week = badge(s, 3);
    s = base(); for (let i = 0; i < 30; i++) plan(s, -i); out.plans30 = badge(s, 4);
    s = base(); for (let i = 0; i < 100; i++) s.trades.push(trade(0)); out.a100 = badge(s, 5);
    s = base(); for (let i = 0; i < 10; i++) s.trades.push(trade(-i)); out.fomo10 = badge(s, 6);
    s = base(); for (let i = 0; i < 10; i++) s.trades.push(trade(-i, { emotion: i === 5 ? 'FOMO' : 'Spokój' })); out.fomoBreak = badge(s, 6);
    s = base(); for (let i = 0; i < 10; i++) s.trades.push(trade(-i * 2)); out.fomoGaps = badge(s, 6);
    s = base(); for (let i = 0; i < 5; i++) { plan(s, -i * 2); s.trades.push(trade(-i * 2)); } out.weekGaps = badge(s, 3);

    const prev = new Date(new Date().getFullYear(), new Date().getMonth() - 1, 12, 12, 0, 0).toISOString();
    s = base(); s.trades.push({ ...trade(0), date: prev, result: 100 }); out.month = badge(s, 7);
    s = base(); s.trades.push({ ...trade(0), date: prev, result: 100, grade: 'C' }); out.monthC = badge(s, 7);

    s = base();
    plan(s, 0);
    for (let i = 0; i < 10; i++) s.trades.push(trade(-i, { grade: 'B', result: 0 }));
    for (let i = 0; i < 50; i++) s.trades.push(trade(0, { grade: 'B', result: 0 }));
    out.summary4 = AV.badgeSummary(AV.badges(s));
    out.live = AV.badgeSummary(AV.badges(AV.state));
    return out;
  });
  log('odznaka 1: pierwszy plan dnia', badges.plan.done && badges.plan.progress === 100, badges.plan.hint);
  log('odznaka 2: 7 dni z dziennikiem', badges.seven.done && badges.seven.progress === 100, badges.seven.hint);
  log('odznaka 3: 50 × ze stop lossem', badges.sl50.done && !badges.sl49.done && badges.sl49.hint === '49 / 50', badges.sl49.hint);
  log('odznaka 4: tydzień bez złamania zasad', badges.week.done && badges.week.progress === 100, badges.week.hint);
  log('odznaka 4: przerwy w kalendarzu nie przerywają serii', badges.weekGaps.done && badges.weekGaps.progress === 100, badges.weekGaps.hint);
  log('odznaka 5: 30 planów dnia', badges.plans30.done && badges.plans30.progress === 100, badges.plans30.hint);
  log('odznaka 6: 100 transakcji A', badges.a100.done && badges.a100.progress === 100, badges.a100.hint);
  log('odznaka 7: 10 dni bez FOMO', badges.fomo10.done && !badges.fomoBreak.done && badges.fomoBreak.hint === '5 / 10', badges.fomoBreak.hint);
  log('odznaka 7: przerwy w kalendarzu nie przerywają serii', badges.fomoGaps.done && badges.fomoGaps.progress === 100, badges.fomoGaps.hint);
  log('odznaka 8: miesiąc na plusie bez C', badges.month.done && !badges.monthC.done, badges.month.hint);
  log('odznaki: puste dane bez fałszywych postępów', badges.count === 8 && badges.empty && badges.emptySummary.done === 0 && badges.emptySummary.level === 1, JSON.stringify(badges.emptySummary));
  log('odznaki: 4 z 8 daje poziom 3', badges.summary4.done === 4 && badges.summary4.level === 3, JSON.stringify(badges.summary4));
  const summaryText = await page.locator('#badgeSummary').innerText();
  const levelText = await page.locator('#levelBadge').innerText();
  log('odznaki: dynamiczny licznik i poziom', summaryText === `${badges.live.done} z ${badges.live.total} · poziom ${badges.live.level}` && levelText === 'poziom ' + badges.live.level, summaryText + ' / ' + levelText);

  // przywróć stan demo i poczekaj na zapis do MySQL
  await page.evaluate((raw) => {
    AV.state = AV.normalizeServer(JSON.parse(raw));
    AV.saveNow();
  }, backup);
  await page.waitForTimeout(700);
  const restoredCount = await page.evaluate(() => AV.state.trades.length);
  log('przywrócenie stanu demo', restoredCount === 47, restoredCount);

  // bramka roli: pełnoekranowa blokada panelu
  await page.evaluate(() => window.AV.showAccessGate('Brak aktywnej roli Alpha.'));
  const gateVisible = await page.locator('#roleGate').isVisible();
  const gateZ = await page.locator('#roleGate').evaluate((el) => Number(getComputedStyle(el).zIndex));
  log('bramka roli blokuje panel', gateVisible && gateZ >= 150, 'z-index ' + gateZ);
  await page.evaluate(() => document.getElementById('roleGate').classList.remove('on'));

  // wylogowanie
  const leakMarker = 'E2E-LEAK-' + Date.now();
  await page.evaluate((marker) => { AV.state.e2eLeakMarker = marker; AV.saveNow(); }, leakMarker);
  await page.waitForTimeout(400);
  await page.evaluate(() => document.querySelector('#logout').click());
  await page.waitForURL(BASE + '/', { timeout: 10000 }).catch(() => {});
  await page.waitForFunction(() => window.AV?.api?.authenticated === false, null, { timeout: 10000 });
  const cacheAfterLogout = await page.evaluate((marker) => ({
    body: document.body.innerText.includes(marker),
    state: JSON.stringify(window.AV?.state || {}).includes(marker),
    keys: Object.keys(localStorage).filter((key) => key.startsWith('alphavault.'))
  }), leakMarker);
  log('wylogowanie', await page.locator('#auth').isVisible());
  log('wylogowanie czyści prywatny cache', !cacheAfterLogout.body && !cacheAfterLogout.state
    && !cacheAfterLogout.keys.some((key) => key.startsWith('alphavault.state.v2') || key.startsWith('alphavault.state.v3')),
    JSON.stringify(cacheAfterLogout.keys));
} catch (err) {
  console.error('\nFAIL  test przerwany:', err.message);
  results.push({ name: 'test przerwany', ok: false, info: err.message });
  process.exitCode = 1;
} finally {
  await browser.close();
}

const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} PASS`);
if (consoleErrors.length) console.log('Console errors:', consoleErrors.slice(0, 10));
if (pageErrors.length) console.log('Page errors:', pageErrors.slice(0, 10));
if (failed.length) process.exitCode = 1;
