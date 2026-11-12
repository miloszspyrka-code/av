#!/usr/bin/env node
/**
 * Alpha Vault — E2E notowań w tickerze.
 *
 * Sprawdza publiczny endpoint /api/quotes przeciw lokalnemu dostawcy:
 * kształt odpowiedzi, cache (jedno zapytanie do dostawcy na TTL),
 * zachowanie ostatniego dobrego zestawu przy błędzie oraz pusty stan.
 *
 * Uruchom: npm run test:quotes
 */
import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const APP_PORT = Number(process.env.QUOTES_TEST_PORT || 4193);
const FAKE_PORT = Number(process.env.QUOTES_FAKE_PORT || 4194);
const EMPTY_PORT = Number(process.env.QUOTES_EMPTY_PORT || 4195);
const DOWN_PORT = Number(process.env.QUOTES_DOWN_PORT || 4196);
const OK = `http://127.0.0.1:${APP_PORT}`;
const EMPTY = `http://127.0.0.1:${EMPTY_PORT}`;
const FAKE = `http://127.0.0.1:${FAKE_PORT}`;
const php = process.env.PHP_BIN || (fs.existsSync('C:/Users/milos/.local/php83/php.exe')
  ? 'C:/Users/milos/.local/php83/php.exe'
  : 'php');

const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'av-quotes-'));
const cacheOk = path.join(tmp, 'quotes.json');
const cacheEmpty = path.join(tmp, 'quotes-empty.json');
const hitsFile = path.join(tmp, 'hits.txt');
fs.writeFileSync(cacheEmpty, '');

const results = [];
const servers = [];

function log(name, ok, info = '') {
  results.push({ name, ok, info });
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${info ? '  — ' + info : ''}`);
}

function appEnv(port, extra) {
  return {
    ...process.env,
    ALPHA_VAULT_CONFIG: path.join(ROOT, 'tests', 'config.test.json'),
    APP_URL: `http://127.0.0.1:${port}`,
    APP_ENV: 'local',
    SESSION_SECRET: 'quotes-e2e-session-secret-0123456789',
    ...extra,
  };
}

function start(args, env, label) {
  const child = spawn(php, args, { cwd: ROOT, env, stdio: ['ignore', 'pipe', 'pipe'] });
  child.stderr.on('data', (data) => {
    if (process.env.QUOTES_TEST_VERBOSE) process.stderr.write(`[${label}] ${String(data)}`);
  });
  servers.push(child);
  return child;
}

function stopAll() {
  for (const child of servers) {
    if (process.platform === 'win32') {
      spawnSync('taskkill', ['/pid', String(child.pid), '/T', '/F'], { stdio: 'ignore' });
    } else {
      try { child.kill('SIGTERM'); } catch {}
    }
  }
}

async function waitFor(url, timeoutMs = 20000) {
  const started = Date.now();
  while (Date.now() - started < timeoutMs) {
    try {
      const res = await fetch(url);
      if (res.status < 500) return true;
    } catch {}
    await new Promise((resolve) => setTimeout(resolve, 150));
  }
  return false;
}

async function waitForQuotes(base, timeoutMs = 20000) {
  const started = Date.now();
  while (Date.now() - started < timeoutMs) {
    try {
      const res = await fetch(base + '/api/quotes');
      if (res.ok) {
        const data = await res.json();
        if (Array.isArray(data.quotes) && data.quotes.length === SYMBOLS.length) return true;
      }
    } catch {}
    await new Promise((resolve) => setTimeout(resolve, 200));
  }
  return false;
}

async function getJson(base, pathName) {
  const res = await fetch(base + pathName, { headers: { Accept: 'application/json' } });
  const text = await res.text();
  let json = null;
  try { json = text ? JSON.parse(text) : null; } catch {}
  return { res, text, json };
}

const SYMBOLS = ['BTC', 'ETH', 'SOL', 'NAS100', 'US500', 'XAUUSD', 'EUR/USD', 'OIL'];

async function main() {
  log('środowisko testowe', true, `PHP=${php}`);

  start(['-S', `127.0.0.1:${FAKE_PORT}`, 'tests/fake-quotes-provider.php'],
    { ...process.env, QUOTES_HITS_FILE: hitsFile }, 'fake');
  start(['-S', `127.0.0.1:${APP_PORT}`, '-t', 'public', 'public/router.php'],
    appEnv(APP_PORT, { QUOTES_BASE: FAKE, QUOTES_CACHE: cacheOk, QUOTES_TTL: '60' }), 'app');
  start(['-S', `127.0.0.1:${EMPTY_PORT}`, '-t', 'public', 'public/router.php'],
    appEnv(EMPTY_PORT, { QUOTES_BASE: `http://127.0.0.1:${DOWN_PORT}`, QUOTES_CACHE: cacheEmpty, QUOTES_TTL: '0' }), 'empty');

  assert.ok(await waitForQuotes(OK), 'serwer aplikacji nie wystartował');
  assert.ok(await waitFor(`${FAKE}/__hits`), 'dostawca testowy nie wystartował');

  const first = await getJson(OK, '/api/quotes');
  assert.equal(first.res.status, 200, first.text);
  assert.ok(first.json, 'brak JSON');
  assert.equal(first.json.stale, false);
  assert.equal(first.json.quotes.length, SYMBOLS.length);
  assert.deepEqual(first.json.quotes.map((q) => q.symbol), SYMBOLS);
  for (const quote of first.json.quotes) {
    assert.equal(typeof quote.price, 'number');
    assert.ok(Number.isFinite(quote.price) && quote.price > 0, quote.symbol + ' price');
    assert.equal(typeof quote.changePercent, 'number', quote.symbol + ' change');
    assert.ok(Number.isFinite(quote.changePercent), quote.symbol + ' change finite');
  }
  assert.match(String(first.json.updatedAt), /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/);
  log('GET /api/quotes — 8 notowań', true, first.json.quotes.map((q) => `${q.symbol}=${q.price}`).join(' '));

  const hitsAfterFirst = (await getJson(FAKE, '/__hits')).json.hits;
  assert.equal(hitsAfterFirst, SYMBOLS.length, 'oczekiwano jednego zapytania na symbol');

  const second = await getJson(OK, '/api/quotes');
  assert.equal(second.res.status, 200);
  assert.deepEqual(second.json.quotes.map((q) => q.price), first.json.quotes.map((q) => q.price));
  const hitsAfterSecond = (await getJson(FAKE, '/__hits')).json.hits;
  assert.equal(hitsAfterSecond, hitsAfterFirst, 'cache nie powinien odpytywać dostawcy w TTL');
  log('cache TTL — brak drugiego zapytania do dostawcy', true, `hits=${hitsAfterSecond}`);

  const hitsBeforeFilter = (await getJson(FAKE, '/__hits')).json.hits;
  const filtered = await getJson(OK, '/api/quotes?symbols=' + encodeURIComponent('SOL/USDT,EUR/USD'));
  assert.equal(filtered.res.status, 200, filtered.text);
  assert.deepEqual(filtered.json.quotes.map((q) => q.symbol), ['SOL/USDT', 'EUR/USD']);
  assert.equal(filtered.json.stale, false);
  assert.ok(filtered.json.quotes.every((q) => Number.isFinite(q.price) && q.price > 0));
  const hitsAfterFilter = (await getJson(FAKE, '/__hits')).json.hits;
  assert.equal(hitsAfterFilter - hitsBeforeFilter, 2, 'filtr pobiera tylko zamówione instrumenty');
  log('GET /api/quotes?symbols — filtr instrumentów kalkulatora', true, filtered.json.quotes.map((q) => `${q.symbol}=${q.price}`).join(' '));

  stopAll();
  servers.length = 0;
  start(['-S', `127.0.0.1:${APP_PORT}`, '-t', 'public', 'public/router.php'],
    appEnv(APP_PORT, { QUOTES_BASE: FAKE, QUOTES_CACHE: cacheOk, QUOTES_TTL: '0' }), 'app-restart');
  start(['-S', `127.0.0.1:${EMPTY_PORT}`, '-t', 'public', 'public/router.php'],
    appEnv(EMPTY_PORT, { QUOTES_BASE: `http://127.0.0.1:${DOWN_PORT}`, QUOTES_CACHE: cacheEmpty, QUOTES_TTL: '0' }), 'empty-restart');
  assert.ok(await waitFor(`${OK}/api/quotes`), 'restart aplikacji nieudany');
  assert.ok(await waitFor(`${EMPTY}/api/quotes`), 'serwer pustego stanu nie wystartował');

  const failed = await getJson(OK, '/api/quotes');
  assert.equal(failed.res.status, 200, failed.text);
  assert.equal(failed.json.stale, true, 'po błędzie dostawcy flaga stale');
  assert.equal(failed.json.quotes.length, SYMBOLS.length, 'błąd nie może wyczyścić ostatniego zestawu');
  assert.deepEqual(failed.json.quotes.map((q) => q.price), first.json.quotes.map((q) => q.price));
  assert.equal(failed.json.updatedAt, filtered.json.updatedAt);
  log('błąd dostawcy — ostatni dobry zestaw zachowany', true, `stale=${failed.json.stale}`);

  const empty = await getJson(EMPTY, '/api/quotes');
  assert.equal(empty.res.status, 200, empty.text);
  assert.equal(empty.json.stale, true);
  assert.deepEqual(empty.json.quotes, []);
  assert.equal(empty.json.updatedAt, null);
  log('brak cache i brak dostawcy — puste pola, bez zastępników', true);

  stopAll();
  servers.length = 0;
}

try {
  await main();
  const passed = results.filter((r) => r.ok).length;
  console.log(`\nAlpha Vault quotes E2E — ${passed}/${results.length} PASS`);
  process.exit(0);
} catch (err) {
  console.error(err);
  console.log(`\nAlpha Vault quotes E2E — ${results.filter((r) => r.ok).length}/${results.length} PASS`);
  process.exit(1);
} finally {
  stopAll();
  fs.rmSync(tmp, { recursive: true, force: true });
}
