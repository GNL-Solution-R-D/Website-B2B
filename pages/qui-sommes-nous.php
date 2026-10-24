<?php
/* Qui sommes-nous ? — contenu issu de la maquette validée. */
require_once __DIR__ . '/../include/catalogue.php';
require_once __DIR__ . '/../include/layout.php';
$GNL_PARTNERS = gnl_load_partners();
$gnl_head = gnl_css('qui-sommes-nous');   // assets/gnl/page/qui-sommes-nous.css
gnl_ui_open('Qui sommes-nous ?', 'apropos', array('desc' => 'GNL Solution, hébergeur de proximité né à Besançon : notre histoire, nos valeurs, notre équipe.', 'head' => $gnl_head, 'main' => false));
?>
<div class="gnl-page" style="color: #141a33; background: #f4f6fa;">
<!-- Accroche -->
<section style="background: #141a33; color: #ffffff;">
<div style="max-width: 1240px; margin: 0 auto; padding: 80px 24px; display: flex; flex-wrap: wrap; gap: 48px 64px; align-items: flex-end;">
<div style="flex: 2 1 520px; min-width: 0;">
<p style="margin: 0 0 16px; font-size: 12px; font-weight: 700; letter-spacing: 0.2em; color: #5cd98b;">QUI SOMMES-NOUS ?</p>
<h1 style="margin: 0 0 24px; font-size: clamp(36px, 4.4vw, 58px); line-height: 1.05; font-weight: 800;">Un hébergeur de proximité, né à Besançon.</h1>
<p style="margin: 0; max-width: 620px; font-size: 18px; line-height: 28px; color: #c5cbd8;">GNL Solution héberge les sites et outils des associations, des petites entreprises et des étudiants sur des serveurs sobres, installés dans le vieux centre de Besançon. Une infrastructure locale, des interlocuteurs que l'on connaît, et des chiffres publiés.</p>
</div>
<dl style="flex: 1 1 280px; margin: 0; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1px; background: #32385e; border: 1px solid #32385e;">
<div style="background: #141a33; padding: 20px;"><dt style="font-size: 12px; color: #c5cbd8;">Basé à</dt><dd style="margin: 6px 0 0; font-size: 22px; font-weight: 800;">Besançon</dd></div>
<div style="background: #141a33; padding: 20px;"><dt style="font-size: 12px; color: #c5cbd8;">Depuis</dt><dd style="margin: 6px 0 0; font-size: 22px; font-weight: 800;">2025</dd></div>
<div style="background: #141a33; padding: 20px;"><dt style="font-size: 12px; color: #c5cbd8;">Sites hébergés</dt><dd style="margin: 6px 0 0; font-size: 22px; font-weight: 800;">-</dd></div>
<div style="background: #141a33; padding: 20px;"><dt style="font-size: 12px; color: #c5cbd8;">Par site</dt><dd style="margin: 6px 0 0; font-size: 22px; font-weight: 800;">- W</dd></div>
</dl>
</div>
</section>

<main id="contenu">

<!-- Histoire -->
<section style="background: #ffffff;">
<div style="max-width: 1240px; margin: 0 auto; padding: 96px 24px; display: flex; flex-wrap: wrap; gap: 56px; align-items: center;">
<div style="flex: 1 1 480px; min-width: 0;">
<p style="margin: 0 0 12px; font-size: 12px; font-weight: 700; letter-spacing: 0.2em; color: #1f7a4a;">NOTRE HISTOIRE</p>
<h2 style="margin: 0 0 20px; font-size: 40px; line-height: 46px; font-weight: 800;">Partir d'une question simple.</h2>
<p style="margin: 0 0 16px; font-size: 17px; line-height: 27px; color: #141a33;">Faut-il vraiment un datacenter géant pour héberger le site d'une association ou d'une TPE ? GNL Solution est née de cette question, et d'une conviction : on peut offrir un hébergement fiable et sécurisé avec du matériel sobre, au plus près de ses clients.</p>
<p style="margin: 0 0 16px; font-size: 17px; line-height: 27px; color: #5e6684;">[HISTOIRE : comment le projet a démarré, les premiers clients, ce qui a changé depuis — 2 à 3 phrases.]</p>
<p style="margin: 0; font-size: 17px; line-height: 27px; color: #5e6684;">Aujourd'hui, nous accompagnons aussi des étudiants et des chercheurs avec Le Laboratoire, en collaboration avec une association loi 1901, pour apprendre l'informatique et la cybersécurité dans un environnement réel.</p>
</div>
<div style="flex: 1 1 420px; min-width: 0;">
<img src="/assets/gnl/img/brassage.webp" alt="Panneau de brassage de l'infrastructure GNL Solution à Besançon" style="display: block; width: 100%; aspect-ratio: 4 / 3; object-fit: cover; border-radius: 3px;">
</div>
</div>
</section>

<!-- Valeurs -->
<section style="background: #f4f6fa;">
<div style="max-width: 1240px; margin: 0 auto; padding: 96px 24px;">
<p style="margin: 0 0 12px; font-size: 12px; font-weight: 700; letter-spacing: 0.2em; color: #1f7a4a;">NOS VALEURS</p>
<h2 style="margin: 0 0 48px; font-size: 40px; line-height: 46px; font-weight: 800;">Ce qui guide chacun de nos choix.</h2>
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 40px;">
<article style="border-top: 2px solid #141a33; padding-top: 20px;"><p style="margin: 0 0 10px; display: flex; gap: 12px; align-items: center; font-size: 13px; font-weight: 700; letter-spacing: 0.2em; color: #1f7a4a;"><svg aria-hidden="true" width="10" height="18" viewBox="0 0 22 40"><path d="M13 0h9L9 40H0z" fill="#1f7a4a"></path></svg>01</p><h3 style="margin: 0 0 8px; font-size: 24px; line-height: 30px; font-weight: 800;">Sobre</h3><p style="margin: 0; font-size: 15px; line-height: 24px; color: #5e6684;">Le juste nécessaire : du matériel adapté, des ressources mutualisées, rien de superflu. Sur nos serveurs comme sur ce site.</p></article>
<article style="border-top: 2px solid #141a33; padding-top: 20px;"><p style="margin: 0 0 10px; display: flex; gap: 12px; align-items: center; font-size: 13px; font-weight: 700; letter-spacing: 0.2em; color: #1f7a4a;"><svg aria-hidden="true" width="10" height="18" viewBox="0 0 22 40"><path d="M13 0h9L9 40H0z" fill="#1f7a4a"></path></svg>02</p><h3 style="margin: 0 0 8px; font-size: 24px; line-height: 30px; font-weight: 800;">Fiable</h3><p style="margin: 0; font-size: 15px; line-height: 24px; color: #5e6684;">Des engagements précis et mesurables : supervision continue, état des services publié en temps réel, chiffres accompagnés de leur méthode.</p></article>
<article style="border-top: 2px solid #141a33; padding-top: 20px;"><p style="margin: 0 0 10px; display: flex; gap: 12px; align-items: center; font-size: 13px; font-weight: 700; letter-spacing: 0.2em; color: #1f7a4a;"><svg aria-hidden="true" width="10" height="18" viewBox="0 0 22 40"><path d="M13 0h9L9 40H0z" fill="#1f7a4a"></path></svg>03</p><h3 style="margin: 0 0 8px; font-size: 24px; line-height: 30px; font-weight: 800;">Accessible</h3><p style="margin: 0; font-size: 15px; line-height: 24px; color: #5e6684;">On explique simplement, sans jargon. Des offres lisibles, un interlocuteur joignable à Besançon, des prix adaptés aux petites structures.</p></article>
</div>
</div>
</section>

<!-- Équipe -->
<section style="background: #ffffff;">
<div style="max-width: 1240px; margin: 0 auto; padding: 96px 24px;">
<p style="margin: 0 0 12px; font-size: 12px; font-weight: 700; letter-spacing: 0.2em; color: #1f7a4a;">L'ÉQUIPE</p>
<h2 style="margin: 0 0 48px; font-size: 40px; line-height: 46px; font-weight: 800;">Des personnes, pas un centre d'appels.</h2>
<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 24px;">
<article style="background: #f4f6fa; border: 1px solid #c5cbd8; border-radius: 3px; overflow: hidden;">
<div aria-hidden="true" style="aspect-ratio: 4 / 3; background: #141a33; display: flex; align-items: center; justify-content: center; gap: 10px;"><span style="font-size: 64px; font-weight: 800; color: #ffffff;">GG</span><svg width="28" height="56" viewBox="0 0 22 40"><path d="M13 0h9L9 40H0z" fill="#5cd98b"></path></svg></div>
<div style="padding: 24px;">
<h3 style="margin: 0 0 4px; font-size: 22px; line-height: 28px; font-weight: 800;">Gabin Grobost</h3>
<p style="margin: 0 0 12px; font-size: 14px; font-weight: 700; color: #1f7a4a;">Directeur</p>
<p style="margin: 0 0 16px; font-size: 15px; line-height: 23px; color: #5e6684;">[PRÉSENTATION EN UNE OU DEUX PHRASES : parcours, rôle, ce qui l'anime.]</p>
<p style="margin: 0; display: flex; flex-wrap: wrap; gap: 6px 16px; font-size: 14px; font-weight: 600;"><a href="mailto:gabin.grobost@gnl-solution.com">E-mail</a><a href="https://www.linkedin.com/company/109507423/">LinkedIn</a></p>
</div>
</article>
<article style="border: 2px dashed #5e6684; border-radius: 3px; padding: 32px; display: flex; flex-direction: column; gap: 14px; justify-content: center;">
<h3 style="margin: 0; font-size: 22px; line-height: 28px; font-weight: 800;">Et peut-être vous ?</h3>
<p style="margin: 0; font-size: 15px; line-height: 24px; color: #5e6684;">Étudiant, développeur, passionné de sobriété numérique : nous cherchons de nouveaux partenaires pour développer nos compétences et des projets concrets.</p>
<a href="/contact" style="align-self: flex-start; display: inline-flex; align-items: center; min-height: 44px; padding: 0 18px; border-radius: 3px; border: 1px solid #141a33; color: #141a33; font-weight: 700; font-size: 14px; text-decoration: none;">Nous écrire</a>
</article>
</div>
</div>
</section>

<!-- Open source -->
<section style="background: #141a33; color: #ffffff;">
<div style="max-width: 1240px; margin: 0 auto; padding: 80px 24px; display: flex; flex-wrap: wrap; gap: 32px 64px; align-items: center;">
<div style="flex: 2 1 480px; min-width: 0;">
<p style="margin: 0 0 12px; font-size: 12px; font-weight: 700; letter-spacing: 0.2em; color: #5cd98b;">RECHERCHE ET OPEN SOURCE</p>
<h2 style="margin: 0 0 16px; font-size: 36px; line-height: 42px; font-weight: 800;">Nos outils sont ouverts.</h2>
<p style="margin: 0; max-width: 620px; font-size: 17px; line-height: 27px; color: #c5cbd8;">Nous publions nos travaux de recherche et développement sur GitHub et échangeons avec notre communauté sur Discord. Venez voir comment fonctionne votre hébergeur.</p>
</div>
<div style="flex: 1 1 280px; display: flex; flex-wrap: wrap; gap: 12px;">
<a href="https://github.com/GNL-Solution-R-D" style="display: inline-flex; align-items: center; gap: 10px; min-height: 48px; padding: 0 20px; border-radius: 3px; background: #5cd98b; color: #141a33; font-weight: 700; font-size: 15px; text-decoration: none;"><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 18l6-6-6-6"></path><path d="M8 6l-6 6 6 6"></path></svg>GitHub GNL-Solution-R-D</a>
<a href="https://discord.gg/RKnRsNbQG5" style="display: inline-flex; align-items: center; min-height: 48px; padding: 0 20px; border-radius: 3px; border: 1px solid #5e6684; color: #ffffff; font-weight: 700; font-size: 15px; text-decoration: none;">Rejoindre le Discord</a>
</div>
</div>
</section>

<?php if (!empty($GNL_PARTNERS)): ?>
<!-- Partenaires -->
<section style="background: #ffffff;">
<div style="max-width: 1240px; margin: 0 auto; padding: 80px 24px;">
<h2 style="margin: 0 0 8px; font-size: 28px; line-height: 34px; font-weight: 800;">Ils ont travaillé avec nous</h2>
<p style="margin: 0 0 32px; font-size: 15px; color: #5e6684;">Associations, entreprises et partenaires du territoire.</p>
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px;">
<?php gnl_cat_render_partners($GNL_PARTNERS); ?>
</div>
</div>
</section>
<?php endif; ?>

<!-- Fiche entreprise -->
<section style="background: #f4f6fa;">
<div style="max-width: 1240px; margin: 0 auto; padding: 64px 24px; display: flex; flex-wrap: wrap; gap: 32px; align-items: flex-start; justify-content: space-between;">
<div style="flex: 1 1 300px;">
<h2 style="margin: 0 0 8px; font-size: 24px; line-height: 30px; font-weight: 800;">L'entreprise en bref</h2>
<p style="margin: 0; font-size: 15px; color: #5e6684;">Toutes nos informations légales sont dans les <a href="/cgu">CGU</a>.</p>
</div>
<dl style="flex: 2 1 520px; margin: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px 32px; font-size: 15px; line-height: 22px;">
<div><dt style="font-size: 11px; font-weight: 700; letter-spacing: 0.2em; color: #5e6684;">STATUT</dt><dd style="margin: 4px 0 0;">Entrepreneur individuel (EI)</dd></div>
<div><dt style="font-size: 11px; font-weight: 700; letter-spacing: 0.2em; color: #5e6684;">SIRET</dt><dd style="margin: 4px 0 0;">942 358 805 00011</dd></div>
<div><dt style="font-size: 11px; font-weight: 700; letter-spacing: 0.2em; color: #5e6684;">ADRESSE</dt><dd style="margin: 4px 0 0;">20 rue Gustave Courbet, 25000 Besançon</dd></div>
<div><dt style="font-size: 11px; font-weight: 700; letter-spacing: 0.2em; color: #5e6684;">CONTACT</dt><dd style="margin: 4px 0 0;"><a href="tel:+33365670169">03 65 67 01 69</a></dd></div>
</dl>
</div>
</section>

<!-- Appel -->
<section style="background: #232b52; color: #ffffff;">
<div style="max-width: 1240px; margin: 0 auto; padding: 64px 24px; display: flex; flex-wrap: wrap; gap: 24px 48px; align-items: center; justify-content: space-between;">
<div style="flex: 1 1 480px; min-width: 0;">
<h2 style="margin: 0 0 10px; font-size: 32px; line-height: 38px; font-weight: 800;">Parlons de votre projet.</h2>
<p style="margin: 0; font-size: 17px; line-height: 27px; color: #c5cbd8;">Une question, un besoin particulier ? Nous répondons depuis Besançon.</p>
</div>
<div style="display: flex; flex-wrap: wrap; gap: 12px;">
<a href="/contact" style="display: inline-flex; align-items: center; min-height: 48px; padding: 0 22px; border-radius: 3px; background: #5cd98b; color: #141a33; font-weight: 700; font-size: 15px; text-decoration: none;">Nous contacter</a>
<a href="/solutions" style="display: inline-flex; align-items: center; min-height: 48px; padding: 0 22px; border-radius: 3px; border: 1px solid #5e6684; color: #ffffff; font-weight: 700; font-size: 15px; text-decoration: none;">Voir les offres</a>
</div>
</div>
</section>

</main>
</div>
<?php gnl_ui_close(); ?>
