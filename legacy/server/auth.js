'use strict';
const crypto = require('crypto');
const config = require('./config');
const db = require('./db');

const nowSql = () => new Date().toISOString().slice(0, 19).replace('T', ' ');

function parseCookies(header = '') {
  const out = {};
  String(header).split(';').forEach((part) => {
    const i = part.indexOf('=');
    if (i < 0) return;
    const k = part.slice(0, i).trim();
    const v = part.slice(i + 1).trim();
    if (!k) return;
    try { out[k] = decodeURIComponent(v); } catch { out[k] = v; }
  });
  return out;
}

function serializeCookie(name, value, opts = {}) {
  const parts = [`${name}=${encodeURIComponent(value)}`, 'Path=/', 'HttpOnly', 'SameSite=Lax'];
  if (opts.maxAge != null) parts.push(`Max-Age=${Math.floor(opts.maxAge)}`);
  if (opts.expires) parts.push(`Expires=${new Date(opts.expires).toUTCString()}`);
  if (config.secureCookies) parts.push('Secure');
  return parts.join('; ');
}

function randomToken(bytes = 32) {
  return crypto.randomBytes(bytes).toString('base64url');
}

function hashToken(token) {
  return crypto.createHash('sha256').update(String(token)).digest('hex');
}

function safeEqual(a, b) {
  const aa = Buffer.from(String(a || ''));
  const bb = Buffer.from(String(b || ''));
  if (aa.length !== bb.length) return false;
  return crypto.timingSafeEqual(aa, bb);
}

function clientIp(req) {
  return String(req.headers['x-forwarded-for'] || req.socket.remoteAddress || '').split(',')[0].trim().slice(0, 64);
}

function userAgent(req) {
  return String(req.headers['user-agent'] || '').slice(0, 512);
}

async function audit(userId, action, detail, req) {
  try {
    await db.query(
      'INSERT INTO audit_log (user_id, action, detail, ip, user_agent) VALUES (?, ?, ?, ?, ?)',
      [userId || null, action, detail ? JSON.stringify(detail) : null, clientIp(req), userAgent(req)]
    );
  } catch (err) {
    console.error('[audit]', err.message);
  }
}

async function createSession(userId, remember, req) {
  const token = randomToken(32);
  const id = hashToken(token);
  const days = config.sessionDays;
  const expires = new Date(Date.now() + days * 864e5);
  await db.query(
    'INSERT INTO sessions (id, user_id, remember, user_agent, ip, expires_at) VALUES (?, ?, ?, ?, ?, ?)',
    [id, userId, remember ? 1 : 0, userAgent(req), clientIp(req), nowSqlOf(expires)]
  );
  return { token, expires, days };
}

function nowSqlOf(d) {
  return new Date(d).toISOString().slice(0, 19).replace('T', ' ');
}

function setSessionCookie(res, session) {
  const maxAge = session.days * 86400;
  res.append('Set-Cookie', serializeCookie(config.sessionCookie, session.token, { maxAge, expires: session.expires }));
}

function clearSessionCookie(res) {
  res.append('Set-Cookie', serializeCookie(config.sessionCookie, '', { maxAge: 0, expires: new Date(0) }));
}

async function readSession(req) {
  const cookies = parseCookies(req.headers.cookie || '');
  const token = cookies[config.sessionCookie];
  if (!token) return null;
  const id = hashToken(token);
  const row = await db.queryOne(
    `SELECT s.id AS session_id, s.remember, s.expires_at, u.*
       FROM sessions s JOIN users u ON u.id = s.user_id
      WHERE s.id = ? AND s.expires_at > UTC_TIMESTAMP()
      LIMIT 1`,
    [id]
  );
  if (!row) return null;
  // odświeżaj last_seen najwyżej raz na 5 minut
  if (!row.last_seen_at || Date.now() - new Date(row.last_seen_at).getTime() > 5 * 60000) {
    db.query('UPDATE sessions SET last_seen_at = CURRENT_TIMESTAMP WHERE id = ?', [id]).catch(() => {});
  }
  return {
    sessionId: id,
    remember: !!row.remember,
    expiresAt: row.expires_at,
    user: {
      id: row.id,
      discordId: row.discord_id,
      username: row.username,
      globalName: row.global_name,
      avatarUrl: row.avatar_url,
      email: row.email,
      isDemo: !!row.is_demo,
      plan: row.plan,
      accessUntil: row.access_until,
      roleVerified: !!row.role_verified,
      createdAt: row.created_at,
      lastLoginAt: row.last_login_at
    }
  };
}

async function sessionMiddleware(req, res, next) {
  try {
    req.session = await readSession(req);
    req.user = req.session?.user || null;
    next();
  } catch (err) {
    // Baza może być chwilowo niedostępna — nie blokuj statycznych plików i /api/health.
    req.session = null;
    req.user = null;
    req.dbError = err;
    next();
  }
}

function requireAuth(req, res, next) {
  if (!req.user) { res.status(401).json({ error: 'unauthorized', message: 'Zaloguj się przez Discord.' }); return; }
  next();
}

async function destroySession(req, res) {
  const cookies = parseCookies(req.headers.cookie || '');
  const token = cookies[config.sessionCookie];
  if (token) await db.query('DELETE FROM sessions WHERE id = ?', [hashToken(token)]);
  clearSessionCookie(res);
}

function subscription(user) {
  const until = user.accessUntil ? new Date(user.accessUntil + 'T23:59:59Z') : null;
  const expired = !!(until && until.getTime() < Date.now());
  return {
    plan: user.plan,
    accessUntil: user.accessUntil,
    expired,
    roleVerified: user.roleVerified
  };
}

function publicUser(user) {
  return {
    id: user.id,
    username: user.username,
    globalName: user.globalName,
    avatarUrl: user.avatarUrl,
    email: user.email,
    isDemo: user.isDemo,
    subscription: subscription(user)
  };
}

function requireSameOrigin(req, res, next) {
  const origin = req.headers.origin;
  if (origin && origin !== config.origin) {
    res.status(403).json({ error: 'bad_origin', message: 'Nieprawidłowe źródło żądania.' });
    return;
  }
  next();
}

const rateBuckets = new Map();
function rateLimit(name, limit, windowMs) {
  return (req, res, next) => {
    const key = name + ':' + clientIp(req);
    const now = Date.now();
    let b = rateBuckets.get(key);
    if (!b || b.reset < now) { b = { count: 0, reset: now + windowMs }; rateBuckets.set(key, b); }
    b.count++;
    if (b.count > limit) {
      res.status(429).json({ error: 'rate_limited', message: 'Zbyt wiele prób. Spróbuj za chwilę.' });
      return;
    }
    next();
  };
}

module.exports = {
  parseCookies, serializeCookie, randomToken, hashToken, safeEqual, clientIp, userAgent,
  audit, createSession, setSessionCookie, clearSessionCookie, readSession, sessionMiddleware,
  requireAuth, destroySession, subscription, publicUser, requireSameOrigin, rateLimit, nowSqlOf
};
