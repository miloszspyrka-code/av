#!/usr/bin/env node
/**
 * E2E izolacji danych między użytkownikami.
 *
 * Dwa niezależne "browsery" (jary cookie) logują się demo i dostają różne
 * konta. Test sprawdza stan dziennika, tokeny MCP oraz blokadę zapisu stanu
 * z karty należącej do innego konta.
 *
 * Wymaga działającego serwera: APP_URL (domyślnie http://127.0.0.1:4173).
 */
import assert from 'node:assert/strict';

const BASE = process.env.APP_URL || 'http://127.0.0.1:4173';
const ORIGIN = new URL(BASE).origin;
const results = [];

function log(name, ok, info = '') {
  results.push({ name, ok, info });
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${info ? '  · ' + info : ''}`);
}

class Jar {
  constructor() { this.cookies = new Map(); }
  store(res) {
    const list = res.headers.getSetCookie ? res.headers.getSetCookie() : [res.headers.get('set-cookie') || ''];
    for (const raw of list) {
      const part = String(raw || '').split(';')[0];
      const i = part.indexOf('=');
      if (i <= 0) continue;
      const name = part.slice(0, i).trim();
      const value = part.slice(i + 1).trim();
      if (value === '') this.cookies.delete(name);
      else this.cookies.set(name, value);
    }
  }
  header() { return [...this.cookies].map(([k, v]) => `${k}=${v}`).join('; '); }
  get(name) { return this.cookies.get(name) || ''; }
}

async function call(jar, path, opts = {}) {
  const res = await fetch(BASE + path, {
    redirect: 'manual',
    ...opts,
    headers: {
      ...(opts.body ? { 'Content-Type': 'application/json' } : {}),
      ...(opts.method && opts.method !== 'GET' ? { Origin: ORIGIN } : {}),
      ...(jar.header() ? { Cookie: jar.header() } : {}),
      ...(opts.headers || {})
    }
  });
  jar.store(res);
  const text = await res.text();
  let json = null;
  try { json = text ? JSON.parse(text) : null; } catch {}
  return { res, text, json };
}

async function demoLogin(jar) {
  const r = await call(jar, '/auth/demo', { method: 'POST', body: JSON.stringify({ remember: true }) });
  assert.equal(r.res.status, 200, r.text);
  assert.ok(r.json.user?.id, 'brak id użytkownika demo');
  return r.json.user;
}

async function getState(jar) {
  const r = await call(jar, '/api/state');
  assert.equal(r.res.status, 200, r.text);
  return r.json;
}

async function putState(jar, state, baseRevision, ownerId) {
  return call(jar, '/api/state', {
    method: 'PUT',
    body: JSON.stringify({ state, baseRevision, ownerId })
  });
}

async function main() {
  console.log('Alpha Vault izolacja użytkowników ·', BASE);
  const health = await call(new Jar(), '/api/health');
  assert.equal(health.res.status, 200, health.text);

  const jarA = new Jar();
  const jarB = new Jar();
  const userA = await demoLogin(jarA);
  const userB = await demoLogin(jarB);
  assert.notEqual(userA.id, userB.id, 'dwa demo nie mogą dzielić konta');
  assert.notEqual(jarA.get('av_demo'), jarB.get('av_demo'), 'brak osobnego cookie demo');
  log('demo A i B mają osobne konta', true, `A=${userA.id} B=${userB.id}`);

  const sameA = await demoLogin(jarA);
  assert.equal(sameA.id, userA.id, 'ponowne logowanie tej samej przeglądarki ma wrócić do tego samego demo');
  log('powrót do demo w tej samej przeglądarce', true);

  const meA = await call(jarA, '/api/me');
  const meB = await call(jarB, '/api/me');
  assert.equal(meA.json.user?.id, userA.id);
  assert.equal(meB.json.user?.id, userB.id);
  log('/api/me zwraca właściwe konto', true);

  const markerA = 'iso-A-' + Date.now();
  const markerB = 'iso-B-' + Date.now();
  const stateA = await getState(jarA);
  const stateB = await getState(jarB);
  assert.equal(stateA.userId, userA.id);
  assert.equal(stateB.userId, userB.id);
  assert.ok(stateA.state && stateB.state, 'demo powinno mieć seed stanu');
  stateA.state.isolation = { marker: markerA };
  stateB.state.isolation = { marker: markerB };
  stateA.state.user = { ...(stateA.state.user || {}), discordId: userA.id };
  stateB.state.user = { ...(stateB.state.user || {}), discordId: userB.id };

  const saveA = await putState(jarA, stateA.state, stateA.revision, userA.id);
  assert.equal(saveA.res.status, 200, saveA.text);
  const saveB = await putState(jarB, stateB.state, stateB.revision, userB.id);
  assert.equal(saveB.res.status, 200, saveB.text);

  const readA = await getState(jarA);
  const readB = await getState(jarB);
  assert.equal(readA.state.isolation.marker, markerA);
  assert.equal(readB.state.isolation.marker, markerB);
  assert.notEqual(readA.state.isolation.marker, markerB);
  assert.notEqual(readB.state.isolation.marker, markerA);
  log('stan dziennika jest rozdzielony', true);

  // Karta A nie może zapisać stanu, gdy w międzyczasie zalogowano konto B.
  const staleState = JSON.parse(JSON.stringify(readA.state));
  staleState.isolation = { marker: 'stale-' + Date.now() };
  const stale = await putState(jarA, staleState, readA.revision, userB.id);
  assert.equal(stale.res.status, 409, stale.text);
  assert.equal(stale.json.error, 'session_changed');
  const afterStale = await getState(jarA);
  assert.equal(afterStale.state.isolation.marker, markerA, 'odrzucony zapis nie może zmienić stanu');
  log('zapis z obcej karty zablokowany (409)', true);

  // Starszy klient bez ownerId też nie może zapisać stanu A do sesji B.
  const withoutOwner = JSON.parse(JSON.stringify(readA.state));
  withoutOwner.isolation = { marker: 'legacy-' + Date.now() };
  const legacy = await putState(jarB, withoutOwner, readB.revision, undefined);
  assert.equal(legacy.res.status, 409, legacy.text);
  assert.equal(legacy.json.error, 'session_changed');
  log('stan z obcym user.discordId zablokowany', true);

  const connA = await call(jarA, '/api/connections');
  const connB = await call(jarB, '/api/connections');
  assert.equal(connA.res.status, 200);
  assert.equal(connB.res.status, 200);
  assert.ok(Array.isArray(connA.json.connections));
  assert.ok(Array.isArray(connB.json.connections));
  log('integracje mają osobne listy połączeń', true, `A=${connA.json.connections.length} B=${connB.json.connections.length}`);

  const mcpA = await call(jarA, '/api/mcp');
  const mcpB = await call(jarB, '/api/mcp');
  assert.equal(mcpA.res.status, 200, mcpA.text);
  assert.equal(mcpB.res.status, 200, mcpB.text);
  assert.ok(Array.isArray(mcpA.json.proposals));
  assert.ok(Array.isArray(mcpB.json.proposals));
  assert.equal('tokens' in mcpA.json, false, 'stary endpoint tokenów nie może wracać');
  assert.equal(mcpA.json.proposals.length, 0);
  assert.equal(mcpB.json.proposals.length, 0);
  const mcpAnon = await call(new Jar(), '/api/mcp');
  assert.equal(mcpAnon.res.status, 401);
  log('panel MCP ma osobne propozycje i wymaga sesji', true);

  await call(jarA, '/auth/logout', { method: 'POST' });
  const afterLogout = await call(new Jar(), '/api/state');
  assert.equal(afterLogout.res.status, 401, 'po wylogowaniu stan musi być niedostępny');
  const stillB = await getState(jarB);
  assert.equal(stillB.state.isolation.marker, markerB);
  log('wylogowanie A nie wpływa na B', true);

  const failed = results.filter((r) => !r.ok).length;
  console.log(`\nIzolacja E2E: ${results.length - failed}/${results.length} PASS`);
  if (failed) process.exitCode = 1;
}

try {
  await main();
} catch (err) {
  console.error('FAIL  izolacja E2E:', err?.stack || err);
  process.exitCode = 1;
}
