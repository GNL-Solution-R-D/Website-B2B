<?php
/* Page introuvable (servie par router.php avec le code 404). */
require_once __DIR__ . '/../include/layout.php';
gnl_ui_open('Page introuvable', '', array('desc' => 'Cette page n\'existe pas ou a été déplacée.'));
?>
<section style="max-width: 760px; margin: 0 auto; padding: 96px 24px 120px;">
<p style="margin: 0 0 12px; font-size: 12px; font-weight: 700; letter-spacing: 0.2em; color: #1f7a4a;">ERREUR 404</p>
<h1 style="margin: 0 0 16px; font-size: clamp(32px, 4vw, 44px); line-height: 1.14; font-weight: 800;">Cette page est introuvable.</h1>
<p style="margin: 0 0 32px; font-size: 17px; line-height: 27px; color: #5e6684;">Elle a peut-être été déplacée ou supprimée. Voici quelques liens pour reprendre votre visite.</p>
<p style="margin: 0; display: flex; flex-wrap: wrap; gap: 12px;"><a class="gnl-b gnl-b-green" href="/">Retour à l'accueil</a><a class="gnl-b gnl-b-line" href="/solutions">Voir les offres</a><a class="gnl-b gnl-b-line" href="/contact">Nous contacter</a></p>
</section>
<?php gnl_ui_close(); ?>
