#!/usr/bin/env node
/**
 * AlphaVault MCP E2E: OAuth 2.1 + PKCE, DCR, consent, token/refresh/revoke,
 * 8 read-only tools, user isolation, handshake and modern MCP eras.
 *
 * Wymaga MySQL zgodnego z tests/config.test.json oraz PHP z rozszerzeniami
 * sodium, openssl, pdo_mysql. Ustaw PHP_BIN, jeśli php nie jest w PATH.
 */
import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { spawn, spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const PHP = process.env.PHP_BIN || 'php';
const PHP_ARGS = (process.env.PHP_ARGS || '').split(/\s+/).filter(Boolean);
const CONFIG = process.env.ALPHA_VAULT_CONFIG || path.join(ROOT, 'tests', 'config.test.json');
const REMOTE = process.env.MCP_E2E_REMOTE === '1';
const PORT = Number(process.env.MCP_TEST_PORT || 4174);
const BASE = REMOTE ? (process.env.MCP_BASE || 'https://mcp.alphavault.pl') : `http://127.0.0.1:${PORT}`;
const SESSIONS_FILE = path.join(ROOT, 'tests', 'mcp-test-sessions.json');
const LOCAL_ENV = REMOTE ? {} : {
  APP_URL: BASE,
  SESSION_COOKIE: 'mcp_session',
  DISCORD_REDIRECT_URI: BASE + '/oauth/discord/callback',
  MCP_ENABLED: 'true',
  MCP_WRITES_ENABLED: 'false',
  MCP_ISSUER: BASE,
  MCP_RESOURCE: BASE + '/mcp',
  MCP_ALLOWED_REDIRECT_HOSTS: 'chatgpt.com,localhost,127.0.0.1',
};

function runPhp(script) {
  const result = spawnSync(PHP, [...PHP_ARGS, script], {
    cwd: ROOT,
    env: { ...process.env, ALPHA_VAULT_CONFIG: CONFIG, ...LOCAL_ENV },
    encoding: 'utf8',
  });
  if (result.status !== 0) {
    throw new Error(`${path.basename(script)} failed:\n${result.stdout}\n${result.stderr}`);
  }
  return result.stdout.trim();
}

async function waitForHealth(timeoutMs = 15000) {
  const started = Date.now();
  while (Date.now() - started < timeoutMs) {
    try {
      const res = await fetch(BASE + '/health');
      if (res.ok) return;
    } catch {}
    await new Promise((resolve) => setTimeout(resolve, 250));
  }
  throw new Error('MCP server nie odpowiedział na /health.');
}

function b64url(buf) {
  return Buffer.from(buf).toString('base64url');
}

function pkce() {
  const verifier = b64url(crypto.randomBytes(48));
  const challenge = b64url(crypto.createHash('sha256').update(verifier).digest());
  return { verifier, challenge };
}

async function req(pathname, opts = {}) {
  const res = await fetch(BASE + pathname, { redirect: 'manual', ...opts });
  const text = await res.text();
  let json = null;
  try { json = text ? JSON.parse(text) : null; } catch {}
  return { res, text, json };
}

async function register(name = 'MCP E2E Client', redirect = 'https://chatgpt.com/connector_platform_oauth_redirect', authMethod = 'none') {
  const r = await req('/oauth/register', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      client_name: name,
      redirect_uris: [redirect],
      token_endpoint_auth_method: authMethod,
    }),
  });
  assert.equal(r.res.status, 201, r.text);
  assert.ok(r.json.client_id);
  return r.json;
}

function parseConsent(html) {
  const consent = /name="consent_id" value="([^"]+)"/.exec(html);
  const csrf = /name="csrf" value="([^"]+)"/.exec(html);
  assert.ok(consent && csrf, 'brak pól consent');
  return { consentId: consent[1], csrf: csrf[1] };
}

async function authorize(client, session, scope = 'alphavault.read') {
  const { verifier, challenge } = pkce();
  const state = 'state-' + crypto.randomBytes(8).toString('hex');
  const q = new URLSearchParams({
    response_type: 'code',
    client_id: client.client_id,
    redirect_uri: client.redirect_uris[0],
    scope,
    state,
    code_challenge: challenge,
    code_challenge_method: 'S256',
    resource: BASE + '/mcp',
  });
  const g = await req('/oauth/authorize?' + q.toString(), {
    headers: { Cookie: 'mcp_session=' + session },
  });
  assert.equal(g.res.status, 200, g.text);
  const { consentId, csrf } = parseConsent(g.text);
  const p = await req('/oauth/authorize', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
      Cookie: 'mcp_session=' + session,
    },
    body: new URLSearchParams({ consent_id: consentId, csrf, decision: 'allow' }).toString(),
  });
  assert.equal(p.res.status, 302, p.text);
  const loc = new URL(p.res.headers.get('location'));
  assert.equal(loc.searchParams.get('state'), state);
  assert.equal(loc.searchParams.get('iss'), BASE);
  const code = loc.searchParams.get('code');
  assert.ok(code, 'brak code');
  return { code, verifier, redirectUri: client.redirect_uris[0] };
}

async function token(client, auth) {
  const body = new URLSearchParams({
    grant_type: 'authorization_code',
    client_id: client.client_id,
    redirect_uri: auth.redirectUri,
    code: auth.code,
    code_verifier: auth.verifier,
    resource: BASE + '/mcp',
  });
  if (client.client_secret) body.set('client_secret', client.client_secret);
  const r = await req('/oauth/token', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: body.toString(),
  });
  assert.equal(r.res.status, 200, r.text);
  assert.ok(r.json.access_token && r.json.refresh_token, r.text);
  assert.equal(r.json.token_type, 'Bearer');
  assert.equal(r.json.scope, 'alphavault.read');
  return r.json;
}

async function refresh(client, refreshToken) {
  return req('/oauth/token', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      grant_type: 'refresh_token',
      client_id: client.client_id,
      refresh_token: refreshToken,
      resource: BASE + '/mcp',
    }).toString(),
  });
}

function jwtPayload(tokenValue) {
  return JSON.parse(Buffer.from(tokenValue.split('.')[1], 'base64url').toString('utf8'));
}

let rpcId = 1;
async function rpc(tokenValue, method, params = {}) {
  return req('/mcp', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json, text/event-stream',
      ...(tokenValue ? { Authorization: 'Bearer ' + tokenValue } : {}),
    },
    body: JSON.stringify({ jsonrpc: '2.0', id: rpcId++, method, params }),
  });
}

async function main() {
  const sessions = JSON.parse(fs.readFileSync(SESSIONS_FILE, 'utf8'));
  const a = sessions['mcp-e2e-a'];
  const b = sessions['mcp-e2e-b'];
  const client = await register();

  const noToken = await rpc('', 'tools/list', {});
  assert.equal(noToken.res.status, 401);
  assert.match(noToken.res.headers.get('www-authenticate') || '', /resource_metadata=/);

  const authA = await authorize(client, a.session);
  const tokA = await token(client, authA);
  const claimsA = jwtPayload(tokA.access_token);
  assert.equal(claimsA.aud, BASE + '/mcp');
  assert.equal(claimsA.iss, BASE);
  assert.equal(claimsA.scope, 'alphavault.read');
  assert.equal(claimsA.sub, String(a.userId));

  const init = await rpc(tokA.access_token, 'initialize', {
    protocolVersion: '2025-06-18',
    capabilities: {},
    clientInfo: { name: 'test', version: '1' },
  });
  assert.equal(init.res.status, 200, init.text);
  assert.equal(init.json.result.serverInfo.name, 'alphavault-mcp');

  const list = await rpc(tokA.access_token, 'tools/list', {});
  assert.equal(list.res.status, 200, list.text);
  const tools = list.json.result.tools;
  const names = tools.map((t) => t.name).sort();
  assert.deepEqual(names, [
    'get_dashboard', 'get_day_plan', 'get_events', 'get_profile', 'get_stats', 'get_trade', 'list_setups', 'list_trades',
  ]);
  assert.equal(tools.some((t) => t.name.includes('create') || t.name.includes('close')), false);
  for (const tool of tools) {
    assert.ok(tool.inputSchema, tool.name + ' inputSchema');
    assert.ok(tool.outputSchema, tool.name + ' outputSchema');
    assert.equal(tool.annotations?.readOnlyHint, true, tool.name + ' readOnlyHint');
    assert.ok(Array.isArray(tool._meta?.securitySchemes), tool.name + ' securitySchemes');
  }
  assert.equal(tools.find((t) => t.name === 'get_profile')?._meta?.['openai/profile'], true);

  const profile = await rpc(tokA.access_token, 'tools/call', { name: 'get_profile', arguments: {} });
  assert.equal(profile.res.status, 200, profile.text);
  assert.equal(profile.json.result.structuredContent.id, 'av_' + a.userId);
  assert.equal(profile.json.result.structuredContent.isAdmin, false);

  const dashboard = await rpc(tokA.access_token, 'tools/call', { name: 'get_dashboard', arguments: {} });
  assert.equal(dashboard.res.status, 200, dashboard.text);
  assert.equal(dashboard.json.result.structuredContent.date, new Date().toLocaleDateString('sv-SE', { timeZone: 'Europe/Warsaw' }));
  assert.equal(typeof dashboard.json.result.structuredContent.today.pnl, 'number');

  const dayPlan = await rpc(tokA.access_token, 'tools/call', { name: 'get_day_plan', arguments: {} });
  assert.equal(dayPlan.res.status, 200, dayPlan.text);
  assert.ok('plan' in dayPlan.json.result.structuredContent);

  const setups = await rpc(tokA.access_token, 'tools/call', { name: 'list_setups', arguments: {} });
  assert.equal(setups.res.status, 200, setups.text);
  assert.equal(setups.json.result.structuredContent.total, 5);

  const tradesA = await rpc(tokA.access_token, 'tools/call', { name: 'list_trades', arguments: { limit: 100 } });
  assert.equal(tradesA.res.status, 200, tradesA.text);
  assert.ok(tradesA.json.result.structuredContent.total > 1, 'konto A ma transakcje');
  const tradeAId = tradesA.json.result.structuredContent.items[0].id;
  const ownTrade = await rpc(tokA.access_token, 'tools/call', { name: 'get_trade', arguments: { id: tradeAId } });
  assert.equal(ownTrade.json.result.structuredContent.found, true);

  const stats = await rpc(tokA.access_token, 'tools/call', { name: 'get_stats', arguments: { period: 'all' } });
  assert.equal(stats.res.status, 200, stats.text);
  assert.equal(stats.json.result.structuredContent.currency, 'USDT');
  assert.ok(typeof stats.json.result.structuredContent.net === 'number');

  const events = await rpc(tokA.access_token, 'tools/call', { name: 'get_events', arguments: { limit: 5 } });
  assert.equal(events.res.status, 200, events.text);
  assert.ok(Array.isArray(events.json.result.structuredContent.events));

  const badTool = await rpc(tokA.access_token, 'tools/call', { name: 'create_trade', arguments: {} });
  assert.equal(badTool.res.status, 200, badTool.text);
  assert.equal(badTool.json.error?.code, -32602, badTool.text);

  const badPeriod = await rpc(tokA.access_token, 'tools/call', { name: 'get_stats', arguments: { period: '1y' } });
  assert.equal(badPeriod.res.status, 200, badPeriod.text);
  assert.equal(badPeriod.json.error?.code, -32602, badPeriod.text);

  const badDate = await rpc(tokA.access_token, 'tools/call', { name: 'list_trades', arguments: { from: '2026-13-40' } });
  assert.equal(badDate.res.status, 200, badDate.text);
  assert.equal(badDate.json.result?.isError, true, badDate.text);

  const modernMeta = {
    'io.modelcontextprotocol/protocolVersion': '2026-07-28',
    'io.modelcontextprotocol/clientCapabilities': {},
    'io.modelcontextprotocol/clientInfo': { name: 'test', version: '1' },
  };
  const modernList = await req('/mcp', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json, text/event-stream',
      Authorization: 'Bearer ' + tokA.access_token,
      'MCP-Protocol-Version': '2026-07-28',
      'Mcp-Method': 'tools/list',
    },
    body: JSON.stringify({ jsonrpc: '2.0', id: 900, method: 'tools/list', params: { _meta: modernMeta } }),
  });
  assert.equal(modernList.res.status, 200, modernList.text);
  assert.ok(Array.isArray(modernList.json.result.tools));

  const modernCall = await req('/mcp', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json, text/event-stream',
      Authorization: 'Bearer ' + tokA.access_token,
      'MCP-Protocol-Version': '2026-07-28',
      'Mcp-Method': 'tools/call',
      'Mcp-Name': 'get_profile',
    },
    body: JSON.stringify({
      jsonrpc: '2.0',
      id: 901,
      method: 'tools/call',
      params: { name: 'get_profile', arguments: {}, _meta: modernMeta },
    }),
  });
  assert.equal(modernCall.res.status, 200, modernCall.text);
  assert.equal(modernCall.json.result.structuredContent.id, 'av_' + a.userId);

  const authB = await authorize(client, b.session);
  const tokB = await token(client, authB);
  const tradesB = await rpc(tokB.access_token, 'tools/call', { name: 'list_trades', arguments: { limit: 100 } });
  assert.equal(tradesB.res.status, 200, tradesB.text);
  assert.equal(tradesB.json.result.structuredContent.total, 1, JSON.stringify(tradesB.json.result.structuredContent));
  assert.equal(tradesB.json.result.structuredContent.items[0].id, 'trade-b-only');
  const cross = await rpc(tokB.access_token, 'tools/call', { name: 'get_trade', arguments: { id: tradeAId } });
  assert.equal(cross.res.status, 200, cross.text);
  assert.equal(cross.json.result.structuredContent.found, false);

  const confidential = await register('MCP E2E Confidential', 'https://chatgpt.com/connector_platform_oauth_redirect', 'client_secret_post');
  assert.ok(confidential.client_secret, 'brak client_secret');
  const authConf = await authorize(confidential, a.session);
  const tokConf = await token(confidential, authConf);
  const confList = await rpc(tokConf.access_token, 'tools/list', {});
  assert.equal(confList.res.status, 200, confList.text);

  const refreshed = await refresh(client, tokA.refresh_token);
  assert.equal(refreshed.res.status, 200, refreshed.text);
  assert.ok(refreshed.json.access_token && refreshed.json.refresh_token);
  assert.notEqual(refreshed.json.access_token, tokA.access_token);
  const reuse = await refresh(client, tokA.refresh_token);
  assert.equal(reuse.res.status, 400, reuse.text);
  assert.equal(reuse.json.error, 'invalid_grant');

  const afterOldAccess = await rpc(tokA.access_token, 'tools/list', {});
  assert.equal(afterOldAccess.res.status, 401, afterOldAccess.text);

  const revoke = await req('/oauth/revoke', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ token: refreshed.json.access_token }).toString(),
  });
  assert.equal(revoke.res.status, 200, revoke.text);
  const afterRevoke = await rpc(refreshed.json.access_token, 'tools/list', {});
  assert.equal(afterRevoke.res.status, 401, afterRevoke.text);

  const noSession = await req('/oauth/authorize?' + new URLSearchParams({
    response_type: 'code',
    client_id: client.client_id,
    redirect_uri: client.redirect_uris[0],
    scope: 'alphavault.read',
    code_challenge: pkce().challenge,
    code_challenge_method: 'S256',
    resource: BASE + '/mcp',
  }).toString());
  assert.equal(noSession.res.status, 302, noSession.text);
  const loginUrl = new URL(noSession.res.headers.get('location'), BASE);
  assert.equal(loginUrl.pathname, '/oauth/login');
  const loginCookie = (noSession.res.headers.getSetCookie?.() || []).find((c) => c.startsWith('mcp_login='));
  assert.ok(loginCookie, 'brak cookie mcp_login');
  const loginPage = await req(loginUrl.pathname + loginUrl.search, {
    headers: { Cookie: loginCookie.split(';')[0] },
  });
  assert.equal(loginPage.res.status, 200, loginPage.text);
  assert.match(loginPage.text, /Alpha Vault/);
  const startMatch = /href="([^"]*\/oauth\/login\/start\?req=[^"]+)"/.exec(loginPage.text);
  assert.ok(startMatch, 'brak linku startu logowania');
  const loginStart = await req(startMatch[1].replace(/&amp;/g, '&'), {
    headers: { Cookie: loginCookie.split(';')[0] },
  });
  assert.equal(loginStart.res.status, 302, loginStart.text);
  const discordUrl = new URL(loginStart.res.headers.get('location'));
  assert.equal(discordUrl.hostname, 'discord.com');
  assert.equal(discordUrl.searchParams.get('redirect_uri'), BASE + '/oauth/discord/callback');

  console.log('MCP E2E PASS');
}

runPhp(path.join(ROOT, 'tests', 'seed-mcp.php'));
runPhp(path.join(ROOT, 'tests', 'mcp-user-service.php'));

let server = null;
let serverLog = '';
if (!REMOTE) {
  server = spawn(
    PHP,
    [...PHP_ARGS, '-S', `127.0.0.1:${PORT}`, '-t', path.join(ROOT, 'mcp', 'public'), path.join(ROOT, 'mcp', 'public', 'index.php')],
    {
      cwd: ROOT,
      env: { ...process.env, ALPHA_VAULT_CONFIG: CONFIG, ...LOCAL_ENV },
      stdio: ['ignore', 'pipe', 'pipe'],
    }
  );
  server.stdout.on('data', (chunk) => { serverLog += chunk.toString(); });
  server.stderr.on('data', (chunk) => { serverLog += chunk.toString(); });
}

try {
  if (!REMOTE) await waitForHealth();
  await main();
} catch (err) {
  console.error(serverLog);
  throw err;
} finally {
  if (server) server.kill('SIGTERM');
  runPhp(path.join(ROOT, 'tests', 'cleanup-mcp.php'));
}
