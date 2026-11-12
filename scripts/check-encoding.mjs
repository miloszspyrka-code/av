#!/usr/bin/env node
// Guard against broken Polish characters in the repository.
//
// Fails when a tracked text file is not valid UTF-8 or still contains the
// UTF-8 -> Windows-1250 -> UTF-8 mojibake pattern (e.g. "WartoĹ›Ä‡").
// Run: node scripts/check-encoding.mjs

import { readdir, readFile } from 'node:fs/promises';
import { join, relative } from 'node:path';
import { hasMojibake } from './lib/mojibake.mjs';

const ROOT = process.cwd();
const SKIP_DIRS = new Set(['.git', 'node_modules', 'vendor', 'cache', 'logs', 'coverage', 'test-results']);
const TEXT_FILE = /\.(php|js|mjs|cjs|ts|json|html|css|md|yml|yaml|sh|sql|txt|xml|svg)$/i;
const ALLOWLIST = new Set([
  // Intentional mojibake fixtures used to test the documentation guard.
  'tests/docs.test.mjs',
  // This guard and its helper contain the patterns they detect.
  'scripts/check-encoding.mjs',
  'scripts/lib/mojibake.mjs',
  'scripts/fix-mojibake.mjs',
]);

const fatalUtf8 = new TextDecoder('utf-8', { fatal: true });
const problems = [];

async function walk(dir) {
  for (const entry of await readdir(dir, { withFileTypes: true })) {
    if (SKIP_DIRS.has(entry.name)) continue;
    const path = join(dir, entry.name);
    if (entry.isDirectory()) {
      await walk(path);
      continue;
    }
    if (!TEXT_FILE.test(entry.name)) continue;

    const rel = relative(ROOT, path).replaceAll('\\', '/');
    if (ALLOWLIST.has(rel)) continue;

    const buffer = await readFile(path);
    try {
      fatalUtf8.decode(buffer);
    } catch {
      problems.push(`${rel}: invalid UTF-8`);
      continue;
    }

    const text = buffer.toString('utf8');
    text.split('\n').forEach((line, index) => {
      if (hasMojibake(line)) problems.push(`${rel}:${index + 1}: mojibake`);
    });
  }
}

await walk(ROOT);

if (problems.length > 0) {
  console.error(`Encoding check failed (${problems.length} problem(s)):`);
  for (const problem of problems.slice(0, 50)) console.error(`- ${problem}`);
  if (problems.length > 50) console.error(`... and ${problems.length - 50} more`);
  console.error('Fix with: node scripts/fix-mojibake.mjs --write <file>');
  process.exit(1);
}

console.log('Encoding check passed.');
