#!/usr/bin/env node
/**
 * Alpha Vault — E2E integracji urządzeń.
 *
 * Testuje pełny przepływ OAuth2 (Oura) przeciw lokalnemu,
 * deterministycznemu dostawcy oraz most Apple Health (kod parowania + ingest).
 * Garmin i Whoop są wyłączone — test pilnuje, że nie wracają do API ani UI.
 * Nie używa prawdziwych kont ani danych zdrowotnych.
 *
 * Uruchom: npm run test:wearables
 */
import assert from 'node:assert/strict';
import { spawn, spawnSync, execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright-core';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const APP_PORT = Number(process.env.WEARABLES_TEST_PORT || 4175);
const FAKE_PORT = Number(process.env.WEARABLES_FAKE_PORT || 4180);
const BASE = `http://127.0.0.1:${APP_PORT}`;
const FAKE = `http://127.0.0.1:${FAKE_PORT}`;
const ORIGIN = new URL(BASE).origin;
const FAKE_STATE = path.join(os.tmpdir(), 'av-wearables-fake-state.json');
const php = process.env.PHP_BIN || (fs.existsSync('C:/Users/milos/.local/php83/php.exe')
  ? 'C:/Users/milos/.local/php83/php.exe'
  : 'php');

const results = [];
const serverLogs = [];
let cookie = '';
let demoCookie = '';
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
    WEARABLES_ENCRYPTION_KEY_PREVIOUS: 'wearables-e2e-previous-key-0123456789',
    OURA_CLIENT_ID: 'fake-oura-client',
    OURA_CLIENT_SECRET: 'fake-oura-secret',
    OURA_AUTHORIZE_URL: `${FAKE}/oauth/oura/authorize`,
    OURA_TOKEN_URL: `${FAKE}/oauth/oura/token`,
    OURA_API_BASE: `${FAKE}/oura`,
    OURA_REVOKE_URL: `${FAKE}/oura/oauth/revoke`,
    FAKE_HEALTH_STATE: FAKE_STATE
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
    serverLogs.push(text);
    if (process.env.WEARABLES_TEST_VERBOSE) process.stderr.write(`[${label}] ${text}`);
  });
  return child;
}

function stopServer(child) {
  if (!child) return;
  if (process.platform === 'win32') {
    spawnSync('taskkill', ['/pid', String(child.pid), '/T', '/F'], { stdio: 'ignore' });
  } else {
    try { child.kill('SIGTERM'); } catch {}
  }
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

function db(args) {
  const raw = execFileSync(php, [path.join(ROOT, 'tests', 'wearables-db.php'), ...args], {
    cwd: ROOT,
    env: baseEnv(),
    encoding: 'utf8'
  });
  return JSON.parse(raw.trim() || '{}');
}

function demoCookieFrom(res) {
  const list = res.headers.getSetCookie ? res.headers.getSetCookie() : [res.headers.get('set-cookie') || ''];
  for (const c of list) {
    const m = /^av_demo=([^;]+)/.exec(c || '');
    if (m) return m[1];
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

async function startFlow(provider) {
  const start = await req(`/auth/wearables/${provider}/start`);
  assert.equal(start.res.status, 302, 'start ' + provider + ': ' + start.text);
  const authorize = start.res.headers.get('location') || '';
  assert.ok(authorize.startsWith(FAKE + '/oauth/' + provider + '/authorize'), 'authorize URL: ' + authorize);
  const authUrl = new URL(authorize);
  assert.equal(authUrl.searchParams.get('code_challenge_method'), 'S256');
  assert.match(authUrl.searchParams.get('code_challenge') || '', /^[A-Za-z0-9_-]{43}$/);
  assert.equal(authUrl.searchParams.get('scope'), 'daily');
  const providerRes = await fetch(authorize, { redirect: 'manual' });
  assert.equal(providerRes.status, 302, 'provider authorize');
  const callback = providerRes.headers.get('location') || '';
  assert.ok(callback.startsWith(BASE + '/auth/wearables/' + provider + '/callback'), 'callback URL: ' + callback);
  return { authorize, callback };
}

async function connect(provider) {
  const { callback } = await startFlow(provider);
  const done = await req(callback);
  assert.equal(done.res.status, 302, 'callback: ' + done.text);
  assert.match(done.res.headers.get('location') || '', new RegExp('connected=' + provider));
  const list = await req('/api/wearables');
  assert.equal(list.res.status, 200, list.text);
  return list.json;
}

function resolveChrome() {
  const candidates = [
    process.env.CHROME_PATH,
    path.join(process.env.LOCALAPPDATA || '', 'Google', 'Chrome', 'Application', 'chrome.exe'),
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
    'C:/Program Files/Microsoft/Edge/Application/msedge.exe'
  ].filter(Boolean);
  return candidates.find((p) => fs.existsSync(p)) || null;
}

async function runUiChecks() {
  const executablePath = resolveChrome();
  if (!executablePath) {
    log('UI: pominięte — brak Chrome/Edge', true);
    return;
  }
  const browser = await chromium.launch({ executablePath, headless: true, args: ['--no-sandbox'] });
  const context = await browser.newContext({ baseURL: BASE, viewport: { width: 1440, height: 1000 } });
  await context.addCookies([{ name: 'av_demo', value: demoCookie, url: BASE }]);
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', (err) => errors.push(err.message));
  try {
    await page.goto('/', { waitUntil: 'load' });
    await page.waitForFunction(() => window.AV && AV.ready);
    await page.click('#enter');
    await page.waitForSelector('#demoEnter');
    await page.click('#demoEnter');
    await page.waitForFunction(() => window.AV?.ready && AV.api.authenticated, null, { timeout: 15000 });
    await page.goto('/#cialo');
    await page.waitForFunction(() => !!document.querySelector('#bodyReady'));
    const uiProviders = await page.$$eval('#devList [data-provider]', (els) => els.map((el) => el.dataset.provider));
    assert.deepEqual(uiProviders, ['apple', 'oura']);
    assert.ok(!/Garmin|Whoop/i.test(await page.locator('#devList').innerText()));
    log('UI: tylko Oura i Apple Watch', true, uiProviders.join(', '));
    assert.equal((await page.locator('#bodyReady').innerText()).trim(), '');
    log('UI: brak urządzenia = puste pola', true);

    await page.locator('#checkMood .chip', { hasText: 'Spokój' }).click();
    await page.locator('#checkEnergy .chip', { hasText: '5' }).click();
    await page.click('#saveCheck');
    await page.waitForFunction(() => document.querySelector('#bodyReady')?.textContent.trim() === '80', null, { timeout: 5000 });
    log('UI: check-in bez urządzenia', true, '80/100');

    const uiConnect = async (provider, expected) => {
      await page.locator(`[data-provider="${provider}"] [data-dev]`).click();
      await page.waitForFunction((p) => window.AV?.api?.wearables?.devices?.[p]?.connected, provider, { timeout: 15000 });
      await page.waitForFunction((v) => document.querySelector('#bodyReady')?.textContent.trim() === String(v), expected, { timeout: 5000 });
      assert.equal((await page.locator('#metricSleep').innerText()).trim(), '5 h 12 min');
      assert.equal((await page.locator('#metricHrv').innerText()).trim(), '38 ms');
      assert.equal((await page.locator('#metricHr').innerText()).trim(), '61');
      assert.equal(await page.locator('#sleepCard').isVisible(), true);
      log('UI: połączenie i metryki · ' + provider, true, 'gotowość=' + expected);
    };

    const uiDisconnect = async (provider) => {
      await page.locator(`[data-provider="${provider}"] [data-dev]`).click();
      await page.waitForSelector('#gModal.on');
      await page.click('#gConfirmOk');
      await page.waitForFunction((p) => !window.AV?.api?.wearables?.devices?.[p]?.connected, provider, { timeout: 10000 });
      assert.equal((await page.locator('#metricSleep').innerText()).trim(), '');
      log('UI: odłączenie i puste metryki · ' + provider, true);
    };

    await uiConnect('oura', 72);
    await uiDisconnect('oura');

    const pair = await req('/api/wearables/apple/pair', { method: 'POST', headers: { Origin: ORIGIN }, body: '{}' });
    assert.equal(pair.res.status, 200, pair.text);
    await page.locator('[data-provider="apple"] [data-dev]').click();
    await page.waitForSelector('#gModal.on');
    const code = (await page.locator('#gBody .mono').innerText()).trim();
    assert.match(code, /^AV-[A-Z2-9]{4}-[A-Z2-9]{4}$/);
    const today = new Date().toISOString().slice(0, 10);
    const ingest = await req('/api/wearables/apple/ingest', {
      method: 'POST',
      body: JSON.stringify({
        code,
        deviceName: 'iPhone UI',
        days: [{ date: today, sleepMinutes: 312, hrvMs: 38, restingHr: 61, providerScore: 70 }]
      })
    });
    assert.equal(ingest.res.status, 200, ingest.text);
    await page.click('#applePairCheck');
    await page.waitForFunction(() => window.AV?.api?.wearables?.devices?.apple?.connected, null, { timeout: 10000 });
    await page.waitForTimeout(400);
    const appleRing = (await page.locator('#bodyReady').innerText()).trim();
    const appleState = await page.evaluate(() => ({ today: AV.api.wearables?.today, readiness: AV.readiness(AV.state) }));
    assert.equal(appleRing, '70', JSON.stringify(appleState));
    log('UI: Apple Watch przez kod parowania', true, 'gotowość=70');
    await uiDisconnect('apple');

    assert.deepEqual(errors, []);
    log('UI: brak błędów JavaScript', true);
  } finally {
    await browser.close();
  }
}

async function main() {
  console.log('Alpha Vault wearables E2E ·', BASE, '· fake', FAKE);
  fs.rmSync(FAKE_STATE, { force: true });
  const env = baseEnv();
  execFileSync(php, ['app/db/migrate.php'], { cwd: ROOT, env, stdio: 'pipe' });
  const configTest = execFileSync(php, [path.join(ROOT, 'tests', 'wearables-config.test.php')], {
    cwd: ROOT,
    env,
    encoding: 'utf8'
  });
  process.stdout.write(configTest);

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
  demoCookie = demoCookieFrom(demo.res);
  assert.ok(cookie);
  assert.ok(demoCookie, 'brak cookie av_demo');
  log('logowanie demo', true);

  await clearCheckins();
  db(['reset']);
  const clean = await req('/api/wearables');
  assert.deepEqual(Object.keys(clean.json.devices), ['apple', 'oura']);
  assert.equal(clean.json.series.length, 0, 'stan urządzeń nie został wyczyszczony');
  log('czyszczenie check-inów i urządzeń', true);

  const garminStart = await req('/auth/wearables/garmin/start');
  assert.equal(garminStart.res.status, 404, 'Garmin start powinien być wyłączony');
  const whoopSync = await req('/api/wearables/whoop/sync', { method: 'POST', headers: { Origin: ORIGIN }, body: '{}' });
  assert.equal(whoopSync.res.status, 404, 'Whoop sync powinien być wyłączony');
  const whoopDelete = await req('/api/wearables/whoop', { method: 'DELETE', headers: { Origin: ORIGIN } });
  assert.equal(whoopDelete.res.status, 404, 'Whoop DELETE powinien być wyłączony');
  log('Garmin i Whoop wyłączone w API', true, 'start/sync/DELETE = 404');

  const badCallback = await req('/auth/wearables/oura/callback?code=x&state=y');
  assert.equal(badCallback.res.status, 400);
  log('callback z błędnym state = 400', true);

  const expiredFlow = await startFlow('oura');
  const expiredState = new URL(expiredFlow.callback).searchParams.get('state');
  db(['expire-state', expiredState]);
  const expiredCallback = await req(expiredFlow.callback);
  assert.equal(expiredCallback.res.status, 400);
  log('wygasły state = 400', true);

  const pkceFlow = await startFlow('oura');
  const badCodeCallback = pkceFlow.callback.replace(/code=[^&]+/, 'code=fake-code-oura-WRONGCHALLENGEVALUE1234567890');
  const pkceFailure = await req(badCodeCallback);
  assert.equal(pkceFailure.res.status, 502);
  log('PKCE: błędny code_verifier = 502', true);

  const badOrigin = await req('/api/wearables/oura/sync', {
    method: 'POST',
    headers: { Origin: 'https://evil.example' },
    body: '{}'
  });
  assert.equal(badOrigin.res.status, 403);
  log('sync z obcym Origin = 403', true);

  const expectations = {
    oura: { score: 69, providerScore: 70, battery: null }
  };

  for (const provider of ['oura']) {
    const data = await connect(provider);
    const device = data.devices[provider];
    assert.equal(device.connected, true, provider + ' connected');
    assert.equal(device.status, 'connected', provider + ' status');
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
    assert.ok(sync.json, 'sync JSON: ' + sync.text);
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

  // OAuth: state jest jednorazowy.
  const replayFlow = await startFlow('oura');
  const replayFirst = await req(replayFlow.callback);
  assert.equal(replayFirst.res.status, 302, replayFirst.text);
  const replaySecond = await req(replayFlow.callback);
  assert.equal(replaySecond.res.status, 400);
  log('state jednorazowy: powtórny callback = 400', true);

  // Rotacja refresh tokenu: nowy token jest zapisywany, kolejny sync go nie zużywa.
  const beforeRefresh = db(['refresh-hash', 'oura']).refreshHash;
  db(['expire-token', 'oura']);
  const rotated = await req('/api/wearables/oura/sync', {
    method: 'POST',
    headers: { Origin: ORIGIN },
    body: '{}'
  });
  assert.equal(rotated.res.status, 200, rotated.text);
  assert.equal(rotated.json.days, 14);
  const afterRefresh = db(['refresh-hash', 'oura']).refreshHash;
  assert.notEqual(afterRefresh, beforeRefresh);
  let fakeState = JSON.parse(fs.readFileSync(FAKE_STATE, 'utf8'));
  assert.equal(fakeState.oura_refresh_count, 1);
  await req('/api/wearables/oura/sync', { method: 'POST', headers: { Origin: ORIGIN }, body: '{}' });
  fakeState = JSON.parse(fs.readFileSync(FAKE_STATE, 'utf8'));
  assert.equal(fakeState.oura_refresh_count, 1);
  log('rotacja refresh tokenu i blokada podwójnego odświeżenia', true);

  // Błędy dostawcy mapują się na token_expired / sync_error, bez wycieku tokenu.
  db(['set-access', 'oura', 'revoked']);
  const unauthorized = await req('/api/wearables/oura/sync', {
    method: 'POST',
    headers: { Origin: ORIGIN },
    body: '{}'
  });
  assert.equal(unauthorized.res.status, 401, unauthorized.text);
  assert.equal(unauthorized.json.error, 'provider_unauthorized');
  let current = await req('/api/wearables');
  assert.equal(current.json.devices.oura.status, 'token_expired');
  assert.ok(!JSON.stringify(current.json).includes('fake-access'));

  db(['set-access', 'oura', 'ratelimit']);
  const limited = await req('/api/wearables/oura/sync', {
    method: 'POST',
    headers: { Origin: ORIGIN },
    body: '{}'
  });
  assert.equal(limited.res.status, 503, limited.text);
  current = await req('/api/wearables');
  assert.equal(current.json.devices.oura.status, 'sync_error');

  db(['set-access', 'oura', 'badjson']);
  const badJson = await req('/api/wearables/oura/sync', {
    method: 'POST',
    headers: { Origin: ORIGIN },
    body: '{}'
  });
  assert.equal(badJson.res.status, 502, badJson.text);
  current = await req('/api/wearables');
  assert.equal(current.json.devices.oura.status, 'sync_error');

  db(['set-access', 'oura', 'ok']);
  const recovered = await req('/api/wearables/oura/sync', {
    method: 'POST',
    headers: { Origin: ORIGIN },
    body: '{}'
  });
  assert.equal(recovered.res.status, 200, recovered.text);
  current = await req('/api/wearables');
  assert.equal(current.json.devices.oura.status, 'connected');
  log('mapowanie 401/429/niepoprawnego JSON i powrót do connected', true);

  db(['set-access-key', 'oura', 'previous', 'previous']);
  const previousKeySync = await req('/api/wearables/oura/sync', {
    method: 'POST',
    headers: { Origin: ORIGIN },
    body: '{}'
  });
  assert.equal(previousKeySync.res.status, 200, previousKeySync.text);
  log('rotacja klucza: token z poprzednim kluczem odszyfrowany', true);

  db(['set-access-unknown-key', 'oura']);
  const unknownKeySync = await req('/api/wearables/oura/sync', {
    method: 'POST',
    headers: { Origin: ORIGIN },
    body: '{}'
  });
  assert.equal(unknownKeySync.res.status, 401, unknownKeySync.text);
  current = await req('/api/wearables');
  assert.equal(current.json.devices.oura.status, 'token_expired');
  log('błędny klucz szyfrowania = token_expired', true);

  await connect('oura');
  const refreshCountBefore = JSON.parse(fs.readFileSync(FAKE_STATE, 'utf8')).oura_refresh_count || 0;
  db(['expire-token', 'oura']);
  db(['expire-refresh', 'oura']);
  const expiredRefresh = await req('/api/wearables/oura/sync', {
    method: 'POST',
    headers: { Origin: ORIGIN },
    body: '{}'
  });
  assert.equal(expiredRefresh.res.status, 401, expiredRefresh.text);
  current = await req('/api/wearables');
  assert.equal(current.json.devices.oura.status, 'token_expired');
  const refreshCountAfter = JSON.parse(fs.readFileSync(FAKE_STATE, 'utf8')).oura_refresh_count || 0;
  assert.equal(refreshCountAfter, refreshCountBefore);
  log('wygasły refresh token = 401 bez niepotrzebnego odświeżania', true);

  await req('/api/wearables/oura', { method: 'DELETE', headers: { Origin: ORIGIN } });

  // Izolacja użytkowników: cudze połączenie nie jest widoczne ani usuwalne.
  const other = db(['create-other-user']);
  const isolated = await req('/api/wearables');
  assert.equal(isolated.json.devices.oura.connected, false);
  assert.equal(isolated.json.today, null);
  assert.equal(isolated.json.series.length, 0);
  assert.equal(db(['count-connections', 'oura']).count, 1);
  const foreignDelete = await req('/api/wearables/oura', { method: 'DELETE', headers: { Origin: ORIGIN } });
  assert.equal(foreignDelete.res.status, 404);
  assert.equal(db(['count-connections', 'oura']).count, 1);
  db(['delete-user', other.userId]);
  assert.equal(db(['count-connections', 'oura']).count, 0);
  log('izolacja użytkowników: odczyt i DELETE nie dotykają cudzego połączenia', true);

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
  assert.equal(appleList.json.readiness.score, 69, JSON.stringify({ today: appleList.json.today, baseline: appleList.json.baseline, readiness: appleList.json.readiness }));
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

  // Gotowość łączy urządzenie (81) z check-inem (54): 0.7*81 + 0.3*54 = 73.
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
  assert.equal(combined.json.readiness.score, 73, JSON.stringify(combined.json.readiness));
  log('gotowość urządzenie + check-in', true, '73/100');

  const del = await req('/api/wearables/apple', {
    method: 'DELETE',
    headers: { Origin: ORIGIN }
  });
  assert.equal(del.res.status, 200, del.text);
  const cleared = await req('/api/wearables');
  assert.equal(cleared.json.devices.apple.connected, false);
  assert.equal(cleared.json.series.length, 0);
  log('Apple: odłączenie usuwa dane', true);

  await clearCheckins();
  await runUiChecks();

  const joinedLogs = serverLogs.join('');
  assert.ok(!joinedLogs.includes('fake-access'), 'logi nie zawierają access tokenu');
  assert.ok(!joinedLogs.includes('fake-refresh'), 'logi nie zawierają refresh tokenu');
  log('logi serwera bez tokenów', true);

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
  stopServer(appServer);
  stopServer(fakeServer);
}
