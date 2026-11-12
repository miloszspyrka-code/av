#!/usr/bin/env node
// Scan repo for broken UTF-8 / mojibake (CP1252<->UTF-8 double encoding).
import { readdir, readFile, stat } from 'node:fs/promises';
import { join, relative } from 'node:path';

const ROOT = process.argv[2] || process.cwd();
const SKIP = new Set(['.git', 'node_modules', 'vendor', 'cache', 'logs']);
const EXT = /\.(php|js|mjs|cjs|ts|json|html|css|md|yml|yaml|sh|sql|txt|xml|svg)$/i;

// Mojibake markers: characters that appear when UTF-8 bytes are decoded as CP1252/Latin-1.
const MOJI = /(?:\u0139|\u0102|\u00e2\u20ac|\u00c3|\u00c5|\u00c2[\u00b7\u00d7\u00b0]|\u013d|\u013a|\u00c4[\u2122\u201e\u2026]|\u00e2\u201a\u00ac|\u00e2\u20ac\u201c|\u00e2\u20ac\u201d|\u00e2\u20ac\u017e|\u00e2\u20ac\u015b|\u00e2\u20ac\u00a6|\u00c2)/;

const files = [];
async function walk(dir) {
  for (const entry of await readdir(dir, { withFileTypes: true })) {
    if (SKIP.has(entry.name)) continue;
    const p = join(dir, entry.name);
    if (entry.isDirectory()) { await walk(p); continue; }
    if (!EXT.test(entry.name)) continue;
    files.push(p);
  }
}
await walk(ROOT);

let invalid = 0, replacement = 0, moji = 0;
const report = { invalid: [], replacement: [], moji: [] };

for (const file of files) {
  const buf = await readFile(file);
  const rel = relative(ROOT, file).replaceAll('\\', '/');
  // Strict UTF-8 validation
  try {
    new TextDecoder('utf-8', { fatal: true }).decode(buf);
  } catch {
    invalid++; report.invalid.push(rel); continue;
  }
  const text = buf.toString('utf8');
  if (text.includes('\uFFFD')) { replacement++; report.replacement.push(rel); }
  const lines = text.split(/\r?\n/);
  const hits = [];
  lines.forEach((l, i) => { if (MOJI.test(l)) hits.push(i + 1); });
  if (hits.length) { moji++; report.moji.push({ file: rel, lines: hits.slice(0, 12), count: hits.length }); }
}

console.log(JSON.stringify({
  scanned: files.length,
  invalidUtf8: invalid,
  replacementChar: replacement,
  mojibakeFiles: moji,
  report,
}, null, 2));
