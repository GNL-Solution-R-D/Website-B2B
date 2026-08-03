<?php
/**
 * Conditions Générales de Vente (CGV)
 * -------------------------------------------------------------------
 * Modèle pour la VENTE À DISTANCE À DES CONSOMMATEURS (B2C) via le Site.
 * Adapte les articles « Livraison » et « Rétractation » selon que tu vends
 * des BIENS physiques, des SERVICES ou des CONTENUS NUMÉRIQUES.
 * Pour l’intégrer : remplace le HTML d’en-tête/pied par tes propres includes.
 */
require_once __DIR__ . '/config-legal.php';
$page_titre = 'Conditions Générales de Vente';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($page_titre) ?> — <?= e($LEGAL['site_nom']) ?></title>
    <meta name="description" content="Conditions générales de vente du site <?= e($LEGAL['site_nom']) ?>.">
    <meta name="robots" content="index,follow">
    <link rel="canonical" href="<?= e(rtrim($LEGAL['site_url'], '/')) ?>/cgv.php">
    <link rel="stylesheet" href="../assets/css/legal.css">
</head>
<body>

<?php
// Pied de page commun (identique à index.php)
if (is_readable('../include/header.php')) {
    include '../include/header.php';
}
?>

<div class="legal-wrap">

    <h1>Conditions Générales de Vente</h1>
    <p class="lead">Conditions applicables à toute commande passée sur <?= e($LEGAL['site_nom']) ?>.</p>
    <span class="maj">Dernière mise à jour : <?= e($LEGAL['date_maj']) ?></span>

    <div class="callout warn">
        <p><strong>À adapter à ton activité.</strong> Ce modèle couvre la vente à distance à des consommateurs. Supprime ou ajuste les passages sur la livraison, la rétractation ou les garanties selon que tu vends des <em>biens physiques</em>, des <em>services</em> ou des <em>contenus numériques</em>. Fais idéalement relire le résultat par un professionnel du droit.</p>
    </div>

    <nav class="toc" aria-label="Sommaire">
        <h2>Sommaire</h2>
        <ol>
            <li><a href="#art1">Objet et champ d’application</a></li>
            <li><a href="#art2">Identité du vendeur</a></li>
            <li><a href="#art3">Produits et services</a></li>
            <li><a href="#art4">Prix</a></li>
            <li><a href="#art5">Commande</a></li>
            <li><a href="#art6">Paiement</a></li>
            <li><a href="#art7">Livraison</a></li>
            <li><a href="#art8">Droit de rétractation</a></li>
            <li><a href="#art9">Garanties légales</a></li>
            <li><a href="#art10">Responsabilité</a></li>
            <li><a href="#art11">Données personnelles</a></li>
            <li><a href="#art12">Propriété intellectuelle</a></li>
            <li><a href="#art13">Force majeure</a></li>
            <li><a href="#art14">Litiges et médiation</a></li>
            <li><a href="#art15">Droit applicable</a></li>
        </ol>
    </nav>

    <h2 id="art1"><span class="num">1.</span>Objet et champ d’application</h2>
    <p>Les présentes Conditions Générales de Vente (ci-après les « CGV ») régissent les relations contractuelles entre <?= e($LEGAL['raison_sociale']) ?> (ci-après le « Vendeur ») et toute personne physique non commerçante effectuant un achat via le site <?= e($LEGAL['site_nom']) ?> (ci-après le « Client »).</p>
    <p>Toute commande passée sur le Site implique l’acceptation sans réserve des présentes CGV. Le Client déclare en avoir pris connaissance avant de valider sa commande. Les CGV applicables sont celles en vigueur à la date de la commande.</p>

    <h2 id="art2"><span class="num">2.</span>Identité du vendeur</h2>
    <p><?= legal_identite($LEGAL) ?></p>

    <h2 id="art3"><span class="num">3.</span>Produits et services</h2>
    <p>Les caractéristiques essentielles des produits et services proposés sont décrites sur les fiches correspondantes du Site. Les photographies et illustrations sont fournies à titre indicatif et n’engagent pas le Vendeur.</p>
    <p>Les offres sont valables dans la limite des stocks disponibles. En cas d’indisponibilité après passation de la commande, le Client en est informé et peut obtenir le remboursement des sommes versées pour le produit indisponible.</p>

    <h2 id="art4"><span class="num">4.</span>Prix</h2>
    <p>Les prix sont indiqués en euros, toutes taxes comprises (TTC)<?php if (!empty($LEGAL['tva_non_applicable'])): ?> (le Vendeur relevant du régime de la franchise en base de TVA — « TVA non applicable, article 293 B du CGI »)<?php endif; ?>. Ils s’entendent hors frais de livraison, lesquels sont indiqués avant la validation de la commande.</p>
    <p>Le Vendeur se réserve le droit de modifier ses prix à tout moment ; les produits et services sont facturés sur la base des tarifs en vigueur au moment de l’enregistrement de la commande.</p>

    <h2 id="art5"><span class="num">5.</span>Commande</h2>
    <p>Le Client sélectionne les produits ou services de son choix, vérifie le détail et le prix total de sa commande, corrige le cas échéant les éventuelles erreurs, puis confirme sa commande. Cette confirmation vaut acceptation des présentes CGV, des prix et des caractéristiques des produits et services.</p>
    <p>La vente est considérée comme définitive après l’envoi au Client de la confirmation de commande par le Vendeur et l’encaissement du prix. Le Vendeur se réserve le droit d’annuler ou de refuser toute commande présentant un différend antérieur ou un caractère anormal.</p>

    <h2 id="art6"><span class="num">6.</span>Paiement</h2>
    <p>Le règlement s’effectue selon les moyens de paiement proposés sur le Site. Le paiement est exigible immédiatement à la commande. Les données de paiement sont transmises de manière sécurisée par le prestataire de paiement ; le Vendeur n’a pas accès aux données bancaires du Client.</p>
    <p>La commande n’est traitée qu’après confirmation de l’accord du centre de paiement. En cas de refus, la commande est automatiquement annulée.</p>

    <h2 id="art7"><span class="num">7.</span>Livraison</h2>
    <p>Les produits sont livrés à l’adresse indiquée par le Client lors de la commande, dans les délais précisés sur le Site. Sauf circonstances particulières, ce délai n’excède pas trente (30) jours à compter de la confirmation de la commande.</p>
    <p>En cas de retard de livraison, le Client peut, après avoir enjoint le Vendeur de livrer dans un délai supplémentaire raisonnable resté infructueux, résoudre le contrat par lettre recommandée ou par écrit sur un autre support durable. Les sommes versées lui sont alors remboursées, conformément aux articles L.216-1 et suivants du Code de la consommation.</p>
    <p>Les risques liés au transport sont transférés au Client au moment où celui-ci, ou un tiers désigné par lui, prend physiquement possession du produit. Le Client est invité à vérifier l’état du colis à la livraison et à signaler toute anomalie.</p>

    <h2 id="art8"><span class="num">8.</span>Droit de rétractation</h2>
    <p>Conformément aux articles L.221-18 et suivants du Code de la consommation, le Client consommateur dispose d’un délai de <strong>quatorze (14) jours</strong> pour exercer son droit de rétractation, sans avoir à motiver sa décision ni à supporter d’autres coûts que ceux prévus par la loi. Ce délai court à compter de la réception du bien (ou de la conclusion du contrat pour les prestations de services).</p>
    <p>Pour exercer ce droit, le Client notifie sa décision par une déclaration dénuée d’ambiguïté (par exemple par courrier postal ou par e-mail à <a href="mailto:<?= e($LEGAL['email']) ?>"><?= e($LEGAL['email']) ?></a>), en utilisant éventuellement le formulaire type ci-dessous. Les biens doivent être retournés sans retard excessif et au plus tard dans les quatorze (14) jours suivant la communication de la décision de rétractation.</p>
    <p>Le Vendeur rembourse la totalité des sommes versées, y compris les frais de livraison standard, au plus tard dans les quatorze (14) jours suivant la date à laquelle il est informé de la décision de rétractation, en utilisant le même moyen de paiement que celui employé lors de la transaction, sauf accord contraire.</p>
    <p><em>Exceptions&nbsp;:</em> le droit de rétractation ne s’applique pas dans les cas prévus à l’article L.221-28 du Code de la consommation, notamment pour les biens confectionnés sur mesure, les biens descellés ne pouvant être renvoyés pour des raisons d’hygiène, ou les contenus numériques fournis sur support immatériel dont l’exécution a commencé avec l’accord préalable et exprès du Client et son renoncement à ce droit.</p>

    <div class="form-retract">
        <p><strong>Formulaire type de rétractation</strong><br>
        <em>(À compléter et à renvoyer uniquement si vous souhaitez vous rétracter.)</em></p>
        <p class="fld">À l’attention de <?= e($LEGAL['raison_sociale']) ?>, <?= e($LEGAL['adresse_siege']) ?> — <?= e($LEGAL['email']) ?> :</p>
        <p class="fld">Je / nous (*) vous notifie / notifions (*) par la présente ma / notre (*) rétractation du contrat portant sur la vente du bien (*) / la prestation de service (*) ci-dessous :</p>
        <p class="fld">— Commandé le (*) / reçu le (*) : …………………</p>
        <p class="fld">— Numéro de commande : …………………</p>
        <p class="fld">— Nom du / des consommateur(s) : …………………</p>
        <p class="fld">— Adresse du / des consommateur(s) : …………………</p>
        <p class="fld">— Date : …………………</p>
        <p class="fld">— Signature (uniquement en cas de notification papier) : …………………</p>
        <p class="fld">(*) Rayez la mention inutile.</p>
    </div>

    <h2 id="art9"><span class="num">9.</span>Garanties légales</h2>
    <p>Indépendamment de toute garantie commerciale éventuelle, le Vendeur reste tenu des garanties légales suivantes&nbsp;:</p>
    <ul>
        <li><strong>Garantie légale de conformité</strong> (articles L.217-3 et suivants du Code de la consommation)&nbsp;: le Client dispose d’un délai de deux (2) ans à compter de la délivrance du bien pour agir. Il peut obtenir la réparation ou le remplacement du bien non conforme, et, à défaut, la réduction du prix ou la résolution de la vente. Il est dispensé de rapporter la preuve de l’existence du défaut de conformité durant les vingt-quatre (24) mois suivant la délivrance du bien.</li>
        <li><strong>Garantie des vices cachés</strong> (articles 1641 et suivants du Code civil)&nbsp;: le Client peut obtenir la résolution de la vente ou une réduction du prix, conformément à l’article 1644 du Code civil, dans un délai de deux (2) ans à compter de la découverte du vice.</li>
    </ul>
    <p>Pour mettre en œuvre ces garanties, le Client contacte le Vendeur à l’adresse <a href="mailto:<?= e($LEGAL['email']) ?>"><?= e($LEGAL['email']) ?></a>.</p>

    <h2 id="art10"><span class="num">10.</span>Responsabilité</h2>
    <p>Le Vendeur est responsable de plein droit à l’égard du Client de la bonne exécution des obligations résultant du contrat conclu à distance, sous réserve des exceptions prévues par la loi (fait du Client, fait imprévisible et insurmontable d’un tiers étranger au contrat, ou cas de force majeure).</p>
    <p>La responsabilité du Vendeur ne saurait être engagée pour les dommages résultant d’une mauvaise utilisation des produits par le Client ou du non-respect des conseils d’utilisation.</p>

    <h2 id="art11"><span class="num">11.</span>Données personnelles</h2>
    <p>Les données collectées dans le cadre d’une commande sont nécessaires à son traitement et sont traitées conformément à la <a href="politique-de-confidentialite.php">Politique de confidentialité</a>, qui détaille les finalités, les durées de conservation et les droits du Client.</p>

    <h2 id="art12"><span class="num">12.</span>Propriété intellectuelle</h2>
    <p>Tous les éléments du Site demeurent la propriété exclusive du Vendeur. La vente d’un produit n’emporte aucune cession de droits de propriété intellectuelle sur les contenus du Site ou sur les produits.</p>

    <h2 id="art13"><span class="num">13.</span>Force majeure</h2>
    <p>La responsabilité du Vendeur ne pourra être engagée en cas d’inexécution ou de retard dans l’exécution de ses obligations résultant d’un cas de force majeure au sens de l’article 1218 du Code civil.</p>

    <h2 id="art14"><span class="num">14.</span>Litiges et médiation de la consommation</h2>
    <p>En cas de réclamation, le Client est invité à contacter préalablement le Vendeur à l’adresse <a href="mailto:<?= e($LEGAL['email']) ?>"><?= e($LEGAL['email']) ?></a> afin de rechercher une solution amiable.</p>
    <p>Conformément aux articles L.612-1 et suivants du Code de la consommation, le Client consommateur peut recourir gratuitement à un médiateur de la consommation en vue de la résolution amiable du litige&nbsp;:</p>
    <p>
        <strong><?= e($LEGAL['mediateur_nom']) ?></strong><br>
        <?= e($LEGAL['mediateur_adresse']) ?><br>
        <a href="<?= e($LEGAL['mediateur_url']) ?>"><?= e($LEGAL['mediateur_url']) ?></a>
    </p>
    <p>Le Client peut également recourir à la plateforme européenne de règlement en ligne des litiges&nbsp;: <a href="https://ec.europa.eu/consumers/odr" rel="nofollow noopener" target="_blank">https://ec.europa.eu/consumers/odr</a>.</p>

    <h2 id="art15"><span class="num">15.</span>Droit applicable</h2>
    <p>Les présentes CGV sont soumises au droit français. À défaut de résolution amiable, tout litige relève de la compétence des tribunaux français compétents.</p>

    <p class="back-top"><a href="#">↑ Revenir en haut</a></p>

<?php
// Pied de page commun (identique à index.php)
if (is_readable('../include/footer.php')) {
    include '../include/footer.php';
}
?>

</div>

</body>
</html>
