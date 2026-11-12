#!/usr/bin/env node
/**
 * Alpha Vault — testy PIT-38: dane z roku podatkowego, nadwyżka kosztów,
 * transakcje neutralne, forex/MT5, eksport CSV i wydruk PDF.
 *
 * Wymaga serwera PHP: APP_URL (domyślnie http://127.0.0.1:4173).
 * Uruchom: npm run test:tax
 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright-core';

const BASE = process.env.APP_URL || 'http://127.0.0.1:4173';
const candidates = [
  process.env.CHROME_PATH,
  path.join(process.env.LOCALAPPDATA || '', 'Google', 'Chrome', 'Application', 'chrome.exe'),
  'C:/Program Files/Google/Chrome/Application/chrome.exe',
  'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
  'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
  '/usr/bin/google-chrome',
  '/usr/bin/chromium'
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
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${info ? ' — ' + info : ''}`);
}

const fixture = {
  flows: [
    { id: 'tax1', date: '2026-03-12', account: 'WEEX', type: 'Wpłata', op: 'zakup USDT za PLN (P2P)', amount: 508.4, currency: 'PLN', fiat: 2000, fx: 1, tax: 'cost' },
    { id: 'tax2', date: '2026-06-02', account: 'WEEX', type: 'Wpłata', op: 'zakup USDT za PLN (P2P)', amount: 791.6, currency: 'PLN', fiat: 2980, fx: 1, tax: 'cost' },
    { id: 'tax3', date: '2026-08-18', account: 'OKX', type: 'Wypłata', op: 'sprzedaż USDT za EUR', amount: 560, currency: 'EUR', fiat: 520, fx: 4.265, tax: 'income' },
    { id: 'tax4', date: '2026-10-30', account: 'WEEX', type: 'Wypłata', op: 'sprzedaż USDT za PLN (P2P)', amount: 1402.1, currency: 'PLN', fiat: 5202.94, fx: 1, tax: 'income' },
    { id: 'tax2025', date: '2025-12-31', account: 'WEEX', type: 'Wypłata', op: 'sprzedaż USDT za PLN', amount: 100, currency: 'PLN', fiat: 999, fx: 1, tax: 'income' }
  ],
  tax: { version: 2, carryLoss: 380, year: 2026 },
  accounts: [
    { id: 'acc1', name: 'WEEX', exchange: 'WEEX', type: 'Futures', currency: 'USDT', start: 1500, date: '01.03.2026', lev: '10×', goal: '', balance: 1812.4, include: true, primary: true },
    { id: 'acc2', name: 'OKX', exchange: 'OKX', type: 'Spot', currency: 'USDT', start: 500, date: '15.04.2026', lev: '1×', goal: '', balance: 600.4, include: true, primary: false },
    { id: 'acc3', name: 'MT5', exchange: 'MetaTrader 5', type: 'Forex', currency: 'USD', start: 10000, date: '01.10.2026', lev: '30×', goal: '', balance: 9640, include: false, primary: false }
  ],
  trades: []
};
for (let i = 0; i < 47; i++) {
  fixture.trades.push({
    id: 'tax-w' + i, date: '2026-09-' + String((i % 27) + 1).padStart(2, '0') + 'T10:00:00.000Z',
    pair: 'BTC/USDT', source: 'WEEX', account: 'WEEX', result: 1
  });
}
for (let i = 0; i < 2; i++) {
  fixture.trades.push({
    id: 'tax-m' + i, date: '2026-10-0' + (i + 1) + 'T10:00:00.000Z',
    pair: 'EUR/USD', source: 'MT5', account: 'MT5', result: 1
  });
}
fixture.trades.push({
  id: 'tax-old', date: '2025-11-01T10:00:00.000Z',
  pair: 'ETH/USDT', source: 'WEEX', account: 'WEEX', result: 1
});

const browser = await chromium.launch({ executablePath, headless: true, args: ['--no-sandbox'] });
const context = await browser.newContext({ baseURL: BASE, viewport: { width: 1440, height: 1000 }, acceptDownloads: true });
const page = await context.newPage();
page.on('console', (msg) => { if (msg.type() === 'error') consoleErrors.push(msg.text()); });
page.on('pageerror', (err) => pageErrors.push(err.message));

let backup = null;

async function readTax() {
  return page.evaluate(() => ({
    revenue: document.querySelector('#taxRevenue')?.textContent,
    costs: document.querySelector('#taxCosts')?.textContent,
    loss: document.querySelector('#taxLoss')?.textContent,
    due: document.querySelector('#taxDue')?.textContent,
    dueHint: document.querySelector('#taxDueHint')?.textContent,
    neutral: document.querySelector('#taxNeutral')?.textContent,
    forex: document.querySelector('#taxForex')?.textContent,
    carry: document.querySelector('#taxCarry')?.textContent,
    rows: document.querySelectorAll('#taxBody tr').length,
    year: document.querySelector('#taxYearEyebrow')?.textContent,
    deadline: document.querySelector('#taxDeadline')?.textContent,
    tax: AV.state.tax,
    data: AV.taxData(AV.state)
  }));
}

async function restore(state) {
  const put = async (baseRevision) => page.evaluate(async ({ state, baseRevision }) => {
    AV.state = JSON.parse(state);
    const res = await fetch('/api/state', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ state: AV.state, baseRevision })
    });
    return { status: res.status, body: await res.json() };
  }, { state, baseRevision });
  let result = await put(await page.evaluate(() => AV.api.revision));
  if (result.status === 409) {
    const latest = await page.evaluate(async () => (await fetch('/api/state', { cache: 'no-store' })).json());
    result = await put(latest.revision);
  }
  return result;
}

try {
  await page.goto('/', { waitUntil: 'load' });
  await page.waitForFunction(() => window.AV && AV.ready, null, { timeout: 15000 });
  if (await page.locator('#auth').isVisible()) {
    await page.click('#enter');
    await page.waitForSelector('#demoEnter');
    await page.click('#demoEnter');
    await page.waitForFunction(() => window.AV?.ready && AV.api.authenticated, null, { timeout: 15000 });
  }
  backup = await page.evaluate(() => JSON.stringify(AV.state));
  const revisionBefore = await page.evaluate(() => AV.api.revision);

  await page.evaluate((f) => {
    AV.state.flows = f.flows;
    AV.state.tax = f.tax;
    AV.state.accounts = f.accounts;
    AV.state.trades = f.trades;
    AV.saveNow();
  }, fixture);
  await page.waitForFunction((revision) => AV.api.revision > revision, revisionBefore, { timeout: 15000 });

  // odczyt po zapisie do MySQL i ponownym wczytaniu
  await page.reload({ waitUntil: 'load' });
  await page.waitForFunction(() => window.AV && AV.ready && AV.api.authenticated, null, { timeout: 15000 });
  await page.click('.navg button[data-v="tax"]');
  await page.waitForTimeout(300);

  const t = await readTax();
  assert.equal(t.year, 'Podatki · rok 2026');
  assert.equal(t.deadline, '30 kwietnia 2027');
  assert.equal(t.revenue, '7420,74 zł');
  assert.equal(t.costs, '4980,00 zł');
  assert.equal(t.loss, '380,00 zł');
  assert.equal(t.due, '≈ 392 zł');
  assert.equal(t.dueHint, 'od dochodu 2060,74 zł');
  assert.equal(t.rows, 4, 'flow z 2025 nie może wejść do zestawienia 2026');
  assert.equal(t.neutral, '47 transakcji');
  assert.equal(t.forex, '2 transakcje');
  assert.equal(t.carry, '0,00 zł');
  assert.equal(t.data.carryForward, 0);
  log('PIT-38 z bazy: rok, przychód, koszty, dochód i podatek', true, t.revenue + ' / ' + t.costs + ' / ' + t.due);

  // edycja nadwyżki kosztów z poprzedniego roku
  await page.click('#taxLossEdit');
  await page.waitForSelector('#pmInput');
  await page.fill('#pmInput', '1000');
  await page.click('#pmOk');
  await page.waitForTimeout(600);
  const edited = await readTax();
  assert.equal(edited.loss, '1000,00 zł');
  assert.equal(edited.dueHint, 'od dochodu 1440,74 zł');
  assert.equal(edited.due, '≈ 274 zł');
  assert.equal(edited.tax.carryLoss, 1000);
  log('nadwyżka kosztów edytowalna i liczona w podatku', true, edited.loss);
  await page.evaluate(() => { AV.state.tax.carryLoss = 380; AV.saveNow(); });
  await page.waitForTimeout(500);

  // eksport CSV
  const downloadPromise = page.waitForEvent('download');
  await page.click('#taxCsv');
  const download = await downloadPromise;
  const csv = fs.readFileSync(await download.path(), 'utf8');
  assert.equal(download.suggestedFilename(), 'alpha-vault-PIT-38-2026.csv');
  assert.match(csv, /Nadwyżka kosztów z 2025/);
  assert.match(csv, /Neutralne podatkowo \(transakcje\);+47/);
  assert.match(csv, /Forex i CFD MT4\/MT5 \(transakcje\);+2/);
  log('CSV PIT-38 z roku podatkowego', true, download.suggestedFilename());

  // wydruk PDF
  const popupPromise = context.waitForEvent('page');
  await page.click('#taxPdf');
  const popup = await popupPromise;
  await popup.waitForLoadState('domcontentloaded').catch(() => {});
  const html = await popup.content();
  assert.equal(await popup.title(), 'PIT-38 · Alpha Vault');
  assert.match(html, /Zestawienie do PIT-38 · 2026/);
  assert.match(html, /Do przeniesienia na 2027/);
  assert.match(html, /Neutralne podatkowo: 47 transakcji/);
  await popup.close();
  log('wydruk PDF dla księgowej', true, 'rok i podsumowanie obecne');

  // formularz wpłaty/wypłaty: waluta obca wymaga kursu NBP
  await page.click('.navg button[data-v="ust"]');
  await page.click('#addFlow');
  await page.waitForSelector('#flCur');
  await page.selectOption('#flType', 'Wypłata');
  await page.selectOption('#flAcc', 'WEEX');
  await page.fill('#flDate', '2026-11-12');
  await page.fill('#flAmount', '100');
  await page.selectOption('#flCur', 'EUR');
  await page.fill('#flFiat', '90');
  await page.fill('#flFx', '1');
  await page.click('#flSave');
  const toastText = await page.locator('#toast').innerText();
  assert.match(toastText, /kurs NBP/i);
  assert.equal(await page.locator('#flSave').count(), 1, 'modal nie może się zamknąć bez kursu');
  await page.fill('#flFx', '4,3');
  await page.click('#flSave');
  await page.waitForTimeout(700);
  await page.click('.navg button[data-v="tax"]');
  const afterFlow = await readTax();
  assert.equal(afterFlow.data.revenue, 7807.74, '90 EUR × 4,3 = 387 PLN');
  assert.equal(afterFlow.rows, 5);
  log('formularz wpłaty/wypłaty zasila PIT-38', true, 'kurs NBP wymagany, +387 zł');

  // puste konto innego użytkownika nie dziedziczy demo 380 zł
  const emptyTax = await page.evaluate(() => AV.normalizeServer({
    tax: { carryLoss: 380, year: 2026 }, flows: [], trades: [], accounts: []
  }, { isDemo: false }).tax);
  assert.deepEqual(emptyTax, { version: 2, carryLoss: 0, year: 2026 });
  log('puste konto bez starego demo', true, 'carryLoss 0');

  assert.equal(consoleErrors.length, 0, 'console errors: ' + consoleErrors.join(' | '));
  assert.equal(pageErrors.length, 0, 'page errors: ' + pageErrors.join(' | '));
  log('brak błędów JS', true);
} catch (err) {
  log('test PIT-38', false, err.message);
  process.exitCode = 1;
} finally {
  if (backup) {
    const restored = await restore(backup).catch((err) => ({ status: 0, body: { error: err.message } }));
    log('przywrócenie stanu demo', restored.status === 200, 'HTTP ' + restored.status);
  }
  await browser.close();
  const failed = results.filter((r) => !r.ok);
  console.log(`\n${results.length - failed.length}/${results.length} PASS`);
  if (failed.length) process.exitCode = 1;
}
