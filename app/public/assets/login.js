'use strict';
/* Login page: show / hide the password, caps-lock warning, no double submit. Works without it too. */
const pass = document.getElementById('pass'), toggle = document.getElementById('pw-toggle'), caps = document.getElementById('caps');
toggle.hidden = false;
toggle.onclick = () => {
  const show = pass.type === 'password';
  pass.type = show ? 'text' : 'password';
  toggle.textContent = show ? 'Masquer' : 'Afficher';
  toggle.setAttribute('aria-pressed', String(show));
  pass.focus();
};
pass.addEventListener('keyup', e => { caps.hidden = !e.getModifierState?.('CapsLock'); });
pass.addEventListener('blur', () => { caps.hidden = true; });
document.querySelector('form').addEventListener('submit', () => {
  const btn = document.getElementById('login-submit');
  pass.type = 'password';   // never leave the clear text for the password manager prompt
  btn.textContent = 'Connexion…';
  setTimeout(() => { btn.disabled = true; });   // after the submit, so the form is still sent
});
