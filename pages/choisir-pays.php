<?php
/* =====================================================================
   gnl-solution.com (racine) — page de choix du pays, servie uniquement
   aux robots d'indexation (les visiteurs sont redirigés automatiquement).
   Cible du hreflang « x-default ».
   ===================================================================== */
require_once __DIR__ . '/../include/layout.php';
$gnl_names = gnl_i18n_lang_names();
if (!headers_sent()) header('Content-Type: text/html; charset=UTF-8');
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>GNL Solution — Choisissez votre pays</title>
<meta name="description" content="Hébergement web bas carbone à Besançon. Choisissez votre pays et votre langue.">
<link rel="icon" href="/assets/gnl/img/logo-gnl-icone.svg" type="image/svg+xml">
<link rel="stylesheet" href="/assets/gnl/gnl.css">
<?php
$GLOBALS['GNL_I18N']['dev'] = false;
foreach (gnl_i18n_regions() as $rk => $r) {
    if ($rk === 'in') continue;
    foreach ($r['langs'] as $l) echo '<link rel="alternate" hreflang="' . ($rk === 'eu' ? $l : gnl_i18n_locale($rk, $l)) . '" href="https://' . $r['host'] . '/' . $l . '/">' . "\n";
}
?>
<link rel="alternate" hreflang="x-default" href="https://<?php echo GNL_ROOT_HOST; ?>/">
</head>
<body>
<main id="contenu" style="max-width: 960px; margin: 0 auto; padding: 56px 24px 96px;">
<img src="/assets/gnl/img/logo-gnl-clair.svg" alt="GNL Solution" width="194" height="62" style="height: 62px; width: auto; margin: 0 0 32px -18px;">
<h1 style="margin: 0 0 12px; font-size: 40px; line-height: 46px; font-weight: 800;">Choisissez votre pays</h1>
<p style="margin: 0 0 32px; font-size: 17px; line-height: 27px; color: #5e6684;">Hébergement web bas carbone, sur des serveurs sobres à Besançon.</p>
<?php foreach (gnl_i18n_regions() as $rk => $r): if ($rk === 'in') continue; ?>
<section class="gnl-loc-region">
<h2 style="margin: 0 0 10px; font-size: 12px; font-weight: 700; letter-spacing: 0.2em; text-transform: uppercase; color: #5e6684;"><?php echo gnl_ui_h($r['name']); ?></h2>
<ul>
<?php foreach ($r['langs'] as $l): ?>
<li><a translate="no" lang="<?php echo $l; ?>" href="https://<?php echo gnl_ui_h($r['host']); ?>/<?php echo $l; ?>/"><?php echo gnl_ui_h($gnl_names[$l]); ?></a></li>
<?php endforeach; ?>
</ul>
</section>
<?php endforeach; ?>
</main>
</body>
</html>
