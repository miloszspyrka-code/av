'use strict';
const express = require('express');
const config = require('../config');
const db = require('../db');
const auth = require('../auth');

const router = express.Router();

function errorPage(res, status, title, text, detail) {
  res.status(status).type('html').send(`<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${title} · Alpha Vault</title>
<style>body{margin:0;background:#080707;color:#f5f1eb;font-family:system-ui,Arial,sans-serif;display:grid;place-items:center;min-height:100vh;padding:24px}.box{max-width:520px;width:100%;background:#121110;border:1px solid rgba(255,255,255,.11);border-radius:24px;padding:34px;text-align:center}.badge{font-family:monospace;font-size:11px;letter-spacing:.2em;color:#d9b25f;text-transform:uppercase}h1{font-size:28px;margin:12px 0}p{color:#b7b0a6;line-height:1.6;font-size:14px}code{color:#f1d48e;background:rgba(217,178,95,.12);padding:2px 6px;border-radius:5px}.btn{display:inline-block;margin-top:20px;background:#f5f1eb;color:#080707;text-decoration:none;font-weight:700;padding:12px 20px;border-radius:99px}small{display:block;margin-top:18px;color:#7f786f;font-size:11px;line-height:1.5}</style></head>
<body><div class="box"><div class="badge">Alpha Vault</div><h1>${title}</h1><p>${text}</p>${detail ? `<p><code>${String(detail).replace(/[<>&]/g, '')}</code></p>` : ''}<a class="btn" href="/">Wróć do logowania</a><small>Jeśli problem się powtarza, skontaktuj się z zespołem Alpha Moves.</small></div></body></html>`);
}

async function discordFetch(url, opts = {}) {
  const res = await fetch(url, opts);
  const text = await res.text();
  let data = null;
  try { data = text ? JSON.parse(text) : null; } catch { data = { raw: text }; }
  return { res, data };
}

async function upsertDiscordUser(profile, roleVerified) {
  const existing = await db.queryOne('SELECT * FROM users WHERE discord_id = ? LIMIT 1', [profile.id]);
  const trialUntil = config.discord.trialDays > 0
    ? new Date(Date.now() + config.discord.trialDays * 864e5).toISOString().slice(0, 10)
    : null;
  if (existing) {
    await db.query(
      `UPDATE users SET username = ?, global_name = ?, avatar_url = ?, email = ?, role_verified = ?, last_login_at = CURRENT_TIMESTAMP
       WHERE id = ?`,
      [profile.username || existing.username, profile.global_name || null, profile.avatar_url || existing.avatar_url,
        profile.email || existing.email, roleVerified ? 1 : existing.role_verified, existing.id]
    );
    return { ...existing, ...profile, role_verified: roleVerified ? 1 : existing.role_verified };
  }
  const result = await db.query(
    `INSERT INTO users (discord_id, username, global_name, avatar_url, email, is_demo, plan, access_until, role_verified, last_login_at)
     VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, CURRENT_TIMESTAMP)`,
    [profile.id, profile.username || 'Trader', profile.global_name || null, profile.avatar_url || null,
      profile.email || null, config.discord.defaultPlan, trialUntil, roleVerified ? 1 : 0]
  );
  return db.queryOne('SELECT * FROM users WHERE id = ?', [result.insertId]);
}

async function checkRole(discordId) {
  if (!config.roleCheckConfigured) return { checked: false, ok: true };
  const url = `https://discord.com/api/v10/guilds/${config.discord.guildId}/members/${discordId}`;
  const { res, data } = await discordFetch(url, {
    headers: { Authorization: 'Bot ' + config.discord.botToken }
  });
  if (res.status === 404) return { checked: true, ok: false, reason: 'Nie jesteś członkiem serwera Alpha Moves.' };
  if (!res.ok) return { checked: true, ok: false, reason: 'Nie udało się sprawdzić roli na Discordzie.', detail: data?.message };
  const roles = Array.isArray(data?.roles) ? data.roles : [];
  if (!roles.includes(config.discord.requiredRoleId)) {
    return { checked: true, ok: false, reason: 'Brak aktywnej roli Alpha na serwerze Alpha Moves.' };
  }
  return { checked: true, ok: true };
}

function discordAvatar(profile) {
  if (profile.avatar) return `https://cdn.discordapp.com/avatars/${profile.id}/${profile.avatar}.png?size=128`;
  const idx = Number(profile.discriminator || 0) % 5;
  return `https://cdn.discordapp.com/embed/avatars/${idx}.png`;
}

router.get('/discord', auth.rateLimit('discord-start', 30, 60000), async (req, res, next) => {
  try {
    if (!config.discordConfigured) {
      errorPage(res, 503, 'Discord nie jest podłączony',
        'Uzupełnij <code>DISCORD_CLIENT_ID</code>, <code>DISCORD_CLIENT_SECRET</code> i <code>DISCORD_REDIRECT_URI</code> w pliku <code>.env</code>, a następnie zrestartuj serwer. Do tego czasu możesz wejść w trybie demo.');
      return;
    }
    const remember = req.query.remember !== '0';
    const state = auth.randomToken(24);
    const expires = new Date(Date.now() + 10 * 60000);
    await db.query('DELETE FROM oauth_states WHERE expires_at < UTC_TIMESTAMP()');
    await db.query(
      'INSERT INTO oauth_states (state, remember, redirect_to, expires_at) VALUES (?, ?, ?, ?)',
      [state, remember ? 1 : 0, '/', auth.nowSqlOf(expires)]
    );
    res.append('Set-Cookie', auth.serializeCookie(config.oauthCookie, state, { maxAge: 600, expires }));
    const params = new URLSearchParams({
      client_id: config.discord.clientId,
      redirect_uri: config.discord.redirectUri,
      response_type: 'code',
      scope: 'identify',
      state,
      prompt: 'consent'
    });
    res.redirect('https://discord.com/api/oauth2/authorize?' + params.toString());
  } catch (err) { next(err); }
});

router.get('/discord/callback', auth.rateLimit('discord-callback', 30, 60000), async (req, res, next) => {
  try {
    if (req.query.error) {
      await auth.audit(null, 'login.cancelled', { error: req.query.error }, req);
      errorPage(res, 400, 'Logowanie anulowane', 'Discord zwrócił informację, że logowanie nie zostało ukończone.');
      return;
    }
    const state = String(req.query.state || '');
    const code = String(req.query.code || '');
    if (!state || !code) { errorPage(res, 400, 'Brak kodu logowania', 'Discord nie przekazał kodu autoryzacji.'); return; }
    const cookies = auth.parseCookies(req.headers.cookie || '');
    if (!auth.safeEqual(cookies[config.oauthCookie], state)) {
      errorPage(res, 400, 'Nieprawidłowy stan logowania', 'Sesja logowania wygasła albo została przerwana. Spróbuj ponownie.');
      return;
    }
    const row = await db.queryOne('SELECT * FROM oauth_states WHERE state = ? AND expires_at > UTC_TIMESTAMP() LIMIT 1', [state]);
    if (!row) { errorPage(res, 400, 'Sesja logowania wygasła', 'Odśwież stronę i zaloguj się ponownie.'); return; }
    await db.query('DELETE FROM oauth_states WHERE state = ?', [state]);
    res.append('Set-Cookie', auth.serializeCookie(config.oauthCookie, '', { maxAge: 0, expires: new Date(0) }));

    const tokenRes = await discordFetch('https://discord.com/api/oauth2/token', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        client_id: config.discord.clientId,
        client_secret: config.discord.clientSecret,
        grant_type: 'authorization_code',
        code,
        redirect_uri: config.discord.redirectUri
      })
    });
    if (!tokenRes.res.ok || !tokenRes.data?.access_token) {
      await auth.audit(null, 'login.failed', { step: 'token', status: tokenRes.res.status, error: tokenRes.data }, req);
      errorPage(res, 502, 'Discord odrzucił logowanie', 'Nie udało się wymienić kodu na token. Sprawdź konfigurację OAuth2.', tokenRes.data?.error || '');
      return;
    }
    const meRes = await discordFetch('https://discord.com/api/users/@me', {
      headers: { Authorization: 'Bearer ' + tokenRes.data.access_token }
    });
    if (!meRes.res.ok || !meRes.data?.id) {
      errorPage(res, 502, 'Nie udało się pobrać profilu', 'Discord nie zwrócił danych użytkownika.');
      return;
    }
    const profile = { ...meRes.data, avatar_url: discordAvatar(meRes.data) };
    const role = await checkRole(profile.id);
    if (!role.ok) {
      await auth.audit(null, 'login.denied', { reason: role.reason, discordId: profile.id }, req);
      errorPage(res, 403, 'Brak dostępu do Alpha Vault', role.reason, role.detail || '');
      return;
    }
    const user = await upsertDiscordUser(profile, role.checked && role.ok);
    const session = await auth.createSession(user.id, !!row.remember, req);
    auth.setSessionCookie(res, session);
    await auth.audit(user.id, 'login.discord', { roleVerified: role.checked }, req);
    res.redirect('/#/pulpit');
  } catch (err) { next(err); }
});

router.post('/demo', auth.requireSameOrigin, auth.rateLimit('demo-login', 20, 60000), async (req, res, next) => {
  try {
    if (!config.allowDemoLogin) { res.status(403).json({ error: 'demo_disabled', message: 'Logowanie demo jest wyłączone.' }); return; }
    let user = await db.queryOne("SELECT * FROM users WHERE discord_id = 'demo-local' LIMIT 1");
    if (!user) {
      const result = await db.query(
        `INSERT INTO users (discord_id, username, global_name, avatar_url, email, is_demo, plan, access_until, role_verified, last_login_at)
         VALUES ('demo-local', 'Kasia (demo)', 'Kasia', NULL, 'kasia@example.com', 1, 'full', '2099-12-31', 1, CURRENT_TIMESTAMP)`
      );
      user = await db.queryOne('SELECT * FROM users WHERE id = ?', [result.insertId]);
    } else {
      await db.query('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?', [user.id]);
    }
    const remember = req.body?.remember !== false;
    const session = await auth.createSession(user.id, remember, req);
    auth.setSessionCookie(res, session);
    await auth.audit(user.id, 'login.demo', { remember }, req);
    res.json({ ok: true, user: auth.publicUser({
      id: user.id, username: user.username, globalName: user.global_name, avatarUrl: user.avatar_url,
      email: user.email, isDemo: true, plan: user.plan, accessUntil: user.access_until, roleVerified: true
    }), redirect: '/' });
  } catch (err) { next(err); }
});

router.all('/logout', auth.requireSameOrigin, async (req, res, next) => {
  try {
    const userId = req.user?.id || null;
    await auth.destroySession(req, res);
    await auth.audit(userId, 'logout', null, req);
    if (req.method === 'GET') { res.redirect('/'); return; }
    res.json({ ok: true });
  } catch (err) { next(err); }
});

module.exports = router;
