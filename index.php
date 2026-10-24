<?php
/* =====================================================================
   GNL Solution — Page d'accueil (nouvelle charte)
   Contenu issu de la maquette validée ; partenaires et prix « dès »
   chargés depuis le catalogue n8n (include/catalogue.php).
   ===================================================================== */
/* Servie directement par Apache (.htaccess ignoré, DirectoryIndex index.php) :
   on repasse par le contrôleur frontal pour avoir région, langue et liens corrects. */
if (empty($GLOBALS['GNL_ROUTED'])) { require __DIR__ . '/router.php'; return; }
require_once __DIR__ . '/include/catalogue.php';
require_once __DIR__ . '/include/layout.php';
$GNL_PRODUCTS = gnl_load_products();
$GNL_PARTNERS = gnl_load_partners();
$GNL_MIN_WEB  = gnl_cat_min_price($GNL_PRODUCTS, 'web_pro');
$GNL_MIN_EDU  = gnl_cat_min_price($GNL_PRODUCTS, 'web_edu');
$gnl_head = gnl_css('accueil');   // assets/gnl/page/accueil.css
gnl_ui_open('', '', array('head' => $gnl_head));
?>
<div class="gnl-page" style="color: #ffffff; background: #141a33;">
<!-- Accroche -->
<section id="accueil" style="position: relative; overflow: hidden;">
<div style="max-width: 1240px; margin: 0 auto; padding: 88px 24px 72px; display: flex; flex-wrap: wrap; gap: 56px; align-items: center;">
<div style="flex: 1 1 480px; min-width: 0;">
<p style="margin: 0 0 18px; font-size: 12px; font-weight: 700; letter-spacing: 0.2em; color: #5cd98b;">HÉBERGEMENT BAS CARBONE · BESANÇON</p>
<h1 style="margin: 0 0 24px; font-size: clamp(36px, 4.6vw, 60px); line-height: 1.04; font-weight: 800; letter-spacing: -0.01em;">Votre site hébergé à Besançon, sur des serveurs sobres.</h1>
<p style="margin: 0 0 36px; max-width: 540px; font-size: 18px; line-height: 28px; color: #c5cbd8;">Pas de datacenter neuf : nos micro-serveurs Raspberry Pi sont installés dans le vieux centre de Besançon. Un hébergement 100 % européen, conforme au RGPD, pensé pour les associations, les TPE et les entrepreneurs.</p>
<div style="display: flex; flex-wrap: wrap; gap: 14px;">
<a href="/solutions" style="display: inline-flex; align-items: center; min-height: 48px; padding: 0 22px; border-radius: 3px; background: #5cd98b; color: #141a33; font-weight: 700; font-size: 15px; text-decoration: none;">Voir les offres</a>
<a href="/impact#methode" style="display: inline-flex; align-items: center; min-height: 48px; padding: 0 22px; border-radius: 3px; border: 1px solid #5e6684; color: #ffffff; font-weight: 700; font-size: 15px; text-decoration: none;">Comment nous mesurons</a>
</div>
</div>
<div style="flex: 1 1 460px; min-width: 0; position: relative;">
<img src="/assets/gnl/img/baie-serveurs.webp" alt="Baie de serveurs GNL Solution à Besançon, éclairée dans la pénombre" style="display: block; width: 100%; aspect-ratio: 4 / 3; object-fit: cover; border-radius: 3px;">
<svg aria-hidden="true" viewBox="0 0 150 90" style="position: absolute; right: -8px; bottom: -28px; width: 150px; height: 90px;"><path d="M38 0h11L18 90H7z" fill="#5cd98b"></path><path d="M72 0h11L52 90H41z" fill="#5cd98b"></path><path d="M106 0h11L86 90H75z" fill="#5cd98b"></path><path d="M140 0h11l-31 90h-11z" fill="#5cd98b"></path></svg>
</div>
</div>

<!-- Chiffres clés -->
<div style="max-width: 1240px; margin: 0 auto; padding: 0 24px 88px;">
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1px; background: #32385e; border: 1px solid #32385e;">
<div style="background: #141a33; padding: 28px 28px 30px;">
<p style="margin: 0; font-size: 44px; line-height: 1; font-weight: 800;">0,17 W</p>
<p style="margin: 10px 0 0; font-size: 14px; line-height: 20px; color: #c5cbd8;">consommés par site hébergé</p>
</div>
<div style="background: #141a33; padding: 28px 28px 30px;">
<p style="margin: 0; font-size: 44px; line-height: 1; font-weight: 800;">100 % UE</p>
<p style="margin: 10px 0 0; font-size: 14px; line-height: 20px; color: #c5cbd8;">données hébergées à Besançon, conformes au RGPD</p>
</div>
<div style="background: #141a33; padding: 28px 28px 30px;">
<p style="margin: 0; font-size: 44px; line-height: 1; font-weight: 800;">0</p>
<p style="margin: 10px 0 0; font-size: 14px; line-height: 20px; color: #c5cbd8;">datacenter neuf construit pour nous</p>
</div>
</div>
</div>
</section>

<!-- Offres -->
<section id="offres" style="background: #f4f6fa; color: #141a33;">
<div style="max-width: 1240px; margin: 0 auto; padding: 96px 24px;">
<p style="margin: 0 0 12px; font-size: 12px; font-weight: 700; letter-spacing: 0.2em; color: #1f7a4a;">NOS SERVICES</p>
<h2 style="margin: 0 0 16px; font-size: 40px; line-height: 46px; font-weight: 800; max-width: 760px;">Des services simples, une infrastructure sobre.</h2>
<p style="margin: 0 0 48px; max-width: 640px; font-size: 17px; line-height: 27px; color: #5e6684;">Commandes, abonnements, factures, suivi : tout se gère depuis votre Espace Client.</p>
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px;">

<article style="background: #ffffff; border: 1px solid #c5cbd8; border-radius: 3px; padding: 32px; display: flex; flex-direction: column; gap: 16px;">
<svg aria-hidden="true" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#1f7a4a" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9" rx="1"></rect><rect x="14" y="3" width="7" height="5" rx="1"></rect><rect x="14" y="12" width="7" height="9" rx="1"></rect><rect x="3" y="16" width="7" height="5" rx="1"></rect></svg>
<p style="margin: 0; font-size: 11px; font-weight: 700; letter-spacing: 0.2em; color: #5e6684;">ADHÉRENTS</p>
<h3 style="margin: 0; font-size: 24px; line-height: 30px; font-weight: 800;">L'Espace Client</h3>
<p style="margin: 0; font-size: 15px; line-height: 24px; color: #141a33; flex: 1;">Le portail unique des associations et micro-entreprises adhérentes : suivez vos ressources, gérez vos abonnements et retrouvez tous vos services au même endroit.</p>
<p style="margin: 0; font-size: 15px; color: #5e6684;">Inclus avec chaque abonnement</p>
<a href="<?php echo gnl_ui_h(GNL_ESPACE_CLIENT_URL); ?>" style="display: inline-flex; align-items: center; justify-content: center; min-height: 44px; border-radius: 3px; border: 1px solid #c5cbd8; color: #141a33; font-weight: 700; font-size: 14px; text-decoration: none;">Se connecter</a>
</article>

<article style="background: #ffffff; border: 1px solid #c5cbd8; border-radius: 3px; padding: 32px; display: flex; flex-direction: column; gap: 16px;">
<svg aria-hidden="true" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#1f7a4a" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M9 3h6"></path><path d="M10 3v6.5L4.5 19a1.5 1.5 0 0 0 1.3 2h12.4a1.5 1.5 0 0 0 1.3-2L14 9.5V3"></path><path d="M7.5 15h9"></path></svg>
<p style="margin: 0; font-size: 11px; font-weight: 700; letter-spacing: 0.2em; color: #5e6684;">ÉTUDIANTS · CHERCHEURS</p>
<h3 style="margin: 0; font-size: 24px; line-height: 30px; font-weight: 800;">Le Laboratoire</h3>
<p style="margin: 0; font-size: 15px; line-height: 24px; color: #141a33; flex: 1;">Un environnement virtualisé, isolé et prêt à l'emploi pour s'exercer à l'informatique et à la cybersécurité : montez vos machines et testez vos scénarios sans risque pour vos systèmes.</p>
<p style="margin: 0; font-size: 15px; color: #5e6684;">En collaboration avec <strong style="color: #141a33;">[ASSOCIATION PARTENAIRE]</strong></p>
<a href="/solutions" style="display: inline-flex; align-items: center; justify-content: center; min-height: 44px; border-radius: 3px; border: 1px solid #c5cbd8; color: #141a33; font-weight: 700; font-size: 14px; text-decoration: none;">Découvrir le Laboratoire</a>
</article>

<button type="button" class="order-card" data-gnl-open="gnl-order" aria-haspopup="dialog" style="min-height: 100%; padding: 32px; border: 2px dashed #5e6684; border-radius: 3px; background: transparent; color: #141a33; display: flex; flex-direction: column; gap: 16px; align-items: center; justify-content: center; font: 800 20px/26px var(--font-display); cursor: pointer;">
<span aria-hidden="true" class="order-plus" style="width: 64px; height: 64px; display: flex; align-items: center; justify-content: center;"><span class="order-fill"></span><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#1f7a4a" stroke-width="2" stroke-linecap="round"><path d="M12 5v14"/><path d="M5 12h14"/></svg></span>
<span class="order-text">Commander un service</span>
<span class="order-text" style="font: 400 14px/20px var(--font-display); color: #5e6684;">Depuis votre Espace Client</span>
</button>

</div>
</div>
</section>

<!-- Impact -->
<section id="impact" style="background: #ffffff; color: #141a33;">
<div style="max-width: 1240px; margin: 0 auto; padding: 96px 24px; display: flex; flex-wrap: wrap; gap: 56px; align-items: center;">
<div style="flex: 1 1 440px; min-width: 0;">
<img src="/assets/gnl/img/brassage.webp" alt="Panneau de brassage et câbles réseau de l'infrastructure GNL Solution" style="display: block; width: 100%; aspect-ratio: 3 / 2; object-fit: cover; border-radius: 3px;">
</div>
<div style="flex: 1 1 480px; min-width: 0;">
<p style="margin: 0 0 12px; font-size: 12px; font-weight: 700; letter-spacing: 0.2em; color: #1f7a4a;">NOTRE IMPACT</p>
<h2 style="margin: 0 0 20px; font-size: 40px; line-height: 46px; font-weight: 800;">Une infrastructure dans le vieux centre de Besançon.</h2>
<p style="margin: 0 0 28px; font-size: 17px; line-height: 27px; color: #5e6684;">Plutôt que de construire, nous réutilisons : nos serveurs sont installés dans un bâtiment existant du centre historique. Des machines petites et sobres, mutualisées, pour une empreinte environnementale minimale.</p>
<ul style="margin: 0 0 32px; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 14px; font-size: 16px; line-height: 24px;">
<li style="display: flex; gap: 12px; align-items: baseline;"><svg aria-hidden="true" width="9" height="16" viewBox="0 0 22 40"><path d="M13 0h9L9 40H0z" fill="#1f7a4a"></path></svg><span><strong>0,17 W</strong> consommés par site hébergé</span></li>
<li style="display: flex; gap: 12px; align-items: baseline;"><svg aria-hidden="true" width="9" height="16" viewBox="0 0 22 40"><path d="M13 0h9L9 40H0z" fill="#1f7a4a"></path></svg><span>Micro-serveurs Raspberry Pi, mutualisés</span></li>
<li style="display: flex; gap: 12px; align-items: baseline;"><svg aria-hidden="true" width="9" height="16" viewBox="0 0 22 40"><path d="M13 0h9L9 40H0z" fill="#1f7a4a"></path></svg><span>Méthode de mesure publiée : [LIEN VERS LA MÉTHODE]</span></li>
</ul>
<a href="/impact#methode" style="color: #1f7a4a; font-weight: 700; font-size: 15px;">Lire notre méthode de mesure →</a>
</div>
</div>
</section>

<!-- Pour qui -->
<section id="qui" style="background: #f4f6fa; color: #141a33;">
<div style="max-width: 1240px; margin: 0 auto; padding: 96px 24px;">
<p style="margin: 0 0 12px; font-size: 12px; font-weight: 700; letter-spacing: 0.2em; color: #1f7a4a;">POUR QUI</p>
<h2 style="margin: 0 0 48px; font-size: 40px; line-height: 46px; font-weight: 800; max-width: 760px;">Un hébergeur de proximité, à taille humaine.</h2>
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 40px;">
<div style="border-top: 2px solid #141a33; padding-top: 20px;">
<h3 style="margin: 0 0 10px; font-size: 20px; line-height: 26px; font-weight: 800;">Associations, TPE, entrepreneurs</h3>
<p style="margin: 0; font-size: 15px; line-height: 24px; color: #5e6684;">Un interlocuteur local, des offres lisibles, un hébergement sécurisé pour votre site et vos outils.</p>
</div>
<div style="border-top: 2px solid #141a33; padding-top: 20px;">
<h3 style="margin: 0 0 10px; font-size: 20px; line-height: 26px; font-weight: 800;">Étudiants et chercheurs</h3>
<p style="margin: 0; font-size: 15px; line-height: 24px; color: #5e6684;">Le Laboratoire, en partenariat avec une association loi 1901, pour apprendre et expérimenter en sécurité.</p>
</div>
<div style="border-top: 2px solid #141a33; padding-top: 20px;">
<h3 style="margin: 0 0 10px; font-size: 20px; line-height: 26px; font-weight: 800;">Bientôt disponible</h3>
<p style="margin: 0; font-size: 15px; line-height: 24px; color: #5e6684;">Nous préparons de nouveaux services. [DÉCRIRE LE PROCHAIN SERVICE EN UNE PHRASE]</p>
</div>
</div>
</div>
</section>

<?php if (!empty($GNL_PARTNERS)): ?>
<!-- Références -->
<section style="background: #ffffff; color: #141a33;">
<div style="max-width: 1240px; margin: 0 auto; padding: 80px 24px;">
<h2 style="margin: 0 0 32px; font-size: 24px; line-height: 30px; font-weight: 800;">Nous avons contribué à leurs projets</h2>
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px;">
<?php gnl_cat_render_partners($GNL_PARTNERS); ?>
</div>
<p style="margin: 16px 0 0; font-size: 12px; color: #5e6684;">Les noms, logos et produits cités restent la propriété exclusive de leurs détenteurs respectifs.</p>
</div>
</section>
<?php endif; ?>

<!-- Appel partenaires -->
<section style="background: #232b52;">
<div style="max-width: 1240px; margin: 0 auto; padding: 64px 24px; display: flex; flex-wrap: wrap; gap: 24px 48px; align-items: center; justify-content: space-between;">
<div style="flex: 1 1 480px; min-width: 0;">
<h2 style="margin: 0 0 10px; font-size: 32px; line-height: 38px; font-weight: 800;">Nous cherchons de nouveaux partenaires.</h2>
<p style="margin: 0; font-size: 17px; line-height: 27px; color: #c5cbd8;">Rejoignez-nous pour développer vos compétences et contribuer à des projets concrets.</p>
</div>
<a href="mailto:gabin.grobost@gnl-solution.com" style="display: inline-flex; align-items: center; min-height: 48px; padding: 0 22px; border-radius: 3px; background: #5cd98b; color: #141a33; font-weight: 700; font-size: 15px; text-decoration: none;">Nous écrire</a>
</div>
</section>
</div>
<!-- Modale de commande -->
<div class="gnl-modal" id="gnl-order" hidden>
<div role="dialog" aria-modal="true" aria-labelledby="titre-commande" style="width: 100%; max-width: 560px; max-height: calc(100vh - 48px); overflow: auto; background: #ffffff; color: #141a33; border-radius: 3px; padding: 36px;">
<div style="display: flex; gap: 16px; align-items: flex-start; justify-content: space-between; margin-bottom: 8px;">
<h2 id="titre-commande" style="margin: 0; font-size: 28px; line-height: 34px; font-weight: 800;">Commander un service</h2>
<button type="button" data-gnl-close aria-label="Fermer" style="flex: none; width: 44px; height: 44px; margin: -8px -8px 0 0; border: 0; border-radius: 3px; background: transparent; color: #141a33; cursor: pointer; display: flex; align-items: center; justify-content: center;"><svg aria-hidden="true" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button>
</div>
<p style="margin: 0 0 24px; font-size: 16px; line-height: 25px; color: #5e6684;">Choisissez votre offre et ses options, ajoutez-la au panier et réglez en ligne. Vous suivez ensuite la mise en service depuis votre Espace Client.</p>
<ul style="margin: 0 0 28px; padding: 0; list-style: none; border: 1px solid #c5cbd8; border-radius: 3px;">
<li style="display: flex; gap: 16px; justify-content: space-between; align-items: baseline; padding: 16px 18px; border-bottom: 1px solid #c5cbd8;"><span><strong style="font-size: 15px;">Hébergement web</strong><br><span style="font-size: 13px; color: #5e6684;">PHP, WordPress, développeur</span></span><span style="font-size: 14px; color: #5e6684; white-space: nowrap;"><?php echo $GNL_MIN_WEB !== '' ? 'dès <strong style="color: #141a33;">' . $GNL_MIN_WEB . '</strong>/mois' : 'sur devis'; ?></span></li>
<li style="display: flex; gap: 16px; justify-content: space-between; align-items: baseline; padding: 16px 18px; border-bottom: 1px solid #c5cbd8;"><span><strong style="font-size: 15px;">Le Laboratoire</strong><br><span style="font-size: 13px; color: #5e6684;">Offre étudiants et chercheurs</span></span><span style="font-size: 14px; color: #5e6684; white-space: nowrap;"><?php echo $GNL_MIN_EDU !== '' ? 'dès <strong style="color: #141a33;">' . $GNL_MIN_EDU . '</strong>/mois' : 'sur devis'; ?></span></li>
<li style="display: flex; gap: 16px; justify-content: space-between; align-items: baseline; padding: 16px 18px;"><span><strong style="font-size: 15px;">Nouveaux services</strong><br><span style="font-size: 13px; color: #5e6684;">Bientôt disponibles</span></span><span style="font-size: 12px; font-weight: 700; letter-spacing: 0.15em; color: #5e6684;">À VENIR</span></li>
</ul>
<div style="display: flex; flex-wrap: wrap; gap: 12px;">
<a href="/solutions" style="flex: 1 1 220px; display: inline-flex; align-items: center; justify-content: center; min-height: 48px; padding: 0 20px; border-radius: 3px; background: #1f7a4a; color: #ffffff; font-weight: 700; font-size: 15px; text-decoration: none;">Choisir mon offre</a>
</div>
<p style="margin: 20px 0 0; font-size: 14px; color: #5e6684;">Une question avant de commander ? <a href="/contact" style="color: #1f7a4a; font-weight: 700;">Contactez-nous</a></p>
</div>
</div>

<?php gnl_ui_close(); ?>
