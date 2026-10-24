/* Mesure d'audience Matomo (exemptée de consentement), paramètres lus sur la balise <script>. */
(function () {
  var me = document.currentScript;
  if (!me || document.cookie.indexOf('gnl_stats_optout=1') !== -1) return;
  var u = me.getAttribute('data-u'), site = me.getAttribute('data-site');
  if (!u) return;
  var _paq = window._paq = window._paq || [];
  _paq.push(['setCookieDomain', location.hostname]);
  _paq.push(['setVisitorCookieTimeout', 34186669]); // 13 mois max (CNIL)
  _paq.push(['trackPageView']);
  _paq.push(['enableLinkTracking']);
  _paq.push(['setTrackerUrl', u + 'matomo.php']);
  _paq.push(['setSiteId', site]);
  var g = document.createElement('script');
  g.async = true; g.src = u + 'matomo.js';
  document.head.appendChild(g);
})();
