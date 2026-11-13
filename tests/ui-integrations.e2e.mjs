#!/usr/bin/env node
/**
 * Alpha Vault — UI E2E integracji: OKX, WEEX, BingX, MetaTrader, EA.
 * Uruchamia mock dostawców + serwer PHP, potem klika formularze w przeglądarce.
 *
 * Uruchom: npm run test:ui:integrations
 */
import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright-core';

const ROOT = path.resolve(import.meta.dirname, '..');
const APP_PORT = Number(process.env.INTEGRATIONS_APP_PORT || 4175);
const MOCK_PORT = Number(process.env.INTEGRATIONS_MOCK_PORT || 4191);
const BASE = `http://127.0.0.1:${APP_PORT}`;
const CONFIG = path.join(ROOT, 'tests', 'config.integrations.json');
const php = process.env.PHP_BINARY
  || (fs.existsSync('C:\\Users\\milos\\.local\\php83\\php.exe')
    ? 'C:\\Users\\milos\\.local\\php83\\php.exe'
    : 'php');

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
if (!fs.existsSync(CONFIG)) {
  console.error('Brak tests/config.integrations.json.');
  process.exit(2);
}

const children = [];
const results = [];
function log(name, ok, info = '') {
  results.push({ name, ok, info });
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${info ? ' — ' + info : ''}`);
}
function start(cmd, args, env = {}) {
  const child = spawn(cmd, args, { cwd: ROOT, env: { ...process.env, ...env }, stdio: ['ignore', 'pipe', 'pipe'], windowsHide: true });
  children.push(child);
  return child;
}
async function waitFor(url, timeoutMs = 15000) {
  const until = Date.now() + timeoutMs;
  while (Date.now() < until) {
    try { const res = await fetch(url); if (res.status < 500) return; } catch {}
    await new Promise((r) => setTimeout(r, 200));
  }
  throw new Error('Nie wstał: ' + url);
}
function stopAll() {
  for (const child of children) {
    try {
      if (process.platform === 'win32') spawnSync('taskkill', ['/pid', String(child.pid), '/T', '/F'], { stdio: 'ignore' });
      else child.kill('SIGTERM');
    } catch {}
  }
}
function reset() {
  const r = spawnSync(php, ['tests/reset-demo.php'], { cwd: ROOT, env: { ...process.env, ALPHA_VAULT_CONFIG: CONFIG }, encoding: 'utf8' });
  assert.equal(r.status, 0, r.stderr || r.stdout);
}

const browser = await chromium.launch({ executablePath, headless: true, args: ['--no-sandbox'] });
const context = await browser.newContext({ baseURL: BASE, viewport: { width: 1440, height: 1000 } });
const page = await context.newPage();
const consoleErrors = [];
page.on('console', (msg) => { if (msg.type() === 'error') consoleErrors.push(msg.text()); });
page.on('pageerror', (err) => consoleErrors.push(err.stack || err.message));

async function waitConnected(provider) {
  await page.waitForFunction((p) => !!document.querySelector(`[data-exstate="${p}"].win`), provider, { timeout: 25000 });
}

try {
  start(php, ['-S', `127.0.0.1:${MOCK_PORT}`, 'tests/fixtures/mock-providers.php']);
  start(php, ['-S', `127.0.0.1:${APP_PORT}`, '-t', 'public', 'public/router.php'], {
  ALPHA_VAULT_CONFIG: CONFIG,
  APP_URL: BASE
});
  await waitFor(`http://127.0.0.1:${MOCK_PORT}/okx/api/v5/time`);
  await waitFor(BASE + '/api/health');
  reset();

  await page.goto('/', { waitUntil: 'load' });
  await page.waitForFunction(() => window.AV && AV.ready);
  await page.click('#enter');
  await page.waitForSelector('#demoEnter');
  await page.click('#demoEnter');
  await page.waitForFunction(() => window.AV?.ready && AV.api.authenticated, null, { timeout: 15000 });
  log('logowanie demo', true);
  const headerBefore = await page.evaluate(() => ({
    hidden: document.querySelector('#syncBadge').hidden,
    text: document.querySelector('#syncBadge').textContent
  }));
  assert.equal(headerBefore.hidden, true, 'chip giełd bez połączeń: ' + headerBefore.text);
  log('nagłówek: chip giełd ukryty bez połączeń', true);

  await page.click('.navg button[data-v="gieldy"]');
  await page.waitForSelector('#exBody');
  assert.equal(await page.locator('#tbody tr').count(), 47);
  log('widok Giełdy', true);

  // OKX — zły klucz (handel) pokazuje błąd
  await page.click('.exl button[data-ex="okx"]');
  await page.fill('#exF0', 'okx-trade');
  await page.fill('#exF1', 'okx-secret');
  await page.fill('#exF2', 'passphrase');
  await page.click('[data-conn="okx"]');
  await page.waitForFunction(() => (document.querySelector('#exMsg')?.textContent || '').length > 10, null, { timeout: 20000 });
  const errText = await page.locator('#exMsg').innerText();
  assert.match(errText, /tylko do odczytu|handlu|read-only/i);
  assert.equal(await page.locator('[data-exstate="okx"].win').count(), 0);
  log('OKX: klucz z handlem odrzucony w UI', true);

  // OKX — poprawny klucz
  await page.fill('#exF0', 'okx-read');
  await page.fill('#exF1', 'okx-secret');
  await page.fill('#exF2', 'passphrase');
  await page.click('[data-conn="okx"]');
  await waitConnected('okx');
  await page.waitForFunction(() => (document.querySelector('#exBody')?.textContent || '').includes('Zaimportowane transakcje'), null, { timeout: 25000 });
  log('OKX: połączenie przez UI + import', true);
  const headerAfter = await page.evaluate(() => ({
    hidden: document.querySelector('#syncBadge').hidden,
    text: document.querySelector('#syncBadge').textContent
  }));
  assert.equal(headerAfter.hidden, false, 'chip giełd po połączeniu');
  assert.match(headerAfter.text, /OKX/, 'chip pokazuje OKX: ' + headerAfter.text);
  log('nagłówek: chip giełd pokazuje OKX po połączeniu', true, headerAfter.text.trim());

  // WEEX — klucz z prawem handlu przechodzi
  await page.click('.exl button[data-ex="weex"]');
  const weexNote = await page.locator('#exBody .note').innerText();
  assert.match(weexNote, /nie blokuje połączenia/i);
  await page.fill('#exF0', 'weex-trade');
  await page.fill('#exF1', 'weex-secret');
  await page.fill('#exF2', 'passphrase');
  await page.click('[data-conn="weex"]');
  await waitConnected('weex');
  await page.waitForFunction(() => (document.querySelector('#exBody')?.textContent || '').includes('Zaimportowane transakcje'), null, { timeout: 25000 });
  assert.match(await page.locator('#exBody').innerText(), /dowolne \(odczyt wystarcza\)/);
  log('WEEX: klucz z handlem przez UI + sync', true);

  // BingX
  await page.click('.exl button[data-ex="bingx"]');
  await page.fill('#exF0', 'bingx-read');
  await page.fill('#exF1', 'bingx-secret');
  await page.click('[data-conn="bingx"]');
  await waitConnected('bingx');
  await page.waitForFunction(() => (document.querySelector('#exBody')?.textContent || '').includes('Zaimportowane transakcje'), null, { timeout: 25000 });
  log('BingX: połączenie przez UI + sync', true);

  // MetaTrader — hasło inwestora
  await page.click('.exl button[data-ex="mt"]');
  await page.fill('#mtServer', 'Mock-Server');
  await page.fill('#mtLogin', '81234567');
  await page.fill('#mtPass', 'investor');
  await page.click('[data-mtconnect]');
  await waitConnected('mt');
  await page.waitForFunction(() => (document.querySelector('#exBody')?.textContent || '').includes('Zaimportowane transakcje'), null, { timeout: 25000 });
  log('MetaTrader: hasło inwestora przez UI', true);

  // EA — kod parowania
  await page.click('#mtTabs [data-m="b"]');
  await page.click('[data-mtpair]');
  await page.waitForFunction(() => /^AV-[A-Z0-9]{4}-[A-Z0-9]{4}$/.test((document.querySelector('#mtCode')?.textContent || '').trim()), null, { timeout: 15000 });
  const code = (await page.locator('#mtCode').innerText()).trim();
  log('EA: kod parowania w UI', true, code);

  // dziennik: 47 + 2 OKX + 1 WEEX + 1 BingX + 1 MT = 52
  await page.click('.navg button[data-v="trans"]');
  const rows = await page.locator('#tbody tr').count();
  assert.equal(rows, 52);
  log('dziennik: transakcje z integracji widoczne', true, rows + ' transakcji');

  const html = await page.content();
  assert.ok(!/okx-secret|weex-secret|bingx-secret|investor/.test(html), 'sekret w DOM');
  log('bezpieczeństwo: brak sekretów w DOM', true);

  const relevantErrors = consoleErrors.filter((e) => !/401|422|Unauthorized/.test(e));
  assert.deepEqual(relevantErrors, [], 'console errors: ' + relevantErrors.join(' | '));
  log('brak błędów konsoli', true);

  assert.equal(results.filter((r) => !r.ok).length, 0);
  console.log(`\nUI integracje E2E: ${results.length}/${results.length} PASS`);
} catch (err) {
  console.error('FAIL  UI integracje E2E:', err?.stack || err);
  console.error('CONSOLE ERRORS:', consoleErrors);
  process.exitCode = 1;
} finally {
  try { await browser.close(); } catch {}
  try { reset(); } catch {}
  stopAll();
}
