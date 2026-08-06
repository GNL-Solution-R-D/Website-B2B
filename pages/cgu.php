<?php
/**
 * Conditions Générales d’Utilisation (CGU)
 * -------------------------------------------------------------------
 * Ce fichier est autonome (il génère sa propre page HTML).
 * POUR L’INTÉGRER À TON SITE : remplace le bloc <!DOCTYPE ...> ... </head>
 * et le </body></html> par tes propres include('header.php') / include('footer.php').
 */
require_once __DIR__ . '/config-legal.php';
$page_titre = 'Conditions Générales d’Utilisation';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($page_titre) ?> — <?= e($LEGAL['site_nom']) ?></title>
    <meta name="description" content="Conditions générales d’utilisation du site <?= e($LEGAL['site_nom']) ?>.">
    <meta name="robots" content="index,follow">
    <link rel="canonical" href="<?= e(rtrim($LEGAL['site_url'], '/')) ?>/cgu.php">
    <link rel="stylesheet" href="../assets/css/legal.css">
    <link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>

<?php
// Pied de page commun (identique à index.php)
if (is_readable('../include/header.php')) {
    include '../include/header.php';
}
?>

<div class="legal-wrap">

    <h1>Conditions Générales d’Utilisation</h1>
    <p class="lead">Les présentes conditions régissent l’accès au site <?= e($LEGAL['site_nom']) ?> et son utilisation.</p>
    <span class="maj">Dernière mise à jour : <?= e($LEGAL['date_maj']) ?></span>

    <nav class="toc" aria-label="Sommaire">
        <h2>Sommaire</h2>
        <ol>
            <li><a href="#art1">Objet</a></li>
            <li><a href="#art2">Mentions légales</a></li>
            <li><a href="#art3">Acceptation des conditions</a></li>
            <li><a href="#art4">Accès au site</a></li>
            <li><a href="#art5">Compte utilisateur</a></li>
            <li><a href="#art6">Propriété intellectuelle</a></li>
            <li><a href="#art7">Obligations de l’utilisateur</a></li>
            <li><a href="#art8">Contenus des utilisateurs</a></li>
            <li><a href="#art9">Liens hypertextes</a></li>
            <li><a href="#art10">Données personnelles et cookies</a></li>
            <li><a href="#art11">Responsabilité</a></li>
            <li><a href="#art12">Disponibilité du service</a></li>
            <li><a href="#art13">Modification des CGU</a></li>
            <li><a href="#art14">Droit applicable et litiges</a></li>
        </ol>
    </nav>

    <h2 id="art1"><span class="num">1.</span>Objet</h2>
    <p>Les présentes Conditions Générales d’Utilisation (ci-après les « CGU ») ont pour objet de définir les modalités et conditions dans lesquelles tout utilisateur (ci-après l’« Utilisateur ») accède au site <?= e($LEGAL['site_nom']) ?>, accessible à l’adresse <a href="<?= e($LEGAL['site_url']) ?>"><?= e($LEGAL['site_url']) ?></a> (ci-après le « Site »), et l’utilise.</p>
    <p>Elles s’appliquent à l’exclusion de toute autre condition, et sans préjudice des Conditions Générales de Vente applicables à tout achat réalisé sur le Site.</p>

    <h2 id="art2"><span class="num">2.</span>Mentions légales</h2>
    <h3>Éditeur du Site</h3>
    <p><?= legal_identite($LEGAL) ?></p>
    <p>Directeur de la publication : <strong><?= e($LEGAL['directeur_publication']) ?></strong>.</p>
    <h3>Hébergeur</h3>
    <p>
        <strong><?= e($LEGAL['hebergeur_nom']) ?></strong><br>
        <?= e($LEGAL['hebergeur_adresse']) ?><br>
        <?= e($LEGAL['hebergeur_tel']) ?>
    </p>

    <h2 id="art3"><span class="num">3.</span>Acceptation des conditions</h2>
    <p>L’accès et l’utilisation du Site impliquent l’acceptation pleine et entière des présentes CGU par l’Utilisateur. Si l’Utilisateur n’accepte pas tout ou partie des CGU, il lui appartient de renoncer à l’usage du Site.</p>
    <p>L’Éditeur se réserve le droit de refuser l’accès au Site, unilatéralement et sans notification préalable, à tout Utilisateur ne respectant pas les présentes CGU.</p>

    <h2 id="art4"><span class="num">4.</span>Accès au site</h2>
    <p>Le Site est en principe accessible 7 jours sur 7 et 24 heures sur 24. L’accès au Site est gratuit ; les frais d’équipement, de connexion et de communication nécessaires y restent à la charge exclusive de l’Utilisateur.</p>
    <p>L’Éditeur peut, à tout moment et sans préavis, suspendre, interrompre ou limiter l’accès à tout ou partie du Site, notamment pour des opérations de maintenance, de mise à jour ou pour toute autre raison, technique ou non, sans que sa responsabilité puisse être engagée.</p>

    <h2 id="art5"><span class="num">5.</span>Compte utilisateur</h2>
    <p>Certaines fonctionnalités du Site peuvent nécessiter la création d’un compte. L’Utilisateur s’engage à fournir des informations exactes, complètes et à jour lors de son inscription et à les actualiser en cas de modification.</p>
    <p>L’Utilisateur est seul responsable de la confidentialité de ses identifiants de connexion et de toute activité réalisée depuis son compte. Il s’engage à informer sans délai l’Éditeur de toute utilisation non autorisée de son compte.</p>
    <p>L’Utilisateur peut demander la suppression de son compte à tout moment en contactant l’Éditeur à l’adresse <a href="mailto:<?= e($LEGAL['email']) ?>"><?= e($LEGAL['email']) ?></a>.</p>

    <h2 id="art6"><span class="num">6.</span>Propriété intellectuelle</h2>
    <p>L’ensemble des éléments composant le Site (structure, textes, graphismes, images, photographies, logos, marques, bases de données, sons, vidéos, logiciels, etc.) est protégé par le droit de la propriété intellectuelle et demeure la propriété exclusive de l’Éditeur ou de ses partenaires.</p>
    <p>Toute reproduction, représentation, modification, publication, adaptation ou exploitation de tout ou partie de ces éléments, par quelque moyen et sous quelque forme que ce soit, est interdite sans l’autorisation écrite préalable de l’Éditeur, sous peine de constituer une contrefaçon sanctionnée par les articles L.335-2 et suivants du Code de la propriété intellectuelle.</p>

    <h2 id="art7"><span class="num">7.</span>Obligations de l’utilisateur</h2>
    <p>L’Utilisateur s’engage à faire un usage du Site conforme à la loi, à l’ordre public, aux bonnes mœurs et aux présentes CGU. Il s’interdit notamment de&nbsp;:</p>
    <ul>
        <li>utiliser le Site à des fins illégales, frauduleuses ou portant atteinte aux droits de tiers&nbsp;;</li>
        <li>tenter de porter atteinte au fonctionnement, à la sécurité ou à l’intégrité du Site&nbsp;;</li>
        <li>collecter ou extraire des données du Site par des procédés automatisés non autorisés&nbsp;;</li>
        <li>diffuser des contenus illicites, diffamatoires, injurieux, violents, haineux ou contraires aux bonnes mœurs&nbsp;;</li>
        <li>usurper l’identité d’un tiers ou porter atteinte à sa vie privée.</li>
    </ul>

    <h2 id="art8"><span class="num">8.</span>Contenus publiés par les utilisateurs</h2>
    <p>Lorsque le Site permet à l’Utilisateur de publier des contenus (avis, commentaires, messages, etc.), ce dernier reste seul responsable des contenus qu’il diffuse et garantit qu’il dispose de tous les droits nécessaires.</p>
    <p>L’Éditeur se réserve le droit de retirer, sans préavis ni indemnité, tout contenu qui serait contraire à la loi ou aux présentes CGU. En publiant un contenu, l’Utilisateur concède à l’Éditeur, à titre gratuit, le droit de le reproduire et de le représenter dans le cadre de l’exploitation du Site.</p>

    <h2 id="art9"><span class="num">9.</span>Liens hypertextes</h2>
    <p>Le Site peut contenir des liens vers des sites tiers. L’Éditeur n’exerce aucun contrôle sur ces sites et décline toute responsabilité quant à leur contenu, leur disponibilité ou l’usage qui en est fait.</p>
    <p>La mise en place d’un lien hypertexte vers le Site nécessite l’autorisation préalable de l’Éditeur.</p>

    <h2 id="art10"><span class="num">10.</span>Données personnelles et cookies</h2>
    <p>Les données personnelles collectées dans le cadre de l’utilisation du Site sont traitées conformément à la
        <a href="politique-de-confidentialite.php">Politique de confidentialité</a>, qui décrit également l’usage des cookies et les moyens de gérer votre consentement.</p>

    <h2 id="art11"><span class="num">11.</span>Responsabilité</h2>
    <p>L’Éditeur s’efforce d’assurer l’exactitude et la mise à jour des informations diffusées sur le Site, sans toutefois pouvoir en garantir l’exhaustivité ou l’absence totale d’erreur. Les informations sont fournies à titre indicatif et sont susceptibles d’évoluer.</p>
    <p>L’Éditeur ne saurait être tenu responsable des dommages directs ou indirects résultant de l’accès au Site, de son utilisation, de son indisponibilité, de la présence de virus, ou de l’utilisation faite des informations qui y figurent.</p>

    <h2 id="art12"><span class="num">12.</span>Disponibilité du service</h2>
    <p>L’Éditeur met en œuvre les moyens raisonnables pour assurer un accès de qualité au Site, sans être tenu à une obligation d’y parvenir. Il ne peut être tenu responsable d’une indisponibilité liée notamment à un cas de force majeure, à une défaillance du réseau internet ou à toute cause extérieure.</p>

    <h2 id="art13"><span class="num">13.</span>Modification des CGU</h2>
    <p>L’Éditeur se réserve le droit de modifier à tout moment les présentes CGU afin de les adapter aux évolutions du Site ou de la réglementation. Les CGU applicables sont celles en vigueur à la date de connexion et d’utilisation du Site par l’Utilisateur.</p>

    <h2 id="art14"><span class="num">14.</span>Droit applicable et litiges</h2>
    <p>Les présentes CGU sont régies par le droit français. En cas de litige relatif à leur interprétation ou à leur exécution, les parties s’efforceront de trouver une solution amiable. À défaut, le litige sera soumis aux tribunaux français compétents dans les conditions de droit commun.</p>

    <p class="back-top"><a href="#">↑ Revenir en haut</a></p>

</div>

<?php
// Pied de page commun (identique à index.php)
if (is_readable('../include/footer.php')) {
    include '../include/footer.php';
}
?>

</body>
</html>
