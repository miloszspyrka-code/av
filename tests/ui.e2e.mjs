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
  await page.waitForFunction(() => document.querySelector('#envBadge')?.textContent.includes('POŁĄCZONO Z MYSQL'), null, { timeout: 15000 });
  await page.waitForFunction(() => window.AV?.ready && AV.api.authenticated);
  log('logowanie demo + sesja', await page.locator('#auth').evaluate((el) => el.classList.contains('hide')), 'cookie + MySQL');

  const rows = await page.locator('#tbody tr').count();
  assert.equal(rows, 47);
  log('dziennik z bazy', rows === 47, rows + ' transakcji');

  // nawigacja po wszystkich widokach
  const views = ['plan', 'trans', 'play', 'nowa', 'kalk', 'stat', 'mentor', 'akad', 'tax', 'kal', 'cialo', 'gieldy', 'ust', 'pulpit'];
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
  const ready0 = await page.locator('#bodyReady').innerText();
  await page.locator('#checkMood .chip', { hasText: 'Spokój' }).click();
  await page.locator('#checkEnergy .chip', { hasText: '5' }).click();
  await page.click('#saveCheck');
  const ready1 = await page.locator('#bodyReady').innerText();
  log('check-in → gotowość', Number(ready1) > Number(ready0), ready0 + ' → ' + ready1);

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
