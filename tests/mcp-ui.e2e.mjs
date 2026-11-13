#!/usr/bin/env node
/**
 * Panel MCP E2E: propozycje, diff, jednorazowy kod, zatwierdzenie, odrzucenie, trwałość po reloadzie.
 * Wymaga serwera APP_URL i Chrome.
 */
import assert from 'node:assert/strict';
import { chromium } from 'playwright-core';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const BASE = process.env.APP_URL || 'http://127.0.0.1:4173';
const PHP = process.env.PHP_BIN || 'php';
const CONFIG = process.env.ALPHA_VAULT_CONFIG || path.join(ROOT, 'tests', 'config.test.json');
const candidates = [
  process.env.CHROME_PATH,
  path.join(process.env.LOCALAPPDATA || '', 'Google', 'Chrome', 'Application', 'chrome.exe'),
  'C:/Program Files/Google/Chrome/Application/chrome.exe',
  'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
  'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
  '/usr/bin/google-chrome',
  '/usr/bin/google-chrome-stable',
  '/usr/bin/chromium',
  '/usr/bin/chromium-browser',
].filter(Boolean);
const executablePath = candidates.find((p) => fs.existsSync(p));
if (!executablePath) {
  console.error('Brak Chrome/Edge. Ustaw CHROME_PATH.');
  process.exit(2);
}

const results = [];
const consoleErrors = [];
const pageErrors = [];
function log(name, ok, info = '') {
  results.push({ name, ok, info });
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${info ? '  · ' + info : ''}`);
}

function seedProposals(userId) {
  const result = spawnSync(PHP, [path.join(ROOT, 'tests', 'seed-mcp-ui.php'), String(userId)], {
    cwd: ROOT,
    env: { ...process.env, ALPHA_VAULT_CONFIG: CONFIG },
    encoding: 'utf8',
  });
  if (result.status !== 0) {
    throw new Error(`seed-mcp-ui failed:\n${result.stdout}\n${result.stderr}`);
  }
  return JSON.parse(result.stdout);
}

const browser = await chromium.launch({ executablePath, headless: true, args: ['--no-sandbox'] });
const context = await browser.newContext({ baseURL: BASE, viewport: { width: 1440, height: 1000 } });
const page = await context.newPage();
page.on('console', (msg) => { if (msg.type() === 'error') consoleErrors.push(msg.text()); });
page.on('pageerror', (err) => pageErrors.push(err.message));

try {
  await page.goto('/', { waitUntil: 'load' });
  await page.waitForFunction(() => window.AV && AV.ready);
  await page.click('#enter');
  await page.waitForSelector('#demoEnter');
  await page.click('#demoEnter');
  await page.waitForFunction(() => document.querySelector('#auth')?.classList.contains('hide'), null, { timeout: 15000 });
  await page.waitForFunction(() => window.AV?.ready && AV.api.authenticated && Number(AV.currentUserId) > 0);
  const userId = await page.evaluate(() => Number(AV.currentUserId));
  assert.ok(userId > 0, 'brak userId');

  await page.click('.navg button[data-v="mentor"]');
  await page.waitForSelector('#mcpPendingSection');
  await page.evaluate(async () => {
    clearTimeout(AV._saveTimer);
    clearTimeout(AV._pushTimer);
    if (AV.api.pushing) await AV.waitForPushIdle();
    if (AV.api.dirty) await AV.pushStateImmediate();
    clearTimeout(AV._pushTimer);
  });
  const seeded = seedProposals(userId);
  await page.evaluate(async () => { AV.api.mcpLoaded = false; await AV.loadMcpProposals(); });
  await page.waitForFunction(() => document.querySelectorAll('.mcp-proposal').length === 2, null, { timeout: 10000 });
  const revDebug = await page.evaluate(async () => {
    const res = await AV.apiFetch('/api/mcp');
    const data = await res.json();
    return { revision: AV.api.revision, bases: data.proposals.map((p) => p.baseRevision) };
  });
  console.log('revision debug:', JSON.stringify(revDebug));
  log('panel: dwie propozycje widoczne', (await page.locator('.mcp-proposal').count()) === 2);

  const diffText = await page.locator('.mcp-proposal').first().innerText();
  assert.match(diffText, /score/i, 'brak diff score');
  assert.match(diffText, /70/, 'brak wartości 70');
  assert.match(diffText, /Musisz|Zatwierdź|Odrzuć/i, 'brak akcji');
  log('panel: diff i akcje', true);

  await page.click('[data-mcp-code="' + seeded.checkin + '"]');
  await page.waitForSelector('.mcp-approval-code code', { timeout: 10000 });
  const approvalCode = await page.locator('.mcp-approval-code code').innerText();
  assert.match(approvalCode.trim(), /^[A-Z2-9]{4}-[A-Z2-9]{4}$/);
  log('panel: jednorazowy kod do rozmowy', true);

  await page.click('[data-mcp-decide="approve"][data-id="' + seeded.checkin + '"]');
  await page.waitForTimeout(1500);
  if (await page.locator('.mcp-proposal').count() !== 1) {
    console.log('approve debug:', await page.locator('#mcpPendingError').innerText(), '|', await page.locator('#toast').innerText());
  }
  await page.waitForFunction(() => document.querySelectorAll('.mcp-proposal').length === 1, null, { timeout: 15000 });
  assert.match(await page.locator('#toast').innerText(), /zatwierdzona/i);
  log('panel: zatwierdzenie check-inu', true);

  await page.click('[data-mcp-decide="reject"][data-id="' + seeded.setup + '"]');
  await page.waitForFunction(() => document.querySelectorAll('.mcp-proposal').length === 0, null, { timeout: 15000 });
  assert.match(await page.locator('#toast').innerText(), /odrzucona/i);
  log('panel: odrzucenie setupu', true);

  const stateAfter = await page.evaluate(async () => {
    const res = await AV.apiFetch('/api/state');
    return res.json();
  });
  const checkin = (stateAfter.state?.checkins || []).find((c) => c.date === seeded.today);
  const setupPresent = (stateAfter.state?.setups || []).some((s) => s.id === seeded.setupId);
  assert.ok(checkin && Number(checkin.score) === 70, 'check-in nie zapisany');
  assert.equal(setupPresent, false, 'odrzucony setup zapisany');
  log('panel: stan po decyzjach', true);

  await page.reload({ waitUntil: 'load' });
  await page.waitForFunction(() => window.AV?.ready && AV.api.authenticated);
  await page.click('.navg button[data-v="mentor"]');
  await page.waitForFunction(() => AV.api.mcpLoaded === true, null, { timeout: 10000 });
  await page.waitForFunction(() => document.querySelectorAll('.mcp-proposal').length === 0);
  log('panel: trwałość po reloadzie', true);

  assert.equal(consoleErrors.length, 0, 'console errors: ' + consoleErrors.join(' | '));
  assert.equal(pageErrors.length, 0, 'page errors: ' + pageErrors.join(' | '));
  log('panel: brak błędów JS', true);

  const failed = results.filter((r) => !r.ok);
  if (failed.length > 0) process.exitCode = 1;
  console.log(`\n${results.length - failed.length} PASS, ${failed.length} FAIL`);
} finally {
  await browser.close();
}
