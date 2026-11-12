'use strict';
const fs = require('fs');
const express = require('express');
const config = require('../config');
const db = require('../db');
const auth = require('../auth');

const router = express.Router();

let demoTemplate = null;
try {
  if (fs.existsSync(config.demoStateFile)) {
    demoTemplate = JSON.parse(fs.readFileSync(config.demoStateFile, 'utf8'));
  }
} catch (err) {
  console.warn('[demo] nie udało się wczytać db/demo-state.json:', err.message);
}

router.get('/health', async (req, res) => {
  try {
    const h = await db.health();
    res.json({ ok: h.ok, service: 'alpha-vault', version: '2.1.0', db: h, uptime: Math.round(process.uptime()), now: new Date().toISOString() });
  } catch (err) {
    res.status(503).json({ ok: false, service: 'alpha-vault', error: 'db_unavailable', message: err.message });
  }
});

router.get('/me', auth.sessionMiddleware, (req, res) => {
  res.set('Cache-Control', 'no-store');
  const base = {
    authenticated: !!req.user,
    discordConfigured: config.discordConfigured,
    roleCheckConfigured: config.roleCheckConfigured,
    demoAllowed: config.allowDemoLogin,
    db: config.db.database
  };
  if (!req.user) { res.json(base); return; }
  res.json({ ...base, user: auth.publicUser(req.user) });
});

router.get('/state', auth.requireAuth, async (req, res, next) => {
  try {
    let row = await db.queryOne('SELECT state, revision, bytes, updated_at FROM user_states WHERE user_id = ?', [req.user.id]);
    if (!row && req.user.isDemo && demoTemplate) {
      const json = JSON.stringify(demoTemplate);
      await db.query(
        'INSERT INTO user_states (user_id, state, revision, bytes) VALUES (?, ?, 1, ?) ON DUPLICATE KEY UPDATE state = VALUES(state)',
        [req.user.id, json, Buffer.byteLength(json)]
      );
      row = await db.queryOne('SELECT state, revision, bytes, updated_at FROM user_states WHERE user_id = ?', [req.user.id]);
      await auth.audit(req.user.id, 'state.seeded_demo', { bytes: row.bytes }, req);
    }
    res.set('Cache-Control', 'no-store');
    if (!row) { res.json({ state: null, revision: 0, updatedAt: null }); return; }
    res.json({ state: row.state, revision: row.revision, bytes: row.bytes, updatedAt: row.updated_at });
  } catch (err) { next(err); }
});

router.put('/state', auth.requireAuth, auth.requireSameOrigin, auth.rateLimit('state-save', 180, 60000), async (req, res, next) => {
  try {
    const state = req.body?.state;
    const baseRevision = Number(req.body?.baseRevision);
    if (!state || typeof state !== 'object' || Array.isArray(state)) {
      res.status(400).json({ error: 'bad_state', message: 'Pole state musi być obiektem JSON.' });
      return;
    }
    const json = JSON.stringify(state);
    const bytes = Buffer.byteLength(json);
    if (bytes > config.maxStateBytes) {
      res.status(413).json({ error: 'state_too_large', message: 'Stan jest za duży. Usuń część screenów.', bytes, limit: config.maxStateBytes });
      return;
    }
    const existing = await db.queryOne('SELECT revision FROM user_states WHERE user_id = ?', [req.user.id]);
    if (!existing) {
      await db.query('INSERT INTO user_states (user_id, state, revision, bytes) VALUES (?, ?, 1, ?)', [req.user.id, json, bytes]);
      await auth.audit(req.user.id, 'state.create', { bytes, revision: 1 }, req);
      res.json({ ok: true, revision: 1, bytes, updatedAt: new Date().toISOString() });
      return;
    }
    if (Number.isFinite(baseRevision) && baseRevision !== existing.revision) {
      res.status(409).json({ error: 'revision_conflict', message: 'Dane zmienione w innej karcie.', revision: existing.revision });
      return;
    }
    const nextRevision = existing.revision + 1;
    const result = await db.query(
      'UPDATE user_states SET state = ?, revision = ?, bytes = ? WHERE user_id = ? AND revision = ?',
      [json, nextRevision, bytes, req.user.id, existing.revision]
    );
    if (!result.affectedRows) {
      const current = await db.queryOne('SELECT revision FROM user_states WHERE user_id = ?', [req.user.id]);
      res.status(409).json({ error: 'revision_conflict', message: 'Dane zmienione w innej karcie.', revision: current?.revision || 0 });
      return;
    }
    await auth.audit(req.user.id, 'state.save', { bytes, revision: nextRevision }, req);
    res.json({ ok: true, revision: nextRevision, bytes, updatedAt: new Date().toISOString() });
  } catch (err) { next(err); }
});

router.delete('/state', auth.requireAuth, auth.requireSameOrigin, async (req, res, next) => {
  try {
    await db.query('DELETE FROM user_states WHERE user_id = ?', [req.user.id]);
    await auth.audit(req.user.id, 'state.delete', null, req);
    res.json({ ok: true });
  } catch (err) { next(err); }
});

router.delete('/account', auth.requireAuth, auth.requireSameOrigin, async (req, res, next) => {
  try {
    const userId = req.user.id;
    await auth.destroySession(req, res);
    await db.query('DELETE FROM users WHERE id = ?', [userId]);
    await auth.audit(userId, 'account.delete', { demo: req.user.isDemo }, req);
    res.json({ ok: true });
  } catch (err) { next(err); }
});

router.get('/me/export', auth.requireAuth, async (req, res, next) => {
  try {
    const row = await db.queryOne('SELECT state, revision, updated_at FROM user_states WHERE user_id = ?', [req.user.id]);
    res.set('Content-Disposition', `attachment; filename="alpha-vault-${new Date().toISOString().slice(0, 10)}.json"`);
    res.type('application/json').send(JSON.stringify({ user: auth.publicUser(req.user), revision: row?.revision || 0, updatedAt: row?.updated_at || null, state: row?.state || null }, null, 2));
  } catch (err) { next(err); }
});

module.exports = router;
