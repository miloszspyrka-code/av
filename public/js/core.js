/* Alpha Vault — core utilities
   Warstwa wspólna: DOM, formatowanie, storage, CSV, ZIP, modale, toasty. */
(function () {
  'use strict';
  const AV = window.AV = window.AV || {};

  AV.$ = (s, r = document) => r.querySelector(s);
  AV.$$ = (s, r = document) => [...r.querySelectorAll(s)];
  AV.clamp = (n, a, b) => Math.min(b, Math.max(a, n));
  AV.esc = (s) => String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  AV.attr = (s) => AV.esc(s).replace(/`/g, '&#96;');

  AV.fmt = (n, d = 2) => {
    const v = Number(n);
    if (!Number.isFinite(v)) return '—';
    return v.toLocaleString('pl-PL', { minimumFractionDigits: d, maximumFractionDigits: d });
  };
  AV.fmtInt = (n) => AV.fmt(n, 0);
  AV.pnum = (v) => {
    if (typeof v === 'number') return Number.isFinite(v) ? v : 0;
    const n = parseFloat(String(v == null ? '' : v)
      .replace(/\u00a0/g, '').replace(/\s/g, '').replace(/[$€£zł%x×]/gi, '')
      .replace(/,(?=\d)/g, '.')
      .replace(/,/g, ''));
    return Number.isFinite(n) ? n : 0;
  };
  AV.money = (n, d = 2, cur = 'USDT') => {
    const v = Number(n) || 0;
    return (v > 0 ? '+' : v < 0 ? '−' : '') + AV.fmt(Math.abs(v), d) + (cur ? ' ' + cur : '');
  };
  AV.signed = (n, d = 2, suf = '') => {
    const v = Number(n) || 0;
    return (v > 0 ? '+' : v < 0 ? '−' : '') + AV.fmt(Math.abs(v), d) + suf;
  };
  AV.plural = (n, one, few, many) => {
    const a = Math.abs(n) % 100, b = a % 10;
    if (a === 1) return one;
    if (b >= 2 && b <= 4 && (a < 12 || a > 14)) return few;
    return many;
  };
  AV.pct = (n, d = 0) => AV.fmt(n, d) + '%';

  AV.uid = (prefix = 'id') => prefix + '_' + Date.now().toString(36) + Math.random().toString(36).slice(2, 7);
  AV.clone = (o) => JSON.parse(JSON.stringify(o));
  AV.debounce = (fn, ms = 250) => {
    let t;
    return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); };
  };

  /* ---------- daty ---------- */
  AV.pad = (n) => String(n).padStart(2, '0');
  AV.dateKey = (d = new Date()) => {
    const x = new Date(d);
    return x.getFullYear() + '-' + AV.pad(x.getMonth() + 1) + '-' + AV.pad(x.getDate());
  };
  AV.parseKey = (k) => {
    const [y, m, d] = String(k).split('-').map(Number);
    return new Date(y, (m || 1) - 1, d || 1, 12, 0, 0);
  };
  AV.addDays = (d, n) => {
    const x = new Date(d);
    x.setDate(x.getDate() + n);
    return x;
  };
  AV.startOfDay = (d = new Date()) => {
    const x = new Date(d);
    x.setHours(0, 0, 0, 0);
    return x;
  };
  AV.monday = (d = new Date()) => {
    const x = AV.startOfDay(d);
    x.setDate(x.getDate() - ((x.getDay() + 6) % 7));
    return x;
  };
  AV.dayName = (d, opt = { weekday: 'long' }) => new Date(d).toLocaleDateString('pl-PL', opt);
  AV.dateLabel = (d) => new Date(d).toLocaleDateString('pl-PL', { day: '2-digit', month: '2-digit', year: 'numeric' });
  AV.dateShort = (d) => new Date(d).toLocaleDateString('pl-PL', { day: '2-digit', month: '2-digit' });
  AV.dateTimeShort = (d) => new Date(d).toLocaleDateString('pl-PL', { day: '2-digit', month: '2-digit' }) + ' ' +
    new Date(d).toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' });
  AV.nowStamp = () => new Date().toLocaleDateString('pl-PL', { day: '2-digit', month: '2-digit', year: 'numeric' }) + ', ' +
    new Date().toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' });

  AV.tzMap = {
    'Polska (Warszawa)': 'Europe/Warsaw',
    'Bali (Makassar)': 'Asia/Makassar',
    'Londyn': 'Europe/London',
    'Dubaj': 'Asia/Dubai'
  };
  AV.timeFmt = (d, state) => {
    const tz = AV.tzMap[state?.user?.timezone] || 'Europe/Warsaw';
    const h12 = state?.user?.hour12;
    return new Date(d).toLocaleTimeString('pl-PL', {
      timeZone: tz, hour: h12 ? 'numeric' : '2-digit', minute: '2-digit', hour12: !!h12
    });
  };
  AV.dayFmt = (d, state) => {
    const tz = AV.tzMap[state?.user?.timezone] || 'Europe/Warsaw';
    return new Date(d).toLocaleDateString('pl-PL', {
      timeZone: tz, weekday: 'long', day: 'numeric', month: 'long'
    });
  };

  /* ---------- storage ---------- */
  AV.safeGet = (k) => { try { return k ? localStorage.getItem(k) : null; } catch { return null; } };
  AV.safeSet = (k, v) => { try { if (!k) return false; localStorage.setItem(k, v); return true; } catch { return false; } };
  AV.safeDel = (k) => { try { if (k) localStorage.removeItem(k); } catch {} };

  /* ---------- toast ---------- */
  AV.toast = (msg, ms = 2600) => {
    const el = AV.$('#toast');
    if (!el) return;
    el.textContent = msg;
    el.classList.add('on');
    clearTimeout(el._t);
    el._t = setTimeout(() => el.classList.remove('on'), ms);
  };

  /* ---------- modale ---------- */
  AV.ensureModal = () => {
    if (AV.$('#gModal')) return;
    const el = document.createElement('div');
    el.className = 'modal';
    el.id = 'gModal';
    el.innerHTML = '<div class="mbox" id="gBody" role="dialog" aria-modal="true"></div>';
    document.body.appendChild(el);
    el.addEventListener('click', (e) => { if (e.target.id === 'gModal') AV.closeModal(); });
  };

  AV.openModal = (html, opts = {}) => {
    AV.ensureModal();
    const root = AV.$('#gModal'), body = AV.$('#gBody');
    body.style.maxWidth = opts.maxWidth || '';
    body.innerHTML = '<span class="mclose" data-gclose><svg class="i" style="width:16px;height:16px"><use href="#i-x"/></svg></span>' + html;
    root.classList.add('on');
    root.scrollTop = 0;
    body.querySelectorAll('[data-gclose]').forEach((b) => b.onclick = AV.closeModal);
    if (opts.onMount) opts.onMount(body);
    return body;
  };
  AV.closeModal = () => {
    const root = AV.$('#gModal');
    if (root) root.classList.remove('on');
  };

  AV.confirm = (opts) => {
    const body = AV.openModal(
      '<div class="eyebrow">' + AV.esc(opts.eyebrow || 'Potwierdzenie') + '</div>' +
      '<h1 style="font-size:28px;margin-top:8px">' + AV.esc(opts.title || 'Jesteś pewna?') + '</h1>' +
      '<p class="mut" style="margin-top:12px;line-height:1.6">' + (opts.html || AV.esc(opts.text || '')) + '</p>' +
      (opts.input ? '<span class="label">' + AV.esc(opts.inputLabel || 'Wpisz, aby potwierdzić') + '</span><input class="input mono" id="gConfirmInput" autocomplete="off">' : '') +
      '<div style="display:flex;gap:10px;margin-top:22px;justify-content:flex-end;flex-wrap:wrap">' +
      '<button class="btn g" data-gclose>' + AV.esc(opts.cancelLabel || 'Anuluj') + '</button>' +
      '<button class="btn ' + (opts.danger ? 'w' : 'p') + '" id="gConfirmOk">' + AV.esc(opts.confirmLabel || 'Potwierdź') + '</button></div>',
      { maxWidth: '520px' }
    );
    const ok = AV.$('#gConfirmOk', body);
    const input = AV.$('#gConfirmInput', body);
    ok.onclick = () => {
      if (opts.input && input.value.trim() !== opts.input) {
        input.style.borderColor = 'var(--loss)';
        AV.toast('Wpisz dokładnie: ' + opts.input);
        return;
      }
      AV.closeModal();
      if (opts.onConfirm) opts.onConfirm(input ? input.value.trim() : null);
    };
    if (input) setTimeout(() => input.focus(), 60);
  };

  /* ---------- pliki ---------- */
  AV.download = (filename, content, type = 'text/plain;charset=utf-8') => {
    const blob = content instanceof Blob ? content : new Blob([content], { type });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    setTimeout(() => { URL.revokeObjectURL(url); a.remove(); }, 1500);
  };

  AV.csvCell = (v) => {
    const s = String(v == null ? '' : v);
    return /[";\n\r]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
  };
  AV.toCsv = (rows, sep = ';') => '\ufeff' + rows.map((r) => r.map(AV.csvCell).join(sep)).join('\r\n');

  AV.parseCsv = (text, sep) => {
    text = String(text).replace(/^\ufeff/, '');
    if (!sep) {
      const first = text.split(/\r?\n/)[0] || '';
      sep = (first.match(/;/g) || []).length >= (first.match(/,/g) || []).length ? ';' : ',';
      if ((first.match(/\t/g) || []).length > (first.match(/;/g) || []).length) sep = '\t';
    }
    const rows = [];
    let row = [], cell = '', quote = false;
    for (let i = 0; i < text.length; i++) {
      const c = text[i];
      if (quote) {
        if (c === '"' && text[i + 1] === '"') { cell += '"'; i++; }
        else if (c === '"') quote = false;
        else cell += c;
      } else if (c === '"') quote = true;
      else if (c === sep) { row.push(cell); cell = ''; }
      else if (c === '\n') { row.push(cell); rows.push(row); row = []; cell = ''; }
      else if (c !== '\r') cell += c;
    }
    if (cell !== '' || row.length) { row.push(cell); rows.push(row); }
    return rows.filter((r) => r.some((x) => String(x).trim() !== ''));
  };

  /* ---------- ZIP (store) ---------- */
  const CRC_TABLE = (() => {
    const t = new Uint32Array(256);
    for (let n = 0; n < 256; n++) {
      let c = n;
      for (let k = 0; k < 8; k++) c = (c & 1) ? (0xEDB88320 ^ (c >>> 1)) : (c >>> 1);
      t[n] = c >>> 0;
    }
    return t;
  })();
  const crc32 = (bytes) => {
    let c = 0xFFFFFFFF;
    for (let i = 0; i < bytes.length; i++) c = CRC_TABLE[(c ^ bytes[i]) & 0xFF] ^ (c >>> 8);
    return (c ^ 0xFFFFFFFF) >>> 0;
  };
  const dosDate = (d = new Date()) => ((d.getFullYear() - 1980) << 9) | ((d.getMonth() + 1) << 5) | d.getDate();
  const dosTime = (d = new Date()) => (d.getHours() << 11) | (d.getMinutes() << 5) | (d.getSeconds() >> 1);

  AV.zip = (files) => {
    const enc = new TextEncoder();
    const chunks = [], central = [];
    let offset = 0;
    files.forEach((f) => {
      const name = enc.encode(f.name);
      const data = typeof f.data === 'string' ? enc.encode(f.data) : new Uint8Array(f.data);
      const crc = crc32(data);
      const local = new Uint8Array(30 + name.length);
      const dv = new DataView(local.buffer);
      dv.setUint32(0, 0x04034b50, true);
      dv.setUint16(4, 20, true);
      dv.setUint16(6, 0x0800, true); // UTF-8
      dv.setUint16(8, 0, true);
      dv.setUint16(10, dosTime(), true);
      dv.setUint16(12, dosDate(), true);
      dv.setUint32(14, crc, true);
      dv.setUint32(18, data.length, true);
      dv.setUint32(22, data.length, true);
      dv.setUint16(26, name.length, true);
      dv.setUint16(28, 0, true);
      local.set(name, 30);
      chunks.push(local, data);

      const cd = new Uint8Array(46 + name.length);
      const cv = new DataView(cd.buffer);
      cv.setUint32(0, 0x02014b50, true);
      cv.setUint16(4, 20, true);
      cv.setUint16(6, 20, true);
      cv.setUint16(8, 0x0800, true);
      cv.setUint16(10, 0, true);
      cv.setUint16(12, dosTime(), true);
      cv.setUint16(14, dosDate(), true);
      cv.setUint32(16, crc, true);
      cv.setUint32(20, data.length, true);
      cv.setUint32(24, data.length, true);
      cv.setUint16(28, name.length, true);
      cv.setUint16(30, 0, true);
      cv.setUint16(32, 0, true);
      cv.setUint16(34, 0, true);
      cv.setUint16(36, 0, true);
      cv.setUint32(38, 0, true);
      cv.setUint32(42, offset, true);
      cd.set(name, 46);
      central.push(cd);
      offset += local.length + data.length;
    });
    const centralSize = central.reduce((a, c) => a + c.length, 0);
    const end = new Uint8Array(22);
    const ev = new DataView(end.buffer);
    ev.setUint32(0, 0x06054b50, true);
    ev.setUint16(8, files.length, true);
    ev.setUint16(10, files.length, true);
    ev.setUint32(12, centralSize, true);
    ev.setUint32(16, offset, true);
    return new Blob([...chunks, ...central, end], { type: 'application/zip' });
  };

  AV.bytesFromDataUrl = (url) => {
    const m = /^data:[^;]+;base64,(.*)$/.exec(url || '');
    if (!m) return null;
    const bin = atob(m[1]);
    const out = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
    return out;
  };

  /* ---------- obrazki ---------- */
  AV.readImage = (file, maxSide = 1280) => new Promise((resolve, reject) => {
    if (!file || !String(file.type).startsWith('image/')) { reject(new Error('To nie jest plik obrazu')); return; }
    const r = new FileReader();
    r.onerror = () => reject(new Error('Nie udało się odczytać pliku'));
    r.onload = () => {
      const img = new Image();
      img.onerror = () => reject(new Error('Nie udało się otworzyć obrazu'));
      img.onload = () => {
        const scale = Math.min(1, maxSide / Math.max(img.width, img.height));
        const w = Math.max(1, Math.round(img.width * scale));
        const h = Math.max(1, Math.round(img.height * scale));
        const c = document.createElement('canvas');
        c.width = w; c.height = h;
        c.getContext('2d').drawImage(img, 0, 0, w, h);
        resolve(c.toDataURL('image/jpeg', 0.78));
      };
      img.src = r.result;
    };
    r.readAsDataURL(file);
  });

  AV.showImage = (labelEl, dataUrl) => {
    const drop = labelEl.closest('[data-drop]') || labelEl;
    if (!drop || !dataUrl) return;
    drop.querySelector('img')?.remove();
    const img = new Image();
    img.src = dataUrl;
    img.alt = 'Screen wykresu';
    drop.appendChild(img);
    drop.classList.add('has');
  };

  /* ---------- liczby rynkowe ---------- */
  AV.dec = (p) => Number(p) < 1 ? 4 : Number(p) < 20 ? 3 : Number(p) < 1000 ? 2 : 0;
  AV.pipSize = (x) => x && x[3] ? x[3] : 0.01;

  AV.dailyKeyFrom = (date, time) => {
    const [hh, mm] = String(time || '00:00').split(':').map(Number);
    const d = new Date(date);
    d.setHours(hh || 0, mm || 0, 0, 0);
    return d;
  };

  /* ---------- debug / test API ---------- */
  AV.ready = false;
})();
