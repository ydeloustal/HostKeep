'use strict';
/* Hostkeep — vanilla JS front-end. All dynamic text goes through textContent (no innerHTML with data). */

const CSRF = document.querySelector('meta[name="csrf"]').content;
const content = document.getElementById('content');

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
  logout: [['path', { d: 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9' }]],
  x: [['path', { d: 'M18 6 6 18M6 6l12 12' }]],
  folder: [['path', { d: 'M3 6a2 2 0 0 1 2-2h4l2 2.5h8a2 2 0 0 1 2 2V18a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z' }]],
  file: [['path', { d: 'M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z' }], ['path', { d: 'M14 3v5h5' }]],
  link: [['path', { d: 'M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1' }]],
  up: [['path', { d: 'm15 18-6-6 6-6' }]],
};
const icon = name => el('svg', { viewBox: '0 0 24 24', class: 'i', 'aria-hidden': 'true' }, (ICONS[name] || []).map(([t, a]) => el(t, a)));
document.querySelectorAll('[data-icon]').forEach(s => s.replaceChildren(icon(s.dataset.icon)));

async function api(params, opts = {}) {
  const q = new URLSearchParams(params).toString();
  const res = await fetch(`/api.php?${q}`, {
    method: opts.body ? 'POST' : 'GET',
    headers: { 'X-CSRF-Token': CSRF, 'X-Poll': opts.poll ? '1' : '0', ...(opts.body ? { 'Content-Type': 'application/json' } : {}) },
    body: opts.body ? JSON.stringify(opts.body) : undefined,
    credentials: 'same-origin',
  });
  if (res.status === 401) { location.href = '/login.php'; throw new Error('session expirée'); }
  const data = await res.json().catch(() => ({ error: `HTTP ${res.status}` }));
  if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`);
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
function table(headers, rows, empty = 'Rien à afficher.') {
  if (!rows.length) return el('p', { class: 'empty' }, empty);
  return el('div', { class: 'table-wrap' }, el('table', {},
    el('thead', {}, el('tr', {}, headers.map(h => el('th', { class: h.num ? 'num' : null }, h.label ?? h)))),
    el('tbody', {}, rows.map(r => el('tr', {}, r.map((c, i) => cell(headers[i], c)))))));
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
const stale = upd => el('p', { class: 'muted small' }, `Données collectées ${ago(upd)}.`);
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
document.addEventListener('keydown', e => { if (e.key === 'Escape' && !modal.hidden) closeModal(); });

function actionBtn(label, verb, args, { confirm: msg, cls = '' } = {}) {
  return el('button', {
    class: `btn small ${cls}`, onclick: () => {
      if (msg && !window.confirm(msg)) return;
      runAction(label, verb, args);
    }
  }, label);
}
async function runAction(label, verb, args) {
  const status = el('p', {}, badge('en cours…', 'info'));
  const out = el('pre', { class: 'log' }, 'Lancement…');
  openModal(`${label} — ${[verb, ...args].join(' ')}`, status, out);
  modal.onclose = () => route(true);
  let id;
  try { id = (await api({ r: 'action' }, { body: { verb, args } })).job; }
  catch (e) { status.replaceChildren(badge('refusé', 'crit')); out.textContent = e.message; return; }
  const poll = async () => {
    if (modal.hidden) return;
    try {
      const j = await api({ r: 'job', id }, { poll: true });
      out.textContent = j.output || '(pas encore de sortie)';
      out.scrollTop = out.scrollHeight;
      if (j.done) { status.replaceChildren(j.rc === 0 ? badge('terminé avec succès', 'ok') : badge(`échec (code ${j.rc})`, 'crit')); return; }
    } catch (e) { out.textContent = e.message; }
    setTimeout(poll, 1500);
  };
  poll();
}

/* ---------- SVG line chart ---------- */
function lineChart(points, series, { yFmt = v => v, yMax = null, height = 170 } = {}) {
  const W = 800, H = height, L = 56, R = 10, T = 10, B = 22;
  const wrap = el('div', {});
  if (!points.length) { wrap.append(el('p', { class: 'empty' }, 'Pas encore de données (le collecteur passe chaque minute).')); return wrap; }
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
  svg.addEventListener('mousemove', e => {
    const r = svg.getBoundingClientRect(), t = x0 + ((e.clientX - r.left) / r.width * W - L) / (W - L - R) * (x1 - x0);
    let best = points[0];
    for (const p of points) if (Math.abs(p.t - t) < Math.abs(best.t - t)) best = p;
    hover.setAttribute('x1', X(best.t)); hover.setAttribute('x2', X(best.t)); hover.setAttribute('visibility', 'visible');
    tip.textContent = `${fmtDate(Number(best.t))} — ` + series.map(s => `${s.name} : ${yFmt(Number(s.get(best)) || 0)}`).join(' · ');
  });
  svg.addEventListener('mouseleave', () => { hover.setAttribute('visibility', 'hidden'); tip.textContent = ' '; });
  wrap.append(svg, series.length > 1 ? el('div', { class: 'legend' }, series.map(s => el('span', {}, el('i', { style: { background: `var(${s.color})` } }), s.name))) : null, tip);
  return wrap;
}

/* ---------- blocks (each returns nodes for one section of a page) ---------- */
let liveTimer = null;
const B = {};
const SEV = { crit: ['critique', 'crit'], warn: ['à corriger', 'warn'], info: ['conseil', 'info'] };

/* Old section ids (used by alerts and old bookmarks) -> page/block. */
const LEGACY = {
  overview: 'home', resources: 'home/history', processes: 'home/processes', services: 'system/services', pm2: 'system/pm2',
  sites: 'sites/sites', traffic: 'sites/traffic', certs: 'sites/certs', databases: 'data/databases', backups: 'data/backups',
  perf: 'system/perf', storage: 'system/storage', files: 'data/files', logs: 'data/logs', security: 'security/audit',
  updates: 'security/updates', cron: 'system/pm2', audit: 'security/journal', panel: 'security/panel',
};

const liveHist = [];   // last live samples, kept across page changes for the trend lines
B.summary = async () => {
  const [ov, live] = await Promise.all([api({ r: 'overview' }), api({ r: 'live' }, { poll: true })]);
  const m = ov.metric || {};
  const box = el('div', { class: 'stats kpis' });
  const draw = l => {
    const memUsed = l.mem_total - l.mem_avail;
    liveHist.push({ cpu: l.cpu, mem: pct(memUsed, l.mem_total), net: l.net_rx + l.net_tx });
    if (liveHist.length > 40) liveHist.shift();
    const h = k => liveHist.map(x => x[k]);
    box.replaceChildren(
      stat('CPU', `${l.cpu.toFixed(0)} %`, `charge ${l.load1.toFixed(2)} · ${l.cores} cœur${l.cores > 1 ? 's' : ''}`, l.cpu, '', h('cpu')),
      stat('RAM', `${pct(memUsed, l.mem_total).toFixed(0)} %`, `${fmtBytes(memUsed)} / ${fmtBytes(l.mem_total)}`, pct(memUsed, l.mem_total), '', h('mem')),
      stat('Disque', m.disk_total ? `${pct(m.disk_used, m.disk_total).toFixed(0)} %` : '–', m.disk_total ? `${fmtBytes(m.disk_used)} / ${fmtBytes(m.disk_total)}` : 'en attente', m.disk_total ? pct(m.disk_used, m.disk_total) : undefined),
      stat('Réseau', `↓ ${fmtRate(l.net_rx)}`, `↑ ${fmtRate(l.net_tx)}`, undefined, '', h('net')),
      stat('Sites en ligne', `${ov.sites_up} / ${ov.sites_total}`, `uptime ${fmtDur(l.uptime)}`, undefined, ov.sites_up < ov.sites_total ? 'bad' : ''),
    );
  };
  draw(live);
  liveTimer = setInterval(async () => { try { draw(await api({ r: 'live' }, { poll: true })); } catch (e) { /* ignore */ } }, 5000);
  const alerts = ov.alerts.length
    ? ov.alerts.map(a => el('a', { class: `alert ${a.level}`, href: `#${LEGACY[a.section] || 'home'}` }, badge({ crit: 'critique', warn: 'attention', info: 'info' }[a.level], a.level), el('span', {}, a.text), el('span', { class: 'go' }, el('span', { class: 'long' }, 'Voir '), icon('chevron'))))
    : [el('div', { class: 'alert ok' }, badge('OK', 'ok'), el('span', {}, 'Aucune alerte : tout fonctionne.'))];
  const list = el('div', { class: 'alerts', id: 'alerts' }, alerts);
  /* Banner: the most urgent problems first, before the numbers. */
  const crit = ov.alerts.filter(a => a.level === 'crit');
  const banner = crit.length ? el('div', { class: 'banner crit', role: 'status' },
    el('span', { class: 'banner-ico' }, icon('alert')),
    el('div', { class: 'banner-text' },
      el('strong', {}, `${crit.length} problème${crit.length > 1 ? 's' : ''} critique${crit.length > 1 ? 's' : ''} à traiter`),
      el('span', {}, crit.map(a => a.text.replace(/\.$/, '')).join(' · '))),
    el('button', { class: 'btn', onclick: () => list.scrollIntoView({ behavior: 'smooth', block: 'center' }) }, 'Voir les alertes')) : null;
  return [banner, box, list, note(`${ov.host} · ${ov.os} · noyau ${ov.kernel}`)];
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

B.sites = async () => {
  const s = await section('sites');
  const d = s.data || {}, up = d.uptime || {};
  const rows = (d.sites || []).map(x => {
    const code = x.code ?? null;
    const cls = code === null ? '' : code >= 200 && code < 400 ? 'ok' : code < 500 && code > 0 ? 'warn' : 'crit';
    const certCls = x.cert_days === null || x.cert_days === undefined ? '' : x.cert_days < 15 ? 'crit' : x.cert_days < 30 ? 'warn' : 'ok';
    return [
      el('div', {}, el('a', { href: x.url, target: '_blank', rel: 'noopener noreferrer' }, x.name), el('div', { class: 'muted small mono' }, x.docroot || ''), x.aliases?.length ? el('div', { class: 'muted small' }, x.aliases.join(', ')) : null),
      code === null ? '–' : badge(code === 0 ? 'injoignable' : `HTTP ${code}`, cls),
      code ? `${x.ms} ms` : '–',
      up[x.name] !== undefined ? `${up[x.name]} %` : '–',
      x.cert_days !== null && x.cert_days !== undefined ? badge(`${x.cert_days} j`, certCls) : badge('HTTP seul'),
      el('details', { class: 'more' }, el('summary', {}, 'Vhost'), el('div', { class: 'mono small' }, (x.confs || []).join(', ')),
        (x.confs || []).filter(c => !c.startsWith(d.self)).map(c => actionBtn(`Désactiver ${c}`, 'site', ['disable', c], { cls: 'danger', confirm: `Désactiver le vhost ${c} ? Le site ne sera plus servi (configtest + reload, retour arrière si erreur).` }))),
    ];
  });
  const disabled = d.disabled || [];
  return {
    actions: actionBtn('Contrôler maintenant', 'collect-now', []),
    body: [
      card(null, table(['Site', 'Statut', { label: 'Réponse', num: true }, { label: 'Dispo 7 j', num: true }, 'Certificat', ''], rows)),
      disabled.length ? card(`Vhosts désactivés (${disabled.length})`, table(['Fichier', ''], disabled.map(c => [el('span', { class: 'mono' }, c), actionBtn('Activer', 'site', ['enable', c], { confirm: `Activer le vhost ${c} ? (configtest + reload, retour arrière si erreur)` })]))) : null,
      stale(s.updated),
    ],
  };
};

B.certs = async () => {
  const s = await section('certs');
  const rows = (s.data?.certs || []).map(c => [el('strong', {}, c.name), el('span', { class: 'small' }, c.domains.join(', ')), fmtDate(c.expires),
    badge(`${c.days} j`, c.days < 15 ? 'crit' : c.days < 30 ? 'warn' : 'ok')]);
  return {
    actions: [actionBtn('Tester', 'certbot', ['dry-run']), actionBtn('Renouveler', 'certbot', ['renew'], { confirm: 'Lancer certbot renew ? (seuls les certificats à moins de 30 jours sont renouvelés)' })],
    body: [card(null, table(['Nom', 'Domaines', 'Expire le', 'Restant'], rows)), note('Renouvellement automatique par le timer certbot. Aucune suppression possible ici.')],
  };
};

B.traffic = async () => {
  let days = Number(sessionStorage.getItem('tdays') || 7), host = sessionStorage.getItem('thost') || '';
  const seg = el('div', { class: 'seg', role: 'group', 'aria-label': 'Période' }), sel = el('select', { 'aria-label': 'Site' }), body = el('div', { class: 'stack' });
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
      t.since_first ? null : el('div', { class: 'alert info' }, badge('info', 'info'), 'Les premières statistiques arrivent dans les 5 minutes.'),
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

function pm2Table(pm2) {
  const apps = pm2.data?.apps || [];
  return table(['Nom', 'État', { label: 'CPU', num: true }, { label: 'Mémoire', num: true }, { label: 'Redém.', num: true }, 'En ligne depuis', ''],
    apps.map(a => [el('strong', {}, a.name), badge(a.status, a.status === 'online' ? 'ok' : 'crit'), `${a.cpu} %`, fmtBytes(a.memory), a.restarts,
      a.uptime ? fmtDur(Date.now() / 1000 - a.uptime / 1000) : '–', actionBtn('Restart', 'pm2-restart', [a.name], { confirm: `Redémarrer l'appli PM2 ${a.name} ?` })]),
    'Aucune appli PM2.');
}

B.services = async () => {
  const s = await section('services');
  const failed = s.data?.failed || [];
  const rows = (s.data?.units || []).map(u => {
    const ok = u.ActiveState === 'active';
    const acts = u.action ? [
      actionBtn('Restart', 'service', ['restart', u.action], { confirm: `Redémarrer ${u.action} ?` }),
      u.reload ? actionBtn('Reload', 'service', ['reload', u.action]) : null,
    ] : el('span', { class: 'muted small' }, 'lecture seule');
    return [el('div', {}, el('strong', {}, u.Id.replace('.service', '')), el('div', { class: 'muted small' }, u.Description)), badge(ok ? 'actif' : `${u.ActiveState} (${u.SubState})`, ok ? 'ok' : 'crit'),
      u.MemoryCurrent && u.MemoryCurrent !== '[not set]' ? fmtBytes(Number(u.MemoryCurrent)) : '–', el('span', { class: 'small' }, u.ActiveEnterTimestamp || '–'), acts];
  });
  return [
    failed.length ? el('div', { class: 'alert crit' }, badge('échec', 'crit'), `Unités en échec : ${failed.join(', ')}`) : null,
    card(null, table(['Service', 'État', { label: 'Mémoire', num: true }, 'Démarré le', ''], rows)),
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
    card('Crontabs', files.length ? files.map(([name, c]) => el('details', {}, el('summary', { class: 'mono' }, name), el('pre', { class: 'log' }, c || '(vide)'))) : el('p', { class: 'empty' }, 'Aucune crontab.')),
    stale(cron.updated),
  ];
};

B.perf = async () => {
  const s = await section('perf');
  const d = s.data || {}, m = d.mariadb || {};
  const memMax = Math.max(1, ...(d.memory || []).map(x => x.rss));
  return [
    el('div', { class: 'grid2' },
      card('Mémoire par composant', table(['Composant', { label: 'RAM', num: true }, ''], (d.memory || []).map(x => [el('strong', {}, x.name), fmtBytes(x.rss), bar(pct(x.rss, memMax))]))),
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

B.storage = async () => {
  const s = await section('storage');
  const d = s.data || {};
  const maxDir = Math.max(1, ...(d.dirs || []).map(x => x.size));
  return {
    actions: [actionBtn('Vider le cache apt', 'apt-clean', [], { confirm: 'Vider le cache apt ?' }), actionBtn('Réduire le journal', 'journal-vacuum', [], { confirm: 'Réduire le journal systemd à 50 Mo ?' })],
    body: [
      card(null, table(['Montage', 'Type', { label: 'Taille', num: true }, { label: 'Libre', num: true }, 'Remplissage'],
        (d.df || []).map(p => [el('div', {}, el('strong', {}, p.target), el('div', { class: 'mono small muted' }, p.source)), p.fstype, fmtBytes(p.size), fmtBytes(p.avail), el('div', { class: 'fill' }, `${p.pct} %`, bar(p.pct))]))),
      el('div', { class: 'grid2' },
        card('Plus gros dossiers', table(['Dossier', { label: 'Taille', num: true }, ''], (d.dirs || []).map(x => [el('span', { class: 'mono small' }, x.path), fmtBytes(x.size), bar(pct(x.size, maxDir))]))),
        card('Nettoyable', stats(stat('Journal systemd', fmtBytes(d.journal), 'plafonné à 100 Mo'), stat('Cache apt', fmtBytes(d.apt_cache), 'paquets téléchargés')))),
      stale(s.updated),
    ],
  };
};

B.audit = async () => {
  const [s, sc] = await Promise.all([section('security'), section('secscan')]);
  const d = s.data || {}, scan = sc.data;
  const counts = { crit: 0, warn: 0, info: 0 };
  (scan?.findings || []).forEach(f => counts[f.severity]++);
  return {
    actions: actionBtn('Lancer le scan', 'security-scan', []),
    body: [
      stats(
        stat('Critiques', scan ? counts.crit : '–', scan ? `scan ${ago(scan.ran)}` : 'jamais lancé', undefined, counts.crit ? 'bad' : ''),
        stat('À corriger', scan ? counts.warn : '–', `${counts.info} conseil(s)`),
        stat('Redémarrage', d.reboot_required ? 'requis' : 'non requis', `noyau ${d.kernel_running || '?'}${d.reboot_required ? ` → ${d.kernel_latest}` : ''}`),
        stat('IP bannies', (d.jails || []).reduce((n, j) => n + j.banned_now, 0), `${(d.jails || []).reduce((n, j) => n + j.banned_total, 0)} au total`)),
      card('Constats', [
        scan ? table(['Gravité', 'Site', 'Constat', 'Détail'], scan.findings.map(f => [badge(...SEV[f.severity]), el('strong', {}, f.site), f.title, el('span', { class: 'mono small' }, f.detail)]), 'Aucun problème trouvé.')
          : el('p', { class: 'empty' }, 'Pas encore de scan (chaque nuit à 4 h 20).'),
        note('Cherche fichiers sensibles exposés (.env, .git, dumps…), dossiers listables, en-têtes, ports et config SSH/Apache. Lecture seule.'),
      ]),
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
  if (!d.git) return [card(null, el('p', { class: 'empty' }, 'Le panel n\'a pas été installé depuis un clone git : la mise à jour depuis cette page est indisponible.')), stale(s.updated)];
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
      : el('p', { class: 'empty' }, 'fail2ban ne répond pas.')),
    el('div', { class: 'grid2' },
      card('Ports en écoute', table(['Adresse', 'Processus'], (d.listening || []).map(l => [el('span', { class: 'mono' }, l.addr), l.proc]))),
      card('Connexions SSH (7 j)', (d.logins || []).length ? el('pre', { class: 'log short' }, d.logins.join('\n')) : el('p', { class: 'empty' }, 'Aucune connexion trouvée.'))),
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

B.backups = async () => {
  const s = await section('backups');
  const d = s.data || {};
  const dl = (day, db) => el('a', { class: 'btn small', href: `/api.php?${new URLSearchParams({ r: 'backupdl', day, db })}` }, 'Télécharger');
  const last = d.days?.[0]?.day;
  return {
    actions: actionBtn('Sauvegarder maintenant', 'backup-now', []),
    body: [
      stats(
        stat('Dernière', last ? new Date(`${last}T12:00:00`).toLocaleDateString('fr-FR') : 'aucune', 'chaque nuit à 3 h 10', undefined, !last ? 'bad' : ''),
        stat('Espace', fmtBytes(d.total), `${d.days?.length ?? 0} jour(s) / ${d.keep ?? 7} max`),
        stat('Emplacement', 'Ce serveur', d.dir || '/var/backups/hostkeep')),
      card(null, (d.days || []).length ? d.days.map(day => el('details', { open: day === d.days[0] ? true : null },
        el('summary', {}, el('strong', {}, new Date(`${day.day}T12:00:00`).toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' })), el('span', { class: 'muted small' }, ` — ${day.files.length} base(s), ${fmtBytes(day.size)}`)),
        table(['Base', { label: 'Taille', num: true }, 'Faite à', ''], day.files.map(f => [el('strong', {}, f.db), fmtBytes(f.size), fmtDate(f.mtime),
          [dl(day.day, f.db), f.db === 'mysql' ? null : actionBtn('Restaurer', 'backup-restore', [day.day, f.db], { cls: 'danger', confirm: `Remplacer le contenu actuel de la base ${f.db} par la sauvegarde du ${day.day} ?\n\nUne copie de l'état actuel est faite juste avant, tu pourras revenir en arrière.` })]]))))
        : el('p', { class: 'empty' }, 'Aucune sauvegarde pour l\'instant.')),
      (d.pre || []).length ? card('Copies avant restauration', table(['Fichier', { label: 'Taille', num: true }, 'Date', ''], d.pre.map(p => [el('span', { class: 'mono' }, p.file), fmtBytes(p.size), fmtDate(p.mtime),
        el('a', { class: 'btn small', href: `/api.php?${new URLSearchParams({ r: 'backupdl', pre: p.file })}` }, 'Télécharger')]))) : null,
      note('Les sauvegardes restent sur ce serveur : télécharge de temps en temps celles qui comptent. La base « mysql » ne se restaure pas d\'ici.'),
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
  if (!roots.length) return card(null, el('p', { class: 'empty' }, 'Aucun dossier autorisé (HOSTKEEP_FILE_ROOTS).'));
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
    render(list, el('p', { class: 'empty' }, 'Chargement…'));
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

/* ---------- pages ---------- */
const PAGES = {
  home: { title: 'Accueil', desc: 'L\'état du serveur en un coup d\'œil.', blocks: [['summary', 'En direct'], ['history', 'Historique'], ['processes', 'Processus']] },
  sites: { title: 'Sites', desc: 'Disponibilité, certificats HTTPS et fréquentation.', blocks: [['sites', 'Sites'], ['certs', 'Certificats'], ['traffic', 'Trafic']] },
  system: { title: 'Système', desc: 'Services, applis, performances et disque.', blocks: [['services', 'Services'], ['pm2', 'PM2 & tâches'], ['perf', 'Performances'], ['storage', 'Stockage']] },
  security: { title: 'Sécurité', desc: 'Audit, mises à jour, accès et historique des actions.', blocks: [['audit', 'Audit'], ['updates', 'Mises à jour'], ['panel', 'Version du panel'], ['access', 'Accès'], ['journal', 'Journal d\'actions']] },
  data: { title: 'Données', desc: 'Bases, sauvegardes, journaux et fichiers.', blocks: [['databases', 'Bases'], ['backups', 'Sauvegardes'], ['logs', 'Journaux'], ['files', 'Fichiers']] },
};

const chips = document.getElementById('chips');
let curPage = null, observer = null, renderId = 0;

function parseHash() {
  let h = location.hash.slice(1);
  if (LEGACY[h] && !PAGES[h]) h = LEGACY[h];
  const [page, anchor] = h.split('/');
  return PAGES[page] ? [page, anchor] : ['home', null];
}
const scrollToBlock = id => document.getElementById(`b-${id}`)?.scrollIntoView({ block: 'start' });

async function renderPage(page) {
  const myId = ++renderId;
  clearInterval(liveTimer); liveTimer = null;
  cache = new Map();
  observer?.disconnect();
  const P = PAGES[page];
  curPage = page;
  document.querySelectorAll('[data-nav]').forEach(a => a.toggleAttribute('aria-current', a.dataset.nav === page));
  document.getElementById('page-title').textContent = P.title;
  document.getElementById('page-desc').textContent = P.desc;
  document.title = `${P.title} · Hostkeep`;
  chips.replaceChildren(...P.blocks.map(([id, label]) => el('a', { href: `#${page}/${id}`, 'data-chip': id }, label)));

  observer = new IntersectionObserver(entries => {
    for (const e of entries) if (e.isIntersecting) chips.querySelectorAll('[data-chip]').forEach(c => c.classList.toggle('on', `b-${c.dataset.chip}` === e.target.id));
  }, { rootMargin: '-120px 0px -65% 0px' });

  const jobs = P.blocks.map(([id, label]) => {
    const head = el('div', { class: 'block-head' }, el('h2', {}, label));
    const body = el('div', { class: 'block-body' }, el('div', { class: 'skeleton' }));
    const sec = el('section', { class: 'block', id: `b-${id}`, 'aria-labelledby': `h-${id}` }, head, body);
    head.firstChild.id = `h-${id}`;
    observer.observe(sec);
    return [sec, async () => {
      try {
        const out = await B[id]();
        if (myId !== renderId) return;
        const { body: nodes, actions } = out && out.body !== undefined ? out : { body: out };
        if (actions) head.append(el('div', { class: 'actions' }, actions));
        render(body, nodes);
      } catch (e) {
        if (myId === renderId) render(body, el('div', { class: 'error-box' }, `Erreur : ${e.message}`));
      }
    }];
  });
  render(content, jobs.map(j => j[0]));
  await Promise.allSettled(jobs.map(j => j[1]()));
  if (myId === renderId) {
    const upd = document.getElementById('updated');
    upd.textContent = `Mis à jour à ${new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}`;
    upd.hidden = false;
  }
}

async function route(force = false) {
  const [page, anchor] = parseHash();
  if (force || page !== curPage) {
    window.scrollTo(0, 0);
    const done = renderPage(page);
    if (anchor) { scrollToBlock(anchor); await done; scrollToBlock(anchor); }
    await done;
  } else if (anchor) {
    scrollToBlock(anchor);
  } else {
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
}

/* Header health pill: number of alerts, refreshed every minute. */
async function refreshHealth() {
  const pill = document.getElementById('health');
  try {
    const ov = await api({ r: 'overview' }, { poll: true });
    const crit = ov.alerts.filter(a => a.level === 'crit').length, warn = ov.alerts.filter(a => a.level === 'warn').length;
    pill.className = `health ${crit ? 'crit' : warn ? 'warn' : 'ok'}`;
    const long = crit ? `${crit} critique${crit > 1 ? 's' : ''}` : warn ? `${warn} alerte${warn > 1 ? 's' : ''}` : 'Tout va bien';
    pill.replaceChildren(el('span', { class: 'short' }, crit || warn || 'OK'), el('span', { class: 'long' }, long));
    pill.setAttribute('aria-label', long);
    pill.hidden = false;
    /* Per-section counters in the navigation (critical + warning alerts). */
    const counts = {};
    for (const a of ov.alerts) if (a.level !== 'info') { const p = (LEGACY[a.section] || 'home').split('/')[0]; (counts[p] ||= { n: 0, crit: false }).n++; if (a.level === 'crit') counts[p].crit = true; }
    document.querySelectorAll('[data-count]').forEach(c => {
      const k = counts[c.dataset.count];
      c.hidden = !k; c.textContent = k ? k.n : '';
      c.className = `count ${k?.crit ? 'crit' : 'warn'}`;
      c.setAttribute('aria-label', k ? `${k.n} alerte${k.n > 1 ? 's' : ''}` : '');
    });
    document.querySelector('.server')?.setAttribute('data-state', crit ? 'crit' : warn ? 'warn' : 'ok');
    document.getElementById('server-sub').textContent = ov.os || '';
  } catch (e) { /* ignore */ }
}

window.addEventListener('hashchange', () => route());
document.getElementById('refresh-btn').onclick = () => { route(true); refreshHealth(); };

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

route();
refreshHealth();
setInterval(refreshHealth, 60000);
