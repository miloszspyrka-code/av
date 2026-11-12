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

try {
  await page.goto('/', { waitUntil: 'load' });
  await page.waitForFunction(() => window.AV && AV.ready);
  log('UI start', await page.locator('#auth').isVisible(), 'ekran logowania');

  await page.click('#enter');
  await page.waitForSelector('#demoEnter');
  log('modal logowania', await page.locator('#demoEnter').isVisible(), 'Discord niepodłączony → demo');

  await page.click('#demoEnter');
  await page.waitForFunction(() => document.querySelector('#envBadge')?.textContent.includes('zapis automatyczny'), null, { timeout: 15000 });
  await page.waitForFunction(() => window.AV?.ready && AV.api.authenticated);
  log('logowanie demo + sesja', await page.locator('#auth').evaluate((el) => el.classList.contains('hide')), 'cookie + sesja');

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
  log('Discord live z API', liveTitle.includes('AT - Marcin') && liveWho.includes('Marcin'), liveTitle + ' / ' + liveWho);
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

  // backup stanu po stronie klienta (do przywrócenia po testach zapisu)
  const backup = await page.evaluate(() => JSON.stringify(AV.state));

  // plan: dodaj i usuń poziom, zatwierdź
  await page.click('.navg button[data-v="plan"]');
  const beforeLevels = await page.locator('#lvls .lvl').count();
  await page.click('#addLvl');
  const afterAdd = await page.locator('#lvls .lvl').count();
  await page.locator('#lvls .lvl').last().locator('.x').click();
  await page.click('#savePlan');
  const approved = await page.evaluate(() => !!AV.state.plans[AV.dateKey(new Date())]?.approvedAt);
  log('plan dnia: poziomy + zatwierdzenie', afterAdd === beforeLevels + 1 && approved);

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
  const calc = await page.locator('#rMain').innerText();
  await page.click('#calcMode [data-m="fx"]');
  const calcFx = await page.locator('#rMainL').innerText();
  log('kalkulator krypto + FX', calc !== '—' && calcFx.includes('MT4'), calc + ' / ' + calcFx);

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

  // mentor
  await page.click('.navg button[data-v="mentor"]');
  const beforeChat = await page.locator('#msgs .msg').count();
  await page.locator('#mChips .chip').first().click();
  await page.waitForFunction((n) => document.querySelectorAll('#msgs .msg').length >= n + 2, beforeChat, { timeout: 5000 });
  const afterChat = await page.locator('#msgs .msg').count();
  log('mentor: odpowiedź', afterChat >= beforeChat + 2, beforeChat + ' → ' + afterChat);

  // akademia
  await page.click('.navg button[data-v="akad"]');
  await page.click('#liveJoin');
  await page.waitForSelector('#gModal.on');
  log('akademia: modal live', true);
  await page.evaluate(() => AV.closeModal());

  // kalendarz
  await page.click('.navg button[data-v="kal"]');
  const ev0 = await page.locator('#evFull .ev').count();
  await page.click('#calSeg [data-w="1"]');
  const ev1 = await page.locator('#evFull .ev').count();
  await page.click('#calSeg [data-w="0"]');
  log('kalendarz: tygodnie', ev1 > 0, `${ev0} → ${ev1}`);

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

  // nowa transakcja: obejście limitu + zapis + zamknięcie
  await page.click('.navg button[data-v="nowa"]');
  await page.click('#pastTrade');
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
  if (addedId) {
    await page.evaluate((id) => AV.closeTradeModal(id), addedId);
    await page.fill('#ctExit', '105');
    await page.click('#ctSave');
    const closed = await page.evaluate((id) => AV.state.trades.find((t) => t.id === id)?.result, addedId);
    log('zamknięcie pozycji', closed === 5, String(closed));
  }

  // przywróć stan demo i poczekaj na zapis do MySQL
  await page.evaluate((raw) => {
    AV.state = AV.normalizeServer(JSON.parse(raw));
    AV.saveNow();
  }, backup);
  await page.waitForTimeout(700);
  const restoredCount = await page.evaluate(() => AV.state.trades.length);
  log('przywrócenie stanu demo', restoredCount === 47, restoredCount);

  // wylogowanie
  await page.evaluate(() => document.querySelector('#logout').click());
  await page.waitForURL(BASE + '/', { timeout: 10000 }).catch(() => {});
  await page.waitForFunction(() => window.AV?.api?.authenticated === false, null, { timeout: 10000 });
  log('wylogowanie', await page.locator('#auth').isVisible());
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
