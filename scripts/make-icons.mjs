#!/usr/bin/env node
/**
 * Generuje ikony PWA z logo Alpha Vault osadzonego w public/index.html.
 * Uruchom: node scripts/make-icons.mjs
 */
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright-core';

const root = path.resolve(import.meta.dirname, '..');
const html = fs.readFileSync(path.join(root, 'public/index.html'), 'utf8');
const match = html.match(/data:image\/png;base64,([A-Za-z0-9+/=]+)/);
if (!match) throw new Error('Nie znaleziono logo w public/index.html');
const logo = 'data:image/png;base64,' + match[1];

const candidates = [
  process.env.CHROME_PATH,
  path.join(process.env.LOCALAPPDATA || '', 'Google', 'Chrome', 'Application', 'chrome.exe'),
  'C:/Program Files/Google/Chrome/Application/chrome.exe',
  'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
  'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
  '/usr/bin/google-chrome',
  '/usr/bin/google-chrome-stable',
  '/usr/bin/chromium',
  '/usr/bin/chromium-browser'
].filter(Boolean);
const executablePath = candidates.find((p) => fs.existsSync(p));
if (!executablePath) throw new Error('Brak Chrome/Edge. Ustaw CHROME_PATH.');

const browser = await chromium.launch({ executablePath, headless: true, args: ['--no-sandbox'] });
const page = await browser.newPage({ viewport: { width: 512, height: 512 }, deviceScaleFactor: 1 });

const targets = [
  { file: 'icon-192.png', size: 192, logoScale: 0.82 },
  { file: 'icon-512.png', size: 512, logoScale: 0.82 },
  { file: 'icon-maskable-512.png', size: 512, logoScale: 0.58 },
  { file: 'apple-touch-icon.png', size: 180, logoScale: 0.78 }
];

const outDir = path.join(root, 'public/assets/pwa');
fs.mkdirSync(outDir, { recursive: true });

for (const target of targets) {
  const { size, logoScale } = target;
  await page.setViewportSize({ width: size, height: size });
  await page.setContent(`<!doctype html><html><body style="margin:0;width:${size}px;height:${size}px;background:#080707;display:grid;place-items:center;overflow:hidden"><img src="${logo}" alt="" style="width:${Math.round(size * logoScale)}px;height:${Math.round(size * logoScale)}px;object-fit:contain"></body></html>`);
  await page.screenshot({ path: path.join(outDir, target.file), clip: { x: 0, y: 0, width: size, height: size } });
  console.log('OK', target.file, size + 'x' + size);
}

await browser.close();
