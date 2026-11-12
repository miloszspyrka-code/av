#!/usr/bin/env node
/**
 * Alpha Vault — testy routingu hash (#/slug) bez przeglądarki.
 * Uruchom: npm run test:router
 * Ładuje public/js/actions.js w izolowanym kontekście i sprawdza mapowanie widoków.
 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const source = fs.readFileSync(path.join(root, 'public', 'js', 'actions.js'), 'utf8');

const results = [];
function log(name, ok, info = '') {
  results.push({ name, ok, info });
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${info ? '  — ' + info : ''}`);
}
function check(name, fn) {
  try { fn(); log(name, true); } catch (err) { log(name, false, err.message); }
}

/* Kontekst: minimalne stuby DOM, bez uruchamiania AV.init(). */
const listeners = [];
const sandbox = {
  console,
  setTimeout,
  setInterval: () => 0,
  clearTimeout,
  clearInterval,
  location: { hash: '', pathname: '/', search: '', href: 'http://127.0.0.1:4173/' },
  history: { replaceState: (_s, _t, url) => { sandbox.location.hash = String(url); } },
  window: {
    scrollTo: () => {},
    addEventListener: (type, fn) => listeners.push([type, fn]),
    AV: {
      $: () => null,
      $$: () => [],
      renderJournal: () => {},
      renderStats: () => {},
      renderProp: () => {},
      renderPlan: () => {},
      renderSettings: () => {},
      renderCalendar: () => {},
      renderBody: () => {},
      renderMentor: () => {},
      renderExchangeList: () => {},
      renderEx: () => {},
      renderNewTradeForm: () => {},
      renderLock: () => {}
    }
  },
  document: { readyState: 'loading', addEventListener: () => {}, visibilityState: 'visible' }
};
sandbox.window.window = sandbox.window;
sandbox.window.document = sandbox.document;
sandbox.globalThis = sandbox;
vm.createContext(sandbox);
vm.runInContext(source, sandbox, { filename: 'public/js/actions.js' });

const AV = sandbox.window.AV;

check('router: AV.views ma 15 widoków', () => assert.equal(AV.views.length, 15));
check('router: każdy widok ma slug', () => {
  for (const id of AV.views) assert.equal(typeof AV.viewSlugs[id], 'string', `brak sluga dla ${id}`);
});
check('router: slugi są unikalne', () => {
  const slugs = AV.views.map((id) => AV.viewSlugs[id]);
  assert.equal(new Set(slugs).size, slugs.length, slugs.join(','));
});
check('router: slugi są przyjazne (ascii, małe litery, myślniki)', () => {
  for (const id of AV.views) assert.match(AV.viewSlugs[id], /^[a-z0-9]+(-[a-z0-9]+)*$/, id);
});
check('router: brak krótkich slugów z listy wewnętrznej', () => {
  for (const short of ['trans', 'play', 'nowa', 'kalk', 'akad', 'stat', 'tax', 'kal', 'cialo', 'ust']) {
    assert.notEqual(AV.viewSlugs[short], short, `slug ${short} nie jest czytelny`);
  }
});
check('router: id -> slug -> id w obie strony', () => {
  for (const id of AV.views) {
    const slug = AV.viewSlug(id);
    assert.equal(AV.slugViews[slug], id, `${slug} -> ${AV.slugViews[slug]}`);
    assert.equal(AV.slugViews[id], id, `alias ${id}`);
  }
});

const fromHash = (hash) => {
  sandbox.location.hash = hash;
  return AV.viewFromHash();
};

check('router: #/akad (stary adres) -> akad', () => assert.equal(fromHash('#/akad'), 'akad'));
check('router: #/akademia -> akad', () => assert.equal(fromHash('#/akademia'), 'akad'));
check('router: #/akademia/ -> akad', () => assert.equal(fromHash('#/akademia/'), 'akad'));
check('router: #/Akademia -> akad (wielkość liter)', () => assert.equal(fromHash('#/Akademia'), 'akad'));
check('router: #/dziennik -> trans', () => assert.equal(fromHash('#/dziennik'), 'trans'));
check('router: #/podatki -> tax', () => assert.equal(fromHash('#/podatki'), 'tax'));
check('router: #/cialo-i-glowa -> cialo', () => assert.equal(fromHash('#/cialo-i-glowa'), 'cialo'));
check('router: #/konto -> ust', () => assert.equal(fromHash('#/konto'), 'ust'));
check('router: pusty hash -> pulpit', () => assert.equal(fromHash(''), 'pulpit'));
check('router: nieznany slug -> pulpit', () => assert.equal(fromHash('#/nie-ma-takiego'), 'pulpit'));

check('router: showView zapisuje czytelny slug', () => {
  AV.showView('akad');
  assert.equal(sandbox.location.hash, '#/akademia');
  AV.showView('trans');
  assert.equal(sandbox.location.hash, '#/dziennik');
  AV.showView('pulpit');
  assert.equal(sandbox.location.hash, '#/pulpit');
});
check('router: showView z nieznanym id wraca na pulpit', () => {
  AV.showView('nieznany');
  assert.equal(AV.currentView, 'pulpit');
  assert.equal(sandbox.location.hash, '#/pulpit');
});

const failed = results.filter((r) => !r.ok);
console.log(`\nrouter: ${results.length - failed.length}/${results.length} PASS`);
process.exit(failed.length ? 1 : 0);
