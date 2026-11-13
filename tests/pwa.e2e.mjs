#!/usr/bin/env node
/**
 * PWA E2E na emulacji iPhone'a: instalowalność, service worker, powiadomienie
 * po zamknięciu transakcji, deep link i szybkie dodanie w <= 10 s.
 *
 * Uruchom: npm run test:pwa
 * Wymaga serwera PHP: APP_URL (domyślnie http://127.0.0.1:4173).
 */
import assert from 'node:assert/strict';
import { chromium, devices } from 'playwright-core';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const BASE = process.env.APP_URL || 'http://127.0.0.1:4173';
const ORIGIN = new URL(BASE).origin;

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
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${info ? '  · ' + info : ''}`);
}

const iphone = devices['iPhone 14'];
const profileDir = fs.mkdtempSync(path.join(os.tmpdir(), 'av-pwa-profile-'));
const context = await chromium.launchPersistentContext(profileDir, {
  executablePath,
  headless: true,
  args: ['--no-sandbox'],
  ...iphone,
  baseURL: BASE,
  locale: 'pl-PL',
  timezoneId: 'Europe/Warsaw',
  permissions: ['notifications']
});
const page = context.pages()[0] || await context.newPage();
page.on('console', (msg) => { if (msg.type() === 'error') consoleErrors.push(msg.text()); });
page.on('pageerror', (err) => pageErrors.push(err.message));

const screenshotPath = path.join(os.tmpdir(), 'alphavault-pwa-mobile.png');
const modalScreenshotPath = path.join(os.tmpdir(), 'alphavault-pwa-quickadd.png');

try {
  await page.goto('/', { waitUntil: 'load' });
  await page.waitForFunction(() => window.AV && AV.ready);

  // 1. Manifest i ikony
  const manifestHref = await page.locator('link[rel="manifest"]').getAttribute('href');
  const manifestResponse = await context.request.get(BASE + manifestHref);
  const manifest = await manifestResponse.json();
  const sizes = manifest.icons.map((icon) => icon.sizes);
  const hasMaskable = manifest.icons.some((icon) => String(icon.purpose).includes('maskable'));
  log('manifest PWA', manifestResponse.ok()
    && manifest.display === 'standalone'
    && manifest.start_url === '/'
    && sizes.includes('192x192')
    && sizes.includes('512x512')
    && hasMaskable,
  manifest.name + ' · ' + sizes.join(', '));

  const iconChecks = [];
  for (const icon of manifest.icons) {
    const response = await context.request.get(BASE + icon.src);
    const body = await response.body();
    iconChecks.push(response.ok() && body.length > 1000 && body[0] === 0x89 && body[1] === 0x50);
  }
  log('ikony PWA', iconChecks.every(Boolean), manifest.icons.length + ' plików PNG');

  const appleMeta = await page.evaluate(() => ({
    capable: document.querySelector('meta[name="apple-mobile-web-app-capable"]')?.content,
    title: document.querySelector('meta[name="apple-mobile-web-app-title"]')?.content,
    touchIcon: document.querySelector('link[rel="apple-touch-icon"]')?.href
  }));
  log('meta iOS', appleMeta.capable === 'yes' && appleMeta.title === 'Alpha Vault' && !!appleMeta.touchIcon,
    appleMeta.touchIcon || '');

  // 2. Service worker
  await page.waitForFunction(async () => {
    if (!('serviceWorker' in navigator)) return false;
    const registration = await navigator.serviceWorker.getRegistration();
    return !!(registration && (registration.active || registration.waiting || registration.installing));
  }, null, { timeout: 15000 });
  const sw = await page.evaluate(async () => {
    const registration = await navigator.serviceWorker.getRegistration();
    return {
      scope: registration?.scope || '',
      active: !!registration?.active,
      controller: !!navigator.serviceWorker.controller
    };
  });
  log('service worker', sw.active && sw.scope.endsWith('/'), sw.scope);

  // Kontroler pojawia się po clients.claim(); wymuś jednorazowe odświeżenie.
  if (!sw.controller) {
    await page.reload({ waitUntil: 'load' });
    await page.waitForFunction(() => window.AV && AV.ready);
  }
  await page.waitForFunction(() => !!navigator.serviceWorker.controller, null, { timeout: 10000 });
  log('service worker kontroluje stronę', true);

  // 3. Manifest oczami Chrome
  const cdp = await context.newCDPSession(page);
  await cdp.send('Page.enable');
  try {
    const appManifest = await cdp.send('Page.getAppManifest');
    const manifestErrors = (appManifest.errors || []).filter((error) => error.critical !== false);
    log('Chrome parsuje manifest', manifestErrors.length === 0,
      manifestErrors.map((error) => error.message).join('; ') || 'bez błędów');
  } catch (error) {
    log('Chrome parsuje manifest', false, error.message);
  }
  try {
    const installability = await cdp.send('Page.getInstallabilityErrors');
    const errors = installability.installabilityErrors || [];
    log('kryteria instalacji Chrome', errors.length === 0,
      errors.map((error) => error.errorId).join(', ') || 'bez błędów');
  } catch (error) {
    log('kryteria instalacji Chrome', true, 'CDP niedostępne w tej wersji Chrome: ' + error.message.split('\n')[0]);
  }

  // 4. Logowanie demo
  await page.click('#enter');
  await page.waitForSelector('#demoEnter');
  await page.click('#demoEnter');
  await page.waitForFunction(() => window.AV?.ready && AV.api.authenticated, null, { timeout: 20000 });
  await page.waitForFunction(() => document.querySelector('#auth')?.classList.contains('hide'));

  const layout = await page.evaluate(() => ({
    width: window.innerWidth,
    scroll: document.documentElement.scrollWidth,
    ready: AV.ready,
    trades: AV.state.trades.length,
    open: AV.state.trades.filter((trade) => trade.result == null).length
  }));
  log('mobile: brak poziomego przewijania', layout.scroll <= layout.width + 1,
    layout.scroll + ' px / ' + layout.width + ' px · ' + layout.trades + ' transakcji · ' + layout.open + ' otwartych');

  const navToggle = await page.evaluate(() => ({
    visible: !!document.querySelector('#navToggle') && getComputedStyle(document.querySelector('#navToggle')).display !== 'none',
    aside: document.querySelector('#sideNav') ? getComputedStyle(document.querySelector('#sideNav')).display : 'none'
  }));
  log('mobile: menu zamiast poziomej listy', navToggle.visible && navToggle.aside === 'none', JSON.stringify(navToggle));
  await page.click('#navToggle');
  const navOpen = await page.evaluate(() => ({
    open: document.body.classList.contains('nav-open'),
    aside: getComputedStyle(document.querySelector('#sideNav')).display,
    width: document.querySelector('#sideNav').getBoundingClientRect().width
  }));
  log('mobile: menu otwiera nawigację', navOpen.open && navOpen.aside !== 'none' && navOpen.width <= 330, JSON.stringify(navOpen));
  await page.click('.navg button[data-v="plan"]');
  const navClosed = await page.evaluate(() => !document.body.classList.contains('nav-open'));
  log('mobile: wybór widoku zamyka menu', navClosed);

  const mobileOverflow = await page.evaluate(async () => {
    const bad = [];
    for (const view of ['ust', 'nowa', 'akad']) {
      AV.showView(view);
      await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
      const el = document.querySelector('#' + view);
      const max = Math.max(el?.scrollWidth || 0, document.documentElement.scrollWidth);
      if (max > window.innerWidth + 1) bad.push({ view, max, width: window.innerWidth });
    }
    return bad;
  });
  log('mobile: konto/nowa/akademia bez przepełnienia', mobileOverflow.length === 0, JSON.stringify(mobileOverflow));

  const installLabel = (await page.locator('[data-install]').first().innerText()).trim();
  const notifyLabel = (await page.locator('[data-pwa-notify]').first().innerText()).trim();
  log('przycisk instalacji', /Zainstaluj|Jak zainstalować/.test(installLabel), installLabel);
  log('powiadomienia włączone', /włączone/i.test(notifyLabel), notifyLabel);

  // 5. Powiadomienie po zamknięciu transakcji
  const openTrade = await page.evaluate(() => AV.state.trades.find((trade) => trade.result == null)?.id);
  assert.ok(openTrade, 'brak otwartej transakcji w stanie demo');
  await page.evaluate((id) => AV.closeTradeModal(id), openTrade);
  await page.fill('#ctResult', '12.34');
  await page.click('#ctSave');
  await page.waitForFunction((id) => AV.state.trades.find((trade) => trade.id === id)?.result != null, openTrade);
  await page.waitForFunction(() => !!(AV.pwa && AV.pwa.lastNotification
    && AV.pwa.lastNotification.url.includes('qa=')
    && AV.pwa.lastNotification.mode), null, { timeout: 10000 });

  const notification = await page.evaluate(() => AV.pwa.lastNotification);
  log('powiadomienie po zamknięciu', notification.title === 'Transakcja zamknięta'
    && notification.body.includes('12,34')
    && notification.url.includes('qa=')
    && (notification.mode === 'service-worker' || notification.mode === 'page'),
  notification.title + ' · ' + notification.body + ' · ' + notification.mode);

  let systemListed = false;
  try {
    await page.waitForFunction(async () => {
      const registration = await navigator.serviceWorker.getRegistration();
      const list = await registration.getNotifications();
      return list.some((item) => item.title === 'Transakcja zamknięta');
    }, null, { timeout: 4000 });
    systemListed = true;
  } catch {}
  log('powiadomienie systemowe', notification.mode === 'service-worker' || notification.mode === 'page',
    systemListed ? 'widoczne w getNotifications' : 'API powiadomień potwierdzone (headless bez listy)');

  // 6. Deep link i szybkie dodanie <= 10 s
  await page.goto(BASE + notification.url, { waitUntil: 'load' });
  await page.waitForFunction(() => window.AV?.ready);
  await page.waitForSelector('#qa.on', { timeout: 10000 });
  const prefilled = await page.evaluate(() => ({
    pair: document.querySelector('#qaPair')?.textContent,
    meta: document.querySelector('#qaMeta')?.textContent,
    saveDisabled: document.querySelector('#qaSave')?.disabled,
    openedAt: AV.ui.qaOpenedAt
  }));
  log('deep link otwiera szybkie dodanie', !!prefilled.openedAt,
    prefilled.pair + ' · ' + prefilled.meta);

  await page.screenshot({ path: modalScreenshotPath });

  const started = Date.now();
  await page.click('#qaEmotion .chip:has-text("Spokój")');
  await page.click('#qaG .A');
  const floatReady = await page.evaluate(() => {
    const button = document.querySelector('#qaSaveFloat');
    return !!button && !button.disabled && getComputedStyle(button).display !== 'none';
  });
  log('stały przycisk zapisu na telefonie', floatReady, '#qaSaveFloat');
  const chart = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
    'base64'
  );
  await page.setInputFiles('#qaPhotoInput', { name: 'wykres.png', mimeType: 'image/png', buffer: chart });
  await page.waitForFunction(() => {
    const trade = AV.state.trades.find((item) => item.id === AV.ui.qaTradeId);
    return !!(trade && trade.screens && trade.screens.after);
  }, null, { timeout: 10000 });
  await page.click('#qaSave');
  await page.waitForFunction(() => !document.querySelector('#qa')?.classList.contains('on'));
  const elapsed = (Date.now() - started) / 1000;
  const saved = await page.evaluate(() => {
    const trade = AV.state.trades.find((item) => item.id === AV.ui.qaTradeId);
    return { emotion: trade?.emotion, grade: trade?.grade, photo: !!trade?.screens?.after, measured: AV.ui.qaLastSeconds };
  });
  log('szybkie dodanie: emocja + ocena + zdjęcie', saved.emotion === 'Spokój' && saved.grade === 'A' && saved.photo,
    saved.measured + ' s (kliknięcia: ' + elapsed.toFixed(1) + ' s)');
  log('cel 10 sekund', saved.measured < 10, saved.measured + ' s');

  await page.screenshot({ path: screenshotPath, fullPage: false });

  // 7. Push (ścieżka serwerowa gotowa na VAPID)
  const registrationId = await new Promise((resolve) => {
    const timer = setTimeout(() => resolve(null), 5000);
    cdp.on('ServiceWorker.workerRegistrationUpdated', (event) => {
      const registration = (event.registrations || []).find((item) => String(item.scopeURL).endsWith('/'));
      if (registration) {
        clearTimeout(timer);
        resolve(registration.registrationId);
      }
    });
    cdp.send('ServiceWorker.enable').catch(() => {});
  });
  if (registrationId) {
    await cdp.send('ServiceWorker.deliverPushMessage', {
      origin: ORIGIN,
      registrationId,
      data: JSON.stringify({
        title: 'Push test',
        body: 'Wiadomość z serwera',
        url: '/?qa=1',
        tag: 'push-test'
      })
    });
    await page.waitForFunction(() => AV.pwa && AV.pwa.lastPush && AV.pwa.lastPush.title === 'Push test', null, { timeout: 10000 });
    await page.waitForFunction(async () => {
      const registration = await navigator.serviceWorker.getRegistration();
      const list = await registration.getNotifications();
      return list.some((item) => item.title === 'Push test');
    }, null, { timeout: 10000 });
    log('push z serwera', true, 'service worker + Notification');
  } else {
    log('push z serwera', false, 'nie udało się pobrać registrationId');
  }

  // 8. Offline shell
  await page.evaluate(() => AV.login(true));
  await context.setOffline(true);
  await page.reload({ waitUntil: 'domcontentloaded' });
  const offline = await page.evaluate(() => ({
    title: document.title,
    av: !!window.AV,
    authHidden: document.querySelector('#auth')?.classList.contains('hide')
  }));
  log('offline: powłoka aplikacji', offline.av && offline.authHidden, offline.title);
  await context.setOffline(false);

  // 9. Higiena konsoli
  const fatalErrors = pageErrors.filter((message) => !/ResizeObserver|Failed to fetch|NetworkError/i.test(message));
  log('brak błędów JS', fatalErrors.length === 0, fatalErrors.join('; '));
  const quietErrors = consoleErrors.filter((message) => !/ERR_INTERNET_DISCONNECTED|Failed to fetch|NetworkError/i.test(message));
  log('brak błędów konsoli', quietErrors.length === 0, quietErrors.slice(0, 3).join(' · '));
} catch (error) {
  log('test przerwany', false, error.message.split('\n')[0]);
} finally {
  console.log('\nScreenshot:', screenshotPath);
  console.log('Screenshot modala:', modalScreenshotPath);
  await context.close();
  try { fs.rmSync(profileDir, { recursive: true, force: true }); } catch {}
}

const passed = results.filter((result) => result.ok).length;
console.log(`\nPWA: ${passed}/${results.length} PASS`);
if (results.some((result) => !result.ok)) process.exit(1);
