#!/usr/bin/env node
/**
 * MCP Inspector CLI smoke against a running AlphaVault MCP endpoint.
 * Tworzy tymczasowe konto testowe w bazie, przechodzi OAuth i wywołuje tools/list.
 */
import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const PHP = process.env.PHP_BIN || 'php';
const PHP_ARGS = (process.env.PHP_ARGS || '').split(/\s+/).filter(Boolean);
const CONFIG = process.env.ALPHA_VAULT_CONFIG || path.join(ROOT, 'tests', 'config.test.json');
const BASE = process.env.MCP_BASE || 'https://mcp.alphavault.pl';
const SESSIONS = path.join(ROOT, 'tests', 'mcp-test-sessions.json');

function runPhp(script) {
  const r = spawnSync(PHP, [...PHP_ARGS, script], {
    cwd: ROOT,
    env: { ...process.env, ALPHA_VAULT_CONFIG: CONFIG },
    encoding: 'utf8',
  });
  if (r.status !== 0) throw new Error(`${script} failed:\n${r.stdout}\n${r.stderr}`);
}

function b64url(buf) {
  return Buffer.from(buf).toString('base64url');
}

async function req(pathname, opts = {}) {
  const res = await fetch(BASE + pathname, { redirect: 'manual', ...opts });
  const text = await res.text();
  let json = null;
  try { json = text ? JSON.parse(text) : null; } catch {}
  return { res, text, json };
}

runPhp(path.join(ROOT, 'tests', 'seed-mcp.php'));
const sessions = JSON.parse(fs.readFileSync(SESSIONS, 'utf8'));
const user = sessions['mcp-e2e-a'];

try {
  const client = await req('/oauth/register', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      client_name: 'MCP E2E Client',
      redirect_uris: ['https://chatgpt.com/connector_platform_oauth_redirect'],
      token_endpoint_auth_method: 'none',
    }),
  });
  assert.equal(client.res.status, 201, client.text);

  const verifier = b64url(crypto.randomBytes(48));
  const challenge = b64url(crypto.createHash('sha256').update(verifier).digest());
  const q = new URLSearchParams({
    response_type: 'code',
    client_id: client.json.client_id,
    redirect_uri: client.json.redirect_uris[0],
    scope: 'alphavault.read',
    state: 'inspector',
    code_challenge: challenge,
    code_challenge_method: 'S256',
    resource: BASE + '/mcp',
  });
  const auth = await req('/oauth/authorize?' + q.toString(), { headers: { Cookie: 'mcp_session=' + user.session } });
  assert.equal(auth.res.status, 200, auth.text);
  const consent = /name="consent_id" value="([^"]+)"/.exec(auth.text);
  const csrf = /name="csrf" value="([^"]+)"/.exec(auth.text);
  assert.ok(consent && csrf);
  const approval = await req('/oauth/authorize', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', Cookie: 'mcp_session=' + user.session },
    body: new URLSearchParams({ consent_id: consent[1], csrf: csrf[1], decision: 'allow' }).toString(),
  });
  assert.equal(approval.res.status, 302, approval.text);
  const code = new URL(approval.res.headers.get('location')).searchParams.get('code');
  assert.ok(code);
  const token = await req('/oauth/token', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      grant_type: 'authorization_code',
      client_id: client.json.client_id,
      redirect_uri: client.json.redirect_uris[0],
      code,
      code_verifier: verifier,
      resource: BASE + '/mcp',
    }).toString(),
  });
  assert.equal(token.res.status, 200, token.text);

  const cmdFile = path.join(ROOT, 'tests', 'tmp-inspector.cmd');
  fs.writeFileSync(cmdFile, [
    '@echo off',
    'npx -y @modelcontextprotocol/inspector@latest --cli --transport http --server-url ' + BASE + '/mcp --method tools/list --strict --header "Authorization: Bearer ' + token.json.access_token + '"',
  ].join('\r\n') + '\r\n');
  const inspector = spawnSync(process.env.ComSpec || 'cmd.exe', ['/d', '/c', cmdFile], {
    cwd: ROOT,
    encoding: 'utf8',
    timeout: 180000,
  });
  fs.unlinkSync(cmdFile);
  const output = (inspector.stdout || '') + (inspector.stderr || '');
  console.log(output.split('\n').filter(Boolean).slice(-12).join('\n'));
  assert.equal(inspector.status, 0, 'Inspector exit ' + inspector.status);
  for (const name of ['get_profile', 'get_dashboard', 'list_trades', 'get_trade', 'get_stats', 'get_events', 'get_day_plan', 'list_setups']) {
    assert.ok(output.includes(name), 'Inspector nie widzi ' + name);
  }
  console.log('MCP Inspector CLI PASS');
} finally {
  runPhp(path.join(ROOT, 'tests', 'cleanup-mcp.php'));
}
