#!/usr/bin/env node
// Repair UTF-8 text corrupted by a UTF-8 -> Windows-1250 -> UTF-8 roundtrip.
//
// Usage:
//   node scripts/fix-mojibake.mjs <file...>          # dry run, prints report
//   node scripts/fix-mojibake.mjs --write <file...>  # rewrites files in place

import { readFile, writeFile } from 'node:fs/promises';
import { repairMojibake } from './lib/mojibake.mjs';

const args = process.argv.slice(2);
const write = args.includes('--write');
const files = args.filter((arg) => arg !== '--write');

if (files.length === 0) {
  console.error('Usage: node scripts/fix-mojibake.mjs [--write] <file...>');
  process.exit(2);
}

let changedTotal = 0;
let exitCode = 0;

for (const file of files) {
  const source = await readFile(file, 'utf8');
  let changed = 0;
  const output = source
    .split('\n')
    .map((line) => {
      const repaired = repairMojibake(line);
      if (repaired !== line) changed++;
      return repaired;
    })
    .join('\n');

  if (changed === 0) continue;
  changedTotal += changed;
  console.log(`${write ? 'repaired' : 'would repair'} ${changed} line(s): ${file}`);
  if (write) await writeFile(file, output, 'utf8');
}

console.log(`${changedTotal} line(s) ${write ? 'repaired' : 'need repair'}.`);
if (!write && changedTotal > 0) exitCode = 1;
process.exit(exitCode);
