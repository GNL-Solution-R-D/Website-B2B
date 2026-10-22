<?php
/* Conditions générales d'utilisation — contenu issu de la maquette validée. */
require_once __DIR__ . '/../include/layout.php';
$gnl_head = <<<'GNLHEAD'
<style>
a{color:#1f7a4a}a:hover{color:#141a33}
.lg h2{font-size:24px;line-height:30px;font-weight:800;margin:48px 0 14px;scroll-margin-top:24px}
.lg h3{font-size:17px;line-height:24px;font-weight:700;margin:24px 0 8px}
.lg p,.lg li{font-size:16px;line-height:26px}
.lg p{margin:0 0 14px}
.lg ul{margin:0 0 14px;padding-left:22px}
.lg li{margin-bottom:6px}
.lg .n{color:#1f7a4a;margin-right:10px}
.toc a{display:block;padding:7px 0 7px 14px;border-left:2px solid #c5cbd8;color:#5e6684;text-decoration:none;font-size:14px;line-height:20px}
.toc a:hover{color:#141a33;border-left-color:#1f7a4a}
</style>
GNLHEAD;
gnl_ui_open("Conditions générales d'utilisation", '', array('desc' => "Conditions générales d'utilisation du site GNL Solution.", 'head' => $gnl_head));
?>
<div class="gnl-page" style="color: #141a33; background: #f4f6fa;">
<!-- Titre -->
<div style="background: #ffffff; border-bottom: 1px solid #c5cbd8;">
<div style="max-width: 1240px; margin: 0 auto; padding: 56px 24px 0;">
<p style="margin: 0 0 12px; font-size: 12px; font-weight: 700; letter-spacing: 0.2em; color: #1f7a4a;">INFORMATIONS LÉGALES</p>
<h1 style="margin: 0 0 12px; font-size: 44px; line-height: 50px; font-weight: 800;">Conditions générales d'utilisation</h1>
<p style="margin: 0 0 32px; font-size: 15px; color: #5e6684;">Dernière mise à jour : [DATE DE MISE EN LIGNE]</p>
<nav aria-label="Documents légaux" style="display: flex; flex-wrap: wrap; gap: 4px 28px; font-size: 15px; font-weight: 700;">
<a href="/cgu" aria-current="page" style="padding: 0 0 14px; color: #141a33; text-decoration: none; border-bottom: 3px solid #1f7a4a;">CGU</a>
<a href="/cgv" style="padding: 0 0 14px; color: #5e6684; text-decoration: none;">CGV</a>
<a href="/politique-de-confidentialite" style="padding: 0 0 14px; color: #5e6684; text-decoration: none;">Politique de confidentialité</a>
</nav>
</div>
</div>

<div style="max-width: 1240px; margin: 0 auto; padding: 48px 24px 96px; display: flex; flex-wrap: wrap; gap: 48px; align-items: flex-start;">

<!-- Sommaire -->
<nav class="toc" aria-label="Sommaire" style="flex: 1 1 240px; max-width: 300px;">
<p style="margin: 0 0 12px; font-size: 11px; font-weight: 700; letter-spacing: 0.2em; color: #5e6684;">SOMMAIRE</p>
<a href="#objet">1. Objet</a>
<a href="#mentions">2. Mentions légales</a>
<a href="#acceptation">3. Acceptation</a>
<a href="#acces">4. Accès au site</a>
<a href="#compte">5. Compte et Espace Client</a>
<a href="#pi">6. Propriété intellectuelle</a>
<a href="#obligations">7. Obligations de l'utilisateur</a>
<a href="#signalement">8. Signalement de contenus illicites</a>
<a href="#liens">9. Liens hypertextes</a>
<a href="#donnees">10. Données personnelles et cookies</a>
<a href="#responsabilite">11. Responsabilité</a>
<a href="#modification">12. Modification des CGU</a>
<a href="#droit">13. Droit applicable</a>
</nav>

<!-- Contenu -->
<article class="lg" style="flex: 999 1 560px; min-width: 0; max-width: 760px; background: #ffffff; border: 1px solid #c5cbd8; border-radius: 3px; padding: 8px 48px 40px;">

<h2 id="objet"><span class="n">1.</span>Objet</h2>
<p>Les présentes conditions générales d'utilisation (les « CGU ») définissent les conditions dans lesquelles toute personne (l'« Utilisateur ») accède au site GNL Solution, accessible à l'adresse gnl-solution.com (le « Site »), et l'utilise.</p>
<p>Elles s'appliquent sans préjudice des <a href="/cgv">conditions générales de vente</a>, qui régissent les services souscrits depuis l'Espace Client.</p>

<h2 id="mentions"><span class="n">2.</span>Mentions légales</h2>
<h3>Éditeur du Site</h3>
<p>Gabin Grobost, entrepreneur individuel (EI), exerçant sous le nom commercial GNL Solution<br>20 rue Gustave Courbet, 25000 Besançon, France<br>SIREN 942 358 805 · SIRET 942 358 805 00011 · [RCS / RNE : À VÉRIFIER]<br>TVA non applicable, article 293 B du CGI<br>E-mail : contact@gnl-solution.com · Téléphone : 03 65 67 01 69</p>
<p>Directeur de la publication : Gabin Grobost.</p>
<h3>Hébergeur du Site</h3>
<p>Le Site est hébergé par GNL Solution sur sa propre infrastructure : 20 rue Gustave Courbet, 25000 Besançon, France · 03 65 67 01 69.</p>

<h2 id="acceptation"><span class="n">3.</span>Acceptation des conditions</h2>
<p>L'utilisation du Site implique l'acceptation pleine et entière des présentes CGU. L'Utilisateur qui ne les accepte pas doit renoncer à utiliser le Site.</p>
<p>GNL Solution peut refuser l'accès au Site à tout Utilisateur qui ne respecte pas les présentes CGU.</p>

<h2 id="acces"><span class="n">4.</span>Accès au site</h2>
<p>Le Site est accessible gratuitement, 7 jours sur 7 et 24 heures sur 24, sauf interruption pour maintenance ou cas de force majeure. Les frais de matériel et de connexion restent à la charge de l'Utilisateur.</p>
<p>L'état de disponibilité de nos services est publié en temps réel sur la page <a href="/stats">État des services</a>.</p>

<h2 id="compte"><span class="n">5.</span>Compte et Espace Client</h2>
<p>La souscription et la gestion des services se font depuis l'Espace Client, qui nécessite la création d'un compte. L'Utilisateur s'engage à fournir des informations exactes et à les tenir à jour.</p>
<p>Il est seul responsable de la confidentialité de ses identifiants et de l'activité réalisée depuis son compte. Il prévient GNL Solution sans délai de toute utilisation non autorisée.</p>
<p>L'Utilisateur peut demander la suppression de son compte à tout moment depuis son Espace Client ou à contact@gnl-solution.com, sous réserve des services en cours.</p>

<h2 id="pi"><span class="n">6.</span>Propriété intellectuelle</h2>
<p>Les éléments du Site (textes, logos, marque GNL Solution, graphismes, photographies, logiciels) sont protégés par le droit de la propriété intellectuelle et restent la propriété de GNL Solution ou de ses partenaires.</p>
<p>Toute reproduction ou exploitation sans autorisation écrite préalable est interdite et constitue une contrefaçon (articles L.335-2 et suivants du Code de la propriété intellectuelle). Les codes publiés sur notre <a href="https://github.com/GNL-Solution-R-D">GitHub</a> restent soumis à leur propre licence.</p>

<h2 id="obligations"><span class="n">7.</span>Obligations de l'utilisateur</h2>
<p>L'Utilisateur s'engage à utiliser le Site conformément à la loi et aux présentes CGU. Il s'interdit notamment de :</p>
<ul>
<li>utiliser le Site ou nos services à des fins illégales ou frauduleuses ;</li>
<li>porter atteinte au fonctionnement, à la sécurité ou à l'intégrité du Site et de notre infrastructure ;</li>
<li>extraire des données du Site par des procédés automatisés non autorisés ;</li>
<li>diffuser des contenus illicites, haineux, diffamatoires ou portant atteinte aux droits de tiers ;</li>
<li>usurper l'identité d'un tiers.</li>
</ul>

<h2 id="signalement"><span class="n">8.</span>Signalement de contenus illicites</h2>
<p>En tant qu'hébergeur, GNL Solution met à disposition un dispositif permettant à toute personne de signaler un contenu illicite hébergé sur son infrastructure : <a href="/incident">Signaler un incident › Contenu illicite</a>.</p>
<p>Chaque notification fait l'objet d'un accusé de réception, d'un examen diligent et d'une décision motivée communiquée à son auteur. Le fait de présenter sciemment un contenu comme illicite en sachant cette information inexacte est puni d'un an d'emprisonnement et de 15 000 € d'amende.</p>

<h2 id="liens"><span class="n">9.</span>Liens hypertextes</h2>
<p>Le Site peut contenir des liens vers des sites tiers, sur lesquels GNL Solution n'exerce aucun contrôle et dont il ne saurait être responsable.</p>

<h2 id="donnees"><span class="n">10.</span>Données personnelles et cookies</h2>
<p>Les données personnelles collectées sur le Site sont traitées conformément à notre <a href="/politique-de-confidentialite">politique de confidentialité</a>, qui décrit aussi l'usage des cookies.</p>

<h2 id="responsabilite"><span class="n">11.</span>Responsabilité</h2>
<p>GNL Solution s'efforce de diffuser des informations exactes et à jour, sans pouvoir en garantir l'exhaustivité. Sa responsabilité ne saurait être engagée pour les dommages résultant de l'utilisation du Site, de son indisponibilité ou de l'usage des informations qui y figurent, hors faute de sa part.</p>

<h2 id="modification"><span class="n">12.</span>Modification des CGU</h2>
<p>GNL Solution peut modifier les présentes CGU pour les adapter à l'évolution du Site ou de la réglementation. La version applicable est celle en vigueur lors de l'utilisation du Site.</p>

<h2 id="droit"><span class="n">13.</span>Droit applicable et litiges</h2>
<p>Les présentes CGU sont soumises au droit français. En cas de litige, une solution amiable sera recherchée en priorité ; à défaut, les tribunaux français compétents seront saisis.</p>

</article>
</div>
</div>
<?php gnl_ui_close(); ?>
