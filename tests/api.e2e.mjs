#!/usr/bin/env node
/**
 * Alpha Vault — testy API e2e (MySQL + sesje + Discord/demo).
 * Uruchom: npm test
 * Wymaga działającego serwera: npm start
 */
import assert from 'node:assert/strict';

const BASE = process.env.APP_URL || 'http://127.0.0.1:4173';
const ORIGIN = new URL(BASE).origin;
let cookie = '';
const results = [];

function log(name, ok, info = '') {
  results.push({ name, ok, info });
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${info ? '  · ' + info : ''}`);
}

function cookieFrom(res) {
  const list = res.headers.getSetCookie ? res.headers.getSetCookie() : [res.headers.get('set-cookie') || ''];
  for (const c of list) {
    const m = /^av_session=([^;]+)/.exec(c || '');
    if (m) return 'av_session=' + m[1];
  }
  return '';
}

async function req(path, opts = {}) {
  const res = await fetch(BASE + path, {
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

async function main() {
  console.log('Alpha Vault API e2e ·', BASE);
  const health = await req('/api/health');
  assert.equal(health.res.status, 200, 'health');
  assert.equal(health.json.ok, true);
  assert.equal(health.json.db.ok, true);
  assert.ok(health.json.sync && typeof health.json.sync.status === 'string', 'health.sync');
  assert.equal(typeof health.json.sync.stale, 'boolean');
  log('health + MySQL', true, health.json.db.version + ' · sync=' + health.json.sync.status);

  const anon = await req('/api/me');
  assert.equal(anon.res.status, 200);
  assert.equal(anon.json.authenticated, false);
  log('me bez sesji', true);

  const unauthorized = await req('/api/state');
  assert.equal(unauthorized.res.status, 401);
  log('state bez sesji = 401', true);

  const eventsAnon = await req('/api/events');
  assert.equal(eventsAnon.res.status, 401);
  log('events bez sesji = 401', true);

  const demo = await req('/auth/demo', {
    method: 'POST',
    body: JSON.stringify({ remember: true }),
    headers: { Origin: ORIGIN }
  });
  if (demo.res.status === 403) {
    log('demo login wyłączony — pomijam resztę', true);
    return;
  }
  assert.equal(demo.res.status, 200, demo.text);
  cookie = cookieFrom(demo.res);
  assert.ok(cookie, 'brak cookie sesji');
  const setCookie = (demo.res.headers.getSetCookie ? demo.res.headers.getSetCookie() : []).join(';');
  assert.match(setCookie, /HttpOnly/i);
  assert.match(setCookie, /SameSite=Lax/i);
  log('demo login', true, 'cookie HttpOnly + SameSite=Lax');

  const discordStart = await req('/auth/discord?remember=1');
  if (anon.json.discordConfigured) {
    assert.equal(discordStart.res.status, 302);
    assert.match(discordStart.res.headers.get('location') || '', /discord\.com\/api\/oauth2\/authorize/);
    log('OAuth start → Discord', true);
  } else {
    assert.equal(discordStart.res.status, 503);
    log('OAuth start bez konfiguracji = 503', true);
  }
  const badCallback = await req('/auth/discord/callback?code=x&state=y');
  assert.equal(badCallback.res.status, 400);
  log('callback z złym state = 400', true);

  const me = await req('/api/me');
  assert.equal(me.json.authenticated, true);
  assert.equal(me.json.user.isDemo, true);
  log('me z sesją', true, me.json.user.username);

  const events = await req('/api/events');
  assert.equal(events.res.status, 200, events.text);
  assert.ok(Array.isArray(events.json.events));
  assert.ok(events.json.sync && typeof events.json.sync.status === 'string');
  log('events z sesją', true, events.json.events.length + ' wydarzeń');

  const adminEvents = await req('/api/admin/events');
  assert.equal(adminEvents.res.status, 403);
  log('admin events bez uprawnień = 403', true);

  const state = await req('/api/state');
  assert.equal(state.res.status, 200);
  assert.ok(state.json.state, 'brak stanu demo');
  assert.ok(Array.isArray(state.json.state.trades));
  log('GET /api/state', true, `rewizja ${state.json.revision}, transakcje ${state.json.state.trades.length}`);

  const originalState = state.json.state;
  const marker = 'e2e-' + Date.now();
  originalState.meta = originalState.meta || {};
  originalState.meta.e2e = marker;
  const put = await req('/api/state', {
    method: 'PUT',
    body: JSON.stringify({ state: originalState, baseRevision: state.json.revision }),
    headers: { Origin: ORIGIN }
  });
  assert.equal(put.res.status, 200, put.text);
  assert.ok(put.json.revision > state.json.revision);
  log('PUT /api/state', true, 'rewizja ' + put.json.revision);

  const saved = await req('/api/state');
  assert.equal(saved.json.state.meta.e2e, marker);
  assert.equal(saved.json.revision, put.json.revision);
  log('odczyt po zapisie (MySQL)', true);

  const conflict = await req('/api/state', {
    method: 'PUT',
    body: JSON.stringify({ state: originalState, baseRevision: state.json.revision }),
    headers: { Origin: ORIGIN }
  });
  assert.equal(conflict.res.status, 409);
  log('konflikt rewizji = 409', true);

  const badOrigin = await req('/api/state', {
    method: 'PUT',
    body: JSON.stringify({ state: originalState, baseRevision: saved.json.revision }),
    headers: { Origin: 'https://evil.example' }
  });
  assert.equal(badOrigin.res.status, 403);
  log('CSRF / złe Origin = 403', true);

  const index = await req('/');
  assert.equal(index.res.status, 200);
  assert.ok(index.text.includes('Alpha Vault'));
  log('GET / (UI)', true);

  const docs = await req('/docs/API.md');
  assert.equal(docs.res.status, 200);
  log('GET /docs/API.md', true);

  const exportRes = await req('/api/me/export');
  assert.equal(exportRes.res.status, 200);
  assert.match(exportRes.res.headers.get('content-disposition') || '', /attachment/);
  assert.ok(exportRes.json.state);
  log('GET /api/me/export', true);

  // przywróć stan bez markera
  delete originalState.meta.e2e;
  const restore = await req('/api/state', {
    method: 'PUT',
    body: JSON.stringify({ state: originalState, baseRevision: saved.json.revision }),
    headers: { Origin: ORIGIN }
  });
  assert.equal(restore.res.status, 200, restore.text);
  log('przywrócenie stanu demo', true, 'rewizja ' + restore.json.revision);

  const logout = await req('/auth/logout', { method: 'POST', headers: { Origin: ORIGIN }, body: '{}' });
  assert.equal(logout.res.status, 200);
  cookie = '';
  const after = await req('/api/me');
  assert.equal(after.json.authenticated, false);
  const afterState = await req('/api/state');
  assert.equal(afterState.res.status, 401);
  log('logout + unieważnienie sesji', true);

  const failed = results.filter((r) => !r.ok);
  console.log(`\n${results.length - failed.length}/${results.length} PASS`);
  if (failed.length) process.exitCode = 1;
}

main().catch((err) => {
  console.error('\nFAIL  test przerwany:', err.message);
  process.exitCode = 1;
});
