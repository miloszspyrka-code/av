#!/usr/bin/env node
/**
 * UI empty-state test: successful sync with zero events must leave empty fields.
 * Run against a PHP server whose DISCORD_GUILD_ID points to a guild with no events.
 */
import assert from 'node:assert/strict';
import { chromium } from 'playwright-core';
import fs from 'node:fs';
import path from 'node:path';

const BASE = process.env.APP_URL || 'http://127.0.0.1:4174';
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
if (!executablePath) {
  console.error('Brak Chrome/Edge. Ustaw CHROME_PATH.');
  process.exit(2);
}

const results = [];
const consoleErrors = [];
const pageErrors = [];
function log(name, ok, info = '') {
  results.push({ name, ok, info });
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${info ? ' · ' + info : ''}`);
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
  await page.waitForFunction(() => window.AV?.ready && AV.api.authenticated);
  await page.click('.navg button[data-v="akad"]');
  await page.waitForTimeout(800);

  const state = await page.evaluate(() => ({
    title: document.querySelector('#liveTitle')?.textContent.trim() || '',
    who: document.querySelector('#liveWho')?.textContent.trim() || '',
    countdown: !!document.querySelector('#liveCd'),
    cardDisplay: document.querySelector('#liveCard') ? getComputedStyle(document.querySelector('#liveCard')).display : 'none',
    next: document.querySelector('#nextConsult')?.textContent.trim() || '',
    nextWho: document.querySelector('#nextConsultWho')?.textContent.trim() || '',
    top: document.querySelector('#liveTop')?.textContent.trim() || '',
    sepDisplay: document.querySelector('#liveSep') ? getComputedStyle(document.querySelector('#liveSep')).display : 'none',
    consultTop: document.querySelector('#consultTopWrap')?.textContent.trim() || '',
    joinHref: document.querySelector('#liveJoin')?.getAttribute('href') || '',
    joinDisplay: document.querySelector('#liveJoin') ? getComputedStyle(document.querySelector('#liveJoin')).display : 'none',
    acadEvents: document.querySelectorAll('#acad b').length,
    acadDays: document.querySelectorAll('#acad .aday').length,
    sync: AV.api.events?.sync || null,
    events: AV.api.events?.events?.length ?? -1
  }));

  log('live title empty', state.title === '', state.title);
  log('live host empty', state.who === '', state.who);
  log('live countdown removed', state.countdown === false);
  log('live card hidden', state.cardDisplay === 'none', state.cardDisplay);
  log('consultation empty', state.next === '' && state.nextWho === '', state.next + ' / ' + state.nextWho);
  log('strip live empty', state.top === '', state.top);
  log('strip separator hidden', state.sepDisplay === 'none', state.sepDisplay);
  log('strip consultation empty', state.consultTop === '', state.consultTop);
  log('join link hidden', state.joinDisplay === 'none' && !state.joinHref, state.joinDisplay + ' / ' + state.joinHref);
  log('week has 7 empty days', state.acadDays === 7 && state.acadEvents === 0, `${state.acadDays}/${state.acadEvents}`);
  log('API returned zero events', state.events === 0, String(state.events));
  log('sync status available', !!state.sync, state.sync?.status || 'none');
} catch (err) {
  console.error('\nFAIL  test przerwany:', err.message);
  results.push({ name: 'test przerwany', ok: false, info: err.message });
  process.exitCode = 1;
} finally {
  await browser.close();
}

const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} PASS`);
if (consoleErrors.length) console.log('Console errors:', consoleErrors.slice(0, 10));
if (pageErrors.length) console.log('Page errors:', pageErrors.slice(0, 10));
if (failed.length) process.exitCode = 1;
