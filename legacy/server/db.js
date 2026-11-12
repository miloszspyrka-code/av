'use strict';
const fs = require('fs');
const path = require('path');
const mysql = require('mysql2/promise');
const config = require('./config');

const pool = mysql.createPool({
  host: config.db.host,
  port: config.db.port,
  user: config.db.user,
  password: config.db.password,
  database: config.db.database,
  waitForConnections: true,
  connectionLimit: 10,
  queueLimit: 0,
  charset: 'utf8mb4',
  dateStrings: true,
  timezone: 'Z',
  enableKeepAlive: true
});

async function query(sql, params = []) {
  const [rows] = await pool.execute(sql, params);
  return rows;
}

async function queryOne(sql, params = []) {
  const rows = await query(sql, params);
  return rows[0] || null;
}

async function withTransaction(fn) {
  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();
    const result = await fn(conn);
    await conn.commit();
    return result;
  } catch (err) {
    try { await conn.rollback(); } catch {}
    throw err;
  } finally {
    conn.release();
  }
}

async function migrate() {
  const schema = fs.readFileSync(path.join(config.root, 'db', 'schema.sql'), 'utf8');
  const conn = await mysql.createConnection({
    host: config.db.host,
    port: config.db.port,
    user: config.db.user,
    password: config.db.password,
    database: config.db.database,
    multipleStatements: true,
    charset: 'utf8mb4'
  });
  try {
    await conn.query(schema);
    await conn.query(
      'INSERT INTO schema_migrations (version) VALUES (?) ON DUPLICATE KEY UPDATE version = VALUES(version)',
      ['001_initial']
    );
    console.log('[db] schemat gotowy');
  } finally {
    await conn.end();
  }
}

async function health() {
  const start = Date.now();
  const row = await queryOne('SELECT 1 AS ok, VERSION() AS version');
  return { ok: row?.ok === 1, version: row?.version, latencyMs: Date.now() - start };
}

async function close() {
  await pool.end();
}

module.exports = { pool, query, queryOne, withTransaction, migrate, health, close };
