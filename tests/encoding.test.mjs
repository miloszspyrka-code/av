#!/usr/bin/env node
/**
 * Test kodowania UTF-8:
 *  - naprawa mojibake UTF-8 → Windows-1250 → UTF-8,
 *  - brak mojibake w krytycznych plikach aplikacji.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { repairMojibake, hasMojibake } from '../scripts/lib/mojibake.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const cp1250 = new TextDecoder('windows-1250');
let failed = 0;

function check(ok, label, detail = '') {
  console.log((ok ? 'PASS' : 'FAIL') + '  ' + label + (detail ? ' · ' + detail : ''));
  if (!ok) failed++;
}

/** Build the corruption on the fly: UTF-8 bytes decoded as Windows-1250. */
function mojibake(text) {
  return cp1250.decode(Buffer.from(text, 'utf8'));
}

for (const sample of ['Wartość pozycji', 'depozyt przy dźwigni 50× · 1,7% salda', 'Usuń transakcję?']) {
  const broken = mojibake(sample);
  check(broken !== sample && repairMojibake(broken) === sample, 'naprawa: ' + sample, broken);
}

check(
  repairMojibake('Prawidłowy tekst: żółć, jaźń, ĄĆĘŁŃÓŚŹŻ') === 'Prawidłowy tekst: żółć, jaźń, ĄĆĘŁŃÓŚŹŻ',
  'poprawny tekst bez zmian'
);

const critical = ['public/js/actions.js', 'tests/prop.unit.mjs', 'tests/discord-events.test.php'];
for (const rel of critical) {
  const lines = fs.readFileSync(path.join(ROOT, rel), 'utf8').split('\n');
  const bad = lines.map((line, i) => (hasMojibake(line) ? i + 1 : 0)).filter(Boolean);
  check(bad.length === 0, 'brak mojibake: ' + rel, bad.slice(0, 5).join(', '));
}

if (failed > 0) {
  console.error(`\n${failed} test(s) failed.`);
  process.exit(1);
}
console.log('\nAll encoding tests passed.');
