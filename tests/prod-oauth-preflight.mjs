#!/usr/bin/env node
import assert from 'node:assert/strict';
import crypto from 'node:crypto';

const BASE = process.argv[2] || 'https://mcp.alphavault.pl';

function b64url(buf) {
  return Buffer.from(buf).toString('base64url');
}

async function req(url, opts = {}) {
  const res = await fetch(url, { redirect: 'manual', ...opts });
  const text = await res.text();
  let json = null;
  try { json = text ? JSON.parse(text) : null; } catch {}
  return { res, text, json };
}

const verifier = b64url(crypto.randomBytes(48));
const challenge = b64url(crypto.createHash('sha256').update(verifier).digest());

const reg = await req(BASE + '/oauth/register', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    client_name: 'AlphaVault preflight',
    redirect_uris: ['https://chatgpt.com/connector_platform_oauth_redirect'],
    token_endpoint_auth_method: 'none',
  }),
});
assert.equal(reg.res.status, 201, reg.text);
assert.ok(reg.json.client_id);
console.log('DCR PASS');

const auth = await req(BASE + '/oauth/authorize?' + new URLSearchParams({
  response_type: 'code',
  client_id: reg.json.client_id,
  redirect_uri: reg.json.redirect_uris[0],
  scope: 'alphavault.read',
  state: 'preflight',
  code_challenge: challenge,
  code_challenge_method: 'S256',
  resource: BASE + '/mcp',
}));
assert.equal(auth.res.status, 302, auth.text);
const login = new URL(auth.res.headers.get('location'), BASE);
assert.equal(login.pathname, '/oauth/login');
const cookie = (auth.res.headers.getSetCookie?.() || []).find((c) => c.startsWith('mcp_login='));
assert.ok(cookie);
console.log('authorize -> login PASS');

const loginPage = await req(BASE + login.pathname + login.search, { headers: { Cookie: cookie.split(';')[0] } });
assert.equal(loginPage.res.status, 200, loginPage.text);
assert.match(loginPage.text, /Alpha Vault/);
const startMatch = /href="([^"]*\/oauth\/login\/start\?req=[^"]+)"/.exec(loginPage.text);
assert.ok(startMatch, 'brak linku startu logowania');
const start = await req(BASE + startMatch[1].replace(/&amp;/g, '&'), { headers: { Cookie: cookie.split(';')[0] } });
assert.equal(start.res.status, 302, start.text);
const discord = new URL(start.res.headers.get('location'));
assert.equal(discord.hostname, 'discord.com');
assert.equal(discord.searchParams.get('redirect_uri'), BASE + '/oauth/discord/callback');
assert.ok(discord.searchParams.get('client_id'));
console.log('login -> Discord PASS');
console.log('discord authorize URL:', discord.origin + discord.pathname);

const discordRes = await fetch(discord.toString(), { redirect: 'manual' });
const location = discordRes.headers.get('location') || '';
console.log('Discord status:', discordRes.status, 'location:', location.slice(0, 120));
if (discordRes.status >= 400) {
  const body = await discordRes.text();
  console.log(body.slice(0, 300));
  process.exitCode = 2;
}
