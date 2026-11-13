#!/usr/bin/env node
/**
 * E2E logowania MCP przez Discord (fake upstream IdP):
 * - pierwsze logowanie tworzy konto,
 * - kolejne logowanie nie tworzy duplikatu,
 * - brak roli i brak członkostwa dają 403 bez konta,
 * - istniejące konto jest aktualizowane po Discord ID,
 * - po logowaniu działa OAuth consent + token + get_profile.
 */
import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import { spawn, spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const PHP = process.env.PHP_BIN || 'php';
const PHP_ARGS = (process.env.PHP_ARGS || '').split(/\s+/).filter(Boolean);
const CONFIG = path.join(ROOT, 'tests', 'config.mcp-login.json');
const MCP_PORT = Number(process.env.MCP_LOGIN_PORT || 4178);
const DISCORD_PORT = Number(process.env.MCP_FAKE_DISCORD_PORT || 4180);
const BASE = `http://127.0.0.1:${MCP_PORT}`;
const DISCORD = `http://127.0.0.1:${DISCORD_PORT}`;
const CLIENT_NAME = 'MCP Login E2E';

const profiles = {
  'role-ok': { id: 'mcp-discord-ok', username: 'mcp-ok', global_name: 'MCP OK', email: 'mcp-ok@example.test', role: true },
  'role-ok-2': { id: 'mcp-discord-ok', username: 'mcp-ok-renamed', global_name: 'MCP OK Renamed', email: 'mcp-ok@example.test', role: true },
  'role-none': { id: 'mcp-discord-none', username: 'mcp-none', global_name: 'MCP None', email: 'mcp-none@example.test', role: false },
  notmember: { id: 'mcp-discord-notmember', username: 'mcp-notmember', global_name: 'MCP Notmember', email: 'mcp-notmember@example.test', member: false },
  'role-ok-existing': { id: 'mcp-discord-existing', username: 'mcp-existing-new', global_name: 'MCP Existing New', email: 'mcp-existing@example.test', role: true },
};

function b64url(buf) {
  return Buffer.from(buf).toString('base64url');
}

function pkce() {
  const verifier = b64url(crypto.randomBytes(48));
  return { verifier, challenge: b64url(crypto.createHash('sha256').update(verifier).digest()) };
}

function startFakeDiscord() {
  const server = http.createServer((req, res) => {
    const url = new URL(req.url, DISCORD);
    if (req.method === 'POST' && url.pathname === '/api/oauth2/token') {
      let body = '';
      req.on('data', (chunk) => { body += chunk; });
      req.on('end', () => {
        const params = new URLSearchParams(body);
        const code = params.get('code') || '';
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ access_token: 'token-' + code, token_type: 'Bearer' }));
      });
      return;
    }
    if (req.method === 'GET' && url.pathname === '/api/users/@me') {
      const token = (req.headers.authorization || '').replace(/^Bearer\s+/i, '');
      const code = token.replace(/^token-/, '');
      const profile = profiles[code];
      if (!profile) {
        res.writeHead(401, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ message: 'unknown token' }));
        return;
      }
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({
        id: profile.id,
        username: profile.username,
        global_name: profile.global_name,
        email: profile.email,
        avatar: null,
        discriminator: '0',
      }));
      return;
    }
    if (req.method === 'GET' && /^\/api\/v10\/users\/@me\/guilds\/[^/]+\/member$/.test(url.pathname)) {
      const token = (req.headers.authorization || '').replace(/^Bearer\s+/i, '');
      const code = token.replace(/^token-/, '');
      const profile = profiles[code];
      if (profile?.member === false) {
        res.writeHead(404, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ message: 'Unknown Guild Member' }));
        return;
      }
      if (!profile) {
        res.writeHead(401, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ message: 'unknown token' }));
        return;
      }
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ roles: profile.role ? ['test-role'] : [] }));
      return;
    }
    res.writeHead(404);
    res.end('not found');
  });
  return new Promise((resolve) => server.listen(DISCORD_PORT, '127.0.0.1', () => resolve(server)));
}

function makeConfig() {
  const base = JSON.parse(fs.readFileSync(path.join(ROOT, 'tests', 'config.test.json'), 'utf8'));
  const cfg = {
    ...base,
    APP_URL: BASE,
    APP_ENV: 'local',
    SESSION_COOKIE: 'mcp_session',
    DISCORD_REDIRECT_URI: BASE + '/oauth/discord/callback',
    DISCORD_GUILD_ID: 'test-guild',
    DISCORD_REQUIRED_ROLE_ID: 'test-role',
    DISCORD_API_BASE: DISCORD,
    DISCORD_OAUTH_BASE: DISCORD,
    DISCORD_USER_BASE: DISCORD,
    MCP_ENABLED: 'true',
    MCP_WRITES_ENABLED: 'false',
    MCP_ISSUER: BASE,
    MCP_RESOURCE: BASE + '/mcp',
    MCP_ALLOWED_REDIRECT_HOSTS: 'chatgpt.com,localhost,127.0.0.1',
  };
  fs.writeFileSync(CONFIG, JSON.stringify(cfg, null, 2));
}

function runPhp(script) {
  const result = spawnSync(PHP, [...PHP_ARGS, script], {
    cwd: ROOT,
    env: { ...process.env, ALPHA_VAULT_CONFIG: CONFIG },
    encoding: 'utf8',
  });
  if (result.status !== 0) throw new Error(`${script} failed:\n${result.stdout}\n${result.stderr}`);
}

async function waitFor(url) {
  const started = Date.now();
  while (Date.now() - started < 15000) {
    try {
      const res = await fetch(url);
      if (res.ok) return;
    } catch {}
    await new Promise((resolve) => setTimeout(resolve, 250));
  }
  throw new Error('timeout waiting for ' + url);
}

async function req(url, opts = {}) {
  const res = await fetch(url.startsWith('http') ? url : BASE + url, { redirect: 'manual', ...opts });
  const text = await res.text();
  let json = null;
  try { json = text ? JSON.parse(text) : null; } catch {}
  return { res, text, json };
}

function cookieFrom(res, name) {
  return (res.headers.getSetCookie?.() || []).find((c) => c.startsWith(name + '='))?.split(';')[0] || '';
}

async function register() {
  const r = await req('/oauth/register', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      client_name: CLIENT_NAME,
      redirect_uris: ['https://chatgpt.com/connector_platform_oauth_redirect'],
      token_endpoint_auth_method: 'none',
    }),
  });
  assert.equal(r.res.status, 201, r.text);
  return r.json;
}

async function startLogin() {
  const client = await register();
  const { verifier, challenge } = pkce();
  const q = new URLSearchParams({
    response_type: 'code',
    client_id: client.client_id,
    redirect_uri: client.redirect_uris[0],
    scope: 'alphavault.read',
    state: 'login-' + crypto.randomBytes(6).toString('hex'),
    code_challenge: challenge,
    code_challenge_method: 'S256',
    resource: BASE + '/mcp',
  });
  const auth = await req('/oauth/authorize?' + q.toString());
  assert.equal(auth.res.status, 302, auth.text);
  const loginPath = new URL(auth.res.headers.get('location'), BASE).pathname + new URL(auth.res.headers.get('location'), BASE).search;
  const loginCookie = cookieFrom(auth.res, 'mcp_login');
  assert.ok(loginCookie);
  const loginPage = await req(loginPath, { headers: { Cookie: loginCookie } });
  assert.equal(loginPage.res.status, 200, loginPage.text);
  assert.match(loginPage.text, /Alpha Vault/);
  const startMatch = /href="([^"]*\/oauth\/login\/start\?req=[^"]+)"/.exec(loginPage.text);
  assert.ok(startMatch, 'brak linku startu logowania');
  const login = await req(startMatch[1].replace(/&amp;/g, '&'), { headers: { Cookie: loginCookie } });
  assert.equal(login.res.status, 302, login.text);
  const discordUrl = new URL(login.res.headers.get('location'));
  assert.equal(discordUrl.origin, DISCORD);
  return {
    client,
    verifier,
    challenge,
    loginCookie,
    state: discordUrl.searchParams.get('state'),
    redirectUri: client.redirect_uris[0],
  };
}

async function finishConsent(client, verifier, challenge, sessionCookie) {
  const q = new URLSearchParams({
    response_type: 'code',
    client_id: client.client_id,
    redirect_uri: client.redirect_uris[0],
    scope: 'alphavault.read',
    state: 'final',
    code_challenge: challenge,
    code_challenge_method: 'S256',
    resource: BASE + '/mcp',
  });
  const auth = await req('/oauth/authorize?' + q.toString(), { headers: { Cookie: sessionCookie } });
  assert.equal(auth.res.status, 200, auth.text);
  const consentToken = /name="consent_token" value="([^"]+)"/.exec(auth.text);
  const formAction = /<form method="post" action="([^"]+)"/.exec(auth.text);
  assert.ok(consentToken && formAction);
  const action = formAction[1].replace(/&amp;/g, '&');
  const formBody = new URLSearchParams({ consent_id: 'wrong', csrf: 'wrong', consent_token: consentToken[1], decision: 'allow' }).toString();
  const post = await req(action, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', Cookie: sessionCookie },
    body: formBody,
  });
  assert.equal(post.res.status, 302, post.text);
  const retryBody = new URLSearchParams({ consent_id: 'wrong', csrf: 'wrong', consent_token: consentToken[1] + 'x', decision: 'allow' }).toString();
  const retry = await req(action, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', Cookie: sessionCookie },
    body: retryBody,
  });
  assert.equal(retry.res.status, 200, retry.text);
  assert.match(retry.text, /odświeżona/i);
  const code = new URL(post.res.headers.get('location')).searchParams.get('code');
  assert.ok(code);
  const token = await req('/oauth/token', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      grant_type: 'authorization_code',
      client_id: client.client_id,
      redirect_uri: client.redirect_uris[0],
      code,
      code_verifier: verifier,
      resource: BASE + '/mcp',
    }).toString(),
  });
  assert.equal(token.res.status, 200, token.text);
  return token.json.access_token;
}

async function callProfile(accessToken) {
  const r = await req('/mcp', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json, text/event-stream',
      Authorization: 'Bearer ' + accessToken,
    },
    body: JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'tools/call', params: { name: 'get_profile', arguments: {} } }),
  });
  assert.equal(r.res.status, 200, r.text);
  return r.json.result.structuredContent;
}

async function callback(login, code) {
  return req(`/oauth/discord/callback?code=${encodeURIComponent(code)}&state=${encodeURIComponent(login.state)}`, {
    headers: { Cookie: login.loginCookie },
  });
}

function dbScalar(sql) {
  const script = path.join(ROOT, 'tests', 'tmp-login-scalar.php');
  fs.writeFileSync(script, `<?php
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
[, $db] = require $root . '/app/bootstrap.php';
echo (string) ($db->queryOne(${JSON.stringify(sql)})['v'] ?? '0');
`);
  const result = spawnSync(PHP, [...PHP_ARGS, script], {
    cwd: ROOT,
    env: { ...process.env, ALPHA_VAULT_CONFIG: CONFIG },
    encoding: 'utf8',
  });
  fs.unlinkSync(script);
  return result.stdout.trim();
}

function cleanup() {
  const script = path.join(ROOT, 'tests', 'tmp-login-cleanup.php');
  fs.writeFileSync(script, `<?php
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
[, $db] = require $root . '/app/bootstrap.php';
$db->execute("DELETE FROM users WHERE discord_id LIKE 'mcp-discord-%'");
$db->execute("DELETE FROM mcp_clients WHERE client_name = '${CLIENT_NAME}'");
`);
  spawnSync(PHP, [...PHP_ARGS, script], {
    cwd: ROOT,
    env: { ...process.env, ALPHA_VAULT_CONFIG: CONFIG },
    encoding: 'utf8',
  });
  fs.unlinkSync(script);
}

makeConfig();
cleanup();
const fake = await startFakeDiscord();
const server = spawn(PHP, [...PHP_ARGS, '-S', `127.0.0.1:${MCP_PORT}`, '-t', path.join(ROOT, 'mcp', 'public'), path.join(ROOT, 'mcp', 'public', 'index.php')], {
  cwd: ROOT,
  env: { ...process.env, ALPHA_VAULT_CONFIG: CONFIG },
  stdio: ['ignore', 'pipe', 'pipe'],
});

try {
  await waitFor(BASE + '/health');

  // 1. Pierwsze logowanie: tworzy konto.
  const first = await startLogin();
  const firstCallback = await callback(first, 'role-ok');
  assert.equal(firstCallback.res.status, 302, firstCallback.text);
  const firstSession = cookieFrom(firstCallback.res, 'mcp_session');
  assert.ok(firstSession, 'brak mcp_session po callbacku');
  const firstToken = await finishConsent(first.client, first.verifier, first.challenge, firstSession);
  const firstProfile = await callProfile(firstToken);
  assert.match(firstProfile.id, /^av_\d+$/);
  assert.equal(dbScalar("SELECT COUNT(*) AS v FROM users WHERE discord_id = 'mcp-discord-ok'"), '1');

  // 2. Drugie logowanie tego samego użytkownika: bez duplikatu, aktualizacja.
  const second = await startLogin();
  const secondCallback = await callback(second, 'role-ok-2');
  assert.equal(secondCallback.res.status, 302, secondCallback.text);
  const secondSession = cookieFrom(secondCallback.res, 'mcp_session');
  const secondToken = await finishConsent(second.client, second.verifier, second.challenge, secondSession);
  const secondProfile = await callProfile(secondToken);
  assert.equal(secondProfile.id, firstProfile.id);
  assert.equal(dbScalar("SELECT COUNT(*) AS v FROM users WHERE discord_id = 'mcp-discord-ok'"), '1');
  assert.equal(dbScalar("SELECT username AS v FROM users WHERE discord_id = 'mcp-discord-ok'"), 'mcp-ok-renamed');

  // 3. Brak roli: 403, brak konta.
  const noRole = await startLogin();
  const noRoleCallback = await callback(noRole, 'role-none');
  assert.equal(noRoleCallback.res.status, 403, noRoleCallback.text);
  assert.equal(dbScalar("SELECT COUNT(*) AS v FROM users WHERE discord_id = 'mcp-discord-none'"), '0');

  // 4. Brak członkostwa: 403, brak konta.
  const notMember = await startLogin();
  const notMemberCallback = await callback(notMember, 'notmember');
  assert.equal(notMemberCallback.res.status, 403, notMemberCallback.text);
  assert.equal(dbScalar("SELECT COUNT(*) AS v FROM users WHERE discord_id = 'mcp-discord-notmember'"), '0');

  // 5. Istniejące konto po Discord ID: aktualizacja, nie duplikat.
  const existingId = dbScalar("SELECT id AS v FROM users WHERE discord_id = 'mcp-discord-ok'");
  assert.ok(existingId !== '0');
  const existing = await startLogin();
  // Podmieniamy profil w fake IdP na ten sam Discord ID, inne dane.
  profiles['role-ok-existing'].id = 'mcp-discord-ok';
  const existingCallback = await callback(existing, 'role-ok-existing');
  assert.equal(existingCallback.res.status, 302, existingCallback.text);
  assert.equal(dbScalar("SELECT COUNT(*) AS v FROM users WHERE discord_id = 'mcp-discord-ok'"), '1');
  assert.equal(dbScalar("SELECT id AS v FROM users WHERE discord_id = 'mcp-discord-ok'"), existingId);

  console.log('MCP Login E2E PASS');
} finally {
  server.kill('SIGTERM');
  fake.close();
  cleanup();
}

