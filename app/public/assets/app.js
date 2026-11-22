'use strict';
/* Hostkeep — vanilla JS front-end. All dynamic text goes through textContent (no innerHTML with data). */

const CSRF = document.querySelector('meta[name="csrf"]').content;
const content = document.getElementById('content');
/* Signed-in user, role and permissions (embedded by index.php). The server checks every request again. */
const ME = JSON.parse(document.getElementById('me-data').textContent);
const can = perm => ME.perms.includes(perm);
/* Root actions -> permission (mirror of ACTION_PERMS in app/src/rbac.php). */
const VERB_PERMS = {
  'collect-now': 'actions.routine', 'security-scan': 'actions.routine', 'apt-update': 'actions.routine', 'backup-now': 'actions.routine', 'offsite-now': 'actions.routine', 'certbot:dry-run': 'actions.routine',
  service: 'actions.services', 'pm2-restart': 'actions.services', 'f2b-unban': 'actions.services',
  site: 'actions.system', 'certbot:renew': 'actions.system', 'apt-clean': 'actions.system', 'journal-vacuum': 'actions.system', 'apt-upgrade': 'actions.system', 'backup-restore': 'actions.system', reboot: 'actions.system', 'offsite-test': 'actions.system', harden: 'actions.system',
  'self-update': 'panel.update', 'self-rollback': 'panel.update', 'web-domain': 'panel.update',
};
const canVerb = (verb, args = []) => can(VERB_PERMS[`${verb}:${args[0] ?? ''}`] || VERB_PERMS[verb] || 'none');

/* ---------- helpers ---------- */
const SVG_TAGS = ['svg', 'path', 'line', 'text', 'g', 'rect', 'circle', 'polyline', 'ellipse'];
function el(tag, attrs, ...kids) {
  const e = SVG_TAGS.includes(tag) ? document.createElementNS('http://www.w3.org/2000/svg', tag) : document.createElement(tag);
  for (const [k, v] of Object.entries(attrs || {})) {
    if (v === null || v === undefined || v === false) continue;
    if (k === 'class') e.setAttribute('class', v);
    else if (k.startsWith('on')) e.addEventListener(k.slice(2), v);
    else if (k === 'style') Object.assign(e.style, v);
    else e.setAttribute(k, v === true ? '' : v);
  }
  for (const kid of kids.flat(Infinity)) {
    if (kid === null || kid === undefined || kid === false) continue;
    e.append(kid instanceof Node ? kid : document.createTextNode(String(kid)));
  }
  return e;
}
function render(target, ...kids) {
  target.replaceChildren(...kids.flat(Infinity).filter(k => k !== null && k !== undefined && k !== false));
}
const fmtBytes = (b, d = 1) => {
  if (b === null || b === undefined || isNaN(b)) return '–';
  const u = ['o', 'Ko', 'Mo', 'Go', 'To']; let i = 0; b = Number(b);
  while (Math.abs(b) >= 1024 && i < u.length - 1) { b /= 1024; i++; }
  return `${b.toFixed(i ? d : 0)} ${u[i]}`;
};
const fmtRate = b => fmtBytes(b) + '/s';
const fmtDur = s => {
  s = Math.max(0, Math.floor(s)); const d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60);
  return d ? `${d} j ${h} h` : h ? `${h} h ${m} min` : `${m} min`;
};
const fmtDate = ts => ts ? new Date(ts * 1000).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' }) : '–';
const ago = ts => ts ? `il y a ${fmtDur(Date.now() / 1000 - ts)}` : 'jamais';
const num = n => Number(n ?? 0).toLocaleString('fr-FR');
const pct = (a, b) => b ? 100 * a / b : 0;
const level = p => p > 90 ? 'crit' : p > 75 ? 'warn' : '';
const badge = (text, cls) => el('span', { class: `badge ${cls || ''}` }, text);
const bar = p => el('div', { class: `bar ${level(p)}` }, el('span', { style: { width: `${Math.min(100, p).toFixed(1)}%` } }));
/* Bar comparing items between them (not a fill level): neutral colour, never an alert colour. */
const rbar = p => el('div', { class: 'bar rel' }, el('span', { style: { width: `${Math.min(100, p).toFixed(1)}%` } }));
/* systemd timestamps ("Tue 2026-11-10 08:12:33 UTC") -> "il y a 2 j", full date kept as tooltip. */
const since = raw => {
  const m = /(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}(?::\d{2})?)/.exec(raw || '');
  const t = m ? Date.parse(`${m[1]}T${m[2]}${/UTC/.test(raw) ? 'Z' : ''}`) : NaN;
  return isNaN(t) ? el('span', { class: 'small' }, raw || '–') : el('span', { class: 'small', title: raw }, ago(t / 1000));
};

/* Lucide-style line icons, drawn with DOM calls (CSP-safe). */
const ICONS = {
  home: [['path', { d: 'M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z' }]],
  sites: [['circle', { cx: 12, cy: 12, r: 9 }], ['path', { d: 'M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18' }]],
  system: [['rect', { x: 3, y: 4, width: 18, height: 7, rx: 2 }], ['rect', { x: 3, y: 13, width: 18, height: 7, rx: 2 }], ['path', { d: 'M7 7.5h.01M7 16.5h.01' }]],
  security: [['path', { d: 'M12 21s8-3.5 8-10V5l-8-3-8 3v6c0 6.5 8 10 8 10z' }], ['path', { d: 'm9 12 2 2 4-4' }]],
  data: [['ellipse', { cx: 12, cy: 5, rx: 8, ry: 3 }], ['path', { d: 'M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3' }]],
  refresh: [['path', { d: 'M20 12a8 8 0 1 1-2.3-5.7L20 8.5' }], ['path', { d: 'M20 3.5v5h-5' }]],
  theme: [['path', { d: 'M20.5 14A8.5 8.5 0 1 1 10 3.5a6.5 6.5 0 0 0 10.5 10.5z' }]],
  sun: [['circle', { cx: 12, cy: 12, r: 4 }], ['path', { d: 'M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4' }]],
  alert: [['path', { d: 'M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z' }], ['path', { d: 'M12 9v4M12 17h.01' }]],
  chevron: [['path', { d: 'm9 6 6 6-6 6' }]],
  install: [['path', { d: 'M12 3v12M7 10l5 5 5-5' }], ['path', { d: 'M5 21h14' }]],
  logout: [['path', { d: 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9' }]],
  x: [['path', { d: 'M18 6 6 18M6 6l12 12' }]],
  folder: [['path', { d: 'M3 6a2 2 0 0 1 2-2h4l2 2.5h8a2 2 0 0 1 2 2V18a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z' }]],
  file: [['path', { d: 'M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z' }], ['path', { d: 'M14 3v5h5' }]],
  link: [['path', { d: 'M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1' }]],
  up: [['path', { d: 'm15 18-6-6 6-6' }]],
  search: [['circle', { cx: 11, cy: 11, r: 7 }], ['path', { d: 'm20 20-3.5-3.5' }]],
  more: [['path', { d: 'M12 5h.01M12 12h.01M12 19h.01', 'stroke-width': 3 }]],
  check: [['path', { d: 'm5 12.5 4.5 4.5L19 7.5' }]],
  bolt: [['path', { d: 'M13 2 4 14h7l-1 8 9-12h-7z' }]],
  keyboard: [['rect', { x: 2, y: 6, width: 20, height: 12, rx: 2 }], ['path', { d: 'M6 10h.01M10 10h.01M14 10h.01M18 10h.01M7 14h10' }]],
  inbox: [['path', { d: 'M3 13h5l1.5 3h5L16 13h5' }], ['path', { d: 'M5.5 5h13L21 13v5a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-5z' }]],
  admin: [['path', { d: 'M4 21v-1a6 6 0 0 1 9-5.2' }], ['circle', { cx: 10, cy: 8, r: 4 }], ['circle', { cx: 18, cy: 17, r: 2.5 }], ['path', { d: 'M18 12.5v2M18 19.5v2M13.5 17h2M20.5 17h2' }]],
  user: [['circle', { cx: 12, cy: 8, r: 4 }], ['path', { d: 'M4 21a8 8 0 0 1 16 0' }]],
  account: [['circle', { cx: 12, cy: 8, r: 4 }], ['path', { d: 'M4 21a8 8 0 0 1 16 0' }]],
  key: [['circle', { cx: 8, cy: 15, r: 4 }], ['path', { d: 'm10.8 12.2 9.2-9.2M17 6l3 3M14.5 8.5l2 2' }]],
  mail: [['rect', { x: 3, y: 5, width: 18, height: 14, rx: 2 }], ['path', { d: 'm3 7 9 6 9-6' }]],
  plus: [['path', { d: 'M12 5v14M5 12h14' }]],
  updown: [['path', { d: 'm8 9 4-4 4 4M8 15l4 4 4-4' }]],
  sliders: [['path', { d: 'M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0' }], ['circle', { cx: 16, cy: 6, r: 2 }], ['circle', { cx: 10, cy: 12, r: 2 }], ['circle', { cx: 18, cy: 18, r: 2 }]],
  bell: [['path', { d: 'M6 9a6 6 0 0 1 12 0c0 6 2.5 7.5 2.5 7.5h-17S6 15 6 9z' }], ['path', { d: 'M10 20a2 2 0 0 0 4 0' }]],
  cloud: [['path', { d: 'M7 19a4.5 4.5 0 0 1-.6-8.96A6 6 0 0 1 18 9.5a4.75 4.75 0 0 1-.5 9.5z' }]],
};
const icon = name => el('svg', { viewBox: '0 0 24 24', class: 'i', 'aria-hidden': 'true' }, (ICONS[name] || []).map(([t, a]) => el(t, a)));
document.querySelectorAll('[data-icon]').forEach(s => s.replaceChildren(icon(s.dataset.icon)));

/* Accent- and case-insensitive text matching (table filters, command palette). */
const norm = s => String(s).toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');

/* Short confirmation messages, bottom of the screen. */
const toasts = document.getElementById('toasts');
function toast(text, cls = '') {
  const t = el('div', { class: `toast ${cls}`, role: cls === 'crit' ? 'alert' : 'status' }, cls ? icon(cls === 'ok' ? 'check' : 'alert') : null, el('span', {}, text));
  toasts.append(t);
  setTimeout(() => t.remove(), 6000);
  return t;
}

/* Banner shown while the server does not answer (network cut, server down). */
const netbar = document.getElementById('netbar');
const setOffline = off => { netbar.hidden = !off; };

async function api(params, opts = {}) {
  const q = new URLSearchParams(params).toString();
  let res;
  try {
    res = await fetch(`/api.php?${q}`, {
      method: opts.body ? 'POST' : 'GET',
      headers: { 'X-CSRF-Token': CSRF, 'X-Poll': opts.poll ? '1' : '0', ...(opts.body ? { 'Content-Type': 'application/json' } : {}) },
      body: opts.body ? JSON.stringify(opts.body) : undefined,
      credentials: 'same-origin',
    });
  } catch (e) { setOffline(true); throw new Error('serveur injoignable'); }
  setOffline(false);
  if (res.status === 401) { location.href = '/login.php?m=expired'; throw new Error('session expirée'); }
  const data = await res.json().catch(() => ({ error: `HTTP ${res.status}` }));
  if (data.must_change_password) { location.href = '/password.php'; throw new Error(data.error); }
  if (data.must_setup_2fa) { location.href = '/2fa.php'; throw new Error(data.error); }
  if (!res.ok || data.error || data.errors) {
    const err = new Error(data.error || (data.errors ? (data.errors._ || 'Corrige les champs signalés.') : `HTTP ${res.status}`));
    err.fields = data.errors;
    throw err;
  }
  return data;
}
/* Several blocks of one page share a section snapshot: fetch it once per page render. */
let cache = new Map();
const section = name => {
  if (!cache.has(name)) cache.set(name, api({ r: 'section', name }));
  return cache.get(name);
};

function card(title, body, actions) {
  return el('div', { class: 'card' },
    title || actions ? el('div', { class: 'card-head' }, title ? el('h3', {}, title) : el('span'), actions ? el('div', { class: 'actions' }, actions) : null) : null,
    body);
}
const emptyState = text => el('p', { class: 'empty' }, icon('inbox'), el('span', {}, text));
/* Tables longer than 8 rows get a filter field (matches any text of the row). */
function table(headers, rows, empty = 'Rien à afficher.') {
  if (!rows.length) return emptyState(empty);
  const tbody = el('tbody', {}, rows.map(r => el('tr', {}, r.map((c, i) => cell(headers[i], c)))));
  const wrap = el('div', { class: 'table-wrap' }, el('table', {},
    el('thead', {}, el('tr', {}, headers.map(h => el('th', { class: h.num ? 'num' : null, scope: 'col' }, h.label ?? h)))), tbody));
  if (rows.length <= 8) return wrap;
  const none = el('p', { class: 'empty', hidden: true }, 'Aucune ligne ne correspond au filtre.');
  const input = el('input', { type: 'search', class: 'table-filter', placeholder: `Filtrer les ${rows.length} lignes…`, 'aria-label': 'Filtrer le tableau' });
  input.addEventListener('input', () => {
    const q = norm(input.value.trim());
    let shown = 0;
    for (const tr of tbody.children) { tr.hidden = q !== '' && !norm(tr.textContent).includes(q); if (!tr.hidden) shown++; }
    none.hidden = shown > 0;
  });
  return [input, wrap, none];
}
/* data-label: column name shown in front of the value when rows are displayed as cards (phones). */
function cell(h, content) {
  const td = el('td', { class: h?.num ? 'num' : null, 'data-label': typeof h === 'string' ? h : (h?.label ?? '') }, content);
  if (td.children.length && [...td.children].every(k => k.matches('.btn'))) td.classList.add('actions-cell');
  return td;
}
function stat(label, value, sub, p, cls, trend) {
  return el('div', { class: `stat ${cls || ''}` }, el('div', { class: 'label' }, label), el('div', { class: 'value' }, value), trend ? spark(trend) : null, sub ? el('div', { class: 'sub' }, sub) : null, p !== undefined ? bar(p) : null);
}
/* Tiny trend line of the last live samples (no axes: the value above gives the scale). */
function spark(vals) {
  if (vals.length < 2) return null;
  const W = 200, H = 32, max = Math.max(...vals, 1e-9), min = Math.min(...vals, 0);
  const pts = vals.map((v, i) => `${(i * W / (vals.length - 1)).toFixed(1)},${(H - 2 - (v - min) / (max - min || 1) * (H - 4)).toFixed(1)}`).join(' ');
  return el('svg', { viewBox: `0 0 ${W} ${H}`, class: 'spark', preserveAspectRatio: 'none', 'aria-hidden': 'true' },
    el('polyline', { points: `0,${H} ${pts} ${W},${H}`, class: 'spark-area' }),
    el('polyline', { points: pts, class: 'spark-line', 'vector-effect': 'non-scaling-stroke' }));
}
const stats = (...s) => el('div', { class: 'stats' }, s);
const stale = upd => el('span', { class: 'stale', title: upd ? fmtDate(upd) : '' }, `relevé ${ago(upd)}`);
const note = text => el('p', { class: 'muted small' }, text);
const linkBtn = (label, onclick) => el('button', { class: 'linkish', onclick }, label);

/* ---------- modal & actions ---------- */
const modal = document.getElementById('modal');
let lastFocus = null;
function openModal(title, ...body) {
  lastFocus = document.activeElement;
  document.getElementById('modal-title').textContent = title;
  document.getElementById('modal-body').replaceChildren(...body);
  modal.hidden = false;
  document.getElementById('modal-close').focus();
}
function closeModal() {
  modal.hidden = true;
  lastFocus?.focus?.();
  if (modal.onclose) { const f = modal.onclose; modal.onclose = null; f(); }
}
document.getElementById('modal-close').onclick = closeModal;
modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });
document.addEventListener('keydown', e => {
  if (modal.hidden) return;
  if (e.key === 'Escape') { closeModal(); return; }
  if (e.key !== 'Tab') return;   // keep the focus inside the sheet
  const f = [...modal.querySelectorAll('a[href], button:not([disabled]), input, select, summary, [tabindex="0"]')].filter(n => n.offsetParent !== null);
  if (!f.length) return;
  const first = f[0], last = f.at(-1);
  if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
  else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
});

/* Confirmation dialog (native <dialog>: focus trapped, Escape cancels). Resolves true / false. */
function confirmDialog(message, { ok = 'Confirmer', danger = false } = {}) {
  return new Promise(resolve => {
    const yes = el('button', { class: `btn ${danger ? 'danger solid' : 'primary'}`, value: 'ok' }, ok);
    const dlg = el('dialog', { class: 'confirm', 'aria-labelledby': 'confirm-msg' },
      el('form', { method: 'dialog' },
        el('p', { id: 'confirm-msg' }, message),
        el('div', { class: 'actions' }, el('button', { class: 'btn', value: 'cancel' }, 'Annuler'), yes)));
    dlg.addEventListener('close', () => { resolve(dlg.returnValue === 'ok'); dlg.remove(); });
    dlg.addEventListener('click', e => { if (e.target === dlg) dlg.close('cancel'); });   // click on the backdrop
    document.body.append(dlg);
    dlg.showModal();
    yes.focus();
  });
}

function actionBtn(label, verb, args, { confirm: msg, cls = '' } = {}) {
  if (!canVerb(verb, args)) return null;
  return el('button', {
    class: `btn small ${cls}`, onclick: async () => {
      if (msg && !await confirmDialog(msg, { ok: label.replace(/ \(\d+\)$/, ''), danger: cls.includes('danger') })) return;
      runAction(label, verb, args);
    }
  }, label);
}
async function runAction(label, verb, args) {
  if (!canVerb(verb, args)) { toast('Ton rôle ne permet pas cette action.', 'crit'); return; }
  const status = el('p', {}, badge('en cours…', 'info'));
  const out = el('pre', { class: 'log' }, 'Lancement…');
  const name = label.replace(/ \(\d+\)$/, '');
  openModal(`${label} — ${[verb, ...args].join(' ')}`, status, out);
  /* Closing the sheet does not stop the job: it goes on in the background and a toast gives the result. */
  let open = true, done = false;
  modal.onclose = () => { open = false; if (done) route(true); else toast(`${name} : continue en arrière-plan…`); };
  let id;
  try { id = (await api({ r: 'action' }, { body: { verb, args } })).job; }
  catch (e) { done = true; status.replaceChildren(badge('refusé', 'crit')); out.textContent = e.message; return; }
  let fails = 0;
  const poll = async () => {
    try {
      const j = await api({ r: 'job', id }, { poll: true });
      fails = 0;
      out.textContent = j.output || '(pas encore de sortie)';
      out.scrollTop = out.scrollHeight;
      if (j.done) {
        done = true;
        status.replaceChildren(j.rc === 0 ? badge('terminé avec succès', 'ok') : badge(`échec (code ${j.rc})`, 'crit'));
        if (!open) { toast(j.rc === 0 ? `${name} : terminé` : `${name} : échec (code ${j.rc})`, j.rc === 0 ? 'ok' : 'crit'); route(true); }
        return;
      }
    } catch (e) { out.textContent = e.message; if (++fails > 20) return; }
    setTimeout(poll, 1500);
  };
  poll();
}

/* ---------- SVG line chart ---------- */
function lineChart(points, series, { yFmt = v => v, yMax = null, height = 170 } = {}) {
  const W = 800, H = height, L = 56, R = 10, T = 10, B = 22;
  const wrap = el('div', {});
  if (!points.length) { wrap.append(emptyState('Pas encore de données (le collecteur passe chaque minute).')); return wrap; }
  const xs = points.map(p => Number(p.t));
  const x0 = Math.min(...xs), x1 = Math.max(...xs, x0 + 1);
  let max = yMax ?? Math.max(1e-9, ...series.flatMap(s => points.map(p => Number(s.get(p)) || 0)));
  if (yMax === null) max *= 1.1;
  const X = t => L + (t - x0) / (x1 - x0) * (W - L - R), Y = v => T + (1 - v / max) * (H - T - B);
  const svg = el('svg', { viewBox: `0 0 ${W} ${H}`, class: 'chart', preserveAspectRatio: 'none', role: 'img', style: { height: `${H}px` } });
  for (let i = 0; i <= 4; i++) {
    const v = max * i / 4, y = Y(v);
    svg.append(el('line', { x1: L, x2: W - R, y1: y, y2: y, class: 'grid-line' }), el('text', { x: L - 6, y: y + 3, 'text-anchor': 'end' }, yFmt(v)));
  }
  const span = x1 - x0;
  for (let i = 0; i <= 4; i++) {
    const t = x0 + span * i / 4, d = new Date(t * 1000);
    const lab = span > 2 * 86400 ? d.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' }) : d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
    svg.append(el('text', { x: X(t), y: H - 6, 'text-anchor': i === 0 ? 'start' : i === 4 ? 'end' : 'middle' }, lab));
  }
  series.forEach(s => {
    const d = points.map((p, i) => `${i ? 'L' : 'M'}${X(Number(p.t)).toFixed(1)},${Y(Number(s.get(p)) || 0).toFixed(1)}`).join('');
    svg.append(el('path', { d, fill: 'none', style: { stroke: `var(${s.color})` }, 'stroke-width': 2, 'vector-effect': 'non-scaling-stroke' }));
  });
  const hover = el('line', { y1: T, y2: H - B, class: 'hover-line', visibility: 'hidden', 'vector-effect': 'non-scaling-stroke' });
  svg.append(hover);
  const tip = el('div', { class: 'chart-tip' }, ' ');
  const point = e => {
    const r = svg.getBoundingClientRect(), t = x0 + ((e.clientX - r.left) / r.width * W - L) / (W - L - R) * (x1 - x0);
    let best = points[0];
    for (const p of points) if (Math.abs(p.t - t) < Math.abs(best.t - t)) best = p;
    hover.setAttribute('x1', X(best.t)); hover.setAttribute('x2', X(best.t)); hover.setAttribute('visibility', 'visible');
    tip.textContent = `${fmtDate(Number(best.t))} — ` + series.map(s => `${s.name} : ${yFmt(Number(s.get(best)) || 0)}`).join(' · ');
  };
  svg.addEventListener('pointermove', point);
  svg.addEventListener('pointerdown', point);   // touch: a tap shows the value, it stays until the next tap
  svg.addEventListener('pointerleave', e => { if (e.pointerType === 'mouse') { hover.setAttribute('visibility', 'hidden'); tip.textContent = ' '; } });
  const legend = series.length > 1 ? el('div', { class: 'legend' }, series.map(s => el('span', {}, el('i', { style: { background: `var(${s.color})` } }), s.name))) : null;
  wrap.append(...[svg, legend, tip].filter(Boolean));   // append(null) would print "null"
  return wrap;
}

/* ---------- blocks (each returns nodes for one section of a page) ---------- */
let liveTimer = null;
const B = {};
const SEV = { crit: ['critique', 'crit'], warn: ['à corriger', 'warn'], info: ['conseil', 'info'] };

/* Old section ids (used by alerts and old bookmarks) -> page/block. */
const LEGACY = {
  overview: 'home', resources: 'home/history', processes: 'system/processes', services: 'system/services', pm2: 'system/pm2',
  sites: 'sites/sites', traffic: 'sites/traffic', certs: 'sites/certs', databases: 'data/databases', backups: 'data/backups',
  perf: 'system/perf', storage: 'system/storage', files: 'data/files', logs: 'data/logs', security: 'security/audit',
  updates: 'security/updates', cron: 'system/pm2', audit: 'security/journal', panel: 'admin/panel', notify: 'admin/settings/monitor',
};

const liveHist = [];   // last live samples, kept across page changes for the trend lines
/* One-tap routine checks, on the home page and in the command palette (none of them changes the server state). */
const QUICK = [['Contrôler les sites', 'collect-now'], ['Sauvegarder maintenant', 'backup-now'], ['Chercher les mises à jour', 'apt-update'], ['Lancer l\'audit', 'security-scan']];
const plural = (n, one, many = `${one}s`) => `${n} ${n > 1 ? many : one}`;

B.summary = async () => {
  const [ov, live] = await Promise.all([api({ r: 'overview' }), api({ r: 'live' }, { poll: true })]);
  const m = ov.metric || {};
  const box = el('div', { class: 'stats kpis' });
  const order = { crit: 0, warn: 1, info: 2 };
  const all = [...ov.alerts].sort((a, b) => order[a.level] - order[b.level]);
  const crit = all.filter(a => a.level === 'crit'), warn = all.filter(a => a.level === 'warn');
  const state = crit.length ? 'crit' : warn.length ? 'warn' : 'ok';
  const down = ov.sites_total - ov.sites_up;
  const upSince = el('span', {});
  const draw = l => {
    const memUsed = l.mem_total - l.mem_avail;
    liveHist.push({ cpu: l.cpu, mem: pct(memUsed, l.mem_total), net: l.net_rx + l.net_tx });
    if (liveHist.length > 40) liveHist.shift();
    const h = k => liveHist.map(x => x[k]);
    upSince.textContent = `${ov.host} · ${ov.os} · allumé depuis ${fmtDur(l.uptime)}`;
    box.replaceChildren(
      stat('CPU', `${l.cpu.toFixed(0)} %`, `charge ${l.load1.toFixed(2)} · ${plural(l.cores, 'cœur')}`, l.cpu, '', h('cpu')),
      stat('RAM', `${pct(memUsed, l.mem_total).toFixed(0)} %`, `${fmtBytes(memUsed)} / ${fmtBytes(l.mem_total)}`, pct(memUsed, l.mem_total), '', h('mem')),
      stat('Disque', m.disk_total ? `${pct(m.disk_used, m.disk_total).toFixed(0)} %` : '–', m.disk_total ? `${fmtBytes(m.disk_total - m.disk_used)} libres sur ${fmtBytes(m.disk_total)}${forecastText(ov.disk_forecast, true)}` : 'en attente', m.disk_total ? pct(m.disk_used, m.disk_total) : undefined),
      stat('Réseau', `↓ ${fmtRate(l.net_rx)}`, `↑ ${fmtRate(l.net_tx)}`, undefined, '', h('net')),
      stat('Sites en ligne', `${ov.sites_up} / ${ov.sites_total}`, down ? `${plural(down, 'site')} injoignable${down > 1 ? 's' : ''}` : 'tous répondent', undefined, down ? 'bad' : ''),
    );
  };
  draw(live);
  liveTimer = setInterval(async () => { if (document.hidden) return; try { draw(await api({ r: 'live' }, { poll: true })); } catch (e) { /* ignore */ } }, 5000);
  const list = el('div', { class: 'alerts', id: 'alerts' }, all.length
    ? all.map(a => el('a', { class: `alert ${a.level}`, href: `#${LEGACY[a.section] || 'home'}` }, badge({ crit: 'critique', warn: 'attention', info: 'info' }[a.level], a.level), el('span', {}, a.text), el('span', { class: 'go' }, el('span', { class: 'long' }, 'Voir '), icon('chevron'))))
    : el('div', { class: 'alert ok' }, badge('OK', 'ok'), el('span', {}, 'Aucune alerte. Sites, services, certificats et sauvegardes sont en ordre.')));
  /* Hero: the answer to "is everything fine?" first, then the routine checks one tap away. */
  const title = crit.length ? `${plural(crit.length, 'problème')} critique${crit.length > 1 ? 's' : ''} à traiter`
    : warn.length ? `${plural(warn.length, 'point')} à surveiller` : 'Tout fonctionne normalement';
  const hero = el('div', { class: `hero ${state}` },
    el('span', { class: 'hero-ico' }, icon(state === 'ok' ? 'check' : 'alert')),
    el('div', { class: 'hero-text', role: 'status' }, el('strong', {}, title), upSince),
    el('div', { class: 'hero-actions', role: 'group', 'aria-label': 'Actions rapides' },
      QUICK.filter(([, verb]) => canVerb(verb)).map(([label, verb]) => el('button', { class: 'btn', onclick: () => runAction(label, verb, []) }, label))));
  const b = ov.backup || {};
  const backups = el('div', { class: 'stats' },
    stat('Dernière sauvegarde', b.day ? (b.finished ? fmtDate(b.finished) : b.day) : 'aucune',
      b.day ? `${plural(b.dbs || 0, 'base')}${b.archives ? `, ${plural(b.archives, 'dossier')}` : ''} · ${fmtBytes(b.size)}${b.errors?.length ? ' · incomplète' : ''}` : 'chaque nuit',
      undefined, !b.day || b.errors?.length ? 'bad' : ''),
    offsiteStat(b.offsite));
  return [hero, box, el('h3', { class: 'sub-head' }, all.length ? `À traiter (${all.length})` : 'Alertes'), list,
    el('h3', { class: 'sub-head' }, 'Sauvegardes'),
    card(null, [backups, el('div', { class: 'form-actions' },
      b.day ? dlArchive(b.day, '', 'Télécharger la dernière archive complète') : null,
      el('a', { class: 'btn small', href: '#data/backups' }, 'Voir les sauvegardes'))])];
};

B.history = async () => {
  let range = sessionStorage.getItem('range') || '24h';
  const seg = el('div', { class: 'seg', role: 'group', 'aria-label': 'Période' }), box = el('div', { class: 'grid2' });
  const draw = async () => {
    seg.replaceChildren(...['1h', '24h', '7d', '30d'].map(r => el('button', { class: r === range ? 'on' : '', 'aria-pressed': String(r === range), onclick: () => { range = r; sessionStorage.setItem('range', r); draw(); } }, r.replace('d', ' j').replace('h', ' h'))));
    const p = (await api({ r: 'history', range })).points;
    const memMax = Number(p.at(-1)?.mem_total) || null, diskMax = Number(p.at(-1)?.disk_total) || null;
    box.replaceChildren(
      card('CPU', lineChart(p, [{ name: 'CPU', color: '--c1', get: x => x.cpu }], { yFmt: v => `${v.toFixed(0)} %`, yMax: 100 })),
      card('Mémoire', lineChart(p, [{ name: 'RAM', color: '--c2', get: x => x.mem_used }, { name: 'Swap', color: '--c4', get: x => x.swap_used }], { yFmt: v => fmtBytes(v, 0), yMax: memMax })),
      card('Réseau', lineChart(p, [{ name: 'Entrant', color: '--c1', get: x => x.rx }, { name: 'Sortant', color: '--c2', get: x => x.tx }], { yFmt: v => fmtRate(v) })),
      card('Disque', lineChart(p, [{ name: 'Utilisé', color: '--c3', get: x => x.disk_used }], { yFmt: v => fmtBytes(v, 1), yMax: diskMax })),
      card('Charge (1 min)', lineChart(p, [{ name: 'load1', color: '--c4', get: x => x.load1 }], { yFmt: v => v.toFixed(2) })),
    );
  };
  await draw();
  return { actions: seg, body: box };
};

B.processes = async () => {
  const s = await section('processes');
  const t = list => table(['PID', 'Utilisateur', { label: 'CPU', num: true }, { label: 'RAM', num: true }, { label: 'Depuis', num: true }, 'Commande'],
    (list || []).map(p => [p.pid, p.user, `${p.cpu} %`, fmtBytes(p.rss * 1024), fmtDur(p.etime), el('span', { class: 'mono trunc' }, p.cmd)]));
  return [el('div', { class: 'grid2' }, card('Par RAM', t(s.data?.by_mem)), card('Par CPU', t(s.data?.by_cpu))), stale(s.updated)];
};

/* Response time of one site over 7 or 30 days, in the modal. */
async function siteHistory(host) {
  let days = '7';
  const seg = el('div', { class: 'seg', role: 'group', 'aria-label': 'Période' }), box = el('div', { class: 'stack' }, el('div', { class: 'skeleton' }));
  const draw = async () => {
    seg.replaceChildren(...[['7', '7 j'], ['30', '30 j']].map(([v, l]) => el('button', { class: v === days ? 'on' : '', 'aria-pressed': String(v === days), onclick: () => { days = v; draw(); } }, l)));
    try {
      const h = await api({ r: 'sitehistory', host, days });
      const pts = h.points.map(p => ({ ...p, ms: p.ms === null ? null : Number(p.ms), max_ms: p.max_ms === null ? null : Number(p.max_ms) }));
      render(box,
        stats(
          stat('Temps moyen', h.avg !== null ? `${h.avg} ms` : '–', `sur ${h.days} jours`),
          stat('Pire mesure', h.max ? `${h.max} ms` : '–', 'moyenne la plus haute d\'un créneau'),
          stat('Contrôles en échec', `${num(h.errors)} / ${num(h.checks)}`, h.checks ? `${(100 * (h.checks - h.errors) / h.checks).toFixed(2)} % de réussite` : 'aucun contrôle', undefined, h.errors ? 'warn-stat' : ''),
        ),
        card(null, lineChart(pts, [{ name: 'Moyenne', color: '--c1', get: p => p.ms }, { name: 'Pire', color: '--c2', get: p => p.max_ms }], { yFmt: v => `${v.toFixed(0)} ms` })),
        note('Un contrôle toutes les 5 minutes (code HTTP et temps de réponse). Les contrôles en échec ne comptent pas dans les temps.'));
    } catch (e) { render(box, el('div', { class: 'error-box' }, `Erreur : ${e.message}`)); }
  };
  openModal(`Temps de réponse — ${host}`, el('div', { class: 'toolbar' }, seg), box);
  await draw();
}

const WEB_NAME = { apache: 'Apache', nginx: 'Nginx', caddy: 'Caddy' };
const TEST_CMD = { apache: 'configtest', nginx: 'nginx -t' };
/* "nginx:site.conf" -> { server, conf }; Hostkeep ≤ 2.1 snapshots hold bare Apache names. */
const splitConf = c => typeof c === 'object' ? c : c.includes(':') ? { server: c.split(':')[0], conf: c.slice(c.indexOf(':') + 1) } : { server: 'apache', conf: c };
B.sites = async () => {
  const s = await section('sites');
  const d = s.data || {}, up = d.uptime || {}, lat = d.latency || {};
  const both = (d.servers || []).length > 1;
  const rows = (d.sites || []).map(x => {
    const code = x.code ?? null;
    const cls = code === null ? '' : code >= 200 && code < 400 ? 'ok' : code < 500 && code > 0 ? 'warn' : 'crit';
    const certCls = x.cert_days === null || x.cert_days === undefined ? '' : x.cert_days < 15 ? 'crit' : x.cert_days < 30 ? 'warn' : 'ok';
    const confs = (x.confs || []).map(splitConf);
    return [
      el('div', {}, el('a', { href: x.url, target: '_blank', rel: 'noopener noreferrer' }, x.name), el('div', { class: 'muted small mono' }, x.docroot || ''), x.aliases?.length ? el('div', { class: 'muted small' }, x.aliases.join(', ')) : null),
      code === null ? '–' : badge(code === 0 ? 'injoignable' : `HTTP ${code}`, cls),
      code ? `${x.ms} ms` : '–',
      el('button', { class: 'trend', onclick: () => siteHistory(x.name), 'aria-label': `Historique du temps de réponse de ${x.name}`, title: 'Voir l\'historique' },
        spark((lat[x.name] || []).filter(v => v !== null)) || el('span', { class: 'muted small' }, 'Historique')),
      up[x.name] !== undefined ? `${up[x.name]} %` : '–',
      x.cert_days !== null && x.cert_days !== undefined ? badge(`${x.cert_days} j`, certCls) : badge('HTTP seul'),
      el('details', { class: 'more' }, el('summary', {}, both ? `Config ${(x.server || 'apache').split('+').map(v => WEB_NAME[v] || v).join(' + ')}` : 'Config'),
        el('div', { class: 'mono small' }, confs.map(c => both ? `${c.conf} (${WEB_NAME[c.server]})` : c.conf).join(', ')),
        confs.some(c => c.server === 'caddy') ? el('div', { class: 'muted small' }, 'Caddy : un seul Caddyfile pour tous les sites, à modifier sur le serveur (caddy reload).') : null,
        confs.filter(c => !c.conf.startsWith(d.self) && c.server !== 'caddy').map(c => actionBtn(`Désactiver ${c.conf}`, 'site', ['disable', c.server, c.conf], { cls: 'danger', confirm: `Désactiver ${c.conf} (${WEB_NAME[c.server]}) ? Le site ne sera plus servi (${TEST_CMD[c.server]} puis rechargement, retour arrière si erreur).` }))),
    ];
  });
  const disabled = (d.disabled || []).map(splitConf);
  const servers = (d.servers || []).map(v => WEB_NAME[v] || v).join(' et ');
  return {
    actions: actionBtn('Contrôler maintenant', 'collect-now', []),
    body: [
      card(null, table(['Site', 'Statut', { label: 'Réponse', num: true }, 'Tendance 24 h', { label: 'Dispo 7 j', num: true }, 'Certificat', ''], rows,
        servers ? `Aucun site trouvé dans la configuration ${servers}.` : 'Aucun serveur web actif détecté (Apache ou Nginx).')),
      disabled.length ? card(`Configurations désactivées (${disabled.length})`, table(['Fichier', 'Serveur', ''], disabled.map(c => [el('span', { class: 'mono' }, c.conf), WEB_NAME[c.server],
        actionBtn('Activer', 'site', ['enable', c.server, c.conf], { confirm: `Activer ${c.conf} (${WEB_NAME[c.server]}) ? (${TEST_CMD[c.server]} puis rechargement, retour arrière si erreur)` })]))) : null,
      servers ? note(`Sites lus dans la configuration ${servers}, contrôlés toutes les 5 minutes (code HTTP et temps de réponse).`) : null,
      stale(s.updated),
    ],
  };
};

B.certs = async () => {
  const s = await section('certs');
  const rows = (s.data?.certs || []).map(c => [el('strong', {}, c.name), el('span', { class: 'small' }, c.domains.join(', ')), fmtDate(c.expires),
    badge(`${c.days} j`, c.days < 15 ? 'crit' : c.days < 30 ? 'warn' : 'ok'), el('span', { class: 'muted small' }, c.by === 'caddy' ? 'Caddy' : 'certbot')]);
  return {
    actions: [actionBtn('Tester', 'certbot', ['dry-run']), actionBtn('Renouveler', 'certbot', ['renew'], { confirm: 'Lancer certbot renew ? (seuls les certificats à moins de 30 jours sont renouvelés)' })],
    body: [card(null, table(['Nom', 'Domaines', 'Expire le', 'Restant', 'Géré par'], rows)), note('Renouvellement automatique : par le timer certbot, ou par Caddy lui-même (30 jours avant l\'expiration). Les boutons ne concernent que certbot. Aucune suppression possible ici.')],
  };
};

B.traffic = async () => {
  let days = Number(sessionStorage.getItem('tdays') || 7), host = sessionStorage.getItem('thost') || '';
  const seg = el('div', { class: 'seg', role: 'group', 'aria-label': 'Période' }), sel = el('select', { 'aria-label': 'Site' }), body = el('div', { class: 'stack' });
  const sites = await section('sites');
  const draw = async () => {
    sessionStorage.setItem('tdays', days); sessionStorage.setItem('thost', host);
    const t = await api({ r: 'traffic', days, host });
    seg.replaceChildren(...[[1, '24 h'], [7, '7 j'], [30, '30 j']].map(([n, l]) => el('button', { class: n === days ? 'on' : '', 'aria-pressed': String(n === days), onclick: () => { days = n; draw(); } }, l)));
    sel.replaceChildren(el('option', { value: '' }, 'Tous les sites'), ...t.hosts.map(h => el('option', { value: h.host }, h.host)));
    sel.value = host;
    const tot = t.series.reduce((a, r) => ({ hits: a.hits + +r.hits, bots: a.bots + +r.bots, s4: a.s4 + +r.s4, s5: a.s5 + +r.s5 }), { hits: 0, bots: 0, s4: 0, s5: 0 });
    const visitors = host ? (t.hosts.find(h => h.host === host)?.visitors ?? 0) : t.series.reduce((a, r) => a + r.visitors, 0);
    const pts = t.series.map(r => ({ t: Date.parse(`${r.day}T12:00:00`) / 1000, hits: +r.hits - +r.bots, bots: +r.bots, visitors: r.visitors }));
    const top = (title, kind) => el('details', { class: 'top' }, el('summary', {}, title, el('span', { class: 'muted small' }, ` (${(t.top[kind] || []).length})`)),
      table(['', { label: 'Requêtes', num: true }], (t.top[kind] || []).map(x => [el('span', { class: 'mono small trunc' }, x.item), num(x.hits)]), 'Rien sur la période.'));
    render(body,
      t.since_first ? null : el('div', { class: 'alert info' }, badge('info', 'info'), (sites.data?.traffic_logs || []).length ? 'Les premières statistiques arrivent dans les 5 minutes.'
        : 'Aucun journal de trafic : relance « sudo hostkeep update » (Apache : GlobalLog, Nginx : conf.d/hostkeep-traffic.conf).'),
      (sites.data?.untracked || []).length ? el('details', { class: 'alert warn untracked' }, el('summary', {}, badge('à compléter', 'warn'),
        ` ${plural(sites.data.untracked.length, 'site')} Nginx non compté${sites.data.untracked.length > 1 ? 's' : ''} : ils ont leur propre access_log.`),
        el('p', { class: 'small' }, 'Ajoute cette ligne dans leur bloc server (à côté de leur access_log), puis recharge Nginx :'),
        el('pre', { class: 'log short' }, 'include snippets/hostkeep-traffic.conf;'),
        el('p', { class: 'small mono' }, sites.data.untracked.join(', '))) : null,
      stats(
        stat('Requêtes', num(tot.hits), `dont ${num(tot.bots)} de robots`),
        stat('Visiteurs', num(visitors), 'IP uniques hors robots'),
        stat('Erreurs 4xx', num(tot.s4), tot.hits ? `${pct(tot.s4, tot.hits).toFixed(1)} % des requêtes` : ''),
        stat('Erreurs 5xx', num(tot.s5), tot.s5 ? 'à regarder' : 'aucune', undefined, tot.s5 ? 'bad' : '')),
      days > 1 && pts.length > 1 ? card('Par jour', lineChart(pts, [{ name: 'Humains', color: '--c1', get: p => p.hits }, { name: 'Robots', color: '--c4', get: p => p.bots }, { name: 'Visiteurs', color: '--c3', get: p => p.visitors }], { yFmt: v => num(Math.round(v)) })) : null,
      host ? null : card('Par site', table(['Site', { label: 'Requêtes', num: true }, { label: 'Visiteurs', num: true }, { label: 'Robots', num: true }, { label: '4xx', num: true }, { label: '5xx', num: true }, { label: 'Données', num: true }],
        t.hosts.map(h => [linkBtn(h.host, () => { host = h.host; draw(); }), num(h.hits), num(h.visitors), num(h.bots), h.s4, Number(h.s5) ? badge(h.s5, 'crit') : '0', fmtBytes(h.bytes)]), 'Pas encore de données.')),
      card('Détails', el('div', { class: 'tops' }, top('Pages les plus vues', 'page'), top('Sites référents', 'ref'), top('Erreurs 404', '404'), top('Erreurs 5xx', '5xx'), top('IP les plus actives', 'ip'), top('Robots', 'bot'))),
    );
  };
  sel.onchange = () => { host = sel.value; draw(); };
  await draw();
  return { actions: [sel, seg], body };
};

const PM2_STATUS = { online: 'en ligne', stopped: 'arrêtée', stopping: 'arrêt…', launching: 'démarrage…', errored: 'en erreur', 'one-launch-status': 'lancée une fois' };
function pm2Table(pm2) {
  const apps = pm2.data?.apps || [];
  return table(['Nom', 'État', { label: 'CPU', num: true }, { label: 'Mémoire', num: true }, { label: 'Redém.', num: true }, 'En ligne depuis', ''],
    apps.map(a => [el('strong', {}, a.name), badge(PM2_STATUS[a.status] || a.status, a.status === 'online' ? 'ok' : 'crit'), `${a.cpu} %`, fmtBytes(a.memory), a.restarts,
      a.uptime ? fmtDur(Date.now() / 1000 - a.uptime / 1000) : '–', actionBtn('Redémarrer', 'pm2-restart', [a.name], { confirm: `Redémarrer l'appli PM2 ${a.name} ?` })]),
    'Aucune appli PM2.');
}

B.services = async () => {
  const s = await section('services');
  const failed = s.data?.failed || [];
  const rows = (s.data?.units || []).map(u => {
    const ok = u.ActiveState === 'active';
    const acts = u.action && canVerb('service') ? [
      actionBtn('Redémarrer', 'service', ['restart', u.action], { confirm: `Redémarrer ${u.action} ? Le service sera coupé quelques secondes.` }),
      u.reload ? actionBtn('Recharger', 'service', ['reload', u.action]) : null,
    ] : el('span', { class: 'muted small' }, 'lecture seule');
    return [el('div', {}, el('strong', {}, u.Id.replace('.service', '')), el('div', { class: 'muted small' }, u.Description)), badge(ok ? 'actif' : `${u.ActiveState} (${u.SubState})`, ok ? 'ok' : 'crit'),
      u.MemoryCurrent && u.MemoryCurrent !== '[not set]' ? fmtBytes(Number(u.MemoryCurrent)) : '–', since(u.ActiveEnterTimestamp), acts];
  });
  return [
    failed.length ? el('div', { class: 'alert crit' }, badge('échec', 'crit'), `Unités en échec : ${failed.join(', ')}`) : null,
    card(null, table(['Service', 'État', { label: 'Mémoire', num: true }, 'Démarré', ''], rows)),
    stale(s.updated),
  ];
};

B.pm2 = async () => {
  const [pm2, cron] = await Promise.all([section('pm2'), section('cron')]);
  const d = cron.data || {};
  const files = [...Object.entries(d.crontabs || {}).map(([u, c]) => [`crontab ${u}`, c]), ...Object.entries(d.crond || {}).map(([f, c]) => [`/etc/cron.d/${f}`, c])];
  return [
    card('Applications PM2', pm2Table(pm2)),
    card('Timers systemd', table(['Prochaine exécution', 'Timer', 'Déclenche'], (d.timers || []).map(t => [el('span', { class: 'small' }, t.next), el('span', { class: 'mono' }, t.unit), el('span', { class: 'mono' }, t.activates)]))),
    card('Crontabs', files.length ? files.map(([name, c]) => el('details', {}, el('summary', { class: 'mono' }, name), el('pre', { class: 'log' }, c || '(vide)'))) : emptyState('Aucune crontab.')),
    stale(cron.updated),
  ];
};

B.perf = async () => {
  const s = await section('perf');
  const d = s.data || {}, m = d.mariadb || {};
  const memMax = Math.max(1, ...(d.memory || []).map(x => x.rss));
  return [
    el('div', { class: 'grid2' },
      card('Mémoire par composant', table(['Composant', { label: 'RAM', num: true }, ''], (d.memory || []).map(x => [el('strong', {}, x.name), fmtBytes(x.rss), rbar(pct(x.rss, memMax))]))),
      card('MariaDB', stats(
        stat('Requêtes lentes', num(m.Slow_queries), `seuil ${Number(m.long_query_time ?? 0)} s · log ${m.slow_query_log === 'ON' ? 'actif' : 'inactif'}`),
        stat('Connexions max', m.max_connections ?? '–'),
        stat('Buffer InnoDB', fmtBytes(Number(m.innodb_buffer_pool_size)))))),
    card('Pools PHP-FPM', [table(['Pool', 'Utilisateur', 'Mode', { label: 'Processus', num: true }, { label: 'RAM', num: true }, 'Saturation', 'Sites'],
      (d.pools || []).map(p => [el('strong', {}, p.name), p.user, p.pm, `${p.procs} / ${p.max_children}`, fmtBytes(p.rss),
        p.max_reached ? badge(`max atteint ${p.max_reached}×`, 'warn') : badge('jamais', 'ok'), el('span', { class: 'small' }, p.sites.join(', ') || '–')])),
      note('« max atteint » = des visiteurs ont attendu un processus PHP libre.')]),
    (d.slow || []).length ? card('Requêtes MariaDB les plus lentes', table(['Base', { label: 'Nb', num: true }, { label: 'Total', num: true }, { label: 'Max', num: true }, { label: 'Lignes lues', num: true }, 'Requête'],
      d.slow.map(q => [q.db || '–', q.count, `${q.total.toFixed(1)} s`, `${q.max.toFixed(2)} s`, num(q.rows), el('details', {}, el('summary', { class: 'mono small' }, q.query.slice(0, 100)), el('pre', { class: 'log' }, q.example))]))) : null,
    stale(s.updated),
  ];
};

/* "Full in ~12 days" from the 30-day trend (overview.php disk_forecast). */
function forecastText(f, short = false) {
  if (!f) return short ? '' : 'pas encore assez de mesures (3 jours)';
  if (f.days === null) return short ? '' : `stable (${f.per_day >= 0 ? '+' : ''}${fmtBytes(f.per_day)} / jour)`;
  const when = f.days < 1 ? 'moins d\'un jour' : f.days > 365 ? 'plus d\'un an' : `~${plural(f.days, 'jour')}`;
  return short ? ` · plein dans ${when}` : `plein dans ${when} (+${fmtBytes(f.per_day)} / jour)`;
}
B.storage = async () => {
  const [s, ov] = await Promise.all([section('storage'), api({ r: 'overview' })]);
  const d = s.data || {};
  const f = ov.disk_forecast;
  const maxDir = Math.max(1, ...(d.dirs || []).map(x => x.size));
  return {
    actions: [actionBtn('Vider le cache apt', 'apt-clean', [], { confirm: 'Vider le cache apt ?' }), actionBtn('Réduire le journal', 'journal-vacuum', [], { confirm: 'Réduire le journal systemd à 50 Mo ?' })],
    body: [
      stats(stat('Prévision pour /', f?.days != null ? (f.days > 365 ? '> 1 an' : plural(f.days, 'jour')) : f ? 'stable' : '–', forecastText(f), undefined, f?.days != null && f.days < 14 ? 'bad' : '')),
      card(null, table(['Montage', 'Type', { label: 'Taille', num: true }, { label: 'Libre', num: true }, 'Remplissage'],
        (d.df || []).map(p => [el('div', {}, el('strong', {}, p.target), el('div', { class: 'mono small muted' }, p.source)), p.fstype, fmtBytes(p.size), fmtBytes(p.avail), el('div', { class: 'fill' }, `${p.pct} %`, bar(p.pct))]))),
      el('div', { class: 'grid2' },
        card('Plus gros dossiers', table(['Dossier', { label: 'Taille', num: true }, ''], (d.dirs || []).map(x => [el('span', { class: 'mono small' }, x.path), fmtBytes(x.size), rbar(pct(x.size, maxDir))]))),
        card('Nettoyable', stats(stat('Journal systemd', fmtBytes(d.journal), 'plafonné à 100 Mo'), stat('Cache apt', fmtBytes(d.apt_cache), 'paquets téléchargés')))),
      stale(s.updated),
    ],
  };
};

/* Audit fixes: one global, reversible file per fix (root helper « harden »), checked by configtest / nginx -t. */
const FIXES = {
  headers: { label: 'En-têtes de sécurité', desc: 'X-Content-Type-Options, X-Frame-Options, Referrer-Policy, et HSTS en HTTPS (6 mois). Jamais à la place d\'un en-tête déjà envoyé par un site.' },
  sensitive: { label: 'Fichiers sensibles bloqués', desc: '.env, .git, dumps SQL, sauvegardes, composer.json, phpinfo et listes de dossiers refusés (403). Le code PHP les lit toujours normalement.' },
  tokens: { label: 'Version du serveur masquée', desc: 'Apache (ServerTokens Prod) et Nginx (server_tokens off) n\'affichent plus leur version.' },
};
const fixFor = f => f.title === 'En-têtes de sécurité manquants' ? 'headers'
  : /affiche sa version/.test(f.title) ? 'tokens'
  : /téléchargeable|lisible|Liste du dossier|phpinfo/.test(f.title) ? 'sensitive' : null;
const fixBtn = (k, label = 'Appliquer', off = false) => actionBtn(label, 'harden', [k, off ? 'off' : 'on'], {
  cls: off ? '' : 'primary',
  confirm: off ? `Retirer « ${FIXES[k].label} » ? La configuration d'origine est remise et le serveur web rechargé.`
    : `Appliquer « ${FIXES[k].label} » à tous les sites ?\n\n${FIXES[k].desc}\n\nUn seul fichier de configuration est ajouté, vérifié (configtest / nginx -t) puis retiré automatiquement en cas d'erreur. L'audit est relancé ensuite.`,
});
function fixesCard(scan) {
  const h = scan.hardening || {}, srv = h.servers || {};
  const caddyCard = srv.caddy && h.caddy_snippet ? card('Caddy : corrections à copier', [
    el('p', {}, 'Caddy n\'a pas de fichier global que Hostkeep pourrait ajouter sans toucher à tes sites. Colle ce bloc en haut de /etc/caddy/Caddyfile, ajoute « import hostkeep_securite » dans le bloc de chaque site, puis vérifie et recharge :'),
    el('pre', { class: 'log' }, h.caddy_snippet.split('\n').filter(l => !l.startsWith('#')).join('\n').trim()),
    el('pre', { class: 'log' }, 'caddy validate --config /etc/caddy/Caddyfile && systemctl reload caddy'),
    note('Les en-têtes préfixés par « ? » ne remplacent jamais ceux déjà envoyés par un site. Caddy n\'affiche pas sa version : rien à masquer.'),
  ]) : null;
  if (!srv.apache && !srv.nginx) return caddyCard;
  const left = k => scan.findings.filter(f => fixFor(f) === k).length;
  return [card('Corrections automatiques', [
    table(['Correction', 'État', ''], Object.entries(FIXES).map(([k, f]) => [
      el('div', {}, el('strong', {}, f.label), el('div', { class: 'muted small' }, f.desc)),
      h[k] ? [badge('appliquée', 'ok'), left(k) ? el('div', { class: 'small muted' }, `${plural(left(k), 'constat')} restant${left(k) > 1 ? 's' : ''}`) : null]
        : left(k) ? badge(plural(left(k), 'constat'), 'warn') : el('span', { class: 'muted small' }, 'rien à corriger'),
      h[k] ? fixBtn(k, 'Retirer', true) : (left(k) ? fixBtn(k) : null)])),
    srv.nginx ? note('Nginx : les en-têtes globaux ne s\'appliquent pas aux blocs qui ont leurs propres add_header, et le blocage des fichiers sensibles demande une ligne « include » par site. Après « Appliquer », le compte rendu liste les fichiers où l\'ajouter.') : null,
  ]), caddyCard];
}
B.audit = async () => {
  const [s, sc] = await Promise.all([section('security'), section('secscan')]);
  const d = s.data || {}, scan = sc.data;
  const counts = { crit: 0, warn: 0, info: 0 };
  (scan?.findings || []).forEach(f => counts[f.severity]++);
  const newKernel = d.kernel_latest && d.kernel_latest !== d.kernel_running;
  return {
    actions: [
      actionBtn('Redémarrer le serveur', 'reboot', [], {
        cls: d.reboot_required ? 'danger' : '',
        confirm: `Redémarrer le serveur maintenant ?${newKernel ? ` Il doit démarrer sur le noyau ${d.kernel_latest}.` : ''} Tous les sites, services et ce panel seront coupés 1 à 2 minutes.`,
      }),
      actionBtn('Lancer le scan', 'security-scan', [])],
    body: [
      stats(
        stat('Critiques', scan ? counts.crit : '–', scan ? `scan ${ago(scan.ran)}` : 'jamais lancé', undefined, counts.crit ? 'bad' : ''),
        stat('À corriger', scan ? counts.warn : '–', `${counts.info} conseil(s)`),
        stat('Redémarrage', d.reboot_stuck ? 'bloqué' : d.reboot_required ? 'requis' : 'non requis',
          `noyau ${d.kernel_running || '?'}${newKernel ? ` → ${d.kernel_latest}` : ''}${d.booted_at ? ` · démarré ${ago(d.booted_at)}` : ''}`,
          undefined, d.reboot_stuck ? 'bad' : ''),
        stat('IP bannies', (d.jails || []).reduce((n, j) => n + j.banned_now, 0), `${(d.jails || []).reduce((n, j) => n + j.banned_total, 0)} au total`)),
      d.reboot_hint ? note(d.reboot_hint) : null,
      d.since_reboot ? note('Le serveur vient de redémarrer : le reste de cette page sera à jour au prochain passage du collecteur (moins de 5 min).') : null,
      scan ? fixesCard(scan) : null,
      card('Constats', [
        scan ? table(['Gravité', 'Site', 'Constat', 'Détail', ''], scan.findings.map(f => {
          const srv = scan.hardening?.servers || {};
          const k = srv.apache || srv.nginx ? fixFor(f) : null, on = scan.hardening?.[k];   // Caddy only: see the card to copy
          return [badge(...SEV[f.severity]), el('strong', {}, f.site), f.title, el('span', { class: 'mono small' }, f.detail),
            k ? (on ? el('span', { class: 'muted small', title: 'Correction déjà appliquée : ce site envoie ses propres règles, ou (Nginx) doit inclure le snippet.' }, 'voir ci-dessus') : fixBtn(k, 'Corriger')) : null];
        }), 'Aucun problème trouvé.')
          : emptyState('Pas encore de scan (chaque nuit à 4 h 20).'),
        note('Cherche fichiers sensibles exposés (.env, .git, dumps…), dossiers listables, en-têtes, ports et config SSH/Apache. Le scan ne modifie rien ; seuls les boutons « Corriger » changent la configuration.'),
      ]),
      stale(s.updated),
    ],
  };
};

B.updates = async () => {
  const u = await section('updates');
  const up = u.data || {};
  return {
    actions: [actionBtn('Rechercher', 'apt-update', []),
      up.count ? actionBtn(`Tout installer (${up.count})`, 'apt-upgrade', [], { cls: 'primary', confirm: `Installer ${up.count} mise(s) à jour maintenant ? Les services concernés redémarreront brièvement.` }) : null],
    body: [
      card(null, table(['Paquet', 'Nouvelle version', 'Actuelle', ''], (up.packages || []).map(p => [el('strong', {}, p.name), el('span', { class: 'mono small' }, p.version), el('span', { class: 'mono small' }, p.from), p.security ? badge('sécurité', 'warn') : el('span', { class: 'muted small' }, p.suite)]), 'Le système est à jour.')),
      note('apt-get upgrade : rien n\'est supprimé, les configs sont gardées, pas de redémarrage automatique. Les correctifs de sécurité s\'installent déjà seuls chaque nuit.'),
      stale(u.updated),
    ],
  };
};

B.panel = async () => {
  const s = await section('panel');
  const d = s.data || {};
  if (!d.git) return [card(null, emptyState('Le panel n\'a pas été installé depuis un clone git : la mise à jour depuis cette page est indisponible.')), stale(s.updated)];
  const n = (d.behind || []).length;
  const btn = actionBtn(n ? `Mettre à jour (${n})` : 'Vérifier et mettre à jour', 'self-update', [], {
    cls: n ? 'primary' : '',
    confirm: 'Récupérer la dernière version depuis GitHub (git pull) et réinstaller le panel ? La page sera indisponible quelques secondes.',
  });
  const back = d.previous ? actionBtn(`Revenir à ${d.previous.hash}`, 'self-rollback', [], {
    cls: 'danger',
    confirm: `Revenir à la version précédente (${d.previous.hash} — ${d.previous.subject}) et réinstaller le panel ?`,
  }) : null;
  return {
    actions: [back, btn],
    body: [
      stats(
        stat('Version installée', d.hash || '–', d.ts ? `${fmtDate(d.ts)} · ${d.subject}` : ''),
        stat('Branche suivie', d.remote || d.branch || '–', d.source),
        stat('Nouveautés', n ? String(n) : 'aucune', n ? 'en attente sur GitHub' : 'le panel est à jour', undefined, n ? 'warn-stat' : ''),
      ),
      d.dirty ? el('div', { class: 'alert warn' }, badge('attention', 'warn'), el('span', {}, `Des fichiers ont été modifiés à la main dans ${d.source} : la mise à jour sera refusée.`)) : null,
      n ? card('À installer', table(['Commit', 'Date', 'Description'], d.behind.map(c => [el('span', { class: 'mono' }, c.hash), fmtDate(c.ts), c.subject]))) : null,
      d.previous ? note(`Version précédente gardée pour un retour arrière : ${d.previous.hash} (${fmtDate(d.previous.ts)}) — ${d.previous.subject}.`) : null,
      can('panel.update') ? card('Archive de migration', [
        el('p', {}, 'Un seul fichier pour réinstaller ce panel à l\'identique sur un autre serveur : le code de la version installée, les réglages (sans aucun secret), les comptes si tu veux (chiffrés), et restore.sh qui fait tout en une commande.'),
        el('div', { class: 'form-actions' }, el('button', { class: 'btn', type: 'button', onclick: exportBundle }, icon('install'), 'Télécharger l\'archive de migration…')),
        note('Les données du serveur (sites, bases, certificats) ne sont pas dedans : elles passent par les sauvegardes (Données › Sauvegardes, copie externe).'),
      ]) : null,
      note('git pull (avance rapide uniquement) dans le dépôt source, puis ./install.sh app : la configuration (.env) et le mot de passe sont conservés. Après une mise à jour, « Revenir à… » réinstalle la version d\'avant.'),
      stale(s.updated),
    ],
  };
};

B.access = async () => {
  const s = await section('security');
  const d = s.data || {};
  return [
    card('fail2ban', (d.jails || []).length ? d.jails.map(j => el('details', { open: j.banned.length ? true : null },
      el('summary', {}, el('strong', {}, j.name), el('span', { class: 'muted small' }, ` — ${j.banned_now} bannie(s), ${j.failed_now} échec(s) en cours`)),
      table(['IP bannie', ''], j.banned.map(ip => [el('span', { class: 'mono' }, ip), actionBtn('Débannir', 'f2b-unban', [j.name, ip], { confirm: `Débannir ${ip} de ${j.name} ?` })]), 'Aucune IP bannie.')))
      : emptyState('fail2ban ne répond pas.')),
    el('div', { class: 'grid2' },
      card('Ports en écoute', table(['Adresse', 'Processus'], (d.listening || []).map(l => [el('span', { class: 'mono' }, l.addr), l.proc]))),
      card('Connexions SSH (7 j)', (d.logins || []).length ? el('pre', { class: 'log short' }, d.logins.join('\n')) : emptyState('Aucune connexion trouvée.'))),
    stale(s.updated),
  ];
};

B.journal = async () => {
  const rows = await api({ r: 'audit' });
  return card(null, table(['Date', 'Utilisateur', 'IP', 'Action', 'Résultat'],
    rows.map(r => [fmtDate(Number(r.ts)), r.user, el('span', { class: 'mono' }, r.ip), el('span', { class: 'mono' }, r.action),
      r.rc === null ? badge('en cours / inconnu') : Number(r.rc) === 0 ? badge('succès', 'ok') : badge(`échec (${r.rc})`, 'crit')]), 'Aucune action pour l\'instant.'));
};

B.databases = async () => {
  const s = await section('databases');
  const d = s.data || {}, st = d.status || {};
  return [
    stats(stat('MariaDB', d.version || '–', st.Uptime ? `depuis ${fmtDur(st.Uptime)}` : ''),
      stat('Connexions', st.Threads_connected ?? '–', `max utilisé : ${st.Max_used_connections ?? '–'}`),
      stat('Requêtes', st.Questions ? num(st.Questions) : '–', `${st.Slow_queries ?? 0} lente(s)`)),
    card(null, table(['Base', { label: 'Tables', num: true }, { label: 'Lignes (est.)', num: true }, { label: 'Taille', num: true }],
      (d.dbs || []).map(b => [el('strong', {}, b.name), b.tables, num(b.rows), fmtBytes(b.size)]))),
    stale(s.updated),
  ];
};

/* Off-site copy, in a few words (backups page and home). */
function offsiteStat(o) {
  if (!o?.enabled) return stat('Copie externe', 'désactivée', can('settings.view') ? 'Paramètres › Sauvegarde externe' : 'sauvegardes sur ce serveur seulement', undefined, 'bad');
  if (o.last_error) return stat('Copie externe', 'en échec', o.last_ok ? `dernière réussie ${ago(o.last_ok)}` : 'jamais réussie', undefined, 'bad');
  return stat('Copie externe', o.last_ok ? ago(o.last_ok) : 'en attente', o.last_ok ? `${o.last_day ? `jour ${o.last_day} · ` : ''}${o.label || ''}` : 'part avec la prochaine sauvegarde');
}
/* Mirror of backup_holds_panel_secrets() (app/src/rbac.php): the panel's own secrets need « backups.panel ». */
const panelSecret = ({ db = '', archive = null, pre = '' }) => pre ? /^\d{8}-\d{6}-hostkeep\.sql\.gz$/.test(pre) : archive !== null ? archive === '' || archive === 'hostkeep' : db === 'hostkeep';
const canBackupDl = what => can('backups.download') && (!panelSecret(what) || can('backups.panel'));
const dlArchive = (day, archive = '', label = 'Télécharger') => canBackupDl({ archive })
  ? el('a', { class: 'btn small', href: `/api.php?${new URLSearchParams({ r: 'backupdl', day, archive })}`, download: '' }, label) : null;
const dayLabel = day => new Date(`${day}T12:00:00`).toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' });

B.backups = async () => {
  const [s, o] = await Promise.all([section('backups'), section('offsite')]);
  const d = s.data || {}, off = o.data || {};
  const dl = (day, db) => canBackupDl({ db }) ? el('a', { class: 'btn small', href: `/api.php?${new URLSearchParams({ r: 'backupdl', day, db })}` }, 'Télécharger') : null;
  const last = d.days?.[0];
  return {
    actions: [
      off.enabled ? actionBtn('Envoyer maintenant', 'offsite-now', []) : null,
      actionBtn('Sauvegarder maintenant', 'backup-now', [], { cls: 'primary' })],
    body: [
      stats(
        stat('Dernière sauvegarde', last ? (last.finished ? fmtDate(last.finished) : new Date(`${last.day}T12:00:00`).toLocaleDateString('fr-FR')) : 'aucune',
          d.enabled === false ? 'automatique désactivée' : `chaque nuit à ${(d.time || '03:10').replace(':', ' h ')}${last?.errors?.length ? ' · incomplète' : ''}`,
          undefined, !last || d.enabled === false || last.errors?.length ? 'bad' : ''),
        offsiteStat(off),
        stat('Espace utilisé', fmtBytes(d.total), `${plural(d.days?.length ?? 0, 'jour')} · bases ${d.keep ?? 7} j, fichiers ${d.files_enabled ? `${d.files_keep ?? 3} j` : 'non'}${d.free ? ` · ${fmtBytes(d.free)} libres` : ''}`)),
      last && canBackupDl({ archive: '' }) ? card(null, el('div', { class: 'row-between' },
        el('div', {}, el('strong', {}, 'Archive complète du dernier jour'), el('p', { class: 'muted small' },
          `${dayLabel(last.day)} : ${plural(last.files.length, 'base')}${last.archives?.length ? `, ${last.archives.map(a => a.name).join(', ')}` : ''} — ${fmtBytes(last.size)}, en un seul fichier .tar`)),
        dlArchive(last.day, '', 'Télécharger l\'archive complète'))) : null,
      card(null, (d.days || []).length ? d.days.map(day => el('details', { open: day === d.days[0] ? true : null },
        el('summary', {}, el('strong', {}, dayLabel(day.day)),
          el('span', { class: 'muted small' }, ` — ${plural(day.files.length, 'base')}${day.archives?.length ? `, ${plural(day.archives.length, 'archive')} de fichiers` : ''}, ${fmtBytes(day.size)}`),
          day.errors?.length ? [' ', badge('incomplète', 'warn')] : null),
        day.errors?.length ? el('div', { class: 'alert warn' }, badge('erreur', 'warn'), el('span', {}, day.errors.join(' · '))) : null,
        table(['Base', { label: 'Taille', num: true }, 'Faite à', ''], day.files.map(f => [el('strong', {}, f.db), fmtBytes(f.size), fmtDate(f.mtime),
          [dl(day.day, f.db), f.db === 'mysql' || (f.db === 'hostkeep' && !can('backups.panel')) ? null : actionBtn('Restaurer', 'backup-restore', [day.day, f.db], { cls: 'danger', confirm: `Remplacer le contenu actuel de la base ${f.db} par la sauvegarde du ${day.day} ?\n\nUne copie de l'état actuel est faite juste avant, tu pourras revenir en arrière.` })]])),
        day.archives?.length ? table(['Fichiers', 'Dossier', { label: 'Taille', num: true }, ''], day.archives.map(a => [el('strong', {}, a.name), el('span', { class: 'mono small' }, a.path), fmtBytes(a.size), dlArchive(day.day, a.name)])) : null,
        el('p', { class: 'small' }, dlArchive(day.day, '', 'Tout ce jour en un fichier'))))
        : emptyState('Aucune sauvegarde pour l\'instant.')),
      (d.pre || []).length ? card('Copies avant restauration', table(['Fichier', { label: 'Taille', num: true }, 'Date', ''], d.pre.map(p => [el('span', { class: 'mono' }, p.file), fmtBytes(p.size), fmtDate(p.mtime),
        canBackupDl({ pre: p.file }) ? el('a', { class: 'btn small', href: `/api.php?${new URLSearchParams({ r: 'backupdl', pre: p.file })}` }, 'Télécharger') : null]))) : null,
      note(`Les bases se restaurent ici. Les fichiers se restaurent sur le serveur : « sudo hostkeep restore files <jour> <archive> » (extrait à part, sans rien écraser).${off.enabled ? '' : ' Sans copie externe, une panne du disque emporte aussi les sauvegardes.'}`),
      stale(s.updated),
    ],
  };
};

B.logs = async () => {
  const s = await section('logkeys');
  const keys = s.data?.keys || [];
  const sel = el('select', { 'aria-label': 'Journal' }, keys.map(k => el('option', { value: k.key }, k.label + (k.size ? ` (${fmtBytes(k.size)})` : ''))));
  sel.value = sessionStorage.getItem('logkey') || keys[0]?.key || '';
  const lines = el('select', { 'aria-label': 'Nombre de lignes' }, [100, 200, 500, 1000, 2000].map(n => el('option', { value: n }, `${n} lignes`)));
  lines.value = '200';
  const filter = el('input', { type: 'search', placeholder: 'Filtrer…', 'aria-label': 'Filtrer les lignes' });
  const out = el('pre', { class: 'log' }, 'Chargement…');
  let raw = '';
  const apply = () => {
    const f = filter.value.toLowerCase();
    out.textContent = (f ? raw.split('\n').filter(l => l.toLowerCase().includes(f)).join('\n') : raw) || '(vide)';
    out.scrollTop = out.scrollHeight;
  };
  const load = async () => {
    sessionStorage.setItem('logkey', sel.value);
    out.textContent = 'Chargement…';
    try { raw = (await api({ r: 'log', key: sel.value, lines: lines.value })).output; } catch (e) { raw = `Erreur : ${e.message}`; }
    apply();
  };
  sel.onchange = load; lines.onchange = load; filter.oninput = apply;
  if (sel.value) load(); else out.textContent = 'Aucun journal (en attente du collecteur).';
  return card(null, [el('div', { class: 'toolbar' }, sel, lines, filter, el('button', { class: 'btn small', onclick: load }, 'Recharger')), out]);
};

B.files = async () => {
  let path = sessionStorage.getItem('fspath') || '';
  const crumbs = el('div', { class: 'toolbar crumbs' }), list = el('div', {});
  const roots = (await api({ r: 'fsroots' })).roots;   // HOSTKEEP_FILE_ROOTS, filtered by the root helper
  if (!roots.length) return card(null, emptyState('Aucun dossier autorisé (HOSTKEEP_FILE_ROOTS).'));
  if (!roots.some(r => path === r || path.startsWith(`${r}/`))) path = roots[0];
  const rootSel = el('select', { 'aria-label': 'Racine', onchange: () => go(rootSel.value) }, roots.map(r => el('option', { value: r }, r)));
  const dlUrl = p => `/api.php?${new URLSearchParams({ r: 'fsdl', path: p })}`;
  const view = async p => {
    const pre = el('pre', { class: 'log' }, 'Chargement…');
    openModal(p, el('div', { class: 'toolbar' }, el('a', { class: 'btn small', href: dlUrl(p) }, 'Télécharger')), pre);
    try {
      const f = await api({ r: 'fsread', path: p });
      pre.textContent = f.binary ? 'Fichier binaire : utilise « Télécharger ».' : (f.content || '(vide)') + (f.truncated ? '\n\n… (affichage limité à 512 Ko)' : '');
    } catch (e) { pre.textContent = `Erreur : ${e.message}`; }
  };
  const entry = (ico, label, onclick) => el('button', { class: 'linkish entry', onclick }, icon(ico), label);
  const go = async p => {
    render(list, el('div', { class: 'skeleton' }));
    try {
      const r = await api({ r: 'fs', path: p });
      path = r.path; sessionStorage.setItem('fspath', path);
      const root = roots.find(x => path === x || path.startsWith(`${x}/`)) || roots[0];
      rootSel.value = root;
      const parts = path.slice(root.length).split('/').filter(Boolean);
      crumbs.replaceChildren(rootSel, ...parts.flatMap((part, i) => [el('span', { class: 'muted' }, '/'), linkBtn(part, () => go(root + '/' + parts.slice(0, i + 1).join('/')))]));
      const up = path !== root ? [[entry('up', 'Dossier parent', () => go(path.replace(/\/[^/]+$/, '') || root)), '', '', '']] : [];
      render(list, table(['Nom', { label: 'Taille', num: true }, 'Modifié', 'Droits'], [...up, ...r.entries.map(e => {
        const full = `${path}/${e.name}`;
        const nameCell = e.type === 'dir' ? entry('folder', e.name, () => go(full))
          : e.type === 'link' ? entry('link', e.name, async () => { try { await api({ r: 'fs', path: full }); go(full); } catch (err) { view(full); } })
            : entry('file', e.name, () => view(full));
        return [nameCell, e.type === 'file' ? fmtBytes(e.size) : '', el('span', { class: 'small' }, fmtDate(e.mtime)), el('span', { class: 'mono small', title: `${e.owner}:${e.group}` }, `${e.mode} ${e.owner}`)];
      })], 'Dossier vide.'), r.total > r.entries.length ? note(`${r.entries.length} premiers éléments sur ${r.total}.`) : null);
    } catch (e) { render(list, el('div', { class: 'error-box' }, `Erreur : ${e.message}`)); if (p !== roots[0]) sessionStorage.removeItem('fspath'); }
  };
  go(path);
  return card(null, [crumbs, list, note('Lecture seule. Les clés privées ne sont jamais lisibles.')]);
};

/* ---------- forms (settings, accounts) ---------- */
let fieldSeq = 0;
/* One labelled input. f: { key, type, label, help, value, options, min, max, placeholder, set } */
function field(f, { editable = true } = {}) {
  const id = `f${++fieldSeq}`, v = f.value ?? '';
  const help = f.help ? el('p', { class: 'help', id: `${id}-h` }, f.help) : null;
  const err = el('p', { class: 'field-error', role: 'alert', hidden: true });
  const common = { id, name: f.key, disabled: !editable, 'aria-describedby': f.help ? `${id}-h` : null, required: f.required || null };
  if (f.type === 'bool') {
    return el('div', { class: 'field check' }, el('label', { for: id }, el('input', { ...common, type: 'checkbox', checked: v === '1' || v === true }), el('span', {}, f.label)), help, err);
  }
  let input;
  if (f.type === 'select') input = el('select', common, Object.entries(f.options).map(([k, l]) => el('option', { value: k, selected: String(k) === String(v) }, l)));
  else if (f.type === 'cidrs') input = el('textarea', { ...common, rows: 3, spellcheck: 'false', placeholder: '203.0.113.10\n10.8.0.0/24' }, v);
  else if (f.type === 'lines') input = el('textarea', { ...common, rows: 4, spellcheck: 'false', class: 'mono' }, v);
  else if (f.type === 'secrettext') input = el('textarea', { ...common, rows: 5, spellcheck: 'false', autocomplete: 'off', class: 'mono', placeholder: f.set ? '•••••••• (enregistré, jamais réaffiché) — colle une nouvelle valeur pour la remplacer' : f.placeholder }, '');
  else {
    const type = { int: 'number', time: 'time', email: 'email', password: 'password' }[f.type] || 'text';
    input = el('input', {
      ...common, type, value: f.type === 'password' ? '' : v, min: f.min, max: f.max, maxlength: f.type === 'text' ? f.max : null,
      placeholder: f.type === 'password' && f.set ? '•••••••• (enregistré)' : f.placeholder, autocomplete: f.autocomplete || (f.type === 'password' ? 'new-password' : 'off'),
      spellcheck: 'false', inputmode: f.type === 'int' ? 'numeric' : null,
    });
  }
  /* A stored secret can't be shown, nor cleared by leaving the box empty (= keep): explicit box to remove it. */
  const clear = (f.type === 'password' || f.type === 'secrettext') && f.set && editable
    ? el('label', { class: 'small muted clear-secret' }, el('input', { type: 'checkbox', name: `${f.key}#clear` }), ' Supprimer la valeur enregistrée') : null;
  return el('div', { class: 'field', 'data-show': f.show ? JSON.stringify(f.show) : null }, el('label', { for: id }, f.label), input, clear, help, err);
}
/* Fields that only apply to some choices of a select (extra 'show' => [field, [values]]). */
function bindShowWhen(form) {
  const update = () => form.querySelectorAll('[data-show]').forEach(box => {
    const [key, vals] = JSON.parse(box.dataset.show);
    const ctl = form.elements[key];
    box.hidden = !!ctl && !vals.includes(ctl.value);
  });
  form.addEventListener('change', update);
  update();
}
function formValues(form) {
  const out = {};
  for (const e of form.elements) if (e.name && !e.disabled) out[e.name] = e.type === 'checkbox' ? (e.checked ? '1' : '0') : e.type === 'radio' ? (e.checked ? e.value : out[e.name]) : e.value;
  return out;
}
/* Shows the server's per-field messages next to the fields (others as a toast). */
function showErrors(form, errors) {
  form.querySelectorAll('.field-error').forEach(p => { p.hidden = true; });
  let first = null;
  for (const [k, msg] of Object.entries(errors || {})) {
    const input = form.querySelector(`[name="${CSS.escape(k)}"]`);
    const p = input?.closest('.field')?.querySelector('.field-error');
    if (p) { p.textContent = msg; p.hidden = false; first ??= input; } else toast(msg, 'crit');
  }
  first?.focus();
}
/* Form whose submit calls fn(values); the button shows progress, errors go next to the fields. */
function apiForm(fields, submitLabel, fn, { extra = null, editable = true } = {}) {
  const btn = el('button', { class: 'btn primary', type: 'submit', hidden: !editable }, submitLabel);
  const form = el('form', { class: 'form', novalidate: true }, fields, el('div', { class: 'form-actions' }, extra, btn));
  form.addEventListener('submit', async e => {
    e.preventDefault();
    btn.disabled = true;
    const label = btn.textContent;
    btn.textContent = 'Enregistrement…';
    try { await fn(formValues(form), form); showErrors(form, {}); } catch (err) { if (err.fields) showErrors(form, err.fields); else toast(err.message, 'crit'); }
    finally { btn.disabled = false; btn.textContent = label; }
  });
  return form;
}
async function copyText(text) {
  try { await navigator.clipboard.writeText(text); toast('Copié dans le presse-papiers.', 'ok'); } catch (e) { toast('Copie impossible : sélectionne le texte à la main.', 'crit'); }
}
/* A secret shown once (temporary password). */
function secretBox(value) {
  return el('div', { class: 'secret' }, el('code', { class: 'mono' }, value), el('button', { class: 'btn small', type: 'button', onclick: () => copyText(value) }, 'Copier'));
}
const roleBadge = (role, label) => badge(label, { superadmin: 'crit', admin: 'warn', editor: 'info', viewer: '' }[role]);
const avatar = u => el('span', { class: 'avatar', 'aria-hidden': 'true' }, (u.display_name || u.username).slice(0, 1).toUpperCase());

/* ---------- Administration › Utilisateurs ---------- */
function userState(u) {
  if (!u.active) return badge('désactivé', 'crit');
  if (u.locked_until && u.locked_until > Date.now() / 1000) return badge('verrouillé', 'warn');
  if (u.must_change_password) return badge('mot de passe à changer', 'info');
  return badge('actif', 'ok');
}
function userDialog(d, u = null) {
  const roles = d.roles.list.filter(r => d.roles.assignable.includes(r.id));
  const fields = [
    field({ key: 'username', label: 'Identifiant', value: u?.username, max: 40, required: true, help: '3 à 40 caractères : lettres, chiffres, _ . -' }),
    field({ key: 'display_name', label: 'Nom affiché', value: u?.display_name, max: 80 }),
    field({ key: 'email', type: 'email', label: 'E-mail', value: u?.email || '', help: 'Pour « mot de passe oublié », les invitations et les alertes.' }),
    field({ key: 'role', type: 'select', label: 'Rôle', value: u?.role || 'viewer', options: Object.fromEntries(roles.map(r => [r.id, r.label])) }),
    el('p', { class: 'help role-help' }),
  ];
  const roleSel = fields[3].querySelector('select'), roleHelp = fields[4];
  const syncRole = () => { roleHelp.textContent = d.roles.list.find(r => r.id === roleSel.value)?.desc || ''; };
  roleSel.addEventListener('change', syncRole); syncRole();
  if (u) {
    fields.push(field({ key: 'active', type: 'bool', label: 'Compte actif', value: u.active, help: 'Désactiver ferme toutes ses sessions.' }),
      field({ key: 'must_change_password', type: 'bool', label: 'Imposer un nouveau mot de passe à la prochaine connexion', value: u.must_change_password }));
  } else {
    const modes = [['generate', 'Générer un mot de passe temporaire', 'Affiché une seule fois ; à changer à la première connexion.'],
      ['invite', 'Envoyer une invitation par e-mail', d.mail ? 'Lien valable 72 h pour choisir le mot de passe.' : 'Indisponible : aucun serveur SMTP configuré.'],
      ['set', 'Choisir le mot de passe maintenant', `${ME.password_min} caractères minimum.`]];
    const pw = field({ key: 'password', type: 'password', label: 'Mot de passe', autocomplete: 'new-password' });
    pw.hidden = true;
    const radios = el('fieldset', { class: 'field radios' }, el('legend', {}, 'Mot de passe'),
      modes.map(([v, l, h], i) => el('label', {}, el('input', { type: 'radio', name: 'password_mode', value: v, checked: i === 0, disabled: v === 'invite' && !d.mail }), el('span', {}, l, el('small', { class: 'help' }, h)))),
      el('p', { class: 'field-error', role: 'alert', hidden: true }));
    radios.addEventListener('change', () => { pw.hidden = radios.querySelector('input:checked').value !== 'set'; });
    fields.push(radios, pw);
  }
  const form = apiForm(fields, u ? 'Enregistrer' : 'Créer le compte', async values => {
    if (u) {
      await api({ r: 'users.update', id: u.id }, { body: values });
      closeModal(); toast(`Compte ${values.username} enregistré.`, 'ok'); route(true);
      return;
    }
    const res = await api({ r: 'users.create' }, { body: values });
    if (res.password) {
      modal.onclose = () => route(true);
      openModal(`Compte ${res.user.username} créé`, el('p', {}, 'Transmets ce mot de passe temporaire par un canal sûr. Il ne sera plus jamais affiché ; il devra être changé à la première connexion.'), secretBox(res.password));
    } else { closeModal(); toast(res.invited ? `Invitation envoyée à ${res.user.email}.` : `Compte ${res.user.username} créé.`, 'ok'); route(true); }
  }, { extra: el('button', { class: 'btn', type: 'button', onclick: closeModal }, 'Annuler') });
  openModal(u ? `Modifier ${u.username}` : 'Nouvel utilisateur', form);
  form.querySelector('input:not([type=hidden])')?.focus();
}
async function userSessions(u) {
  const box = el('div', { class: 'stack' }, el('div', { class: 'skeleton' }));
  openModal(`Sessions de ${u.username}`, box);
  const draw = async () => {
    const list = await api({ r: 'users.sessions', id: u.id });
    render(box, sessionTable(list, async id => { await api({ r: 'users.revoke', id: u.id }, { body: { session: id } }); draw(); route(true); }),
      list.length ? el('div', { class: 'form-actions' }, el('button', { class: 'btn danger', onclick: async () => { const r = await api({ r: 'users.revoke', id: u.id }, { body: {} }); toast(`${r.revoked} session(s) fermée(s).`, 'ok'); draw(); route(true); } }, 'Tout déconnecter')) : null);
  };
  draw().catch(e => render(box, el('div', { class: 'error-box' }, e.message)));
}
function sessionTable(list, revoke) {
  return table(['Appareil', 'Adresse IP', 'Ouverte', 'Dernière activité', ''], list.map(s => [
    el('div', {}, el('strong', {}, s.agent), s.current ? el('div', {}, badge('cette session', 'ok')) : null),
    el('span', { class: 'mono' }, s.ip), fmtDate(s.created_at), ago(s.last_seen),
    s.current ? '' : el('button', { class: 'btn small danger', onclick: () => revoke(s.id) }, 'Fermer'),
  ]), 'Aucune session ouverte.');
}
async function resetUser(u, mail) {
  const viaMail = mail && u.email;
  if (!await confirmDialog(viaMail ? `Envoyer à ${u.email} un lien pour choisir un nouveau mot de passe ? Le mot de passe actuel reste valable jusque-là.`
    : `Remplacer le mot de passe de ${u.username} par un mot de passe temporaire ? Ses sessions seront fermées.`, { ok: viaMail ? 'Envoyer le lien' : 'Réinitialiser', danger: !viaMail })) return;
  try {
    const r = await api({ r: 'users.reset', id: u.id }, { body: { mode: viaMail ? 'email' : 'generate' } });
    if (r.password) { modal.onclose = () => route(true); openModal(`Nouveau mot de passe de ${u.username}`, el('p', {}, 'Mot de passe temporaire, affiché une seule fois. Il devra être changé à la prochaine connexion.'), secretBox(r.password)); }
    else toast(`Lien envoyé à ${u.email}.`, 'ok');
  } catch (e) { toast(e.message, 'crit'); }
}
B.users = async () => {
  const d = await api({ r: 'users' });
  const counts = d.users.reduce((a, u) => { a[u.role] = (a[u.role] || 0) + 1; return a; }, {});
  const rows = d.users.map(u => {
    const acts = [];
    if (u.manageable) {
      acts.push(el('button', { class: 'btn small', onclick: () => userDialog(d, u) }, 'Modifier'),
        el('details', { class: 'menu-more' }, el('summary', { class: 'btn small', 'aria-label': `Autres actions pour ${u.username}` }, '…'),
          el('div', { class: 'more-pop' },
            d.mail && u.email ? el('button', { class: 'linkish', onclick: () => resetUser(u, true) }, 'Envoyer un lien de réinitialisation') : null,
            el('button', { class: 'linkish', onclick: () => resetUser(u, false) }, 'Générer un mot de passe temporaire'),
            el('button', { class: 'linkish', onclick: () => userSessions(u) }, `Voir les sessions (${u.sessions})`),
            u.two_fa ? el('button', { class: 'linkish', onclick: () => resetTwoFa(u) }, 'Réinitialiser la double authentification') : null,
            el('button', { class: 'linkish danger', onclick: async () => {
              if (!await confirmDialog(`Supprimer définitivement le compte ${u.username} ? Ses actions restent dans le journal.`, { ok: 'Supprimer', danger: true })) return;
              try { await api({ r: 'users.delete', id: u.id }, { body: {} }); toast(`Compte ${u.username} supprimé.`, 'ok'); route(true); } catch (e) { toast(e.message, 'crit'); }
            } }, 'Supprimer le compte'))));
    } else if (u.self) acts.push(el('a', { class: 'btn small', href: '#account' }, 'Mon compte'));
    return [
      el('div', { class: 'user-cell' }, avatar(u), el('div', {}, el('strong', {}, u.display_name || u.username), u.self ? el('span', { class: 'muted small' }, ' (toi)') : null,
        el('div', { class: 'muted small' }, `@${u.username}${u.email ? ` · ${u.email}` : ''}`))),
      roleBadge(u.role, u.role_label), userState(u),
      u.two_fa ? badge('2FA', 'ok') : u.two_fa_required ? badge('à activer', 'warn') : el('span', { class: 'muted small' }, 'non'),
      el('span', { class: 'small', title: u.last_login_ip || '' }, u.last_login_at ? ago(u.last_login_at) : 'jamais'),
      u.sessions ? badge(`${u.sessions} en ligne`, 'ok') : el('span', { class: 'muted small' }, '–'),
      acts.length ? acts : el('span', { class: 'muted small' }, 'rôle supérieur ou égal'),
    ];
  });
  return {
    actions: can('users.manage') ? el('button', { class: 'btn primary small', onclick: () => userDialog(d) }, icon('plus'), 'Nouvel utilisateur') : null,
    body: [
      stats(...d.roles.list.map(r => stat(r.label, String(counts[r.id] || 0), r.id === 'superadmin' ? 'tous les droits' : plural(r.perms.length, 'permission')))),
      card(null, table(['Utilisateur', 'Rôle', 'État', 'Double auth.', 'Dernière connexion', 'Sessions', ''], rows)),
      note(ME.role === 'superadmin' ? 'Le dernier Super Admin actif ne peut être ni supprimé, ni désactivé, ni rétrogradé.'
        : 'Tu gères les comptes de rang inférieur au tien (Éditeur / Modérateur et Lecteur). Les changements de rôle ferment les sessions du compte.'),
    ],
  };
};

B.roles = async () => {
  const d = await api({ r: 'users' });
  const R = d.roles.list;
  return [
    el('div', { class: 'role-cards' }, R.map(r => el('div', { class: `role-card${r.id === ME.role ? ' mine' : ''}` }, el('div', {}, roleBadge(r.id, r.label), r.id === ME.role ? el('span', { class: 'muted small' }, ' ton rôle') : null), el('p', { class: 'small' }, r.desc)))),
    card('Permissions par rôle', table(['Permission', ...R.map(r => ({ label: r.label }))],
      d.roles.permissions.map(p => [p.label, ...R.map(r => r.perms.includes(p.id) ? el('span', { class: 'yes', 'aria-label': 'oui' }, icon('check')) : el('span', { class: 'no', 'aria-label': 'non' }, '–'))]))),
    note('Rôles prédéfinis : chaque rôle a tous les droits des rôles en dessous. Les droits sont vérifiés par le serveur à chaque requête.'),
  ];
};

/* ---------- Administration › Paramètres ---------- */
const settingsData = () => { if (!cache.has('settings')) cache.set('settings', api({ r: 'settings' })); return cache.get('settings'); };
const WEB_LABELS = { apache: 'Apache', nginx: 'Nginx', caddy: 'Caddy', proxy: 'Reverse proxy (Traefik…)', none: 'Aucun' };
function settingsBlock(gid) {
  return async () => {
    const d = await settingsData();
    const g = d.groups.find(x => x.id === gid);
    if (!g) return emptyState('Section indisponible pour ton rôle.');
    const extra = [];
    let top = null;
    if (gid === 'general') {
      top = stats(stat('Domaine', d.domain || 'aucun', d.https ? 'HTTPS' : 'HTTP seul (non chiffré)', undefined, d.https ? '' : 'warn-stat'),
        stat('Serveur web', WEB_LABELS[d.webserver] || d.webserver, 'détecté à l\'installation'), stat('Version', d.version, 'Sécurité › Version du panel'));
      if (d.can_domain) extra.push(el('button', { class: 'btn', type: 'button', onclick: () => changeDomain(d.domain) }, 'Changer de domaine…'));
    }
    if (gid === 'smtp' && g.editable) extra.push(el('button', { class: 'btn', type: 'button', onclick: testMail }, icon('mail'), 'Envoyer un e-mail de test'));
    if (gid === 'offsite' && g.editable) extra.push(actionBtn('Tester la connexion', 'offsite-test', []));
    if (gid === 'notifications' && g.editable) extra.push(el('button', { class: 'btn', type: 'button', onclick: testNotify }, icon('bell'), 'Tester Discord / Telegram / webhook'));
    if (gid === 'monitor') {
      const hb = (await section('heartbeat')).data || {};
      top = stats(
        stat('Dernier ping réussi', hb.enabled ? (hb.last_ok ? ago(hb.last_ok) : 'jamais') : 'désactivé', hb.enabled ? (hb.last_error ? `échec : ${hb.last_error}` : 'chaque minute') : 'renseigne une URL ci-dessous', undefined, hb.enabled && hb.last_error ? 'bad' : ''),
        stat('Collecteur', hb.enabled ? (hb.collector_ok === false ? 'en erreur' : 'OK') : '–', hb.collector_ok === false ? 'signalé comme en échec au service' : 'au dernier passage', undefined, hb.collector_ok === false ? 'bad' : ''));
      if (g.editable) extra.push(el('button', { class: 'btn', type: 'button', onclick: testPing }, icon('bolt'), 'Envoyer un ping de test'));
    }
    const form = apiForm(g.fields.map(f => field(f, { editable: g.editable })), 'Enregistrer', async (values, frm) => {
      const res = await api({ r: 'settings.save', group: gid }, { body: { values } });
      cache.set('settings', Promise.resolve({ ...d, groups: res.groups }));
      frm.querySelectorAll('input[type=password]').forEach(i => { i.value = ''; });
      toast(`${g.label} : enregistré.`, 'ok');
      if (gid === 'general') ME.panel_name = values['general.panel_name'];
      if (g.fields.some(f => f.type === 'secrettext' || f.type === 'password')) route(true);   // redraw the "saved" state of the secrets
    }, { extra, editable: g.editable });
    bindShowWhen(form);
    return [top, card(null, [g.editable ? null : el('div', { class: 'alert info' }, badge('lecture seule', 'info'), el('span', {}, 'Ton rôle permet de consulter ces paramètres, pas de les modifier.')), form]),
      gid === 'backups' ? note('Les sauvegardes elles-mêmes (liste, téléchargement, restauration) sont dans Données › Sauvegardes. La copie hors du serveur se règle dans « Sauvegarde externe ».') : null,
      gid === 'monitor' ? note('Pourquoi ? Les alertes ci-dessus partent du serveur lui-même : s\'il est éteint ou injoignable, il ne peut prévenir personne. Ici c\'est l\'inverse : un service extérieur (Healthchecks.io gratuit, ou ton Uptime Kuma) attend un signal chaque minute et t\'alerte quand il n\'arrive plus.') : null,
      gid === 'offsite' ? note('Enregistre, puis « Tester la connexion ». Les envois suivent chaque sauvegarde ; une copie ratée est retentée toutes les heures. Sur un nouveau serveur : « sudo hostkeep restore fetch » liste les jours disponibles et en rapatrie un.') : null,
      gid === 'security' ? note('Les échecs de connexion sont aussi transmis à fail2ban (jail « hostkeep »), qui bannit l\'adresse au niveau du pare-feu.') : null];
  };
}
/* Classic form POST so that the browser saves the answer as a file (the archive is streamed by the root helper). */
function exportBundle() {
  const acc = el('input', { type: 'checkbox', name: 'accounts', value: '1' });
  const p1 = el('input', { type: 'password', name: 'passphrase', autocomplete: 'new-password', minlength: 12, disabled: true });
  const p2 = el('input', { type: 'password', name: 'passphrase2', autocomplete: 'new-password', minlength: 12, disabled: true });
  const err = el('p', { class: 'field-error', hidden: true });
  const pass = el('div', { hidden: true }, el('div', { class: 'field' }, el('label', {}, 'Phrase de passe (12 caractères minimum)'), p1),
    el('div', { class: 'field' }, el('label', {}, 'Encore une fois'), p2),
    note('Demandée par restore.sh pour importer les comptes. Elle n\'est conservée nulle part : sans elle, les comptes sont perdus (les réglages, eux, restent importables).'));
  acc.onchange = () => { pass.hidden = !acc.checked; p1.disabled = p2.disabled = !acc.checked; };
  const form = el('form', { class: 'form', method: 'post', action: '/api.php?r=panel.export' },
    el('input', { type: 'hidden', name: 'csrf', value: document.querySelector('meta[name="csrf"]').content }),
    el('div', { class: 'field check' }, el('label', {}, acc, el('span', {}, 'Inclure les comptes (identifiants, e-mails, rôles, mots de passe hachés), chiffrés'))),
    pass, err,
    note('Jamais dans l\'archive : mots de passe SMTP, clés de sauvegarde externe, URL d\'alertes et de ping, secrets de double authentification. restore.sh liste ce qu\'il faudra ressaisir.'),
    el('div', { class: 'form-actions' }, el('button', { class: 'btn primary', type: 'submit' }, 'Télécharger')));
  form.onsubmit = e => {
    if (acc.checked && (p1.value.length < 12 || p1.value !== p2.value)) { e.preventDefault(); err.textContent = 'Au moins 12 caractères, identiques dans les deux champs.'; err.hidden = false; return; }
    setTimeout(() => { closeModal(); toast('Archive en préparation : le téléchargement démarre dans un instant.', 'ok'); }, 300);
  };
  openModal('Archive de migration', form);
}
async function testNotify() {
  try {
    const r = await api({ r: 'settings.testnotify' }, { body: {} });
    openModal('Test des alertes', el('ul', { class: 'plain' }, r.results.map(x => el('li', {}, badge(x.ok ? 'envoyé' : 'échec', x.ok ? 'ok' : 'crit'), ` ${x.channel}${x.error ? ` : ${x.error}` : ''}`))),
      note('Enregistre d\'abord les réglages : le test utilise ce qui est enregistré.'));
  } catch (e) { toast(e.message, 'crit'); }
}
async function testPing() {
  try { await api({ r: 'settings.testping' }, { body: {} }); toast('Ping envoyé : le service externe l\'a bien reçu.', 'ok'); route(true); }
  catch (e) { toast(`Ping en échec : ${e.message}`, 'crit'); }
}
async function testMail() {
  const to = el('input', { type: 'email', value: ME.email || '', placeholder: 'toi@example.com', 'aria-label': 'Destinataire' });
  const out = el('p', { class: 'muted small' }, 'Enregistre d\'abord les paramètres SMTP.');
  const send = el('button', { class: 'btn primary', type: 'submit' }, 'Envoyer');
  const form = el('form', { class: 'form' }, el('div', { class: 'field' }, el('label', {}, 'Destinataire'), to), out, el('div', { class: 'form-actions' }, send));
  form.onsubmit = async e => {
    e.preventDefault(); send.disabled = true; out.textContent = 'Envoi en cours…';
    try { const r = await api({ r: 'settings.testmail' }, { body: { to: to.value } }); out.replaceChildren(badge('envoyé', 'ok'), ` E-mail de test envoyé à ${r.to}.`); }
    catch (err) { out.replaceChildren(badge('échec', 'crit'), ` ${err.message}`); }
    finally { send.disabled = false; }
  };
  openModal('E-mail de test', form);
}
async function changeDomain(current) {
  const input = el('input', { value: current || '', placeholder: 'admin.example.com', spellcheck: 'false', autocapitalize: 'none', 'aria-label': 'Nouveau domaine' });
  const err = el('p', { class: 'field-error', hidden: true });
  const form = el('form', { class: 'form' },
    el('p', {}, 'Le serveur web est reconfiguré pour le nouveau domaine et un certificat Let\'s Encrypt est demandé. Avant de valider, l\'enregistrement DNS A/AAAA du nouveau domaine doit pointer vers ce serveur.'),
    el('div', { class: 'field' }, el('label', {}, 'Nouveau domaine'), input, err),
    el('div', { class: 'form-actions' }, el('button', { class: 'btn', type: 'button', onclick: closeModal }, 'Annuler'), el('button', { class: 'btn primary', type: 'submit' }, 'Appliquer')));
  form.onsubmit = async e => {
    e.preventDefault();
    const v = input.value.trim().toLowerCase();
    if (!/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/.test(v)) { err.textContent = 'Nom de domaine invalide.'; err.hidden = false; return; }
    if (v === current) { closeModal(); return; }
    closeModal();
    if (await confirmDialog(`Passer le panel sur ${v} ? L'adresse actuelle (${current}) cessera de répondre une fois l'opération réussie.`, { ok: 'Changer de domaine' })) runAction('Changer de domaine', 'web-domain', [v]);
  };
  openModal('Changer de domaine', form);
  input.focus();
}

/* ---------- Mon compte ---------- */
B.profile = async () => {
  const me = await api({ r: 'me' });
  const form = apiForm([
    field({ key: 'display_name', label: 'Nom affiché', value: me.display_name, max: 80 }),
    field({ key: 'email', type: 'email', label: 'E-mail', value: me.email || '', help: 'Pour recevoir les liens « mot de passe oublié » et, si tu es administrateur, les alertes.' }),
  ], 'Enregistrer', async values => {
    const res = await api({ r: 'account.profile' }, { body: values });
    Object.assign(ME, { display_name: res.user.display_name || ME.username, email: res.user.email });
    document.querySelectorAll('.account-text .who, .menu-head strong').forEach(e => { e.textContent = ME.display_name; });
    toast('Profil enregistré.', 'ok');
  });
  return el('div', { class: 'grid2' },
    card(null, [el('div', { class: 'user-cell big' }, avatar(me), el('div', {}, el('strong', {}, me.display_name), el('div', { class: 'muted' }, `@${me.username}`), roleBadge(me.role, me.role_label))),
      el('dl', { class: 'facts' }, el('dt', {}, 'Dernière connexion'), el('dd', {}, me.last_login_at ? fmtDate(me.last_login_at) : '–'),
        el('dt', {}, 'Permissions'), el('dd', {}, `${me.perms.length} — voir Administration › Rôles`))]),
    card(null, form), twoFaCard(me.two_fa || {}));
};
/* Mon compte: state of the 2FA; set up and management happen on /2fa.php (QR code drawn by the server). */
function twoFaCard(t) {
  const st = t.enabled
    ? [badge('activée', 'ok'), ` depuis le ${fmtDate(t.since).split(' ')[0]} · ${plural(t.codes_left, 'code')} de secours restant${t.codes_left > 1 ? 's' : ''}`]
    : [badge('désactivée', t.required ? 'crit' : 'warn'), t.required ? ' · exigée pour ton rôle' : ' · recommandée'];
  return card('Double authentification', [
    el('p', {}, st),
    t.enabled && t.codes_left <= 3 ? el('div', { class: 'alert warn' }, badge('attention', 'warn'), el('span', {}, 'Il te reste peu de codes de secours : génères-en de nouveaux.')) : null,
    note('Un code à 6 chiffres donné par une application (Aegis, Google Authenticator, Bitwarden…) en plus du mot de passe à chaque connexion.'),
    el('div', { class: 'form-actions' }, el('a', { class: `btn ${t.enabled ? '' : 'primary'}`, href: '/2fa.php' }, t.enabled ? 'Gérer (codes de secours, désactivation)' : 'Activer la double authentification')),
  ]);
}
async function resetTwoFa(u) {
  if (!await confirmDialog(`Réinitialiser la double authentification de ${u.username} ? Ses sessions sont fermées${u.two_fa_required ? ', et le compte devra la réactiver (QR code) à sa prochaine connexion' : ''}. À faire quand le téléphone et les codes de secours sont perdus.`, { ok: 'Réinitialiser', danger: true })) return;
  try { await api({ r: 'users.2fa_reset', id: u.id }, { body: {} }); toast(`Double authentification de ${u.username} réinitialisée.`, 'ok'); route(true); } catch (e) { toast(e.message, 'crit'); }
}
B.password = async () => card(null, apiForm([
  field({ key: 'current', type: 'password', label: 'Mot de passe actuel', autocomplete: 'current-password' }),
  field({ key: 'password', type: 'password', label: 'Nouveau mot de passe', autocomplete: 'new-password', help: `${ME.password_min} caractères minimum. Une phrase de plusieurs mots est plus simple à retenir.` }),
  field({ key: 'confirm', type: 'password', label: 'Confirmation', autocomplete: 'new-password' }),
], 'Changer le mot de passe', async (values, form) => {
  if (values.password !== values.confirm) { const e = new Error('Les deux saisies diffèrent.'); e.fields = { confirm: 'Les deux saisies diffèrent.' }; throw e; }
  await api({ r: 'account.password' }, { body: values });
  form.reset();
  toast('Mot de passe changé. Tes autres sessions ont été fermées.', 'ok');
}));
B.mysessions = async () => {
  const list = await api({ r: 'account.sessions' });
  const others = list.filter(s => !s.current).length;
  return {
    actions: others ? el('button', { class: 'btn small danger', onclick: async () => { const r = await api({ r: 'account.revoke' }, { body: {} }); toast(`${r.revoked} session(s) fermée(s).`, 'ok'); route(true); } }, 'Fermer les autres sessions') : null,
    body: [card(null, sessionTable(list, async id => { await api({ r: 'account.revoke' }, { body: { id } }); toast('Session fermée.', 'ok'); route(true); })),
      note('Une session se ferme seule après la durée d\'inactivité fixée dans les paramètres de sécurité.')],
  };
};

/* Account › "Connexion" tab: password and open sessions on one screen. */
B.login = async () => {
  const s = await B.mysessions();
  return [
    el('h3', { class: 'sub-head' }, 'Changer le mot de passe'), await B.password(),
    el('div', { class: 'sub-row' }, el('h3', { class: 'sub-head' }, 'Sessions ouvertes'), s.actions), ...s.body,
  ];
};

/* Administration › Paramètres: one settings group at a time, with its own side menu. */
const SETTINGS_ICONS = { general: 'sliders', smtp: 'mail', notifications: 'bell', monitor: 'bolt', backups: 'data', offsite: 'cloud', security: 'security' };
B.settings = async sub => {
  const d = await settingsData();
  const ids = d.groups.map(g => g.id);
  let cur = ids.includes(sub) ? sub : (sessionStorage.getItem('settingsTab') || 'general');
  if (!ids.includes(cur)) cur = ids[0];
  sessionStorage.setItem('settingsTab', cur);
  const nav = el('nav', { class: 'subnav', 'aria-label': 'Groupes de paramètres' }, d.groups.map(g => el('a', {
    href: `#admin/settings/${g.id}`, 'aria-current': g.id === cur ? 'page' : null,
  }, icon(SETTINGS_ICONS[g.id] || 'sliders'), el('span', {}, g.label), g.editable ? null : el('small', {}, 'lecture'))));
  const body = await settingsBlock(cur)();
  return el('div', { class: 'settings-layout' }, nav, el('div', { class: 'settings-main' }, el('h2', { class: 'settings-title' }, d.groups.find(g => g.id === cur).label), body));
};

/* ---------- pages ---------- */
/* Each page shows one tab at a time (except the home page, a single dashboard). Third item = permission. */
const PAGES = {
  home: { title: 'Accueil', desc: 'L\'état du serveur en un coup d\'œil.', stacked: true, blocks: [['summary', 'En direct'], ['history', 'Historique']] },
  sites: { title: 'Sites', desc: 'Disponibilité, certificats HTTPS et fréquentation.', blocks: [['sites', 'Sites'], ['certs', 'Certificats'], ['traffic', 'Trafic']] },
  system: { title: 'Système', desc: 'Services, applications, ressources et disque.', blocks: [['services', 'Services'], ['pm2', 'Applis & tâches'], ['processes', 'Processus'], ['perf', 'Performances'], ['storage', 'Stockage']] },
  security: { title: 'Sécurité', desc: 'Audit, mises à jour, accès et historique.', blocks: [['audit', 'Audit'], ['updates', 'Mises à jour'], ['access', 'Accès'], ['journal', 'Journal', 'audit.view']] },
  data: { title: 'Données', desc: 'Bases, sauvegardes, journaux et fichiers.', blocks: [['databases', 'Bases'], ['backups', 'Sauvegardes'], ['logs', 'Journaux', 'logs.view'], ['files', 'Fichiers', 'files.view']] },
  admin: { title: 'Administration', desc: 'Comptes, rôles, paramètres et version du panel.', blocks: [['users', 'Utilisateurs', 'users.view'], ['roles', 'Rôles', 'users.view'], ['settings', 'Paramètres', 'settings.view'], ['panel', 'Version', 'settings.view']] },
  account: { title: 'Mon compte', desc: 'Ton profil, ton mot de passe et tes sessions.', blocks: [['profile', 'Profil'], ['login', 'Connexion & sessions']] },
};
/* Blocks the signed-in user may see. A page without any visible block is not reachable. */
const blocksOf = page => PAGES[page].blocks.filter(b => !b[2] || can(b[2]));
const pageAllowed = page => !!PAGES[page] && blocksOf(page).length > 0;
/* Where each block lives (old links such as #security/panel or #admin/s_smtp keep working). */
const BLOCK_PAGE = Object.fromEntries(Object.entries(PAGES).flatMap(([p, P]) => P.blocks.map(([id]) => [id, p])));

const tabsEl = document.getElementById('tabs');
let curPage = null, curTab = null, renderId = 0, alertCounts = {};

function parseHash() {
  let h = decodeURIComponent(location.hash.slice(1));
  if (LEGACY[h] && !PAGES[h]) h = LEGACY[h];
  let [page, tab, sub] = h.split('/');
  if (tab?.startsWith('s_')) { sub = tab.slice(2); tab = 'settings'; page = 'admin'; }
  if (tab && PAGES[page] && !PAGES[page].blocks.some(b => b[0] === tab) && BLOCK_PAGE[tab]) page = BLOCK_PAGE[tab];
  if (!pageAllowed(page)) return ['home', null, null];
  const ids = blocksOf(page).map(b => b[0]);
  if (PAGES[page].stacked) return [page, ids.includes(tab) ? tab : null, null];
  if (!ids.includes(tab)) { const last = sessionStorage.getItem(`tab:${page}`); tab = ids.includes(last) ? last : ids[0]; }
  return [page, tab, sub || null];
}

/* One block: title row (hidden when the tab already names it), actions, freshness, then the content. */
function blockSection(id, label, { titled }) {
  const head = el('div', { class: 'block-head' }, el('h2', { id: `h-${id}`, class: titled ? null : 'sr-only' }, label));
  const body = el('div', { class: 'block-body' }, el('div', { class: 'skeleton' }));
  return { sec: el('section', { class: 'block', id: `b-${id}`, 'aria-labelledby': `h-${id}` }, head, body), head, body };
}
async function fillBlock(b, id, sub, myId) {
  try {
    const out = await B[id](sub);
    if (myId !== renderId) return;
    const { body: nodes, actions } = out && out.body !== undefined ? out : { body: out };
    if (actions) b.head.append(el('div', { class: 'actions' }, actions));
    render(b.body, nodes);
    const st = b.body.querySelector(':scope > .stale, :scope > * > .stale');
    if (st) b.head.querySelector('h2').after(st);
  } catch (e) {
    if (myId === renderId) render(b.body, el('div', { class: 'error-box' }, `Erreur : ${e.message}`));
  }
}

function drawTabs(page, tab) {
  const P = PAGES[page];
  if (P.stacked) { tabsEl.hidden = true; tabsEl.replaceChildren(); return; }
  tabsEl.hidden = false;
  tabsEl.replaceChildren(...blocksOf(page).map(([id, label]) => {
    const k = alertCounts[`${page}/${id}`];
    return el('a', { href: `#${page}/${id}`, 'data-tab': id, 'aria-current': id === tab ? 'page' : null },
      label, k ? el('span', { class: `tab-count ${k.crit ? 'crit' : 'warn'}`, 'aria-label': `${k.n} alerte${k.n > 1 ? 's' : ''}` }, k.n) : null);
  }));
  tabsEl.querySelector('[aria-current]')?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
}

async function renderPage(page, tab, sub) {
  const myId = ++renderId;
  clearInterval(liveTimer); liveTimer = null;
  cache = new Map();
  const pageChanged = page !== curPage;
  curPage = page; curTab = tab;
  setMenu(false);
  if (!modal.hidden) { modal.onclose = null; modal.hidden = true; }   // never leave a sheet (e.g. a one-time password) over another page
  const P = PAGES[page];
  document.querySelectorAll('[data-nav]').forEach(a => a.toggleAttribute('aria-current', a.dataset.nav === page));
  document.getElementById('page-title').textContent = P.title;
  document.getElementById('page-desc').textContent = P.desc;
  const tabLabel = !P.stacked && blocksOf(page).find(b => b[0] === tab)?.[1];
  document.title = `${tabLabel ? `${tabLabel} · ` : ''}${P.title} · ${ME.panel_name}`;
  drawTabs(page, tab);
  if (!P.stacked) sessionStorage.setItem(`tab:${page}`, tab);

  const list = P.stacked ? blocksOf(page) : blocksOf(page).filter(b => b[0] === tab);
  const parts = list.map(([id, label]) => ({ id, b: blockSection(id, label, { titled: P.stacked }) }));
  render(content, parts.map(p => p.b.sec));
  if (pageChanged) window.scrollTo(0, 0);
  else if (tabsEl.getBoundingClientRect().top < 0 || window.scrollY > tabsEl.offsetTop) window.scrollTo(0, Math.max(0, tabsEl.offsetTop - 70));
  await Promise.allSettled(parts.map(p => fillBlock(p.b, p.id, sub, myId)));
  if (myId === renderId) {
    const upd = document.getElementById('updated');
    upd.textContent = `À jour · ${new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}`;
    upd.hidden = false;
  }
}

let curSub = null;
async function route(force = false) {
  const [page, tab, sub] = parseHash();
  if (!force && page === curPage && tab === curTab && sub === curSub) {
    if (PAGES[page].stacked && tab) document.getElementById(`b-${tab}`)?.scrollIntoView({ block: 'start' });
    return;
  }
  curSub = sub;
  await renderPage(page, tab, sub);
  if (PAGES[page].stacked && tab) document.getElementById(`b-${tab}`)?.scrollIntoView({ block: 'start' });
}

/* Server state (sidebar card, phone pill) and alert counters on the pages and tabs, refreshed every minute. */
async function refreshHealth() {
  if (document.hidden) return;
  const pill = document.getElementById('health');
  try {
    const ov = await api({ r: 'overview' }, { poll: true });
    const crit = ov.alerts.filter(a => a.level === 'crit').length, warn = ov.alerts.filter(a => a.level === 'warn').length;
    const state = crit ? 'crit' : warn ? 'warn' : 'ok';
    const long = crit ? `${plural(crit, 'alerte')} critique${crit > 1 ? 's' : ''}` : warn ? `${plural(warn, 'point')} à surveiller` : 'Tout fonctionne';
    pill.className = `health ${state}`;
    pill.replaceChildren(el('span', {}, crit || warn || 'OK'));
    pill.setAttribute('aria-label', `État du serveur : ${long}`);
    pill.hidden = false;
    const card = document.querySelector('.server-card');
    card.dataset.state = state;
    card.querySelector('.server-state').textContent = long;
    card.title = `${ov.host} · ${ov.os}`;
    const pages = {};
    alertCounts = {};
    for (const a of ov.alerts) {
      if (a.level === 'info') continue;
      let target = LEGACY[a.section] || 'home';
      const [p, b] = target.split('/');
      const page = b && BLOCK_PAGE[b] ? BLOCK_PAGE[b] : p;
      for (const key of [page, b ? `${page}/${b}` : null]) {
        if (!key) continue;
        (pages[key] ||= { n: 0, crit: false }).n++;
        if (a.level === 'crit') pages[key].crit = true;
      }
    }
    for (const [k, v] of Object.entries(pages)) if (k.includes('/')) alertCounts[k] = v;
    document.querySelectorAll('[data-count]').forEach(c => {
      const k = pages[c.dataset.count];
      c.hidden = !k; c.textContent = k ? k.n : '';
      c.className = `count ${k?.crit ? 'crit' : 'warn'}`;
      c.setAttribute('aria-label', k ? `${k.n} alerte${k.n > 1 ? 's' : ''}` : '');
    });
    if (curPage) drawTabs(curPage, curTab);
  } catch (e) { /* ignore */ }
}

window.addEventListener('hashchange', () => route());
const refresh = () => { route(true); refreshHealth(); };
document.getElementById('refresh-btn').onclick = refresh;
document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshHealth(); });   // back on the tab: fresh status
/* Go to a page or a tab, even when the hash is already the current one. */
const goTo = h => { if (location.hash === `#${h}`) route(); else location.hash = h; };
document.getElementById('skip').onclick = e => { e.preventDefault(); document.getElementById('main').focus(); };

/* Account menu: opened from the sidebar account button (desktop) or the avatar (phones). */
const menu = document.getElementById('account-menu');
const triggers = [...document.querySelectorAll('.account-trigger')];
let menuFrom = null;
const setMenu = (open, from = null) => {
  menu.hidden = !open;
  triggers.forEach(t => t.setAttribute('aria-expanded', String(open && t === from)));
  if (open) { menuFrom = from; menu.classList.toggle('from-side', !!from?.closest('.sidebar')); menu.querySelector('.menu-item')?.focus(); }
  else menuFrom = null;
};
triggers.forEach(t => { t.onclick = e => { e.stopPropagation(); setMenu(menu.hidden || menuFrom !== t, t); }; });
document.addEventListener('click', e => { if (!menu.hidden && !menu.contains(e.target)) setMenu(false); });
menu.addEventListener('keydown', e => {
  const items = [...menu.querySelectorAll('.menu-item')].filter(i => i.offsetParent !== null);
  const i = items.indexOf(document.activeElement);
  if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); items[(i + (e.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length]?.focus(); }
  if (e.key === 'Escape') { const f = menuFrom; setMenu(false); f?.focus(); }
  if (e.key === 'Tab') setMenu(false);
});

/* ---------- command palette (Ctrl/⌘ K or /): every page, block and routine action, by name ---------- */
const KEYWORDS = {
  summary: 'cpu ram memoire alertes etat', history: 'graphiques courbes', processes: 'top ps', certs: 'ssl https tls letsencrypt certbot',
  traffic: 'visiteurs statistiques requetes 404 robots', services: 'systemd restart redemarrer apache nginx', pm2: 'cron timers taches node',
  perf: 'php-fpm mariadb lent requetes', storage: 'disque espace partitions nettoyer', audit: 'scan failles env git', updates: 'apt paquets upgrade',
  panel: 'hostkeep version git', access: 'fail2ban ssh ports ip bannies', journal: 'historique actions', databases: 'mysql mariadb sql',
  backups: 'dump restaurer telecharger', logs: 'log erreurs', files: 'explorateur dossiers',
  users: 'comptes utilisateurs creer inviter', roles: 'permissions droits rbac', s_general: 'parametres nom domaine', s_smtp: 'mail email smtp',
  s_notifications: 'alertes email notifications', s_backups: 'sauvegarde heure conservation', s_security: 'session mot de passe ip blocage',
  profile: 'profil nom email', password: 'mot de passe changer', mysessions: 'sessions deconnecter appareils',
};
function paletteItems() {
  const items = [];
  for (const [p, P] of Object.entries(PAGES)) {
    if (!pageAllowed(p)) continue;
    items.push({ label: P.title, hint: 'Page', ico: p, kw: P.desc, run: () => goTo(p) });
    if (!PAGES[p].stacked) for (const [id, label] of blocksOf(p)) items.push({ label: `${P.title} › ${label}`, hint: 'Section', ico: p, kw: KEYWORDS[id] || '', run: () => goTo(`${p}/${id}`) });
  }
  if (can('settings.view')) for (const [g, label] of [['general', 'Général'], ['smtp', 'E-mails (SMTP)'], ['notifications', 'Alertes par e-mail'], ['backups', 'Sauvegardes'], ['security', 'Sécurité']])
    items.push({ label: `Paramètres › ${label}`, hint: 'Réglage', ico: SETTINGS_ICONS[g], kw: KEYWORDS[`s_${g}`] || '', run: () => goTo(`admin/settings/${g}`) });
  items.push({ label: 'Mon compte', hint: 'Page', ico: 'account', kw: 'profil mot de passe sessions', run: () => goTo('account') });
  for (const [label, verb] of QUICK.filter(([, v]) => canVerb(v))) items.push({ label, hint: 'Action', ico: 'bolt', kw: verb, run: () => runAction(label, verb, []) });
  items.push({ label: 'Rafraîchir les données', hint: 'Action', ico: 'refresh', kw: 'recharger actualiser', run: refresh },
    { label: 'Changer de thème (clair / sombre)', hint: 'Affichage', ico: 'theme', kw: 'dark mode nuit', run: () => document.getElementById('theme-btn').click() },
    { label: 'Se déconnecter', hint: 'Session', ico: 'logout', kw: 'logout quitter', run: () => document.getElementById('logout-form').requestSubmit() });
  return items;
}
let palette = null;
function openPalette() {
  if (palette) return;
  setMenu(false);
  const items = paletteItems();
  let shown = items, active = 0;
  const input = el('input', { type: 'search', placeholder: 'Page, section ou action…', role: 'combobox', 'aria-expanded': 'true', 'aria-controls': 'pal-list', 'aria-autocomplete': 'list', 'aria-label': 'Rechercher', autocomplete: 'off', spellcheck: 'false' });
  const list = el('ul', { id: 'pal-list', role: 'listbox', 'aria-label': 'Résultats' });
  const dlg = palette = el('dialog', { class: 'palette', 'aria-label': 'Recherche et commandes' },
    el('div', { class: 'palette-head' }, icon('search'), input, el('button', { class: 'btn small', type: 'button', onclick: () => dlg.close() }, 'Fermer')),
    list, el('p', { class: 'palette-foot' }, '↑ ↓ pour choisir · Entrée pour ouvrir · Échap pour fermer'));
  const pick = i => { const it = shown[i]; if (!it) return; dlg.close(); it.run(); };
  const mark = () => {
    [...list.children].forEach((li, i) => li.setAttribute('aria-selected', String(i === active)));
    const cur = list.children[active];
    if (cur?.id) { input.setAttribute('aria-activedescendant', cur.id); cur.scrollIntoView({ block: 'nearest' }); }
  };
  const draw = () => {
    const words = norm(input.value).split(/\s+/).filter(Boolean);
    shown = items.filter(it => { const hay = norm(`${it.label} ${it.hint} ${it.kw}`); return words.every(w => hay.includes(w)); });
    active = 0;
    render(list, shown.length
      ? shown.map((it, i) => el('li', { role: 'option', id: `pal-${i}`, onclick: () => pick(i), onpointermove: () => { if (active !== i) { active = i; mark(); } } }, icon(it.ico), el('span', { class: 'pal-label' }, it.label), el('span', { class: 'pal-hint' }, it.hint)))
      : el('li', { class: 'pal-none' }, 'Aucun résultat. Essaie « sauvegarde », « ssl » ou « disque ».'));
    mark();
  };
  input.addEventListener('input', draw);
  input.addEventListener('keydown', e => {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); if (shown.length) { active = (active + (e.key === 'ArrowDown' ? 1 : shown.length - 1)) % shown.length; mark(); } }
    else if (e.key === 'Enter') { e.preventDefault(); pick(active); }
  });
  dlg.addEventListener('click', e => { if (e.target === dlg) dlg.close(); });
  dlg.addEventListener('close', () => { dlg.remove(); palette = null; });
  document.body.append(dlg);
  draw();
  dlg.showModal();
  input.focus();
}
document.querySelectorAll('.search-trigger').forEach(b => { b.onclick = openPalette; });
if (/Mac|iPhone|iPad/.test(navigator.platform)) document.querySelectorAll('.search-trigger kbd').forEach(k => { k.textContent = '⌘ K'; });

/* Keyboard shortcuts: Ctrl/⌘ K or / (search), 1 to 5 (pages), R (refresh), ? (this list). */
const SHORTCUTS = [['Ctrl K  ou  /', 'Rechercher une page ou une action'], ['1 … 5', 'Accueil, Sites, Système, Sécurité, Données'], ['R', 'Rafraîchir les données'], ['Échap', 'Fermer la fenêtre ouverte'], ['?', 'Afficher cette aide']];
const showShortcuts = () => { setMenu(false); openModal('Raccourcis clavier', el('dl', { class: 'keys' }, SHORTCUTS.map(([k, d]) => [el('dt', {}, el('kbd', {}, k)), el('dd', {}, d)]))); };
document.getElementById('keys-btn').onclick = showShortcuts;
document.addEventListener('keydown', e => {
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); openPalette(); return; }
  if (e.ctrlKey || e.metaKey || e.altKey || e.target.closest?.('input, select, textarea, dialog') || !modal.hidden) return;
  const pages = ['home', 'sites', 'system', 'security', 'data'];
  if (e.key === '/') { e.preventDefault(); openPalette(); }
  else if (pages[Number(e.key) - 1]) goTo(pages[Number(e.key) - 1]);
  else if (e.key.toLowerCase() === 'r') refresh();
  else if (e.key === '?') showShortcuts();
});

/* theme: system by default, toggle stored per browser */
const root = document.documentElement;
const isDark = () => root.dataset.theme ? root.dataset.theme === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches;
function syncThemeBtn() {
  const dark = isDark();
  document.getElementById('theme-lbl').textContent = dark ? 'Thème clair' : 'Thème sombre';
  document.querySelector('#theme-btn .ico').replaceChildren(icon(dark ? 'sun' : 'theme'));
}
try { const t = localStorage.getItem('theme'); if (t) root.dataset.theme = t; } catch (e) { /* storage blocked */ }
document.getElementById('theme-btn').onclick = () => {
  root.dataset.theme = isDark() ? 'light' : 'dark';
  try { localStorage.setItem('theme', root.dataset.theme); } catch (e) { /* ignore */ }
  syncThemeBtn();
};
matchMedia('(prefers-color-scheme: dark)').addEventListener('change', syncThemeBtn);
syncThemeBtn();

/* Installable app (PWA): service worker + "Installer l'appli" button when the browser offers it. */
if ('serviceWorker' in navigator) {
  /* updateViaCache 'none': sw.js itself is never taken from the HTTP cache, so a new version is seen at once. */
  navigator.serviceWorker.register('/sw.js', { updateViaCache: 'none' }).then(reg => {
    document.addEventListener('visibilitychange', () => { if (!document.hidden) reg.update().catch(() => {}); });   // back to the tab / app
  }).catch(() => { /* not fatal */ });
  /* A new worker took over (Hostkeep was updated): the next screen already uses the new files. */
  const hadController = !!navigator.serviceWorker.controller;
  navigator.serviceWorker.addEventListener('controllerchange', () => {
    if (!hadController) return;   // first install, not an update
    const t = toast('Hostkeep a été mis à jour.', 'ok');
    const b = el('button', { class: 'linkish', type: 'button', onclick: () => location.reload() }, 'Recharger');
    t.append(' ', b);
  });
}
let installEvt = null;
const installBtn = document.getElementById('install-btn');
window.addEventListener('beforeinstallprompt', e => { e.preventDefault(); installEvt = e; installBtn.hidden = false; });
window.addEventListener('appinstalled', () => { installEvt = null; installBtn.hidden = true; });
installBtn.onclick = async () => { if (!installEvt) return; installEvt.prompt(); await installEvt.userChoice; installEvt = null; installBtn.hidden = true; };

route();
refreshHealth();
setInterval(refreshHealth, 60000);
