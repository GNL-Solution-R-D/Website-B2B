<?php
/* =====================================================================
   in.gnl-solution.fr — « Ce site n'est pas disponible dans votre pays »
   Servie par router.php (code HTTP 451) pour les visiteurs situés hors
   de l'UE, de la Suisse, du Canada et du Royaume-Uni.
   ===================================================================== */
require_once __DIR__ . '/../include/layout.php';
$gnl_names = gnl_i18n_lang_names();
if (!headers_sent()) header('Content-Type: text/html; charset=UTF-8');
?><!DOCTYPE html>
<html lang="<?php echo gnl_lang(); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Service non disponible dans votre pays — GNL Solution</title>
<link rel="icon" href="/assets/gnl/img/logo-gnl-icone.svg" type="image/svg+xml">
<link rel="stylesheet" href="/assets/gnl/gnl.css">
</head>
<body style="background: #141a33; color: #ffffff;">
<main id="contenu" style="flex: 1 0 auto; display: flex; align-items: center; justify-content: center; padding: 48px 16px;">
<div style="width: 100%; max-width: 640px;">
<img src="/assets/gnl/img/logo-gnl-marine.svg" alt="GNL Solution" width="194" height="62" style="height: 62px; width: auto; margin: 0 0 32px -18px;">
<p style="margin: 0 0 16px; font-size: 12px; font-weight: 700; letter-spacing: 0.2em; color: #5cd98b;">SERVICE NON DISPONIBLE</p>
<h1 style="margin: 0 0 20px; font-size: clamp(30px, 5vw, 44px); line-height: 1.12; font-weight: 800;">Ce site n'est pas disponible dans votre pays.</h1>
<p style="margin: 0 0 16px; font-size: 18px; line-height: 28px; color: #c5cbd8;">GNL Solution héberge ses services en France et les propose uniquement dans l'Union européenne, en Suisse, au Canada et au Royaume-Uni.</p>
<p style="margin: 0 0 40px; font-size: 16px; line-height: 26px; color: #c5cbd8;">Vous pensez qu'il s'agit d'une erreur, par exemple parce que vous utilisez un VPN ? Écrivez-nous à <a href="mailto:contact@gnl-solution.com" style="color: #5cd98b;">contact@gnl-solution.com</a>.</p>
<nav aria-label="Langue" style="border-top: 1px solid #32385e; padding-top: 20px;">
<p style="margin: 0 0 10px; font-size: 12px; font-weight: 700; letter-spacing: 0.2em; color: #c5cbd8;">LANGUE</p>
<p style="margin: 0; display: flex; flex-wrap: wrap; gap: 6px 16px; font-size: 14px;">
<?php foreach ($gnl_names as $code => $name): ?>
<a translate="no" lang="<?php echo $code; ?>" href="/<?php echo $code; ?>/" style="color: <?php echo $code === gnl_lang() ? '#ffffff' : '#5cd98b'; ?>; font-weight: <?php echo $code === gnl_lang() ? '800' : '500'; ?>;"<?php echo $code === gnl_lang() ? ' aria-current="true"' : ''; ?>><?php echo gnl_ui_h($name); ?></a>
<?php endforeach; ?>
</p>
</nav>
</div>
</main>
</body>
</html>
