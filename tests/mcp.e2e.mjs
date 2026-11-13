#!/usr/bin/env node
/**
 * AlphaVault MCP 2.0 E2E: OAuth 2.1 + PKCE, DCR, consent, token/refresh/revoke,
 * 15 narzędzi odczytu, 7 narzędzi propozycji, prompts, izolacja użytkowników
 * i tryb MCP_WRITES_ENABLED=false/true.
 *
 * Wymaga MySQL z tests/config.test.json oraz PHP z sodium, openssl, pdo_mysql.
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
const PORT = Number(process.env.MCP_TEST_PORT || 4176);
const BASE = `http://127.0.0.1:${PORT}`;
const SESSIONS_FILE = path.join(ROOT, 'tests', 'mcp-test-sessions.json');
const LOCAL_ENV = {
  APP_URL: BASE,
  SESSION_COOKIE: 'mcp_session',
  DISCORD_REDIRECT_URI: BASE + '/oauth/discord/callback',
  MCP_ENABLED: 'true',
  MCP_ISSUER: BASE,
  MCP_RESOURCE: BASE + '/mcp',
  MCP_ALLOWED_REDIRECT_HOSTS: 'chatgpt.com,localhost,127.0.0.1',
  MCP_PRIVATE_KEY_PATH: path.join(ROOT, 'tests', 'mcp-keys', 'oauth-private.pem'),
  MCP_PUBLIC_KEY_PATH: path.join(ROOT, 'tests', 'mcp-keys', 'oauth-public.pem'),
  MCP_ENCRYPTION_KEY_PATH: path.join(ROOT, 'tests', 'mcp-keys', 'oauth-encryption.key'),
  MCP_LOG_FILE: path.join(ROOT, 'tests', 'mcp.log'),
};

const READ_TOOLS = [
  'get_accounts', 'get_capital_summary', 'get_checkin_history', 'get_connection_status',
  'get_dashboard', 'get_day_plan', 'get_events', 'get_macro_calendar', 'get_profile',
  'get_prop_challenges', 'get_stats', 'get_trade', 'get_trade_context', 'list_setups', 'list_trades',
];
const WRITE_TOOLS = [
  'propose_close_trade', 'propose_create_trade', 'propose_save_checkin', 'propose_save_day_plan',
  'propose_save_prop_challenge', 'propose_save_setup', 'propose_trade_review',
];

function runPhp(script, args = []) {
  const result = spawnSync(PHP, [...PHP_ARGS, script, ...args], {
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
  return { verifier, challenge: b64url(crypto.createHash('sha256').update(verifier).digest()) };
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
    body: JSON.stringify({ client_name: name, redirect_uris: [redirect], token_endpoint_auth_method: authMethod }),
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

async function authorize(client, session, scope = 'alphavault.read', grantWrite = false) {
  const { verifier, challenge } = pkce();
  const state = 'state-' + crypto.randomBytes(8).toString('hex');
  const q = new URLSearchParams({
    response_type: 'code', client_id: client.client_id, redirect_uri: client.redirect_uris[0],
    scope, state, code_challenge: challenge, code_challenge_method: 'S256', resource: BASE + '/mcp',
  });
  const g = await req('/oauth/authorize?' + q.toString(), { headers: { Cookie: 'mcp_session=' + session } });
  assert.equal(g.res.status, 200, g.text);
  if (grantWrite) {
    assert.match(g.text, /name="grant_write"/, 'brak checkboxa write');
  }
  const { consentId, csrf } = parseConsent(g.text);
  const body = new URLSearchParams({ consent_id: consentId, csrf, decision: 'allow' });
  if (grantWrite) body.set('grant_write', '1');
  const p = await req('/oauth/authorize', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', Cookie: 'mcp_session=' + session },
    body: body.toString(),
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
    grant_type: 'authorization_code', client_id: client.client_id, redirect_uri: auth.redirectUri,
    code: auth.code, code_verifier: auth.verifier, resource: BASE + '/mcp',
  });
  if (client.client_secret) body.set('client_secret', client.client_secret);
  const r = await req('/oauth/token', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() });
  assert.equal(r.res.status, 200, r.text);
  assert.ok(r.json.access_token && r.json.refresh_token, r.text);
  assert.equal(r.json.token_type, 'Bearer');
  return r.json;
}

async function refresh(client, refreshToken) {
  return req('/oauth/token', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ grant_type: 'refresh_token', client_id: client.client_id, refresh_token: refreshToken, resource: BASE + '/mcp' }).toString(),
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

async function call(tokenValue, name, args = {}) {
  return rpc(tokenValue, 'tools/call', { name, arguments: args });
}

function toolError(r) {
  return r.json?.result?.isError ? String(r.json.result.content?.[0]?.text || '') : '';
}

async function main() {
  const sessions = JSON.parse(fs.readFileSync(SESSIONS_FILE, 'utf8'));
  const a = sessions['mcp-e2e-a'];
  const b = sessions['mcp-e2e-b'];
  const client = await register();

  // --- OAuth i discovery ---
  const noToken = await rpc('', 'tools/list', {});
  assert.equal(noToken.res.status, 401);
  assert.match(noToken.res.headers.get('www-authenticate') || '', /resource_metadata=/);
  const prm = await req('/.well-known/oauth-protected-resource');
  assert.equal(prm.res.status, 200);
  assert.equal(prm.json.resource, BASE + '/mcp');
  assert.ok(prm.json.scopes_supported.includes('alphavault.read'));
  assert.equal(prm.json.scopes_supported.includes('alphavault.write'), false);
  const asm = await req('/.well-known/oauth-authorization-server');
  assert.equal(asm.res.status, 200);
  assert.equal(asm.json.code_challenge_methods_supported[0], 'S256');
  const health = await req('/health');
  assert.equal(health.res.status, 200);
  assert.equal(health.json.mcp.tools, 15);
  assert.equal(health.json.mcp.writesEnabled, false);

  const authA = await authorize(client, a.session);
  const tokA = await token(client, authA);
  const claimsA = jwtPayload(tokA.access_token);
  assert.equal(claimsA.aud, BASE + '/mcp');
  assert.equal(claimsA.iss, BASE);
  assert.equal(claimsA.scope, 'alphavault.read');
  assert.equal(claimsA.sub, String(a.userId));

  const init = await rpc(tokA.access_token, 'initialize', { protocolVersion: '2025-06-18', capabilities: {}, clientInfo: { name: 'test', version: '1' } });
  assert.equal(init.res.status, 200, init.text);
  assert.equal(init.json.result.serverInfo.name, 'alphavault-mcp');

  const list = await rpc(tokA.access_token, 'tools/list', {});
  assert.equal(list.res.status, 200, list.text);
  const tools = list.json.result.tools;
  assert.deepEqual(tools.map((t) => t.name).sort(), READ_TOOLS);
  for (const tool of tools) {
    assert.ok(tool.inputSchema, tool.name + ' inputSchema');
    assert.ok(tool.outputSchema, tool.name + ' outputSchema');
    assert.equal(tool.annotations?.readOnlyHint, true, tool.name + ' readOnlyHint');
    assert.ok(Array.isArray(tool._meta?.securitySchemes), tool.name + ' securitySchemes');
    assert.equal(tool.inputSchema.additionalProperties, false, tool.name + ' additionalProperties');
  }
  assert.equal(tools.find((t) => t.name === 'get_profile')?._meta?.['openai/profile'], true);

  const prompts = await rpc(tokA.access_token, 'prompts/list', {});
  assert.equal(prompts.res.status, 200, prompts.text);
  assert.deepEqual(prompts.json.result.prompts.map((p) => p.name).sort(), ['prepare_trading_day', 'review_trade', 'weekly_review']);

  // --- Odczyt ---
  const profile = await call(tokA.access_token, 'get_profile');
  assert.equal(profile.json.result.structuredContent.id, 'av_' + a.userId);
  assert.equal(profile.json.result.structuredContent.isAdmin, false);

  const dashboard = await call(tokA.access_token, 'get_dashboard');
  assert.equal(dashboard.json.result.structuredContent.date, new Date().toLocaleDateString('sv-SE', { timeZone: 'Europe/Warsaw' }));
  assert.equal(typeof dashboard.json.result.structuredContent.today.pnl, 'number');

  const macro = await call(tokA.access_token, 'get_macro_calendar', { from: '2020-01-01', to: '2100-01-01' });
  assert.ok(macro.json.result.structuredContent.total >= 1, JSON.stringify(macro.json.result.structuredContent));
  assert.equal(macro.json.result.structuredContent.items[0].impact, 3);
  assert.equal('raw_payload' in macro.json.result.structuredContent.items[0], false);

  const prop = await call(tokA.access_token, 'get_prop_challenges');
  assert.equal(prop.json.result.structuredContent.total, 3, JSON.stringify(prop.json.result.structuredContent));
  assert.ok(prop.json.result.structuredContent.items[0].metrics, 'brak metrics');

  const accounts = await call(tokA.access_token, 'get_accounts');
  assert.ok(accounts.json.result.structuredContent.total > 0);
  assert.equal(JSON.stringify(accounts.json.result.structuredContent).includes('apiKey'), false);

  const capital = await call(tokA.access_token, 'get_capital_summary');
  assert.equal(capital.json.result.structuredContent.fxApproximate, true);
  assert.equal(typeof capital.json.result.structuredContent.capital, 'number');

  const connections = await call(tokA.access_token, 'get_connection_status');
  assert.ok(Array.isArray(connections.json.result.structuredContent.connections));
  assert.equal(JSON.stringify(connections.json.result.structuredContent).includes('credentials'), false);

  const checkins = await call(tokA.access_token, 'get_checkin_history');
  assert.ok(Array.isArray(checkins.json.result.structuredContent.items));

  const dayPlan = await call(tokA.access_token, 'get_day_plan');
  assert.ok('plan' in dayPlan.json.result.structuredContent);

  const setups = await call(tokA.access_token, 'list_setups');
  assert.equal(setups.json.result.structuredContent.total, 5);

  const tradesA = await call(tokA.access_token, 'list_trades', { limit: 100 });
  assert.ok(tradesA.json.result.structuredContent.total > 1);
  const tradeAId = tradesA.json.result.structuredContent.items[0].id;
  const ownTrade = await call(tokA.access_token, 'get_trade', { id: tradeAId });
  assert.equal(ownTrade.json.result.structuredContent.found, true);
  const context = await call(tokA.access_token, 'get_trade_context', { tradeId: tradeAId });
  assert.ok(context.json.result.structuredContent.trade);
  assert.ok(context.json.result.structuredContent.planMatch);

  const stats = await call(tokA.access_token, 'get_stats', { period: 'all' });
  assert.equal(stats.json.result.structuredContent.currency, 'USDT');
  assert.equal(typeof stats.json.result.structuredContent.net, 'number');

  const events = await call(tokA.access_token, 'get_events', { limit: 5 });
  assert.ok(Array.isArray(events.json.result.structuredContent.events));

  const badPeriod = await rpc(tokA.access_token, 'tools/call', { name: 'get_stats', arguments: { period: '1y' } });
  assert.equal(badPeriod.json.error?.code, -32602, badPeriod.text);
  const badDate = await call(tokA.access_token, 'list_trades', { from: '2026-13-40' });
  assert.ok(toolError(badDate).length > 0, badDate.text);
  const badTool = await call(tokA.access_token, 'propose_create_trade', { pair: 'BTC/USDT', dir: 'long', entry: 1, size: 1, idempotencyKey: 'idem-bad-tool' });
  assert.ok(badTool.res.status === 403 || badTool.json?.error?.code === -32602, badTool.text);

  const authB = await authorize(client, b.session);
  const tokB = await token(client, authB);
  const tradesB = await call(tokB.access_token, 'list_trades', { limit: 100 });
  assert.equal(tradesB.json.result.structuredContent.total, 1);
  assert.equal(tradesB.json.result.structuredContent.items[0].id, 'trade-b-only');
  const cross = await call(tokB.access_token, 'get_trade', { id: tradeAId });
  assert.equal(cross.json.result.structuredContent.found, false);

  const confidential = await register('MCP E2E Confidential', 'https://chatgpt.com/connector_platform_oauth_redirect', 'client_secret_post');
  assert.ok(confidential.client_secret);
  const authConf = await authorize(confidential, a.session);
  const tokConf = await token(confidential, authConf);
  const confList = await rpc(tokConf.access_token, 'tools/list', {});
  assert.equal(confList.res.status, 200);

  const refreshed = await refresh(client, tokA.refresh_token);
  assert.equal(refreshed.res.status, 200, refreshed.text);
  const reuse = await refresh(client, tokA.refresh_token);
  assert.equal(reuse.res.status, 400);
  assert.equal(reuse.json.error, 'invalid_grant');
  const oldAccess = await rpc(tokA.access_token, 'tools/list', {});
  assert.equal(oldAccess.res.status, 401);

  const revoke = await req('/oauth/revoke', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ token: refreshed.json.access_token }).toString(),
  });
  assert.equal(revoke.res.status, 200);
  const afterRevoke = await rpc(refreshed.json.access_token, 'tools/list', {});
  assert.equal(afterRevoke.res.status, 401);

  const noSession = await req('/oauth/authorize?' + new URLSearchParams({
    response_type: 'code', client_id: client.client_id, redirect_uri: client.redirect_uris[0], scope: 'alphavault.read',
    code_challenge: pkce().challenge, code_challenge_method: 'S256', resource: BASE + '/mcp',
  }).toString());
  assert.equal(noSession.res.status, 302);
  const loginUrl = new URL(noSession.res.headers.get('location'), BASE);
  assert.equal(loginUrl.pathname, '/oauth/login');
  const loginCookie = (noSession.res.headers.getSetCookie?.() || []).find((c) => c.startsWith('mcp_login='));
  assert.ok(loginCookie);
  const loginPage = await req(loginUrl.pathname + loginUrl.search, { headers: { Cookie: loginCookie.split(';')[0] } });
  assert.equal(loginPage.res.status, 200);
  const startMatch = /href="([^"]*\/oauth\/login\/start\?req=[^"]+)"/.exec(loginPage.text);
  assert.ok(startMatch);
  const loginStart = await req(startMatch[1].replace(/&amp;/g, '&'), { headers: { Cookie: loginCookie.split(';')[0] } });
  assert.equal(loginStart.res.status, 302);
  const discordUrl = new URL(loginStart.res.headers.get('location'));
  assert.equal(discordUrl.hostname, 'discord.com');

  console.log('MCP E2E read PASS');
}

async function mainWrites() {
  const sessions = JSON.parse(fs.readFileSync(SESSIONS_FILE, 'utf8'));
  const a = sessions['mcp-e2e-a'];
  const client = await register('MCP E2E Writer');
  const health = await req('/health');
  assert.equal(health.json.mcp.tools, 22);
  assert.equal(health.json.mcp.writesEnabled, true);

  const auth = await authorize(client, a.session, 'alphavault.read alphavault.write', true);
  const tok = await token(client, auth);
  const claims = jwtPayload(tok.access_token);
  assert.deepEqual(claims.scope.split(' ').sort(), ['alphavault.read', 'alphavault.write']);

  const list = await rpc(tok.access_token, 'tools/list', {});
  const tools = list.json.result.tools;
  assert.equal(tools.length, 22);
  for (const name of WRITE_TOOLS) {
    const tool = tools.find((t) => t.name === name);
    assert.ok(tool, name);
    assert.equal(tool.annotations?.readOnlyHint, false, name + ' readOnlyHint');
    assert.ok(tool._meta.securitySchemes[0].scopes.includes('alphavault.write'), name + ' scope');
  }

  const before = JSON.parse(runPhp(path.join(ROOT, 'tests', 'mcp-helper.php'), ['state', String(a.userId)]));
  const beforeCount = before.state.trades.length;

  const pair = 'MCP2/' + crypto.randomBytes(3).toString('hex').toUpperCase();
  const key = 'idem-e2e-' + crypto.randomBytes(8).toString('hex');
  const proposal = await call(tok.access_token, 'propose_create_trade', {
    pair, dir: 'long', entry: 100, size: 2, sl: 95, tp: 120, setup: 'Pullback w trendzie',
    emotion: 'Spokój', note: 'E2E agenta', account: 'WEEX', idempotencyKey: key,
  });
  assert.equal(proposal.res.status, 200, proposal.text);
  const proposalId = proposal.json.result.structuredContent.proposalId;
  assert.ok(proposalId, proposal.text);
  assert.equal(proposal.json.result.structuredContent.status, 'pending');
  const afterProposal = JSON.parse(runPhp(path.join(ROOT, 'tests', 'mcp-helper.php'), ['state', String(a.userId)]));
  assert.equal(afterProposal.state.trades.length, beforeCount, 'stan zmienił się przed zgodą');

  const duplicate = await call(tok.access_token, 'propose_create_trade', {
    pair, dir: 'long', entry: 100, size: 2, sl: 95, tp: 120, setup: 'Pullback w trendzie',
    emotion: 'Spokój', note: 'E2E agenta', account: 'WEEX', idempotencyKey: key,
  });
  assert.equal(duplicate.json.result.structuredContent.proposalId, proposalId);

  const conflict = await call(tok.access_token, 'propose_create_trade', {
    pair, dir: 'short', entry: 100, size: 1, setup: 'Bez setupu', idempotencyKey: key,
  });
  assert.match(toolError(conflict), /idempotencyKey|inną treścią/i, conflict.text);

  const approved = JSON.parse(runPhp(path.join(ROOT, 'tests', 'mcp-helper.php'), ['decide', proposalId, String(a.userId), 'approve']));
  assert.equal(approved.status, 'approved');
  const afterApprove = JSON.parse(runPhp(path.join(ROOT, 'tests', 'mcp-helper.php'), ['state', String(a.userId)]));
  assert.equal(afterApprove.state.trades.length, beforeCount + 1);
  const created = afterApprove.state.trades.find((t) => t.pair === pair);
  assert.ok(created && created.source === 'Ręcznie' && created.result === null, JSON.stringify(created));

  const exchangeReview = await call(tok.access_token, 'propose_trade_review', {
    tradeId: 'ex1', entry: 111, idempotencyKey: 'idem-e2e-ex-' + crypto.randomBytes(4).toString('hex'),
  });
  assert.ok(exchangeReview.json?.error?.code === -32602 || /giełdow/i.test(toolError(exchangeReview)), exchangeReview.text);

  const exchangeClose = await call(tok.access_token, 'propose_close_trade', {
    tradeId: 'ex1', exitPrice: 130, idempotencyKey: 'idem-e2e-ex-close-' + crypto.randomBytes(4).toString('hex'),
  });
  assert.match(toolError(exchangeClose), /giełdow/i, exchangeClose.text);

  const planKey = 'idem-e2e-plan-' + crypto.randomBytes(4).toString('hex');
  const plan = await call(tok.access_token, 'propose_save_day_plan', {
    market: 'BTC', bias: 'long', why: 'E2E plan', riskPercent: 2, maxTrades: 3, idempotencyKey: planKey,
  });
  const planId = plan.json.result.structuredContent.proposalId;
  runPhp(path.join(ROOT, 'tests', 'mcp-helper.php'), ['decide', planId, String(a.userId), 'approve']);
  const dayPlan = await call(tok.access_token, 'get_day_plan');
  assert.equal(dayPlan.json.result.structuredContent.plan.market, 'BTC');
  assert.equal(dayPlan.json.result.structuredContent.plan.approvedAt, null);

  const checkin = await call(tok.access_token, 'propose_save_checkin', {
    mood: 'Spokój', energy: 4, note: 'E2E', idempotencyKey: 'idem-e2e-checkin-' + crypto.randomBytes(4).toString('hex'),
  });
  const checkinId = checkin.json.result.structuredContent.proposalId;
  runPhp(path.join(ROOT, 'tests', 'mcp-helper.php'), ['decide', checkinId, String(a.userId), 'approve']);
  const history = await call(tok.access_token, 'get_checkin_history');
  assert.equal(history.json.result.structuredContent.items[0].score, 70);

  const setup = await call(tok.access_token, 'propose_save_setup', {
    name: 'E2E Setup ' + crypto.randomBytes(3).toString('hex'),
    rules: ['Zasada A', 'Zasada B'], tf: 'H1', grade: 'A',
    idempotencyKey: 'idem-e2e-setup-' + crypto.randomBytes(4).toString('hex'),
  });
  const setupId = setup.json.result.structuredContent.proposalId;
  runPhp(path.join(ROOT, 'tests', 'mcp-helper.php'), ['decide', setupId, String(a.userId), 'approve']);
  const setups = await call(tok.access_token, 'list_setups');
  assert.equal(setups.json.result.structuredContent.total, 6);

  const prop = await call(tok.access_token, 'propose_save_prop_challenge', {
    name: 'E2E Challenge', templateId: 'ftmo-1step', account: 'WEEX', accountSize: 10000,
    startBalance: 10000, startDate: new Date().toLocaleDateString('sv-SE', { timeZone: 'Europe/Warsaw' }),
    idempotencyKey: 'idem-e2e-prop-' + crypto.randomBytes(4).toString('hex'),
  });
  const propId = prop.json.result.structuredContent.proposalId;
  runPhp(path.join(ROOT, 'tests', 'mcp-helper.php'), ['decide', propId, String(a.userId), 'approve']);
  const challenges = await call(tok.access_token, 'get_prop_challenges');
  assert.equal(challenges.json.result.structuredContent.total, 4);

  const reject = await call(tok.access_token, 'propose_save_checkin', {
    mood: 'Skupienie', energy: 3, idempotencyKey: 'idem-e2e-reject-' + crypto.randomBytes(4).toString('hex'),
  });
  const rejectId = reject.json.result.structuredContent.proposalId;
  const rejected = JSON.parse(runPhp(path.join(ROOT, 'tests', 'mcp-helper.php'), ['decide', rejectId, String(a.userId), 'reject']));
  assert.equal(rejected.status, 'rejected');

  console.log('MCP E2E writes PASS');
}

runPhp(path.join(ROOT, 'tests', 'seed-mcp.php'));
runPhp(path.join(ROOT, 'tests', 'mcp-user-service.php'));

let server = null;
let serverLog = '';
async function startServer(writesEnabled) {
  server = spawn(
    PHP,
    [...PHP_ARGS, '-S', `127.0.0.1:${PORT}`, '-t', path.join(ROOT, 'mcp', 'public'), path.join(ROOT, 'mcp', 'public', 'index.php')],
    {
      cwd: ROOT,
      env: { ...process.env, ALPHA_VAULT_CONFIG: CONFIG, ...LOCAL_ENV, MCP_WRITES_ENABLED: writesEnabled ? 'true' : 'false' },
      stdio: ['ignore', 'pipe', 'pipe'],
    }
  );
  server.stdout.on('data', (chunk) => { serverLog += chunk.toString(); });
  server.stderr.on('data', (chunk) => { serverLog += chunk.toString(); });
  await waitForHealth();
}

async function stopServer() {
  if (!server) return;
  server.kill('SIGTERM');
  server = null;
  await new Promise((resolve) => setTimeout(resolve, 400));
}

try {
  await startServer(false);
  await main();
  await stopServer();

  await startServer(true);
  await mainWrites();
} catch (err) {
  console.error(serverLog);
  throw err;
} finally {
  await stopServer();
  runPhp(path.join(ROOT, 'tests', 'cleanup-mcp.php'));
}
