<?php
/* =====================================================================
   En-tête commun (nouvelle charte). Inclus par include/layout.php.
   L'icône panier n'apparaît que si le panier contient au moins un article
   ($_SESSION['gnl_cart'], tenu par pages/cart.php ; cookie gnl_cart_count
   en repli quand la session ne peut plus être ouverte).
   ===================================================================== */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/i18n.php';
if (!function_exists('gnl_ui_h')) {
    function gnl_ui_h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
}
if (!isset($GLOBALS['gnl_cart_count'])) {
    $GLOBALS['gnl_cart_count'] = 0;
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['gnl_cart']) && is_array($_SESSION['gnl_cart'])) {
        foreach ($_SESSION['gnl_cart'] as $gnl_ci) $GLOBALS['gnl_cart_count'] += isset($gnl_ci['qty']) ? max(0, (int) $gnl_ci['qty']) : 1;
    } elseif (isset($_COOKIE['gnl_cart_count'])) {
        $GLOBALS['gnl_cart_count'] = max(0, (int) $_COOKIE['gnl_cart_count']);
    }
}
$gnl_cart_n  = (int) $GLOBALS['gnl_cart_count'];
$gnl_active  = isset($GLOBALS['gnl_ui_active']) ? $GLOBALS['gnl_ui_active'] : '';
$gnl_menu = array(
    'solutions' => array('/solutions',       'Votre solution'),
    'impact'    => array('/impact',          'Notre impact'),
    'apropos'   => array('/qui-sommes-nous', 'Qui sommes-nous ?'),
    'statut'    => array('/stats',           'État des services'),
    'contact'   => array('/contact',         'Contact'),
);
?>
<header class="gnl-hd">
  <div class="gnl-hd-in">
    <a class="gnl-hd-logo" href="/" aria-label="GNL Solution — accueil"><img src="/assets/gnl/img/logo-gnl-marine.svg" alt="GNL Solution" width="194" height="62"></a>
    <button type="button" class="gnl-hd-burger" aria-expanded="false" aria-controls="gnl-nav" aria-label="Ouvrir le menu">
      <svg aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="square"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
    </button>
    <nav id="gnl-nav" class="gnl-hd-nav" aria-label="Navigation principale">
      <?php foreach ($gnl_menu as $key => $it): ?>
        <a href="<?php echo $it[0]; ?>"<?php echo $gnl_active === $key ? ' aria-current="page"' : ''; ?>><?php echo gnl_ui_h($it[1]); ?></a>
      <?php endforeach; ?>
      <a class="gnl-hd-cart" href="/cart"<?php echo (isset($gnl_active) && $gnl_active === 'cart') ? ' aria-current="page"' : ''; ?><?php echo $gnl_cart_n > 0 ? '' : ' hidden'; ?> aria-label="Panier : <?php echo $gnl_cart_n; ?> produit<?php echo $gnl_cart_n > 1 ? 's' : ''; ?>">
        <svg aria-hidden="true" width="36" height="30" viewBox="0 0 36 30" fill="none"><path d="M1.5 3.5H6.2L7.6 8" stroke="#ffffff" stroke-width="2.2" stroke-linecap="square"/><path d="M7.6 8H34L30.4 21H11.2Z" fill="#5cd98b"/><rect x="11.6" y="24.4" width="3.4" height="3.4" fill="#ffffff"/><rect x="25.6" y="24.4" width="3.4" height="3.4" fill="#ffffff"/></svg>
        <span class="gnl-hd-cart-n" aria-hidden="true"><?php echo $gnl_cart_n > 99 ? '99+' : $gnl_cart_n; ?></span>
      </a>
    </nav>
    <button type="button" class="gnl-hd-loc" data-gnl-open="gnl-loc" aria-haspopup="dialog" aria-label="Pays et langue">
      <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.6 3.8 5.6 3.8 9s-1.3 6.4-3.8 9c-2.5-2.6-3.8-5.6-3.8-9S9.5 5.6 12 3z"/></svg>
      <span translate="no"><?php echo strtoupper(gnl_region() === 'eu' ? 'EU' : gnl_region()); ?> · <?php echo strtoupper(gnl_lang()); ?></span>
    </button>
    <a class="gnl-hd-client" href="<?php echo gnl_ui_h(GNL_ESPACE_CLIENT_URL); ?>">Espace Client</a>
  </div>
</header>
