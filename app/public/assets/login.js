'use strict';
/* Installable app from the login page too (the service worker must control start_url). */
if ('serviceWorker' in navigator) navigator.serviceWorker.register('/sw.js', { updateViaCache: 'none' }).catch(() => {});
/* Login / password pages: show / hide passwords, caps-lock warning, no double submit. Works without it too. */
const caps = document.getElementById('caps');
document.querySelectorAll('.pw-field').forEach(field => {
  const input = field.querySelector('input'), toggle = field.querySelector('button');
  if (!input || !toggle) return;
  toggle.hidden = false;
  toggle.onclick = () => {
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    toggle.textContent = show ? 'Masquer' : 'Afficher';
    toggle.setAttribute('aria-pressed', String(show));
    input.focus();
  };
  if (caps) {
    input.addEventListener('keyup', e => { caps.hidden = !e.getModifierState?.('CapsLock'); });
    input.addEventListener('blur', () => { caps.hidden = true; });
  }
});
document.querySelectorAll('form').forEach(form => form.addEventListener('submit', () => {
  const btn = form.querySelector('button[type="submit"]');
  form.querySelectorAll('.pw-field input').forEach(i => { i.type = 'password'; });   // never leave clear text for the password manager prompt
  if (!btn) return;
  btn.textContent = btn.dataset.busy || 'Envoi…';
  setTimeout(() => { btn.disabled = true; });   // after the submit, so the form is still sent
}));
/* password.php: live check that both entries match. */
const p1 = document.getElementById('new1'), p2 = document.getElementById('new2'), hint = document.getElementById('match');
if (p1 && p2 && hint) {
  const check = () => { hint.hidden = !p2.value || p1.value === p2.value; };
  p1.addEventListener('input', check); p2.addEventListener('input', check);
}
