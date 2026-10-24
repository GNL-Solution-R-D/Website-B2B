<?php
/* Conditions générales de vente — contenu issu de la maquette validée. */
require_once __DIR__ . '/../include/layout.php';
$gnl_head = gnl_css('legal');   // assets/gnl/page/legal.css
gnl_ui_open('Conditions générales de vente', '', array('desc' => 'Conditions générales de vente des services GNL Solution.', 'head' => $gnl_head));
?>
<div class="gnl-page" style="color: #141a33; background: #f4f6fa;">
<!-- Titre -->
<div style="background: #ffffff; border-bottom: 1px solid #c5cbd8;">
<div style="max-width: 1240px; margin: 0 auto; padding: 56px 24px 0;">
<p style="margin: 0 0 12px; font-size: 12px; font-weight: 700; letter-spacing: 0.2em; color: #1f7a4a;">INFORMATIONS LÉGALES</p>
<h1 style="margin: 0 0 12px; font-size: 44px; line-height: 50px; font-weight: 800;">Conditions générales de vente</h1>
<p style="margin: 0 0 32px; font-size: 15px; color: #5e6684;">Dernière mise à jour : [DATE DE MISE EN LIGNE]</p>
<nav aria-label="Documents légaux" style="display: flex; flex-wrap: wrap; gap: 4px 28px; font-size: 15px; font-weight: 700;">
<a href="/cgu" style="padding: 0 0 14px; color: #5e6684; text-decoration: none;">CGU</a>
<a href="/cgv" aria-current="page" style="padding: 0 0 14px; color: #141a33; text-decoration: none; border-bottom: 3px solid #1f7a4a;">CGV</a>
<a href="/politique-de-confidentialite" style="padding: 0 0 14px; color: #5e6684; text-decoration: none;">Politique de confidentialité</a>
</nav>
</div>
</div>

<div style="max-width: 1240px; margin: 0 auto; padding: 48px 24px 96px; display: flex; flex-wrap: wrap; gap: 48px; align-items: flex-start;">

<!-- Sommaire -->
<nav class="toc" aria-label="Sommaire" style="flex: 1 1 240px; max-width: 300px;">
<p style="margin: 0 0 12px; font-size: 11px; font-weight: 700; letter-spacing: 0.2em; color: #5e6684;">SOMMAIRE</p>
<a href="#objet">1. Objet et champ d'application</a>
<a href="#prestataire">2. Identité du prestataire</a>
<a href="#services">3. Services proposés</a>
<a href="#commande">4. Commande depuis l'Espace Client</a>
<a href="#prix">5. Prix et paiement</a>
<a href="#duree">6. Durée, renouvellement et résiliation</a>
<a href="#mise">7. Mise en service et disponibilité</a>
<a href="#retractation">8. Droit de rétractation</a>
<a href="#usage">9. Usage acceptable et suspension</a>
<a href="#donnees-client">10. Données hébergées</a>
<a href="#fin">11. Fin de contrat et récupération des données</a>
<a href="#responsabilite">12. Responsabilité</a>
<a href="#force">13. Force majeure</a>
<a href="#litiges">14. Réclamations et médiation</a>
<a href="#droit">15. Droit applicable</a>
</nav>

<!-- Contenu -->
<article class="lg" style="flex: 999 1 560px; min-width: 0; max-width: 760px; background: #ffffff; border: 1px solid #c5cbd8; border-radius: 3px; padding: 8px 48px 40px;">
<h2 id="objet"><span class="n">1.</span>Objet et champ d'application</h2>
<p>Les présentes conditions générales de vente (les « CGV ») régissent la fourniture des services de GNL Solution (le « Prestataire ») à toute personne physique ou morale qui les souscrit (le « Client ») : associations, entreprises, entrepreneurs, étudiants et chercheurs.</p>
<p>Toute commande implique l'acceptation sans réserve des CGV en vigueur à la date de la commande. Certaines dispositions ne s'appliquent qu'aux Clients consommateurs ou non-professionnels ; elles sont signalées comme telles.</p>
<h2 id="prestataire"><span class="n">2.</span>Identité du prestataire</h2>
<p>Gabin Grobost, entrepreneur individuel (EI), exerçant sous le nom commercial GNL Solution<br>20 rue Gustave Courbet, 25000 Besançon, France<br>SIREN 942 358 805 · SIRET 942 358 805 00011<br>TVA non applicable, article 293 B du CGI<br>E-mail : contact@gnl-solution.com · Téléphone : 03 65 67 01 69</p>
<h2 id="services"><span class="n">3.</span>Services proposés</h2>
<p>Le Prestataire propose notamment :</p>
<ul><li><strong>Hébergement web</strong> : hébergement mutualisé de sites et d'applications (PHP, WordPress, offres développeur) sur l'infrastructure du Prestataire à Besançon ;</li><li><strong>Le Laboratoire</strong> : environnements virtualisés isolés destinés à l'apprentissage et à l'expérimentation en informatique et cybersécurité, proposés en collaboration avec [ASSOCIATION PARTENAIRE] ;</li><li><strong>L'Espace Client</strong> : portail de gestion des services, inclus dans tout abonnement.</li></ul>
<p>Les caractéristiques de chaque offre (ressources, options, limites) sont décrites dans l'Espace Client au moment de la commande.</p>
<h2 id="commande"><span class="n">4.</span>Commande depuis l'Espace Client</h2>
<p>Toute commande se fait depuis l'Espace Client. Le Client choisit son service, vérifie le récapitulatif et le prix, corrige d'éventuelles erreurs, puis valide et paie. Un e-mail de confirmation récapitule la commande.</p>
<p>Le Prestataire peut refuser une commande en cas de litige antérieur, d'informations manifestement erronées ou d'usage envisagé contraire à l'article « Usage acceptable ».</p>
<h2 id="prix"><span class="n">5.</span>Prix et paiement</h2>
<p>Les prix sont indiqués en euros. TVA non applicable, article 293 B du CGI : le montant affiché est le montant payé.</p>
<p>Les abonnements sont payables d'avance, par période [MENSUELLE / ANNUELLE], par carte bancaire ou par les moyens proposés dans l'Espace Client, via notre prestataire de paiement sécurisé [Mollie]. Le Prestataire n'a jamais accès aux données bancaires du Client. Les factures sont disponibles dans l'Espace Client.</p>
<p>Toute modification de prix est notifiée au Client au moins [30] jours avant son application ; le Client peut alors résilier sans frais.</p>
<h2 id="duree"><span class="n">6.</span>Durée, renouvellement et résiliation</h2>
<p>Les abonnements sont conclus pour la période choisie et se renouvellent tacitement pour une durée identique, sauf résiliation.</p>
<p>Le Client peut résilier à tout moment depuis son Espace Client ; la résiliation prend effet à la fin de la période en cours, déjà payée. Pour les Clients consommateurs, le Prestataire rappelle par écrit la possibilité de ne pas reconduire un contrat annuel, conformément à l'article L.215-1 du Code de la consommation.</p>
<h2 id="mise"><span class="n">7.</span>Mise en service et disponibilité</h2>
<p>Le service est mis en service dans un délai de [DÉLAI DE MISE EN SERVICE] après confirmation du paiement.</p>
<p>Le Prestataire met en œuvre les moyens nécessaires pour assurer une disponibilité de [TAUX DE DISPONIBILITÉ] % par mois, hors maintenances planifiées annoncées sur la page <a href="/stats">État des services</a>. Il s'agit d'une obligation de moyens. Des sauvegardes sont réalisées [FRÉQUENCE] et conservées [DURÉE] ; elles ne dispensent pas le Client de conserver ses propres copies.</p>
<h2 id="retractation"><span class="n">8.</span>Droit de rétractation</h2>
<p><em>Clients consommateurs uniquement.</em> Le Client dispose de quatorze (14) jours à compter de la souscription pour se rétracter, sans motif, par une déclaration dénuée d'ambiguïté envoyée à contact@gnl-solution.com ou depuis l'Espace Client (articles L.221-18 et suivants du Code de la consommation).</p>
<p>Si le Client demande expressément que le service commence avant la fin de ce délai, il reste redevable du montant correspondant au service fourni jusqu'à sa rétractation (article L.221-25). Le remboursement intervient sous quatorze (14) jours, par le même moyen de paiement.</p>
<p><strong>Modèle de formulaire de rétractation</strong> — À l'attention de GNL Solution, 20 rue Gustave Courbet, 25000 Besançon, contact@gnl-solution.com : je vous notifie par la présente ma rétractation du contrat portant sur le service ci-dessous. Service : … · Souscrit le : … · Nom : … · Adresse : … · Date : … · Signature (en cas d'envoi papier).</p>
<h2 id="usage"><span class="n">9.</span>Usage acceptable et suspension</h2>
<p>Le Client est seul responsable des contenus et données qu'il héberge. Il s'interdit d'utiliser les services pour héberger ou diffuser des contenus illicites, des logiciels malveillants, du spam, du hameçonnage, ou pour porter atteinte à la sécurité de l'infrastructure ou de tiers. Sur le Laboratoire, les tests offensifs sont limités aux machines fournies.</p>
<p>En cas de manquement, de notification fondée de contenu illicite ou de risque pour la sécurité, le Prestataire peut suspendre tout ou partie du service, après information du Client sauf urgence, et résilier le contrat en cas de manquement grave ou répété. La décision est motivée.</p>
<h2 id="donnees-client"><span class="n">10.</span>Données hébergées</h2>
<p>Pour les données personnelles que le Client héberge sur ses services, le Client est responsable de traitement et le Prestataire agit en qualité de sous-traitant au sens de l'article 28 du RGPD : il ne traite ces données que sur instruction du Client, les héberge exclusivement à Besançon (France), en assure la sécurité et la confidentialité, et notifie toute violation de données dans les meilleurs délais. [Accord de traitement des données (DPA) disponible dans l'Espace Client.]</p>
<h2 id="fin"><span class="n">11.</span>Fin de contrat et récupération des données</h2>
<p>À la fin du contrat, le Client peut récupérer ses données depuis son Espace Client pendant [DURÉE] jours. Passé ce délai, les données et leurs sauvegardes sont supprimées définitivement, sauf obligation légale de conservation.</p>
<h2 id="responsabilite"><span class="n">12.</span>Responsabilité</h2>
<p>Le Prestataire est responsable de la bonne exécution de ses obligations, sauf fait du Client, fait d'un tiers ou force majeure. Envers les Clients professionnels, sa responsabilité est limitée aux dommages directs et plafonnée au montant payé au cours des douze (12) derniers mois. Rien dans les présentes ne limite les droits des Clients consommateurs prévus par la loi.</p>
<h2 id="force"><span class="n">13.</span>Force majeure</h2>
<p>Aucune partie n'est responsable d'un manquement causé par un cas de force majeure au sens de l'article 1218 du Code civil.</p>
<h2 id="litiges"><span class="n">14.</span>Réclamations et médiation</h2>
<p>Toute réclamation est adressée à contact@gnl-solution.com ou via la page <a href="/contact">Contact</a> ; une réponse est apportée sous [DÉLAI].</p>
<p><em>Clients consommateurs.</em> À défaut d'accord, le Client peut recourir gratuitement au médiateur de la consommation : [NOM DU MÉDIATEUR], [ADRESSE], [SITE INTERNET] (articles L.612-1 et suivants du Code de la consommation).</p>
<h2 id="droit"><span class="n">15.</span>Droit applicable</h2>
<p>Les présentes CGV sont soumises au droit français. À défaut de résolution amiable, les litiges avec des Clients professionnels relèvent des tribunaux compétents de Besançon ; les Clients consommateurs conservent le bénéfice des règles de compétence légales.</p>
</article>
</div>
</div>
<?php gnl_ui_close(); ?>
