/* =====================================================================
   GNL Solution — scripts communs (menu mobile, modales, statistiques)
   ===================================================================== */
(function () {
  'use strict';
  var T = function (s) { return window.gnlT ? window.gnlT(s) : s; };

  /* ---------- Menu mobile ---------- */
  var hd = document.querySelector('.gnl-hd');
  var burger = hd && hd.querySelector('.gnl-hd-burger');
  if (burger) {
    burger.addEventListener('click', function () {
      var open = hd.classList.toggle('is-open');
      burger.setAttribute('aria-expanded', open ? 'true' : 'false');
      burger.setAttribute('aria-label', open ? T('Fermer le menu') : T('Ouvrir le menu'));
    });
  }

  /* ---------- Modales génériques : [data-gnl-open="id"] / [data-gnl-close] ---------- */
  var lastFocus = null;
  function openModal(m) {
    if (!m) return;
    lastFocus = document.activeElement;
    m.hidden = false;
    document.body.classList.add('gnl-noscroll');
    var f = m.querySelector('button, [href], input, select, textarea');
    if (f) f.focus();
  }
  function closeModal(m) {
    if (!m) return;
    m.hidden = true;
    if (!document.querySelector('.gnl-modal:not([hidden])')) document.body.classList.remove('gnl-noscroll');
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }
  window.gnlOpenModal = function (id) { openModal(document.getElementById(id)); };
  window.gnlCloseModal = function (id) { closeModal(document.getElementById(id)); };

  document.addEventListener('click', function (e) {
    var o = e.target.closest('[data-gnl-open]');
    if (o) { e.preventDefault(); openModal(document.getElementById(o.getAttribute('data-gnl-open'))); return; }
    var c = e.target.closest('[data-gnl-close]');
    if (c) { e.preventDefault(); closeModal(c.closest('.gnl-modal')); return; }
    if (e.target.classList && e.target.classList.contains('gnl-modal')) closeModal(e.target);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var m = document.querySelector('.gnl-modal:not([hidden])');
    if (m) closeModal(m);
  });

  /* ---------- Statistiques : opposition (cookie 13 mois) ---------- */
  function getCookie(n) {
    var m = document.cookie.match(new RegExp('(?:^|; )' + n + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : null;
  }
  function setOptOut(on) {
    var exp = new Date(); exp.setMonth(exp.getMonth() + 13);
    document.cookie = 'gnl_stats_optout=' + (on ? '1' : '0') + '; expires=' + exp.toUTCString() + '; path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
    if (window._paq) window._paq.push([on ? 'optUserOut' : 'forgetUserOptOut']);
  }
  var toggle = document.getElementById('gnl-stats-toggle');
  var state = document.getElementById('gnl-stats-state');
  function render() {
    var off = getCookie('gnl_stats_optout') === '1';
    if (toggle) toggle.checked = !off;
    if (state) { state.textContent = off ? T('Désactivées pour vous') : T('Actives'); state.classList.toggle('is-off', off); }
  }
  if (toggle) toggle.addEventListener('change', function () { setOptOut(!toggle.checked); render(); });
  render();

  /* ---------- Bouton de paiement : remplissage diagonal quand les CGV sont cochées ---------- */
  document.querySelectorAll('[data-gnl-pay]').forEach(function (btn) {
    var box = document.getElementById(btn.getAttribute('data-gnl-pay'));
    if (!box) return;
    function sync() { btn.classList.toggle('is-ready', box.checked); btn.setAttribute('aria-disabled', box.checked ? 'false' : 'true'); }
    box.addEventListener('change', sync);
    sync();
    btn.addEventListener('click', function (e) {
      if (!box.checked) { e.preventDefault(); box.focus(); box.closest('label') && box.closest('label').classList.add('err'); }
    });
  });
})();
