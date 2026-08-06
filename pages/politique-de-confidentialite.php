<?php
/**
 * Politique de confidentialité (RGPD)
 * -------------------------------------------------------------------
 * Adapte le tableau des traitements (art. 4) à ce que tu collectes réellement
 * et la liste des cookies (art. 9) à ceux réellement déposés sur ton site.
 */
require_once __DIR__ . '/config-legal.php';
$page_titre = 'Politique de confidentialité';

/* -------------------------------------------------------------------
   Détail des traitements (art. 4) — chargé dynamiquement
   Source : datatable n8n "traitement" via le MÊME webhook que la boutique
        https://api.gnl-solution.fr/webhook/boutique   (action = traitement.list)
   Repli automatique sur traitement_rgpd.csv si le webhook est indisponible.
   Colonnes attendues : id, finalite, base_legal, duree, createdAt, updatedAt
   ------------------------------------------------------------------- */
if (!defined('GNL_WEBHOOK_URL')) {
    define('GNL_WEBHOOK_URL', 'https://api.gnl-solution.fr/webhook/boutique');
}
if (!defined('GNL_CACHE_TTL')) {
    define('GNL_CACHE_TTL', 300); // durée du cache local en secondes
}
if (!defined('RGPD_CSV_FALLBACK')) {
    define('RGPD_CSV_FALLBACK', __DIR__ . '/traitement_rgpd.csv');
}

/* --- Appel du webhook n8n (POST) -> corps brut de la réponse ------- */
function rgpd_fetch_from_webhook() {
    $payload = json_encode(array('source' => 'politique-de-confidentialite.php', 'action' => 'traitement.list'));

    if (function_exists('curl_init')) {
        $ch = curl_init(GNL_WEBHOOK_URL);
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => array('Content-Type: application/json', 'Accept: application/json'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_CONNECTTIMEOUT => 4,
        ));
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        if ($body !== false && $code >= 200 && $code < 300) {
            return rgpd_decode_rows($body);
        }
        return null;
    }

    // Repli sans cURL
    $ctx = stream_context_create(array('http' => array(
        'method'        => 'POST',
        'header'        => "Content-Type: application/json\r\nAccept: application/json\r\n",
        'content'       => $payload,
        'timeout'       => 6,
        'ignore_errors' => true,
    )));
    $body = @file_get_contents(GNL_WEBHOOK_URL, false, $ctx);
    if ($body === false) return null;
    return rgpd_decode_rows($body);
}

/* --- Décodage tolérant de la réponse JSON en liste de lignes ------- */
function rgpd_decode_rows($body) {
    $data = json_decode($body, true);
    if (!is_array($data)) return null;

    // Déballe les enveloppes n8n les plus courantes
    foreach (array('data', 'traitements', 'traitement', 'rows', 'items', 'result') as $k) {
        if (isset($data[$k]) && is_array($data[$k])) { $data = $data[$k]; break; }
    }
    // Objet unique -> liste à un élément
    if (isset($data['id']) || isset($data['finalite']) || isset($data['base_legal'])) {
        $data = array($data);
    }

    $rows = array();
    foreach ($data as $item) {
        if (!is_array($item)) continue;
        if (isset($item['json']) && is_array($item['json'])) $item = $item['json']; // format {json:{...}}
        $rows[] = $item;
    }
    return $rows ? $rows : null;
}

/* --- Repli sur traitement_rgpd.csv -------------------------------- */
function rgpd_fetch_from_csv() {
    if (!is_readable(RGPD_CSV_FALLBACK)) return array();
    $rows = array();
    if (($h = fopen(RGPD_CSV_FALLBACK, 'r')) !== false) {
        $header = fgetcsv($h);
        if ($header) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]); // retire un BOM éventuel
            while (($line = fgetcsv($h)) !== false) {
                if ($line === array(null) || (count($line) === 1 && $line[0] === null)) continue;
                $line = array_pad($line, count($header), '');
                $line = array_slice($line, 0, count($header));
                $rows[] = array_combine($header, $line);
            }
        }
        fclose($h);
    }
    return $rows;
}

/* --- Chargement (webhook -> cache -> csv) ------------------------- */
function rgpd_load_traitements() {
    $cacheFile = sys_get_temp_dir() . '/gnl_traitements_cache.json';

    // Cache frais ?
    if (is_readable($cacheFile) && (time() - filemtime($cacheFile) < GNL_CACHE_TTL)) {
        $cached = json_decode(@file_get_contents($cacheFile), true);
        if (is_array($cached) && $cached) return rgpd_normalize($cached);
    }

    $rows = rgpd_fetch_from_webhook();
    if ($rows) {
        @file_put_contents($cacheFile, json_encode($rows)); // best effort
    } else {
        $rows = rgpd_fetch_from_csv();
    }
    return rgpd_normalize($rows);
}

/* --- Normalisation d'une ligne (clés stables + nettoyage) --------- */
function rgpd_normalize($rows) {
    $out = array();
    if (!is_array($rows)) return $out;
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $g = function ($k) use ($r) { return isset($r[$k]) ? $r[$k] : ''; };
        $finalite = trim((string) $g('finalite'));
        $base     = trim((string) $g('base_legal'));
        $duree    = trim((string) $g('duree'));
        if ($finalite === '' && $base === '' && $duree === '') continue; // ligne vide
        $out[] = array(
            'id'       => $g('id'),
            'finalite' => $finalite,
            'base'     => $base,
            'duree'    => $duree,
        );
    }
    return $out;
}

/* --- Rendu des lignes du tableau (art. 4) ------------------------- */
function rgpd_render_traitement_rows() {
    global $RGPD_TRAITEMENTS;
    if (!is_array($RGPD_TRAITEMENTS)) return;
    foreach ($RGPD_TRAITEMENTS as $t) {
        echo '            <tr>' . "\n";
        echo '                <td>' . e($t['finalite']) . '</td>' . "\n";
        echo '                <td>' . e($t['base']) . '</td>' . "\n";
        echo '                <td>' . e($t['duree']) . '</td>' . "\n";
        echo '            </tr>' . "\n";
    }
}

/* --- Chargement effectif ------------------------------------------ */
$RGPD_TRAITEMENTS = rgpd_load_traitements();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($page_titre) ?> — <?= e($LEGAL['site_nom']) ?></title>
    <meta name="description" content="Politique de confidentialité et de protection des données personnelles du site <?= e($LEGAL['site_nom']) ?>.">
    <meta name="robots" content="index,follow">
    <link rel="canonical" href="<?= e(rtrim($LEGAL['site_url'], '/')) ?>/politique-de-confidentialite.php">
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

    <h1>Politique de confidentialité</h1>
    <p class="lead">Comment <?= e($LEGAL['site_nom']) ?> collecte, utilise et protège vos données personnelles.</p>
    <span class="maj">Dernière mise à jour : <?= e($LEGAL['date_maj']) ?></span>

    <nav class="toc" aria-label="Sommaire">
        <h2>Sommaire</h2>
        <ol>
            <li><a href="#art1">Responsable du traitement</a></li>
            <li><a href="#art2">Données collectées</a></li>
            <li><a href="#art3">Finalités et bases légales</a></li>
            <li><a href="#art4">Détail des traitements</a></li>
            <li><a href="#art5">Destinataires des données</a></li>
            <li><a href="#art6">Durées de conservation</a></li>
            <li><a href="#art7">Transferts hors UE</a></li>
            <li><a href="#art8">Sécurité</a></li>
            <li><a href="#art9">Cookies</a></li>
            <li><a href="#art10">Vos droits</a></li>
            <li><a href="#art11">Réclamation</a></li>
            <li><a href="#art12">Modifications</a></li>
        </ol>
    </nav>

    <h2 id="art1"><span class="num">1.</span>Responsable du traitement</h2>
    <p>Le responsable du traitement des données personnelles collectées sur le Site est&nbsp;:</p>
    <p><?= legal_identite($LEGAL) ?></p>
    <?php if (!empty($LEGAL['dpo_present'])): ?>
    <p>Un Délégué à la Protection des Données (DPO) a été désigné et peut être contacté à l’adresse&nbsp;:
        <a href="mailto:<?= e($LEGAL['dpo_contact']) ?>"><?= e($LEGAL['dpo_contact']) ?></a>.</p>
    <?php endif; ?>
    <p>La présente politique est établie conformément au Règlement (UE) 2016/679 (« RGPD ») et à la loi n°78-17 du 6 janvier 1978 modifiée dite « Informatique et Libertés ».</p>

    <h2 id="art2"><span class="num">2.</span>Données collectées</h2>
    <p>Nous collectons uniquement les données strictement nécessaires aux finalités décrites ci-dessous. Selon votre utilisation du Site, il peut s’agir&nbsp;:</p>
    <ul>
        <li><strong>Données d’identification et de contact</strong> : nom, prénom, adresse postale, adresse e-mail, numéro de téléphone&nbsp;;</li>
        <li><strong>Données de commande</strong> : produits commandés, montant, historique, adresse de livraison et de facturation&nbsp;;</li>
        <li><strong>Données de paiement</strong> : traitées directement par notre prestataire de paiement (nous ne conservons pas vos coordonnées bancaires)&nbsp;;</li>
        <li><strong>Données de connexion et de navigation</strong> : adresse IP, type de navigateur, pages consultées, via les cookies (voir article 9)&nbsp;;</li>
        <li><strong>Données de compte</strong> : identifiant, mot de passe (chiffré), préférences.</li>
    </ul>

    <h2 id="art3"><span class="num">3.</span>Finalités et bases légales</h2>
    <p>Chaque traitement de vos données repose sur une base légale prévue par le RGPD&nbsp;: l’exécution d’un contrat, le respect d’une obligation légale, votre consentement, ou l’intérêt légitime du responsable de traitement. Le détail figure dans le tableau ci-dessous.</p>

    <h2 id="art4"><span class="num">4.</span>Détail des traitements</h2>
    <table>
        <thead>
            <tr><th>Finalité</th><th>Base légale</th><th>Durée de conservation</th></tr>
        </thead>
        <tbody>
<?php rgpd_render_traitement_rows(); ?>
        </tbody>
    </table>
    <div class="callout warn">
        <p><strong>À ajuster.</strong> Ce tableau est un exemple. Ne conserve que les lignes correspondant aux traitements que tu réalises réellement, et vérifie chaque durée de conservation.</p>
    </div>

    <h2 id="art5"><span class="num">5.</span>Destinataires des données</h2>
    <p>Vos données sont destinées aux services habilités du responsable de traitement. Elles peuvent être transmises, dans la limite de leurs missions respectives, à des sous-traitants et prestataires (hébergeur, prestataire de paiement, transporteur, outil d’e-mailing, solution de mesure d’audience). Ces prestataires n’agissent que sur instruction et présentent des garanties conformes au RGPD.</p>
    <p>Vos données peuvent également être communiquées aux autorités administratives ou judiciaires lorsque la loi l’exige. Elles ne sont ni vendues ni louées à des tiers à des fins commerciales.</p>

    <h2 id="art6"><span class="num">6.</span>Durées de conservation</h2>
    <p>Vos données sont conservées pour la durée nécessaire aux finalités pour lesquelles elles ont été collectées, puis archivées ou supprimées conformément aux durées indiquées dans le tableau de l’article 4 et aux obligations légales applicables.</p>

    <h2 id="art7"><span class="num">7.</span>Transferts de données hors de l’Union européenne</h2>
    <p>Vos données sont en principe hébergées et traitées au sein de l’Union européenne. Si certains prestataires devaient conduire à un transfert hors de l’UE, celui-ci serait encadré par des garanties appropriées prévues par le RGPD (décision d’adéquation ou clauses contractuelles types de la Commission européenne).</p>

    <h2 id="art8"><span class="num">8.</span>Sécurité</h2>
    <p>Nous mettons en œuvre des mesures techniques et organisationnelles appropriées afin de protéger vos données contre la perte, l’accès non autorisé, la divulgation ou l’altération (chiffrement des échanges, contrôle des accès, sauvegardes, mots de passe protégés, etc.).</p>

    <h2 id="art9"><span class="num">9.</span>Cookies</h2>
    <p>Un cookie est un petit fichier déposé sur votre terminal lors de la consultation du Site. Certains cookies sont indispensables au fonctionnement du Site ; d’autres, soumis à votre consentement, servent à mesurer l’audience ou à personnaliser les contenus.</p>
    <ul>
        <li><strong>Cookies strictement nécessaires</strong> : indispensables à la navigation et à la sécurité (panier, session, préférences). Ils ne requièrent pas de consentement.</li>
        <li><strong>Cookies de mesure d’audience</strong> : statistiques de fréquentation. Soumis à votre consentement.</li>
        <li><strong>Cookies de personnalisation ou publicitaires</strong> : soumis à votre consentement.</li>
    </ul>
    <p>Lors de votre première visite, un bandeau vous permet d’accepter, de refuser ou de paramétrer les cookies non essentiels. Vous pouvez modifier vos choix à tout moment via le paramétrage des cookies ou les réglages de votre navigateur. La durée de vie des cookies n’excède pas treize (13) mois et le consentement est de nouveau sollicité à l’expiration de ce délai.</p>

    <h2 id="art10"><span class="num">10.</span>Vos droits</h2>
    <p>Conformément au RGPD et à la loi Informatique et Libertés, vous disposez des droits suivants sur vos données&nbsp;:</p>
    <ul>
        <li>droit d’<strong>accès</strong> et de <strong>rectification</strong>&nbsp;;</li>
        <li>droit à l’<strong>effacement</strong> (« droit à l’oubli »)&nbsp;;</li>
        <li>droit à la <strong>limitation</strong> du traitement&nbsp;;</li>
        <li>droit d’<strong>opposition</strong>, notamment à la prospection commerciale&nbsp;;</li>
        <li>droit à la <strong>portabilité</strong> de vos données&nbsp;;</li>
        <li>droit de <strong>retirer votre consentement</strong> à tout moment, sans que cela remette en cause la licéité du traitement effectué avant le retrait&nbsp;;</li>
        <li>droit de définir des <strong>directives</strong> relatives au sort de vos données après votre décès.</li>
    </ul>
    <p>Vous pouvez exercer ces droits en écrivant à <a href="mailto:<?= e($LEGAL['contact_rgpd']) ?>"><?= e($LEGAL['contact_rgpd']) ?></a><?php if (!empty($LEGAL['dpo_present'])): ?> ou au DPO à <a href="mailto:<?= e($LEGAL['dpo_contact']) ?>"><?= e($LEGAL['dpo_contact']) ?></a><?php endif; ?>, ou par courrier à l’adresse du siège social. Une preuve d’identité pourra vous être demandée en cas de doute raisonnable sur votre identité.</p>

    <h2 id="art11"><span class="num">11.</span>Réclamation auprès de la CNIL</h2>
    <p>Si, après nous avoir contactés, vous estimez que vos droits ne sont pas respectés, vous pouvez introduire une réclamation auprès de la Commission Nationale de l’Informatique et des Libertés (CNIL), 3 Place de Fontenoy, TSA 80715, 75334 Paris Cedex 07, ou en ligne sur <a href="https://www.cnil.fr" rel="noopener" target="_blank">www.cnil.fr</a>.</p>

    <h2 id="art12"><span class="num">12.</span>Modifications</h2>
    <p>La présente politique peut être modifiée à tout moment afin de tenir compte des évolutions légales, réglementaires ou techniques. La version applicable est celle en vigueur lors de votre consultation du Site.</p>

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
