<?php
/* =====================================================================
   GNL Solution — Proxy "Recherche d'entreprises"  (/entreprise-lookup)
   ---------------------------------------------------------------------
   Appelé en AJAX par /inscription quand un SIRET (14 chiffres) est saisi.
   Interroge l'API ouverte data.gouv.fr :
     https://recherche-entreprises.api.gouv.fr/search?q=<siret>
   et renvoie des champs normalisés pour pré-remplir l'organisation.

   Passer par un proxy serveur évite les soucis de CORS et centralise le
   mapping. Aucune donnée personnelle n'est renvoyée (ni e-mail ni tél,
   non fournis par l'API).

   Réponse : {"ok":true,"data":{...}} ou {"ok":false,"error":"..."}.
   ===================================================================== */

ob_start();
require __DIR__ . '/keycloak_rest.php'; // pour gnl_http()

/* ---- Numéro de TVA intracommunautaire FR calculé depuis le SIREN ---- */
if (!function_exists('gnl_fr_tva')) {
    function gnl_fr_tva($siren) {
        if (!preg_match('/^\d{9}$/', $siren)) return '';
        $key = (12 + 3 * ((int) $siren % 97)) % 97;
        return 'FR' . sprintf('%02d', $key) . $siren;
    }
}

/* ---- Libellé de forme juridique (codes INSEE les plus courants) ----- */
if (!function_exists('gnl_nj_label')) {
    function gnl_nj_label($code, $company) {
        if (!empty($company['complements']['est_entrepreneur_individuel'])) return 'Entrepreneur individuel';
        $map = array(
            '1000' => 'Entrepreneur individuel',
            '5410' => 'SARL', '5498' => 'SARL', '5499' => 'SARL',
            '5710' => 'SAS', '5720' => 'SASU',
            '5505' => 'SA', '5510' => 'SA', '5515' => 'SA', '5599' => 'SA',
            '6540' => 'SCI',
            '9210' => 'Association', '9220' => 'Association', '9260' => 'Association',
            '5385' => 'Société d\'exercice libéral',
        );
        $code = (string) $code;
        return isset($map[$code]) ? $map[$code] : '';
    }
}

/* ---- Normalise la réponse de l'API en champs du formulaire ---------- */
if (!function_exists('gnl_entreprise_normalize')) {
    function gnl_entreprise_normalize($apiJson, $siret) {
        $results = (is_array($apiJson) && isset($apiJson['results']) && is_array($apiJson['results'])) ? $apiJson['results'] : array();
        if (empty($results)) return null;
        $c = $results[0];
        $siren = substr(preg_replace('/\D/', '', $siret), 0, 9);

        // Établissement correspondant au SIRET saisi, sinon le siège.
        $etab = null;
        if (!empty($c['matching_etablissements']) && is_array($c['matching_etablissements'])) {
            foreach ($c['matching_etablissements'] as $e) {
                if (isset($e['siret']) && preg_replace('/\D/', '', $e['siret']) === preg_replace('/\D/', '', $siret)) { $etab = $e; break; }
            }
            if (!$etab) $etab = $c['matching_etablissements'][0];
        }
        if (!$etab && isset($c['siege']) && is_array($c['siege'])) $etab = $c['siege'];
        if (!is_array($etab)) $etab = array();

        $g = function ($a, $k) { return (isset($a[$k]) && $a[$k] !== null) ? $a[$k] : ''; };

        $enseignes = (isset($etab['liste_enseignes']) && is_array($etab['liste_enseignes'])) ? array_filter($etab['liste_enseignes']) : array();
        $raison = (string) $g($c, 'nom_raison_sociale');
        if ($raison === '') $raison = (string) $g($c, 'nom_complet');
        $nomCommercial = $enseignes ? (string) reset($enseignes) : (string) $g($c, 'sigle');
        if ($nomCommercial === '') $nomCommercial = $raison;

        $voie = trim($g($etab, 'type_voie') . ' ' . $g($etab, 'libelle_voie'));
        $nbr  = trim($g($etab, 'numero_voie') . ' ' . $g($etab, 'indice_repetition'));

        return array(
            'siren'          => $siren,
            'siret'          => preg_replace('/\D/', '', $siret),
            'nom_commercial' => $nomCommercial,
            'raison'         => $raison,
            'entite_legal'   => gnl_nj_label($g($c, 'nature_juridique'), $c),
            'voie_nbr'       => trim($nbr),
            'voie_name'      => $voie,
            'cp'             => (string) $g($etab, 'code_postal'),
            'commune'        => (string) $g($etab, 'libelle_commune'),
            'pays'           => 'France',
            'tva'            => gnl_fr_tva($siren),
            'etat'           => (string) $g($etab, 'etat_administratif'), // 'A' actif, 'F' fermé
        );
    }
}

/* En test unitaire : on ne charge que les fonctions ci-dessus. */
if (defined('GNL_LOOKUP_TEST')) return;

/* --------------------------- Exécution ------------------------------ */
header('Content-Type: application/json; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$siret = preg_replace('/\D/', '', isset($_GET['siret']) ? (string) $_GET['siret'] : '');
if (strlen($siret) !== 14 && strlen($siret) !== 9) {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => 'siret_invalide'));
    exit;
}

$url = 'https://recherche-entreprises.api.gouv.fr/search?' . http_build_query(array(
    'q' => $siret, 'page' => 1, 'per_page' => 1,
));
$r = gnl_http('GET', $url, null, array('Accept: application/json', 'User-Agent: GNL-Solution/1.0'));

if (!is_array($r) || (isset($r['_status']) && ((int) $r['_status'] < 200 || (int) $r['_status'] >= 300) && empty($r['results']))) {
    echo json_encode(array('ok' => false, 'error' => 'api_indisponible'));
    exit;
}

$data = gnl_entreprise_normalize($r, $siret);
if ($data === null) {
    echo json_encode(array('ok' => false, 'error' => 'introuvable'));
    exit;
}
echo json_encode(array('ok' => true, 'data' => $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
