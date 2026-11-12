#!/usr/bin/env node
/**
 * Test spójności dokumentacji:
 *  - wszystkie względne linki prowadzą do istniejących plików,
 *  - dokumenty główne wskazują na siebie,
 *  - wymagane rozdziały istnieją,
 *  - public/docs jest zsynchronizowane ze źródłami.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { checkDocs, MANAGED } from '../scripts/sync-docs.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
let failed = 0;

function check(ok, label, detail = '') {
  console.log((ok ? 'PASS' : 'FAIL') + '  ' + label + (detail ? ' · ' + detail : ''));
  if (!ok) failed++;
}

function read(rel) {
  return fs.readFileSync(path.join(ROOT, rel), 'utf8').replace(/\r\n/g, '\n');
}

function stripCode(content) {
  return content
    .replace(/```[\s\S]*?```/g, '')
    .replace(/`[^`\n]*`/g, '');
}

function relativeLinks(content) {
  const links = [];
  const regex = /\[[^\]]*\]\(([^)\s]+)\)/g;
  let match;
  while ((match = regex.exec(content)) !== null) {
    links.push(match[1]);
  }
  return links;
}

const canonical = [
  'README.md',
  'PRD.md',
  'DOCUMENTATION.md',
  'AGENTS.md',
  ...fs.readdirSync(path.join(ROOT, 'docs'))
    .filter((name) => name.endsWith('.md'))
    .map((name) => 'docs/' + name)
];

let linkCount = 0;
const broken = [];
for (const rel of canonical) {
  if (!fs.existsSync(path.join(ROOT, rel))) {
    broken.push(rel + ' (brak pliku)');
    continue;
  }
  const content = stripCode(read(rel));
  for (const raw of relativeLinks(content)) {
    if (/^(https?:|mailto:|#)/i.test(raw)) continue;
    const target = raw.split('#')[0];
    if (target === '') continue;
    linkCount++;
    const resolved = path.resolve(path.dirname(path.join(ROOT, rel)), decodeURI(target));
    if (!resolved.startsWith(ROOT + path.sep)) {
      broken.push(rel + ' → ' + raw + ' (poza repo)');
    } else if (!fs.existsSync(resolved)) {
      broken.push(rel + ' → ' + raw);
    }
  }
}
check(broken.length === 0, 'linki względne w dokumentacji', broken.length ? broken.join('; ') : linkCount + ' linków');

const publicDocs = fs.readdirSync(path.join(ROOT, 'public', 'docs'))
  .filter((name) => name.endsWith('.md'))
  .map((name) => 'public/docs/' + name);
const brokenPublic = [];
for (const rel of publicDocs) {
  const content = stripCode(read(rel));
  for (const raw of relativeLinks(content)) {
    if (/^(https?:|mailto:|#)/i.test(raw)) continue;
    const target = raw.split('#')[0];
    if (target === '') continue;
    const resolved = path.resolve(path.dirname(path.join(ROOT, rel)), target);
    if (!fs.existsSync(resolved)) brokenPublic.push(rel + ' → ' + raw);
  }
}
check(brokenPublic.length === 0, 'linki względne w public/docs', brokenPublic.slice(0, 5).join('; ') || publicDocs.length + ' plików');

const mojibake = ['Ĺ', 'â€', 'Ăł', 'Ä™', 'Ĺ‚', 'ĹĽ', 'Ĺ›', 'Ĺ„'];
const badEncoding = canonical.filter((rel) => {
  const text = fs.readFileSync(path.join(ROOT, rel), 'utf8');
  return mojibake.some((token) => text.includes(token));
});
check(badEncoding.length === 0, 'kodowanie UTF-8 dokumentów', badEncoding.join(', '));

const prd = read('PRD.md');
const doc = read('DOCUMENTATION.md');
const agents = read('AGENTS.md');
const docsIndex = read('docs/README.md');

check(prd.includes('## 8. Kryteria akceptacji'), 'PRD: kryteria akceptacji');
check(prd.includes('## 11. Status wdrożenia'), 'PRD: status wdrożenia');
check(doc.includes('## 2. Mapa systemu i punkty styku'), 'DOCUMENTATION: punkty styku');
check(doc.includes('## 7. Discord Scheduled Events'), 'DOCUMENTATION: pełny przepływ Discord');
check(doc.includes('## 17. Zarządzanie dokumentacją'), 'DOCUMENTATION: zarządzanie dokumentacją');
check(agents.includes('## 3. Niezmienne zasady'), 'AGENTS: zasady');
check(agents.includes('## 7. Testy i Definition of Done'), 'AGENTS: Definition of Done');

check(prd.includes('DOCUMENTATION.md') && prd.includes('AGENTS.md'), 'PRD → DOCUMENTATION + AGENTS');
check(doc.includes('PRD.md') && doc.includes('AGENTS.md'), 'DOCUMENTATION → PRD + AGENTS');
check(agents.includes('PRD.md') && agents.includes('DOCUMENTATION.md'), 'AGENTS → PRD + DOCUMENTATION');
check(docsIndex.includes('../PRD.md') && docsIndex.includes('../DOCUMENTATION.md') && docsIndex.includes('../AGENTS.md'), 'docs/README → dokumenty główne');

const drift = checkDocs();
check(drift.length === 0, 'public/docs zsynchronizowane', drift.length ? drift.join(', ') : MANAGED.length + ' plików');

const missingPublic = MANAGED.filter((entry) => !fs.existsSync(path.join(ROOT, 'public', 'docs', entry.out)));
check(missingPublic.length === 0, 'public/docs zawiera wszystkie dokumenty', missingPublic.map((e) => e.out).join(', '));

console.log('\n' + (failed === 0 ? 'Dokumentacja: all PASS' : 'Dokumentacja: ' + failed + ' FAIL'));
if (failed > 0) process.exit(1);
