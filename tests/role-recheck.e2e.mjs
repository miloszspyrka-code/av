#!/usr/bin/env node
/**
 * Alpha Vault — cykliczna weryfikacja roli (cron + bot).
 * Uruchom: npm run test:roles
 * Wymaga MySQL z tests/config.test.json. Test używa lokalnego mocka Discord.
 */
import assert from 'node:assert/strict';
import { execFile, execFileSync } from 'node:child_process';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import { promisify } from 'node:util';

const ROOT = process.cwd();
const php = process.env.PHP_BIN || (fs.existsSync('C:/Users/milos/.local/php83/php.exe')
  ? 'C:/Users/milos/.local/php83/php.exe'
  : 'php');
const helper = path.join(ROOT, 'tests', 'role-recheck-helper.php');
const execFileAsync = promisify(execFile);
const results = [];

function log(name, ok, info = '') {
  results.push({ name, ok, info });
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${info ? '  · ' + info : ''}`);
}

let mode = 'ok';
let requests = 0;
let rateLimitOnce = false;

const server = http.createServer((req, res) => {
  requests++;
  if (!req.url?.startsWith('/api/v10/guilds/test-guild/members/')) {
    res.writeHead(404, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ message: 'not found' }));
    return;
  }
  if (rateLimitOnce) {
    rateLimitOnce = false;
    res.writeHead(429, { 'Content-Type': 'application/json', 'Retry-After': '1' });
    res.end(JSON.stringify({ message: 'rate limited' }));
    return;
  }
  if (mode === 'not_member') {
    res.writeHead(404, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ message: 'Unknown Member' }));
    return;
  }
  if (mode === 'error') {
    res.writeHead(500, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ message: 'discord unavailable' }));
    return;
  }
  res.writeHead(200, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify({ roles: mode === 'ok' ? ['test-role'] : [] }));
});

await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
const port = server.address().port;

const env = {
  ...process.env,
  ALPHA_VAULT_CONFIG: path.join(ROOT, 'tests', 'config.test.json'),
  DISCORD_API_BASE: `http://127.0.0.1:${port}/api/v10`,
  DISCORD_GUILD_ID: 'test-guild',
  DISCORD_REQUIRED_ROLE_ID: 'test-role',
  DISCORD_BOT_TOKEN: 'test-bot-token',
  DISCORD_ROLE_RECHECK_SECONDS: '300',
};

function phpJson(command, ...args) {
  const raw = execFileSync(php, [helper, command, ...args], { cwd: ROOT, env, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
  return JSON.parse(raw.trim().split(/\r?\n/).pop());
}

async function cron() {
  const { stdout } = await execFileAsync(php, ['bin/check-discord-roles.php'], {
    cwd: ROOT,
    env,
    encoding: 'utf8',
  });
  return stdout.trim();
}

try {
  execFileSync(php, ['app/db/migrate.php'], { cwd: ROOT, env, stdio: 'ignore' });
  phpJson('cleanup', 'role-e2e-user');

  // 1. Rola obecna — sesja zostaje.
  phpJson('seed', 'role-e2e-user');
  phpJson('expire', 'role-e2e-user');
  mode = 'ok';
  requests = 0;
  let output = await cron();
  let state = phpJson('state', 'role-e2e-user');
  assert.match(output, /success checked=1 revoked=0 errors=0/);
  assert.equal(String(state.user.role_verified), '1');
  assert.equal(state.sessions, 1);
  assert.equal(requests, 1);
  log('rola obecna: dostęp utrzymany', true, output);

  // 2. Brak roli — sesje usunięte, audit.
  phpJson('expire', 'role-e2e-user');
  mode = 'missing';
  output = await cron();
  state = phpJson('state', 'role-e2e-user');
  assert.match(output, /success checked=1 revoked=1 errors=0/);
  assert.equal(String(state.user.role_verified), '0');
  assert.equal(state.user.role_check_status, 'missing');
  assert.equal(state.sessions, 0);
  assert.equal(state.auditRevoked, 1);
  log('brak roli: sesja unieważniona + audit', true, output);

  // 3. Błąd Discorda — fail open dla wcześniej zweryfikowanego.
  phpJson('seed', 'role-e2e-user');
  phpJson('expire', 'role-e2e-user');
  mode = 'error';
  output = await cron();
  state = phpJson('state', 'role-e2e-user');
  assert.match(output, /error checked=1 revoked=0 errors=1/);
  assert.equal(String(state.user.role_verified), '1');
  assert.equal(state.sessions, 1);
  assert.equal(state.checkStatus, 'error');
  log('błąd Discord = fail open (kupujący nie traci dostępu)', true, output);

  // 4. 404 — brak członkostwa, sesja usunięta.
  phpJson('seed', 'role-e2e-user');
  phpJson('expire', 'role-e2e-user');
  mode = 'not_member';
  output = await cron();
  state = phpJson('state', 'role-e2e-user');
  assert.match(output, /revoked=1/);
  assert.equal(state.sessions, 0);
  log('404: brak członkostwa = dostęp odebrany', true, output);

  // 5. 429 z Retry-After — ponowna próba kończy się sukcesem.
  phpJson('seed', 'role-e2e-user');
  phpJson('expire', 'role-e2e-user');
  mode = 'ok';
  rateLimitOnce = true;
  requests = 0;
  output = await cron();
  state = phpJson('state', 'role-e2e-user');
  assert.match(output, /success checked=1 revoked=0 errors=0/);
  assert.equal(state.sessions, 1);
  assert.ok(requests >= 2, 'oczekiwano ponownej próby po 429');
  log('429: Retry-After i ponowna próba', true, `requests=${requests}`);

  // 6. Lock — drugi cron nie nakłada się.
  phpJson('seed', 'role-e2e-user');
  phpJson('expire', 'role-e2e-user');
  phpJson('lock', 'role-e2e-user');
  output = await cron();
  state = phpJson('state', 'role-e2e-user');
  assert.match(output, /skipped \(locked\)/);
  assert.equal(state.sessions, 1);
  log('lock: równoległy cron pominięty', true, output);

  phpJson('cleanup', 'role-e2e-user');
  const failed = results.filter((r) => !r.ok);
  console.log(`\n${results.length - failed.length}/${results.length} PASS`);
  if (failed.length) process.exitCode = 1;
} catch (err) {
  console.error('\nFAIL  test przerwany:', err.message);
  process.exitCode = 1;
  try { phpJson('cleanup', 'role-e2e-user'); } catch {}
} finally {
  server.close();
}
