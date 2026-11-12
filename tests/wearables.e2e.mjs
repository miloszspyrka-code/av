#!/usr/bin/env node
/**
 * Alpha Vault — E2E integracji urządzeń.
 *
 * Testuje pełny przepływ OAuth2 (Garmin/Oura/Whoop) przeciw lokalnemu,
 * deterministycznemu dostawcy oraz most Apple Health (kod parowania + ingest).
 * Nie używa prawdziwych kont ani danych zdrowotnych.
 *
 * Uruchom: npm run test:wearables
 */
import assert from 'node:assert/strict';
import { spawn, execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const APP_PORT = Number(process.env.WEARABLES_TEST_PORT || 4175);
const FAKE_PORT = Number(process.env.WEARABLES_FAKE_PORT || 4180);
const BASE = `http://127.0.0.1:${APP_PORT}`;
const FAKE = `http://127.0.0.1:${FAKE_PORT}`;
const ORIGIN = new URL(BASE).origin;
const php = process.env.PHP_BIN || (fs.existsSync('C:/Users/milos/.local/php83/php.exe')
  ? 'C:/Users/milos/.local/php83/php.exe'
  : 'php');

const results = [];
let cookie = '';
let appServer = null;
let fakeServer = null;

function log(name, ok, info = '') {
  results.push({ name, ok, info });
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${info ? '  · ' + info : ''}`);
}

function baseEnv() {
  return {
    ...process.env,
    ALPHA_VAULT_CONFIG: path.join(ROOT, 'tests', 'config.test.json'),
    APP_URL: BASE,
    APP_ENV: 'local',
    ALLOW_DEMO_LOGIN: 'true',
    WEARABLES_ENCRYPTION_KEY: 'wearables-e2e-key-0123456789abcdef',
    GARMIN_CLIENT_ID: 'fake-garmin-client',
    GARMIN_CLIENT_SECRET: 'fake-garmin-secret',
    GARMIN_AUTHORIZE_URL: `${FAKE}/oauth/garmin/authorize`,
    GARMIN_TOKEN_URL: `${FAKE}/oauth/garmin/token`,
    GARMIN_API_BASE: `${FAKE}/garmin`,
    OURA_CLIENT_ID: 'fake-oura-client',
    OURA_CLIENT_SECRET: 'fake-oura-secret',
    OURA_AUTHORIZE_URL: `${FAKE}/oauth/oura/authorize`,
    OURA_TOKEN_URL: `${FAKE}/oauth/oura/token`,
    OURA_API_BASE: `${FAKE}/oura`,
    WHOOP_CLIENT_ID: 'fake-whoop-client',
    WHOOP_CLIENT_SECRET: 'fake-whoop-secret',
    WHOOP_AUTHORIZE_URL: `${FAKE}/oauth/whoop/authorize`,
    WHOOP_TOKEN_URL: `${FAKE}/oauth/whoop/token`,
    WHOOP_API_BASE: `${FAKE}/whoop`
  };
}

async function waitFor(url, timeoutMs = 15000) {
  const started = Date.now();
  while (Date.now() - started < timeoutMs) {
    try {
      const res = await fetch(url);
      if (res.status < 500) return true;
    } catch {}
    await new Promise((r) => setTimeout(r, 150));
  }
  return false;
}

function startServer(args, label) {
  const child = spawn(php, args, { cwd: ROOT, env: baseEnv(), stdio: ['ignore', 'pipe', 'pipe'] });
  child.stderr.on('data', (data) => {
    const text = String(data);
    if (process.env.WEARABLES_TEST_VERBOSE) process.stderr.write(`[${label}] ${text}`);
  });
  return child;
}

async function req(pathOrUrl, opts = {}) {
  const url = pathOrUrl.startsWith('http') ? pathOrUrl : BASE + pathOrUrl;
  const res = await fetch(url, {
    redirect: 'manual',
    ...opts,
    headers: {
      ...(opts.body ? { 'Content-Type': 'application/json' } : {}),
      ...(cookie ? { Cookie: cookie } : {}),
      ...(opts.headers || {})
    }
  });
  const text = await res.text();
  let json = null;
  try { json = text ? JSON.parse(text) : null; } catch {}
  return { res, text, json };
}

function cookieFrom(res) {
  const list = res.headers.getSetCookie ? res.headers.getSetCookie() : [res.headers.get('set-cookie') || ''];
  for (const c of list) {
    const m = /^av_session=([^;]+)/.exec(c || '');
    if (m) return 'av_session=' + m[1];
  }
  return '';
}

async function clearCheckins() {
  const state = await req('/api/state');
  assert.equal(state.res.status, 200, state.text);
  const value = state.json.state;
  value.checkins = [];
  const put = await req('/api/state', {
    method: 'PUT',
    headers: { Origin: ORIGIN },
    body: JSON.stringify({ state: value, baseRevision: state.json.revision })
  });
  assert.equal(put.res.status, 200, put.text);
}

async function connect(provider) {
  const start = await req(`/auth/wearables/${provider}/start`);
  assert.equal(start.res.status, 302, 'start ' + provider + ': ' + start.text);
  const authorize = start.res.headers.get('location') || '';
  assert.ok(authorize.startsWith(FAKE + '/oauth/' + provider + '/authorize'), 'authorize URL: ' + authorize);
  const providerRes = await fetch(authorize, { redirect: 'manual' });
  assert.equal(providerRes.status, 302, 'provider authorize');
  const callback = providerRes.headers.get('location') || '';
  assert.ok(callback.startsWith(BASE + '/auth/wearables/' + provider + '/callback'), 'callback URL: ' + callback);
  const done = await req(callback);
  assert.equal(done.res.status, 302, 'callback: ' + done.text);
  assert.match(done.res.headers.get('location') || '', new RegExp('connected=' + provider));
  const list = await req('/api/wearables');
  assert.equal(list.res.status, 200, list.text);
  return list.json;
}

async function main() {
  console.log('Alpha Vault wearables E2E ·', BASE, '· fake', FAKE);
  const env = baseEnv();
  execFileSync(php, ['app/db/migrate.php'], { cwd: ROOT, env, stdio: 'pipe' });

  fakeServer = startServer(['-S', `127.0.0.1:${FAKE_PORT}`, path.join(ROOT, 'tests', 'fake-health-provider.php')], 'fake');
  appServer = startServer(['-S', `127.0.0.1:${APP_PORT}`, '-t', path.join(ROOT, 'public'), path.join(ROOT, 'public', 'router.php')], 'app');
  assert.ok(await waitFor(`${FAKE}/health`), 'fake provider nie wystartował');
  assert.ok(await waitFor(`${BASE}/api/health`), 'aplikacja nie wystartowała');
  const health = await req('/api/health');
  assert.equal(health.res.status, 200, health.text);
  assert.equal(health.json.ok, true);

  const anon = await req('/api/wearables');
  assert.equal(anon.res.status, 401);
  log('wearables bez sesji = 401', true);

  const demo = await req('/auth/demo', {
    method: 'POST',
    headers: { Origin: ORIGIN },
    body: JSON.stringify({ remember: true })
  });
  assert.equal(demo.res.status, 200, demo.text);
  cookie = cookieFrom(demo.res);
  assert.ok(cookie);
  log('logowanie demo', true);

  await clearCheckins();
  log('czyszczenie check-inów do testu gotowości', true);

  const badCallback = await req('/auth/wearables/oura/callback?code=x&state=y');
  assert.equal(badCallback.res.status, 400);
  log('callback z błędnym state = 400', true);

  const badOrigin = await req('/api/wearables/oura/sync', {
    method: 'POST',
    headers: { Origin: 'https://evil.example' },
    body: '{}'
  });
  assert.equal(badOrigin.res.status, 403);
  log('sync z obcym Origin = 403', true);

  const expectations = {
    garmin: { score: 65, providerScore: null, battery: 34 },
    oura: { score: 69, providerScore: 70, battery: null },
    whoop: { score: 69, providerScore: 70, battery: null }
  };

  for (const provider of ['garmin', 'oura', 'whoop']) {
    const data = await connect(provider);
    const device = data.devices[provider];
    assert.equal(device.connected, true, provider + ' connected');
    assert.equal(device.lastSyncStatus, 'ok', provider + ' sync status');
    assert.ok(device.lastSyncAt, provider + ' lastSyncAt');
    const today = data.today;
    assert.ok(today, provider + ' today');
    assert.equal(today.sleepMinutes, 312, provider + ' sleep');
    assert.equal(Math.round(today.hrvMs), 38, provider + ' hrv');
    assert.equal(today.restingHr, 61, provider + ' rhr');
    assert.equal(today.bodyBattery, expectations[provider].battery, provider + ' battery');
    assert.equal(today.providerScore, expectations[provider].providerScore, provider + ' provider score');
    assert.equal(data.readiness.score, expectations[provider].score, provider + ' readiness');
    assert.equal(data.baseline.hrvMs, 52, provider + ' baseline hrv');
    assert.ok(data.series.length >= 14, provider + ' series');
    const raw = JSON.stringify(data);
    assert.ok(!raw.includes('fake-access'), provider + ' nie ujawnia access tokenu');
    assert.ok(!raw.includes('fake-refresh'), provider + ' nie ujawnia refresh tokenu');
    log('OAuth + dane + gotowość · ' + provider, true, `score=${today.readiness} · sleep=${today.sleepMinutes} min · days=${data.series.length}`);

    const sync = await req(`/api/wearables/${provider}/sync`, {
      method: 'POST',
      headers: { Origin: ORIGIN },
      body: '{}'
    });
    assert.equal(sync.res.status, 200, sync.text);
    assert.equal(sync.json.days, 14);
    log('ręczna synchronizacja · ' + provider, true, sync.json.days + ' dni');

    const del = await req(`/api/wearables/${provider}`, {
      method: 'DELETE',
      headers: { Origin: ORIGIN }
    });
    assert.equal(del.res.status, 200, del.text);
    const after = await req('/api/wearables');
    assert.equal(after.json.devices[provider].connected, false);
    assert.equal(after.json.today, null);
    assert.equal(after.json.series.length, 0);
    log('odłączenie usuwa dane · ' + provider, true);
  }

  // Apple Health: parowanie i ingest bez OAuth.
  const pair = await req('/api/wearables/apple/pair', {
    method: 'POST',
    headers: { Origin: ORIGIN },
    body: '{}'
  });
  assert.equal(pair.res.status, 200, pair.text);
  assert.match(pair.json.code, /^AV-[A-Z2-9]{4}-[A-Z2-9]{4}$/);
  log('Apple: kod parowania', true, pair.json.code);

  const today = new Date().toISOString().slice(0, 10);
  const yesterday = new Date(Date.now() - 86400000).toISOString().slice(0, 10);
  const ingestBody = {
    code: pair.json.code,
    deviceName: 'iPhone E2E',
    days: [
      { date: today, sleepMinutes: 312, hrvMs: 38, restingHr: 61, providerScore: 70 },
      { date: yesterday, sleepMinutes: 450, hrvMs: 52, restingHr: 56, providerScore: 75 }
    ]
  };
  const ingest = await req('/api/wearables/apple/ingest', {
    method: 'POST',
    body: JSON.stringify(ingestBody)
  });
  assert.equal(ingest.res.status, 200, ingest.text);
  assert.ok(ingest.json.deviceToken && ingest.json.deviceToken.startsWith('avw_'));
  log('Apple: ingest z kodem parowania', true, ingest.json.days + ' dni');

  const appleList = await req('/api/wearables');
  assert.equal(appleList.json.devices.apple.connected, true);
  assert.equal(appleList.json.today.sleepMinutes, 312);
  assert.equal(appleList.json.readiness.score, 69);
  log('Apple: dane w panelu', true, 'score=' + appleList.json.today.readiness);

  const replay = await req('/api/wearables/apple/ingest', {
    method: 'POST',
    body: JSON.stringify(ingestBody)
  });
  assert.equal(replay.res.status, 401);
  log('Apple: kod jednorazowy', true);

  const badToken = await req('/api/wearables/apple/ingest', {
    method: 'POST',
    headers: { Authorization: 'Bearer avw_invalid' },
    body: JSON.stringify({ days: [{ date: today, sleepMinutes: 400 }] })
  });
  assert.equal(badToken.res.status, 401);
  log('Apple: zły token urządzenia = 401', true);

  const second = await req('/api/wearables/apple/ingest', {
    method: 'POST',
    headers: { Authorization: 'Bearer ' + ingest.json.deviceToken },
    body: JSON.stringify({ days: [{ date: today, sleepMinutes: 400, hrvMs: 42, restingHr: 59, providerScore: 72 }] })
  });
  assert.equal(second.res.status, 200, second.text);
  const updated = await req('/api/wearables');
  assert.equal(updated.json.today.sleepMinutes, 400);
  log('Apple: kolejny ingest z tokenem', true, 'sleep=400 min');

  // Gotowość łączy urządzenie (70) z check-inem (54): 0.7*70 + 0.3*54 = 65.
  const state = await req('/api/state');
  const value = state.json.state;
  value.checkins = [{
    date: today,
    mood: 'Zmęczenie',
    energy: 2,
    note: 'E2E',
    score: 54
  }];
  const put = await req('/api/state', {
    method: 'PUT',
    headers: { Origin: ORIGIN },
    body: JSON.stringify({ state: value, baseRevision: state.json.revision })
  });
  assert.equal(put.res.status, 200, put.text);
  const combined = await req('/api/wearables');
  assert.equal(combined.json.readiness.score, 65, JSON.stringify(combined.json.readiness));
  log('gotowość urządzenie + check-in', true, '65/100');

  const del = await req('/api/wearables/apple', {
    method: 'DELETE',
    headers: { Origin: ORIGIN }
  });
  assert.equal(del.res.status, 200, del.text);
  const cleared = await req('/api/wearables');
  assert.equal(cleared.json.devices.apple.connected, false);
  assert.equal(cleared.json.series.length, 0);
  log('Apple: odłączenie usuwa dane', true);

  const failed = results.filter((r) => !r.ok);
  console.log(`\n${results.length - failed.length}/${results.length} PASS`);
  if (failed.length) process.exitCode = 1;
}

try {
  await main();
} catch (err) {
  console.error('\nFAIL  test przerwany:', err.message);
  if (err.stack) console.error(err.stack.split('\n').slice(0, 6).join('\n'));
  process.exitCode = 1;
} finally {
  if (appServer) appServer.kill();
  if (fakeServer) fakeServer.kill();
}
