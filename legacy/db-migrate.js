'use strict';
const db = require('../server/db');

db.migrate()
  .then(() => db.health())
  .then((h) => { console.log('MySQL OK:', h.version, h.latencyMs + ' ms'); return db.close(); })
  .catch((err) => { console.error('Błąd migracji:', err.message); process.exitCode = 1; });
