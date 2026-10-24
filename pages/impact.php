<?php
/* Notre impact — contenu issu de la maquette validée. */
require_once __DIR__ . '/../include/layout.php';
$gnl_head = gnl_css('impact');   // assets/gnl/page/impact.css
gnl_ui_open('Notre impact', 'impact', array('desc' => 'Notre impact : un hébergement sobre à Besançon, des chiffres publiés avec leur méthode.', 'head' => $gnl_head, 'main' => false));
?>
<div class="gnl-page" style="color: #141a33; background: #f4f6fa;">
<!-- Accroche -->
<section style="background: #141a33; color: #ffffff; position: relative; overflow: hidden;">
<div style="max-width: 1240px; margin: 0 auto; padding: 80px 24px 72px; display: flex; flex-wrap: wrap; gap: 56px; align-items: center;">
<div style="flex: 1 1 480px; min-width: 0;">
<p style="margin: 0 0 16px; font-size: 12px; font-weight: 700; letter-spacing: 0.2em; color: #5cd98b;">NOTRE IMPACT</p>
<h1 style="margin: 0 0 24px; font-size: clamp(36px, 4.4vw, 58px); line-height: 1.05; font-weight: 800;">Héberger mieux, en consommant moins.</h1>
<p style="margin: 0 0 32px; max-width: 560px; font-size: 18px; line-height: 28px; color: #c5cbd8;">Pas de datacenter neuf, des machines petites et sobres, des chiffres publiés avec leur méthode. Voici comment nous réduisons l'empreinte de vos sites, et ce que nous mesurons vraiment.</p>
<a href="#methode" style="display: inline-flex; align-items: center; min-height: 48px; padding: 0 22px; border-radius: 3px; background: #5cd98b; color: #141a33; font-weight: 700; font-size: 15px; text-decoration: none;">Lire notre méthode de mesure</a>
</div>
<div style="flex: 1 1 420px; min-width: 0; position: relative;">
<div style="width: 100%; aspect-ratio: 4 / 3; background: #232b52; border-radius: 3px; overflow: hidden;"><svg role="img" aria-label="Feuille stylisée aux couleurs de GNL Solution, symbole d'un hébergement bas carbone" viewBox="0 0 400 300" style="display: block; width: 100%; height: 100%;"><g stroke="#32385e" stroke-width="1"><path d="M0 60H400M0 120H400M0 180H400M0 240H400M80 0V300M160 0V300M240 0V300M320 0V300"></path></g><g transform="translate(205 145) rotate(18)"><path d="M0 100V125" stroke="#5cd98b" stroke-width="9" stroke-linecap="square"></path><path d="M0 -120C85 -60 85 50 0 100C-85 50 -85 -60 0 -120Z" fill="#5cd98b"></path><path d="M0 -96V100" stroke="#141a33" stroke-width="7"></path><path d="M0 -55L34 -85" stroke="#141a33" stroke-width="5" stroke-linecap="butt"></path><path d="M0 -55L-34 -85" stroke="#141a33" stroke-width="5" stroke-linecap="butt"></path><path d="M0 -10L34 -40" stroke="#141a33" stroke-width="5" stroke-linecap="butt"></path><path d="M0 -10L-34 -40" stroke="#141a33" stroke-width="5" stroke-linecap="butt"></path><path d="M0 35L34 5" stroke="#141a33" stroke-width="5" stroke-linecap="butt"></path><path d="M0 35L-34 5" stroke="#141a33" stroke-width="5" stroke-linecap="butt"></path></g></svg></div>
<svg aria-hidden="true" viewBox="0 0 150 90" style="position: absolute; left: -10px; bottom: -28px; width: 150px; height: 90px;"><path d="M38 0h11L18 90H7z" fill="#5cd98b"></path><path d="M72 0h11L52 90H41z" fill="#5cd98b"></path><path d="M106 0h11L86 90H75z" fill="#5cd98b"></path></svg>
</div>
</div>
</section>

<main id="contenu">

<!-- Chiffres -->
<section style="background: #141a33; border-top: 1px solid #32385e;">
<div style="max-width: 1240px; margin: 0 auto; padding: 0 24px 80px;">
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1px; background: #32385e; border: 1px solid #32385e;">
<div style="background: #141a33; padding: 28px;"><p style="margin: 0; font-size: 44px; line-height: 1; font-weight: 800; color: #ffffff;">0,17 W</p><p style="margin: 10px 0 0; font-size: 14px; line-height: 20px; color: #c5cbd8;">consommés en moyenne par site hébergé</p></div>
<div style="background: #141a33; padding: 28px;"><p style="margin: 0; font-size: 44px; line-height: 1; font-weight: 800; color: #ffffff;">[X] kWh</p><p style="margin: 10px 0 0; font-size: 14px; line-height: 20px; color: #c5cbd8;">consommés par toute l'infrastructure sur un an</p></div>
<div style="background: #141a33; padding: 28px;"><p style="margin: 0; font-size: 44px; line-height: 1; font-weight: 800; color: #ffffff;">[X] g</p><p style="margin: 10px 0 0; font-size: 14px; line-height: 20px; color: #c5cbd8;">de CO₂e par site et par an, électricité seule</p></div>
<div style="background: #141a33; padding: 28px;"><p style="margin: 0; font-size: 44px; line-height: 1; font-weight: 800; color: #ffffff;">0</p><p style="margin: 10px 0 0; font-size: 14px; line-height: 20px; color: #c5cbd8;">datacenter neuf construit pour nous</p></div>
</div>
<p style="margin: 16px 0 0; font-size: 13px; color: #c5cbd8;">Chiffres mesurés sur [PÉRIODE]. Détail du calcul dans la <a href="#methode" style="color: #5cd98b;">méthode</a> ci-dessous.</p>
</div>
</section>

<!-- Trois leviers -->
<section style="background: #f4f6fa;">
<div style="max-width: 1240px; margin: 0 auto; padding: 96px 24px;">
<p style="margin: 0 0 12px; font-size: 12px; font-weight: 700; letter-spacing: 0.2em; color: #1f7a4a;">COMMENT C'EST POSSIBLE</p>
<h2 style="margin: 0 0 48px; max-width: 760px; font-size: 40px; line-height: 46px; font-weight: 800;">Trois leviers, appliqués à chaque site.</h2>
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 24px;">
<article style="background: #ffffff; border: 1px solid #c5cbd8; border-radius: 3px; padding: 28px; display: flex; flex-direction: column; gap: 12px;"><p style="margin: 0; font-size: 44px; line-height: 1; font-weight: 800; color: #1f7a4a;">01</p><h3 style="margin: 0; font-size: 20px; line-height: 26px; font-weight: 800;">Réutiliser plutôt que construire</h3><p style="margin: 0; font-size: 15px; line-height: 24px; color: #5e6684;">Nos serveurs sont installés dans un bâtiment existant du vieux centre de Besançon. Aucune construction, aucun terrain artificialisé.</p></article>
<article style="background: #ffffff; border: 1px solid #c5cbd8; border-radius: 3px; padding: 28px; display: flex; flex-direction: column; gap: 12px;"><p style="margin: 0; font-size: 44px; line-height: 1; font-weight: 800; color: #1f7a4a;">02</p><h3 style="margin: 0; font-size: 20px; line-height: 26px; font-weight: 800;">Du matériel sobre</h3><p style="margin: 0; font-size: 15px; line-height: 24px; color: #5e6684;">Des micro-serveurs Raspberry Pi qui consomment quelques watts chacun, là où un serveur classique en consomme beaucoup plus. [AJOUTER UNE RÉFÉRENCE SOURCÉE]</p></article>
<article style="background: #ffffff; border: 1px solid #c5cbd8; border-radius: 3px; padding: 28px; display: flex; flex-direction: column; gap: 12px;"><p style="margin: 0; font-size: 44px; line-height: 1; font-weight: 800; color: #1f7a4a;">03</p><h3 style="margin: 0; font-size: 20px; line-height: 26px; font-weight: 800;">Mutualiser au plus juste</h3><p style="margin: 0; font-size: 15px; line-height: 24px; color: #5e6684;">Plusieurs sites partagent une même machine, dimensionnée selon leurs besoins réels. Moins de ressources inutilisées, moins d'énergie gaspillée.</p></article>
</div>
</div>
</section>

<!-- Local -->
<section style="background: #ffffff;">
<div style="max-width: 1240px; margin: 0 auto; padding: 96px 24px; display: flex; flex-wrap: wrap; gap: 56px; align-items: center;">
<div style="flex: 1 1 440px; min-width: 0;">
<img src="/assets/gnl/img/brassage.webp" alt="Panneau de brassage et câbles réseau de l'infrastructure GNL Solution" style="display: block; width: 100%; aspect-ratio: 3 / 2; object-fit: cover; border-radius: 3px;">
</div>
<div style="flex: 1 1 480px; min-width: 0;">
<p style="margin: 0 0 12px; font-size: 12px; font-weight: 700; letter-spacing: 0.2em; color: #1f7a4a;">LOCAL ET SOUVERAIN</p>
<h2 style="margin: 0 0 20px; font-size: 36px; line-height: 42px; font-weight: 800;">Vos données restent à Besançon.</h2>
<p style="margin: 0 0 24px; font-size: 17px; line-height: 27px; color: #5e6684;">Héberger près de chez vous, c'est aussi moins d'intermédiaires, un interlocuteur que vous pouvez rencontrer et des données qui ne quittent pas l'Union européenne.</p>
<ul style="margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 12px; font-size: 16px; line-height: 24px;">
<li style="display: flex; gap: 12px; align-items: baseline;"><svg aria-hidden="true" width="9" height="16" viewBox="0 0 22 40"><path d="M13 0h9L9 40H0z" fill="#1f7a4a"></path></svg><span>Hébergement 100 % UE, conforme au RGPD</span></li>
<li style="display: flex; gap: 12px; align-items: baseline;"><svg aria-hidden="true" width="9" height="16" viewBox="0 0 22 40"><path d="M13 0h9L9 40H0z" fill="#1f7a4a"></path></svg><span>Électricité : [FOURNISSEUR / CONTRAT, ex. offre verte certifiée]</span></li>
<li style="display: flex; gap: 12px; align-items: baseline;"><svg aria-hidden="true" width="9" height="16" viewBox="0 0 22 40"><path d="M13 0h9L9 40H0z" fill="#1f7a4a"></path></svg><span>Matériel : [POLITIQUE DE RÉEMPLOI / RECYCLAGE]</span></li>
</ul>
</div>
</div>
</section>

<!-- Méthode -->
<section id="methode" style="background: #f4f6fa; scroll-margin-top: 24px;">
<div style="max-width: 1040px; margin: 0 auto; padding: 96px 24px;">
<p style="margin: 0 0 12px; font-size: 12px; font-weight: 700; letter-spacing: 0.2em; color: #1f7a4a;">TRANSPARENCE</p>
<h2 style="margin: 0 0 16px; font-size: 40px; line-height: 46px; font-weight: 800;">Notre méthode de mesure</h2>
<p style="margin: 0 0 40px; max-width: 720px; font-size: 17px; line-height: 27px; color: #5e6684;">Un chiffre sans méthode ne vaut rien. Voici exactement comment nous obtenons nos résultats, pour que vous puissiez les vérifier.</p>
<ol style="margin: 0 0 40px; padding: 0; list-style: none; counter-reset: st; display: flex; flex-direction: column; gap: 24px;">
<li class="step" style="display: flex; gap: 18px; align-items: flex-start;"><div><h3 style="margin: 6px 0 6px; font-size: 17px; line-height: 24px; font-weight: 800;">Mesurer la consommation réelle</h3><p style="margin: 0; font-size: 15px; line-height: 24px; color: #5e6684;">Un wattmètre [MODÈLE] mesure en continu la consommation électrique de toute la baie (serveurs, réseau, alimentation), relevée toutes les [FRÉQUENCE].</p></div></li>
<li class="step" style="display: flex; gap: 18px; align-items: flex-start;"><div><h3 style="margin: 6px 0 6px; font-size: 17px; line-height: 24px; font-weight: 800;">Diviser par le nombre de sites</h3><p style="margin: 0; font-size: 15px; line-height: 24px; color: #5e6684;">La consommation moyenne sur [PÉRIODE] est divisée par le nombre de sites actifs sur la même période : c'est le chiffre de 0,17 W par site.</p></div></li>
<li class="step" style="display: flex; gap: 18px; align-items: flex-start;"><div><h3 style="margin: 6px 0 6px; font-size: 17px; line-height: 24px; font-weight: 800;">Convertir en émissions</h3><p style="margin: 0; font-size: 15px; line-height: 24px; color: #5e6684;">Les kWh consommés sont multipliés par le facteur d'émission du mix électrique français ([X] gCO₂e/kWh, source : [ADEME Base Empreinte, année]).</p></div></li>
<li class="step" style="display: flex; gap: 18px; align-items: flex-start;"><div><h3 style="margin: 6px 0 6px; font-size: 17px; line-height: 24px; font-weight: 800;">Publier et mettre à jour</h3><p style="margin: 0; font-size: 15px; line-height: 24px; color: #5e6684;">Les résultats sont mis à jour [FRÉQUENCE DE PUBLICATION] et l'historique reste consultable sur cette page.</p></div></li>
</ol>
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px;">
<div style="background: #ffffff; border: 1px solid #c5cbd8; border-radius: 3px; padding: 24px 28px;">
<h3 style="margin: 0 0 12px; font-size: 17px; font-weight: 800;">Ce que le calcul inclut</h3>
<ul style="margin: 0; padding-left: 20px; font-size: 15px; line-height: 24px; color: #5e6684;"><li>Les serveurs et leur alimentation</li><li>Les équipements réseau de la baie</li><li>[REFROIDISSEMENT / AUTRES SI APPLICABLE]</li></ul>
</div>
<div style="background: #ffffff; border: 1px solid #c5cbd8; border-radius: 3px; padding: 24px 28px;">
<h3 style="margin: 0 0 12px; font-size: 17px; font-weight: 800;">Ce qu'il n'inclut pas (encore)</h3>
<ul style="margin: 0; padding-left: 20px; font-size: 15px; line-height: 24px; color: #5e6684;"><li>La fabrication du matériel</li><li>Le réseau internet jusqu'à vos visiteurs</li><li>Les ordinateurs et téléphones de vos visiteurs</li></ul>
</div>
</div>
</div>
</section>

<!-- Ce que nous ne disons pas -->
<section style="background: #ffffff;">
<div style="max-width: 1040px; margin: 0 auto; padding: 80px 24px;">
<div style="border: 2px dashed #5e6684; border-radius: 3px; padding: 36px; display: flex; flex-wrap: wrap; gap: 24px 48px;">
<div style="flex: 1 1 300px; min-width: 0;">
<h2 style="margin: 0 0 12px; font-size: 28px; line-height: 34px; font-weight: 800;">Ce que nous ne prétendons pas</h2>
<p style="margin: 0; font-size: 15px; line-height: 24px; color: #5e6684;">Un hébergement a toujours un impact. Nous préférons le réduire et le mesurer que l'effacer avec des formules.</p>
</div>
<ul style="flex: 1 1 360px; margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 12px; font-size: 16px; line-height: 24px;">
<li style="display: flex; gap: 12px; align-items: baseline;"><svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#b42318" stroke-width="2.5" stroke-linecap="round"><path d="M18 6 6 18"></path><path d="m6 6 12 12"></path></svg><span>Nous ne sommes pas « neutres en carbone » ni « zéro émission ».</span></li>
<li style="display: flex; gap: 12px; align-items: baseline;"><svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#b42318" stroke-width="2.5" stroke-linecap="round"><path d="M18 6 6 18"></path><path d="m6 6 12 12"></path></svg><span>Nous ne compensons pas nos émissions par l'achat de crédits carbone.</span></li>
<li style="display: flex; gap: 12px; align-items: baseline;"><svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#b42318" stroke-width="2.5" stroke-linecap="round"><path d="M18 6 6 18"></path><path d="m6 6 12 12"></path></svg><span>Nous ne comparons pas nos chiffres à ceux d'autres hébergeurs sans source vérifiable.</span></li>
</ul>
</div>
</div>
</section>

<!-- Éco-conception -->
<section style="background: #f4f6fa;">
<div style="max-width: 1240px; margin: 0 auto; padding: 96px 24px;">
<p style="margin: 0 0 12px; font-size: 12px; font-weight: 700; letter-spacing: 0.2em; color: #1f7a4a;">ÉCO-CONCEPTION</p>
<h2 style="margin: 0 0 16px; max-width: 760px; font-size: 36px; line-height: 42px; font-weight: 800;">Ce site aussi est sobre.</h2>
<p style="margin: 0 0 40px; max-width: 680px; font-size: 17px; line-height: 27px; color: #5e6684;">Nous appliquons à notre propre site ce que nous recommandons à nos clients.</p>
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 32px;">
<div style="border-top: 2px solid #141a33; padding-top: 16px;"><p style="margin: 0 0 6px; font-size: 28px; font-weight: 800;">1</p><p style="margin: 0; font-size: 14px; line-height: 22px; color: #5e6684;">seule police, hébergée chez nous</p></div>
<div style="border-top: 2px solid #141a33; padding-top: 16px;"><p style="margin: 0 0 6px; font-size: 28px; font-weight: 800;">~40 Ko</p><p style="margin: 0; font-size: 14px; line-height: 22px; color: #5e6684;">par photo, au format WebP</p></div>
<div style="border-top: 2px solid #141a33; padding-top: 16px;"><p style="margin: 0 0 6px; font-size: 28px; font-weight: 800;">0</p><p style="margin: 0; font-size: 14px; line-height: 22px; color: #5e6684;">vidéo en lecture automatique, carrousel ou traceur publicitaire</p></div>
<div style="border-top: 2px solid #141a33; padding-top: 16px;"><p style="margin: 0 0 6px; font-size: 28px; font-weight: 800;">[NOTE]</p><p style="margin: 0; font-size: 14px; line-height: 22px; color: #5e6684;">EcoIndex de la page d'accueil</p></div>
</div>
</div>
</section>

<!-- Appel -->
<section style="background: #232b52; color: #ffffff;">
<div style="max-width: 1240px; margin: 0 auto; padding: 64px 24px; display: flex; flex-wrap: wrap; gap: 24px 48px; align-items: center; justify-content: space-between;">
<div style="flex: 1 1 480px; min-width: 0;">
<h2 style="margin: 0 0 10px; font-size: 32px; line-height: 38px; font-weight: 800;">Hébergez votre site sobrement.</h2>
<p style="margin: 0; font-size: 17px; line-height: 27px; color: #c5cbd8;">PHP, WordPress, projet de développeur ou Laboratoire : trouvez l'offre adaptée.</p>
</div>
<a href="/solutions" style="display: inline-flex; align-items: center; min-height: 48px; padding: 0 22px; border-radius: 3px; background: #5cd98b; color: #141a33; font-weight: 700; font-size: 15px; text-decoration: none;">Voir les offres</a>
</div>
</section>

</main>
</div>
<?php gnl_ui_close(); ?>
