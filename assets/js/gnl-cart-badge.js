/* =====================================================================
   GNL Solution — Badge panier universel (toutes les pages)
   ---------------------------------------------------------------------
   Le panier « maison » vit dans la session PHP et n'est connu que des
   pages personnalisées (/cart, configurateur, /commande). Pour afficher
   l'icône panier sur TOUTES les pages — y compris celles rendues par
   WordPress — le panier écrit un cookie « gnl_cart_count » (voir
   cart.php). Ce script lit ce cookie et révèle l'icône SureCart de
   l'en-tête, quel que soit le mode de rendu de la page.

   Il n'utilise que le cookie -> compatible avec la mise en cache des
   pages (personnalisation côté navigateur, pas côté serveur).
   ===================================================================== */
(function () {
  function cartCount() {
    var m = document.cookie.match(/(?:^|;\s*)gnl_cart_count=(\d+)/);
    return m ? parseInt(m[1], 10) : 0;
  }

  // CSS : force l'affichage même si SureCart remet l'attribut [hidden],
  // masque le compteur natif SureCart (lié à un panier vide) et stylise le nôtre.
  var css = document.createElement('style');
  css.textContent =
    '.wp-block-surecart-cart-menu-icon-button.gnl-has-items,' +
    '.wp-block-surecart-cart-menu-icon-button.gnl-has-items[hidden]{display:inline-block!important}' +
    '.wp-block-surecart-cart-menu-icon-button.gnl-has-items .sc-cart-count{display:none!important}' +
    '.gnl-cart-count{position:absolute;inset:-12px -16px auto auto;min-width:14px;padding:2px 6px;' +
    'border-radius:9999px;background:var(--sc-color-primary-500,#6c9400);color:#fff;font-size:10px;' +
    'font-weight:700;line-height:14px;text-align:center;box-sizing:border-box;z-index:1}';
  (document.head || document.documentElement).appendChild(css);

  function apply() {
    var count = cartCount();
    var icons = document.querySelectorAll('.wp-block-surecart-cart-menu-icon-button');
    for (var i = 0; i < icons.length; i++) {
      var icon = icons[i];
      if (count > 0) {
        if (!icon.classList.contains('gnl-has-items')) icon.classList.add('gnl-has-items');
        icon.removeAttribute('hidden');

        // Notre propre badge (non piloté par SureCart) pour afficher le vrai nombre.
        var host = icon.querySelector('.sc-cart-icon') || icon;
        var badge = host.querySelector('.gnl-cart-count');
        if (!badge) {
          badge = document.createElement('span');
          badge.className = 'gnl-cart-count';
          host.appendChild(badge);
        }
        var txt = String(count);
        if (badge.textContent !== txt) badge.textContent = txt;
        badge.setAttribute('aria-label', count + ' article' + (count > 1 ? 's' : '') + ' dans le panier');

        // Le tiroir SureCart est vide (panier maison) -> le clic mène au vrai panier.
        if (!icon.getAttribute('data-gnl-bound')) {
          icon.setAttribute('data-gnl-bound', '1');
          icon.setAttribute('href', '/cart');
          icon.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopImmediatePropagation();
            window.location.href = '/cart';
          }, true);
        }
      } else {
        icon.classList.remove('gnl-has-items');
        var b = icon.querySelector('.gnl-cart-count');
        if (b) b.parentNode.removeChild(b);
      }
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', apply);
  } else {
    apply();
  }

  // SureCart s'hydrate parfois après coup : on ré-applique si l'en-tête change.
  if (window.MutationObserver) {
    var raf = null;
    var obs = new MutationObserver(function () {
      if (raf) return;
      raf = requestAnimationFrame(function () { raf = null; apply(); });
    });
    obs.observe(document.body || document.documentElement, { childList: true, subtree: true });
  }
})();
