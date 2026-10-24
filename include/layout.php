<?php
/* =====================================================================
   GNL Solution — Gabarit commun (nouvelle charte « bas carbone »)
   ---------------------------------------------------------------------
   Utilisation dans une page :

       require_once __DIR__ . '/../include/layout.php';
       gnl_ui_open('Titre de la page', 'solutions', array('desc' => '…'));
       ... contenu ...
       gnl_ui_close();

   $active : solutions | impact | apropos | statut | contact | '' (aucun)
   Options : desc (meta description), head (HTML ajouté dans <head>),
             body_class, main (false = pas de balise <main> automatique).
   ===================================================================== */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/i18n.php';

if (!function_exists('gnl_ui_h')) {
    function gnl_ui_h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
}

/* Nombre d'articles dans le panier (session = source de vérité, cookie en repli). */
if (!function_exists('gnl_ui_cart_count')) {
    function gnl_ui_cart_count() {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) { @session_start(); }
        $n = 0;
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (!empty($_SESSION['gnl_cart']) && is_array($_SESSION['gnl_cart'])) {
                foreach ($_SESSION['gnl_cart'] as $ci) {
                    $n += isset($ci['qty']) ? max(0, (int) $ci['qty']) : 1;
                }
            }
        } elseif (isset($_COOKIE['gnl_cart_count'])) {
            $n = max(0, (int) $_COOKIE['gnl_cart_count']);
        }
        return $n;
    }
}

if (!function_exists('gnl_ui_open')) {
    function gnl_ui_open($title, $active = '', $opts = array()) {
        $GLOBALS['gnl_ui_active'] = $active;
        $GLOBALS['gnl_ui_opts']   = $opts;
        $GLOBALS['gnl_cart_count'] = gnl_ui_cart_count(); // avant toute sortie (session)
        $desc = isset($opts['desc']) ? $opts['desc'] : 'Hébergement web bas carbone à Besançon, sur des micro-serveurs sobres. Données en France, conformes au RGPD.';
        $fullTitle = ($title === '' ? 'GNL Solution — Hébergement bas carbone à Besançon' : $title . ' — GNL Solution');
        if (!headers_sent()) header('Content-Type: text/html; charset=UTF-8');
        ?><!DOCTYPE html>
<html lang="<?php echo gnl_lang(); ?>">
<head>
<meta charset="UTF-8">
<meta http-equiv="content-language" content="<?php echo gnl_lang(); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo gnl_ui_h($fullTitle); ?></title>
<meta name="description" content="<?php echo gnl_ui_h($desc); ?>">
<meta name="author" content="GNL Solution">
<meta name="theme-color" content="#141a33">
<link rel="icon" href="/assets/gnl/img/logo-gnl-icone.svg" type="image/svg+xml">
<link rel="icon" href="/assets/gnl/img/favicon-32.png" sizes="32x32">
<link rel="icon" href="/assets/gnl/img/favicon-192.png" sizes="192x192">
<link rel="apple-touch-icon" href="/assets/gnl/img/favicon-180.png">
<link rel="preload" href="/assets/gnl/fonts/Manrope.ttf" as="font" type="font/ttf" crossorigin>
<link rel="stylesheet" href="<?php echo gnl_asset('/assets/gnl/gnl.css'); ?>">
<link rel="stylesheet" href="<?php echo gnl_asset('/assets/gnl/print.css'); ?>" media="print">
<?php echo gnl_i18n_hreflang(); ?>
<?php echo gnl_i18n_js_dict(); ?>
<?php if (!empty($opts['head'])) echo $opts['head'] . "\n"; ?>
<?php gnl_ui_stats_snippet(); ?>
</head>
<body<?php echo !empty($opts['body_class']) ? ' class="' . gnl_ui_h($opts['body_class']) . '"' : ''; ?>>
<a class="gnl-skip" href="#contenu">Aller au contenu</a>
<?php
        include __DIR__ . '/header.php';
        if (!isset($opts['main']) || $opts['main'] !== false) echo '<main id="contenu">' . "\n";
    }
}

if (!function_exists('gnl_ui_close')) {
    function gnl_ui_close($scripts = '') {
        $opts = isset($GLOBALS['gnl_ui_opts']) ? $GLOBALS['gnl_ui_opts'] : array();
        if (!isset($opts['main']) || $opts['main'] !== false) echo "\n</main>\n";
        include __DIR__ . '/footer.php';
        ?>
<script src="<?php echo gnl_asset('/assets/gnl/gnl.js'); ?>" defer></script>
<?php echo $scripts; ?>
</body>
</html>
<?php
    }
}

/* Traceur de statistiques (exempté de consentement) — chargé seulement si
   GNL_MATOMO_URL est renseigné ET si le visiteur ne s'y est pas opposé. */
if (!function_exists('gnl_ui_stats_snippet')) {
    function gnl_ui_stats_snippet() {
        if (GNL_MATOMO_URL === '') return;
        if (isset($_COOKIE['gnl_stats_optout']) && $_COOKIE['gnl_stats_optout'] === '1') return;
        $u = rtrim(GNL_MATOMO_URL, '/') . '/';
        /* Script externe (assets/gnl/stats-matomo.js), paramètres en attributs data- */
        echo '<script src="' . gnl_ui_h(gnl_asset('/assets/gnl/stats-matomo.js')) . '" data-u="' . gnl_ui_h($u) . '" data-site="' . gnl_ui_h((string) GNL_MATOMO_SITE_ID) . '" defer></script>' . "\n";
    }
}
