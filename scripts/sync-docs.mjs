#!/usr/bin/env node
/**
 * Synchronizuje dokumentację źródłową do public/docs (serwowanego pod /docs/).
 *
 * Użycie:
 *   node scripts/sync-docs.mjs          # zapis
 *   node scripts/sync-docs.mjs --check  # tylko weryfikacja (CI)
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const PUBLIC_DIR = path.join(ROOT, 'public', 'docs');
const GITHUB = 'https://github.com/miloszspyrka-code/alphavault/blob/main/';

/** Dokumenty publiczne. Kolejność ma znaczenie tylko dla komunikatów. */
export const MANAGED = [
  { src: 'PRD.md', out: 'PRD.md', root: true },
  { src: 'DOCUMENTATION.md', out: 'DOCUMENTATION.md', root: true },
  { src: 'docs/README.md', out: 'README.md', root: false },
  { src: 'docs/API.md', out: 'API.md', root: false },
  { src: 'docs/ARCHITEKTURA.md', out: 'ARCHITEKTURA.md', root: false },
  { src: 'docs/BAZA-DANYCH.md', out: 'BAZA-DANYCH.md', root: false },
  { src: 'docs/FUNKCJE.md', out: 'FUNKCJE.md', root: false },
  { src: 'docs/TESTOWANIE.md', out: 'TESTOWANIE.md', root: false },
  { src: 'docs/openapi.yaml', out: 'openapi.yaml', root: false }
];

/** Dokumenty dostępne tylko w repo — w wersji publicznej linkujemy do GitHub. */
const REPO_ONLY = new Set([
  'AGENTS.md',
  'README.md',
  'CHANGELOG.md',
  'docs/AGENTS.md',
  'docs/DEPLOY.md',
  'docs/DISCORD.md',
  'docs/MCP.md',
  'docs/LOCAL-DEPLOY.md'
]);

const PUBLIC_NAMES = new Set(MANAGED.map((entry) => entry.out));

function githubUrl(target) {
  return GITHUB + target.replace(/^\.\//, '');
}

function rewriteTarget(target, isRoot) {
  if (/^(https?:|mailto:|#)/i.test(target)) return target;

  const [pathPart, hash = ''] = target.split('#');
  const suffix = hash ? '#' + hash : '';

  if (isRoot) {
    if (pathPart.startsWith('docs/')) {
      const name = pathPart.slice('docs/'.length);
      if (PUBLIC_NAMES.has(name)) return name + suffix;
      return githubUrl(pathPart) + suffix;
    }
    if (REPO_ONLY.has(pathPart)) return githubUrl(pathPart) + suffix;
    return target;
  }

  if (pathPart === '../PRD.md' || pathPart === '../DOCUMENTATION.md') {
    return pathPart.slice(3) + suffix;
  }
  if (pathPart.startsWith('../')) {
    const repoPath = pathPart.slice(3);
    return githubUrl(repoPath) + suffix;
  }
  if (PUBLIC_NAMES.has(pathPart)) return pathPart + suffix;
  if (REPO_ONLY.has('docs/' + pathPart)) return githubUrl('docs/' + pathPart) + suffix;
  return target;
}

function rewriteLinks(content, isRoot) {
  return content.replace(/\]\(([^)\s]+)\)/g, (match, target) => {
    return '](' + rewriteTarget(target, isRoot) + ')';
  });
}

function render(entry) {
  const source = path.join(ROOT, entry.src);
  if (!fs.existsSync(source)) {
    throw new Error('Brak pliku źródłowego: ' + entry.src);
  }
  let content = fs.readFileSync(source, 'utf8').replace(/\r\n/g, '\n');
  if (entry.out.endsWith('.md')) {
    content = rewriteLinks(content, entry.root);
    const banner = '<!-- Wygenerowane z ' + entry.src + ' przez scripts/sync-docs.mjs. Nie edytuj ręcznie. -->\n\n';
    content = banner + content;
  }
  return content;
}

export function checkDocs() {
  const drift = [];
  for (const entry of MANAGED) {
    const target = path.join(PUBLIC_DIR, entry.out);
    const expected = render(entry);
    const actual = fs.existsSync(target) ? fs.readFileSync(target, 'utf8').replace(/\r\n/g, '\n') : null;
    if (actual !== expected) drift.push(entry.out);
  }
  return drift;
}

export function writeDocs() {
  fs.mkdirSync(PUBLIC_DIR, { recursive: true });
  let written = 0;
  for (const entry of MANAGED) {
    const target = path.join(PUBLIC_DIR, entry.out);
    const expected = render(entry);
    const actual = fs.existsSync(target) ? fs.readFileSync(target, 'utf8').replace(/\r\n/g, '\n') : null;
    if (actual !== expected) {
      fs.writeFileSync(target, expected, 'utf8');
      written++;
    }
  }
  return written;
}

function main() {
  const check = process.argv.includes('--check');
  try {
    if (check) {
      const drift = checkDocs();
      if (drift.length) {
        console.error('[docs] Niespójne pliki w public/docs: ' + drift.join(', '));
        console.error('[docs] Uruchom: npm run docs:sync');
        process.exit(1);
      }
      console.log('[docs] OK — ' + MANAGED.length + ' plików w public/docs jest aktualnych.');
      return;
    }
    const written = writeDocs();
    console.log('[docs] Zapisano ' + written + ' z ' + MANAGED.length + ' plików.');
  } catch (err) {
    console.error('[docs] ' + err.message);
    process.exit(1);
  }
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  main();
}
