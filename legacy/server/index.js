'use strict';
const path = require('path');
const express = require('express');
const config = require('./config');
const db = require('./db');
const auth = require('./auth');
const apiRoutes = require('./routes/api');
const authRoutes = require('./routes/auth');

const app = express();
app.disable('x-powered-by');
app.set('trust proxy', 1);

app.use((req, res, next) => {
  res.set({
    'X-Content-Type-Options': 'nosniff',
    'X-Frame-Options': 'DENY',
    'Referrer-Policy': 'strict-origin-when-cross-origin',
    'Permissions-Policy': 'camera=(), microphone=(), geolocation=()',
    'Cross-Origin-Opener-Policy': 'same-origin',
    'Content-Security-Policy': [
      "default-src 'self'",
      "script-src 'self'",
      "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
      "font-src 'self' https://fonts.gstatic.com data:",
      "img-src 'self' data: blob: https://cdn.discordapp.com",
      "connect-src 'self'",
      "frame-ancestors 'none'",
      "base-uri 'self'",
      "form-action 'self'"
    ].join('; ')
  });
  if (config.secureCookies) res.set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
  next();
});

app.use(express.json({ limit: config.maxStateBytes }));
app.use(express.urlencoded({ extended: false, limit: '64kb' }));

app.use('/api', auth.sessionMiddleware, apiRoutes);
app.use('/auth', auth.sessionMiddleware, authRoutes);

const staticOpts = {
  index: false,
  dotfiles: 'deny',
  etag: true,
  maxAge: config.isProd ? '1h' : 0,
  setHeaders: (res) => res.set('Cache-Control', config.isProd ? 'public, max-age=3600' : 'no-cache')
};
app.use('/js', express.static(path.join(config.root, 'js'), staticOpts));
app.use('/docs', express.static(path.join(config.root, 'docs'), staticOpts));
app.use('/assets', express.static(path.join(config.root, 'assets'), staticOpts));

app.get('/', (req, res) => {
  res.set('Cache-Control', 'no-store');
  res.sendFile(path.join(config.root, 'index.html'));
});
app.get('/index.html', (req, res) => res.redirect('/'));
app.get('/favicon.ico', (req, res) => res.status(204).end());

app.get('/docs/', (req, res) => {
  res.set('Content-Type', 'text/html; charset=utf-8');
  res.send(`<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Dokumentacja · Alpha Vault</title>
<style>body{background:#080707;color:#f5f1eb;font-family:system-ui,Arial,sans-serif;max-width:820px;margin:0 auto;padding:40px 22px;line-height:1.6}h1{font-size:34px;letter-spacing:-1px}a{color:#f1d48e}li{margin:8px 0}code{background:rgba(217,178,95,.12);padding:2px 6px;border-radius:5px;color:#f1d48e}small{color:#7f786f}</style></head>
<body><h1>Alpha Vault · dokumentacja</h1><p>Dokumentacja techniczna i produktowa.</p><ul>
<li><a href="/docs/README.md">Przegląd projektu</a></li>
<li><a href="/docs/API.md">API i logowanie Discord</a></li>
<li><a href="/docs/ARCHITEKTURA.md">Architektura</a></li>
<li><a href="/docs/BAZA-DANYCH.md">Baza danych MySQL</a></li>
<li><a href="/docs/FUNKCJE.md">Funkcje aplikacji</a></li>
<li><a href="/docs/TESTOWANIE.md">Testy e2e</a></li>
</ul><p><a href="/">← Wróć do aplikacji</a></p></body></html>`);
});

app.use((req, res) => {
  if (req.path.startsWith('/api') || req.path.startsWith('/auth')) {
    res.status(404).json({ error: 'not_found', message: 'Nie ma takiego endpointu.' });
    return;
  }
  res.status(404).type('html').send('<!doctype html><meta charset="utf-8"><body style="background:#080707;color:#f5f1eb;font-family:Arial;padding:40px"><h1>404</h1><p>Nie ma takiej strony. <a style="color:#f1d48e" href="/">Wróć do Alpha Vault</a>.</p></body>');
});

app.use((err, req, res, next) => {
  console.error('[error]', err);
  if (res.headersSent) { next(err); return; }
  if (req.path.startsWith('/api') || req.path.startsWith('/auth')) {
    const status = err.status || err.statusCode || 500;
    res.status(status).json({
      error: status === 413 ? 'payload_too_large' : 'server_error',
      message: status === 413 ? 'Żądanie jest za duże.' : (config.isProd ? 'Błąd serwera.' : err.message)
    });
    return;
  }
  res.status(500).type('html').send('<!doctype html><meta charset="utf-8"><body style="background:#080707;color:#f5f1eb;font-family:Arial;padding:40px"><h1>Błąd serwera</h1><p>Odśwież stronę za chwilę. <a style="color:#f1d48e" href="/">Wróć do Alpha Vault</a>.</p></body>');
});

async function start() {
  try {
    await db.migrate();
    const h = await db.health();
    console.log(`[db] MySQL ${h.version} · ${config.db.host}:${config.db.port}/${config.db.database} · ${h.latencyMs} ms`);
  } catch (err) {
    console.error('[db] Baza niedostępna — aplikacja wstanie, API zwróci 503. Błąd:', err.message);
  }
  return new Promise((resolve) => {
    const server = app.listen(config.port, '127.0.0.1', () => {
      console.log(`Alpha Vault: ${config.appUrl}  (port ${config.port})`);
      console.log(`Discord OAuth: ${config.discordConfigured ? 'skonfigurowany' : 'brak konfiguracji — tryb demo'}`);
      console.log(`Demo login: ${config.allowDemoLogin ? 'włączony' : 'wyłączony'}`);
      resolve(server);
    });
  });
}

if (require.main === module) {
  start().then((server) => {
    const shutdown = async () => {
      console.log('\nZamykanie…');
      server.close(async () => { await db.close(); process.exit(0); });
      setTimeout(() => process.exit(0), 4000).unref();
    };
    process.on('SIGINT', shutdown);
    process.on('SIGTERM', shutdown);
  }).catch((err) => { console.error(err); process.exit(1); });
}

module.exports = { app, start };
