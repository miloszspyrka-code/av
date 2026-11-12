/* Alpha Vault — PWA: instalacja, powiadomienia i deep link do szybkiego dodania. */
(function () {
  'use strict';

  const AV = window.AV;
  if (!AV) return;

  const P = AV.pwa = AV.pwa || {};
  const ICON = '/assets/pwa/icon-192.png';

  P.registration = null;
  P.installEvent = null;
  P.lastNotification = null;
  P.lastPush = null;
  P.standalone = false;

  const isStandalone = () => {
    try {
      return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    } catch {
      return false;
    }
  };

  const notifySupported = () => 'Notification' in window;

  P.refreshUi = () => {
    P.standalone = isStandalone();

    document.querySelectorAll('[data-install]').forEach((button) => {
      if (P.standalone) {
        button.textContent = 'Zainstalowana ✓';
        button.disabled = true;
        button.setAttribute('aria-disabled', 'true');
      } else {
        button.disabled = false;
        button.removeAttribute('aria-disabled');
        button.textContent = P.installEvent ? 'Zainstaluj aplikację' : 'Jak zainstalować';
      }
    });

    document.querySelectorAll('[data-pwa-status]').forEach((node) => {
      node.textContent = P.standalone
        ? 'Aplikacja jest zainstalowana na tym urządzeniu.'
        : (P.installEvent
          ? 'Gotowa do instalacji na tym urządzeniu.'
          : 'iPhone: Safari → Udostępnij → Do ekranu początkowego. Android: Chrome → Zainstaluj aplikację.');
    });

    const permission = notifySupported() ? Notification.permission : 'unsupported';
    document.querySelectorAll('[data-pwa-notify]').forEach((button) => {
      if (permission === 'granted') {
        button.textContent = 'Powiadomienia włączone ✓';
        button.disabled = true;
      } else if (permission === 'denied') {
        button.textContent = 'Powiadomienia zablokowane';
        button.disabled = true;
      } else if (permission === 'unsupported') {
        button.textContent = 'Brak obsługi powiadomień';
        button.disabled = true;
      } else {
        button.textContent = 'Włącz powiadomienia';
        button.disabled = false;
      }
    });
  };

  P.askNotifications = async () => {
    if (!notifySupported()) {
      if (AV.toast) AV.toast('Ta przeglądarka nie obsługuje powiadomień');
      return 'unsupported';
    }
    if (Notification.permission === 'granted') {
      P.refreshUi();
      return 'granted';
    }
    if (Notification.permission === 'denied') {
      if (AV.toast) AV.toast('Powiadomienia są zablokowane w ustawieniach przeglądarki');
      P.refreshUi();
      return 'denied';
    }
    try {
      const permission = await Notification.requestPermission();
      P.refreshUi();
      if (permission === 'granted' && AV.toast) AV.toast('Powiadomienia włączone');
      if (permission === 'denied' && AV.toast) AV.toast('Powiadomienia zostały zablokowane');
      return permission;
    } catch {
      return 'error';
    }
  };

  P.register = async () => {
    if (!('serviceWorker' in navigator)) return null;
    try {
      const registration = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
      P.registration = registration;
      navigator.serviceWorker.addEventListener('message', (event) => {
        const data = event.data || {};
        if (data.type === 'av:push') P.lastPush = data.payload || null;
      });
      await navigator.serviceWorker.ready;
      P.registration = (await navigator.serviceWorker.getRegistration()) || registration;
      P.refreshUi();
      return registration;
    } catch (error) {
      console.warn('Rejestracja service workera nie powiodła się:', error);
      return null;
    }
  };

  P.notify = async (title, body, options = {}) => {
    const payload = {
      title,
      body,
      url: options.url || '/?qa=1',
      tag: options.tag || 'av',
      at: Date.now()
    };
    P.lastNotification = payload;

    let registration = P.registration;
    if (!registration && 'serviceWorker' in navigator) {
      try { registration = await navigator.serviceWorker.getRegistration(); } catch {}
      if (registration) P.registration = registration;
    }

    if (registration && registration.showNotification) {
      try {
        await registration.showNotification(title, {
          body,
          icon: ICON,
          badge: ICON,
          tag: payload.tag,
          renotify: true,
          data: { url: payload.url }
        });
        payload.mode = 'service-worker';
        payload.shownAt = Date.now();
        return 'service-worker';
      } catch (error) {
        console.warn('Nie udało się pokazać powiadomienia:', error);
      }
    }

    if (notifySupported() && Notification.permission === 'granted') {
      try {
        new Notification(title, { body, icon: ICON, tag: payload.tag });
        payload.mode = 'page';
        payload.shownAt = Date.now();
        return 'page';
      } catch {}
    }

    payload.mode = 'none';
    return 'none';
  };

  P.notifyTradeClosed = (trade) => {
    if (!trade) return Promise.resolve('none');
    const direction = trade.dir === 'l' ? 'LONG' : 'SHORT';
    const result = trade.result == null
      ? ''
      : ' ' + (typeof AV.signed === 'function' ? AV.signed(trade.result, 2, ' USDT') : trade.result);
    return P.notify(
      'Transakcja zamknięta',
      trade.pair + ' ' + direction + result + '. Dodaj emocję i ocenę.',
      { url: '/?qa=' + encodeURIComponent(trade.id), tag: 'trade-' + trade.id }
    );
  };

  P.install = async () => {
    P.standalone = isStandalone();
    if (P.standalone) {
      if (AV.toast) AV.toast('Aplikacja jest już zainstalowana');
      return;
    }
    if (P.installEvent) {
      P.installEvent.prompt();
      try { await P.installEvent.userChoice; } catch {}
      P.installEvent = null;
      P.refreshUi();
      return;
    }
    const modal = document.getElementById('qa');
    if (modal) {
      modal.classList.add('on');
      return;
    }
    if (AV.toast) AV.toast('iPhone: Udostępnij → Do ekranu początkowego. Android: menu → Zainstaluj aplikację.');
  };

  P.quickAddUrl = (tradeId) => '/?qa=' + (tradeId ? encodeURIComponent(tradeId) : '1');

  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    P.installEvent = event;
    P.refreshUi();
  });

  window.addEventListener('appinstalled', () => {
    P.installEvent = null;
    P.standalone = true;
    P.refreshUi();
    if (AV.toast) AV.toast('Alpha Vault zainstalowana');
  });

  if (window.matchMedia) {
    try {
      window.matchMedia('(display-mode: standalone)').addEventListener('change', () => {
        P.standalone = isStandalone();
        P.refreshUi();
      });
    } catch {}
  }

  document.addEventListener('click', (event) => {
    if (event.target.closest('[data-install]')) {
      event.preventDefault();
      P.install();
      return;
    }
    if (event.target.closest('[data-pwa-notify]')) {
      event.preventDefault();
      P.askNotifications();
    }
  });

  const start = () => {
    P.standalone = isStandalone();
    P.refreshUi();
    P.register();
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
