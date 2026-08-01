<?php
ob_start(); // tampon de sortie : un avis PHP ne doit jamais casser une redirection/session
/* =====================================================================
   GNL Solution — SSO Keycloak (connexion / callback / déconnexion)
   Fichier : keycloak_callback.php   (à placer à la RACINE du site,
   car l'URI de redirection enregistrée est /keycloak_callback.php)
   ---------------------------------------------------------------------
   Flux OpenID Connect « Authorization Code + PKCE », client "siteweb".

   Points d'entrée :
     /keycloak_callback.php?action=login&return=/commande   -> Keycloak
     /keycloak_callback.php?action=register&return=/commande-> inscription
     /keycloak_callback.php   (?code&state, URI enregistrée) -> callback
     /keycloak_callback.php?action=logout                    -> déconnexion

   Après connexion : profil dans $_SESSION['gnl_user'] puis retour "return".
   La config est lue depuis vos variables d'environnement KEYCLOAK_*.
   ===================================================================== */

/* Lecture robuste des variables d'environnement : getenv() ne voit pas
   toujours les variables sous PHP-FPM (clear_env), d'où le repli sur
   $_SERVER et $_ENV. */
function gnl_env($key, $default = '') {
    $v = getenv($key);
    if ($v !== false && $v !== '') return $v;
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return $_SERVER[$key];
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') return $_ENV[$key];
    return $default;
}
if (!defined('KEYCLOAK_ISSUER'))       define('KEYCLOAK_ISSUER', gnl_env('KEYCLOAK_ISSUER', 'https://auth.gnl-solution.fr/auth/realms/client-auth'));
if (!defined('KEYCLOAK_CLIENT_ID'))    define('KEYCLOAK_CLIENT_ID', gnl_env('KEYCLOAK_CLIENT_ID', 'siteweb'));
if (!defined('KEYCLOAK_CLIENT_SECRET'))define('KEYCLOAK_CLIENT_SECRET', gnl_env('KEYCLOAK_CLIENT_SECRET', ''));
if (!defined('KEYCLOAK_REDIRECT_URI')) define('KEYCLOAK_REDIRECT_URI', gnl_env('KEYCLOAK_REDIRECT_URI', 'https://beta.gnl-solution.fr/keycloak_callback.php'));
if (!defined('KEYCLOAK_POST_LOGOUT'))  define('KEYCLOAK_POST_LOGOUT', gnl_env('KEYCLOAK_POST_LOGOUT_REDIRECT_URI', 'https://beta.gnl-solution.fr/connexion'));
// Scopes demandés. On reste minimal par défaut (les mappers custom d'un scope
// peuvent faire échouer la génération du jeton -> 'unknown_error' chez Keycloak).
// Pour récupérer civilité/téléphone/entreprise/organisation, ajoutez-les via la
// variable d'env KEYCLOAK_SCOPES, un par un, ex. "openid profile email phone entreprise organization".
if (!defined('KEYCLOAK_SCOPES'))       define('KEYCLOAK_SCOPES', gnl_env('KEYCLOAK_SCOPES', 'openid profile email organization'));

define('KC_OIDC', rtrim(KEYCLOAK_ISSUER, '/') . '/protocol/openid-connect');

if (session_status() === PHP_SESSION_NONE) session_start();

/* ---------- Utilitaires -------------------------------------------- */
function gnl_site_base() {
    static $b = null;
    if ($b !== null) return $b;
    $u = parse_url(KEYCLOAK_REDIRECT_URI);
    if ($u && !empty($u['scheme']) && !empty($u['host'])) return $b = $u['scheme'] . '://' . $u['host'];
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    return $b = ($https ? 'https' : 'http') . '://' . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost');
}
function gnl_safe_return($v) {
    $v = (string) $v;
    if ($v === '' || $v[0] !== '/' || (isset($v[1]) && $v[1] === '/')) return '/commande';
    return $v;
}
function gnl_b64url($bin) { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }
function gnl_rand_hex($bytes = 24) {
    try { return bin2hex(random_bytes($bytes)); }
    catch (Exception $e) { return substr(md5(uniqid('', true) . mt_rand()), 0, $bytes * 2); }
}
/* Décode (sans revérifier la signature — jeton reçu de Keycloak en TLS)
   la charge utile d'un JWT, pour récupérer les claims custom du realm. */
function gnl_jwt_payload($jwt) {
    $parts = explode('.', (string) $jwt);
    if (count($parts) < 2) return array();
    $p = strtr($parts[1], '-_', '+/');
    $p .= str_repeat('=', (4 - strlen($p) % 4) % 4);
    $data = json_decode(base64_decode($p), true);
    return is_array($data) ? $data : array();
}
/* Aplati une table d'attributs Keycloak ({cle:[val]} ou {cle:val}) */
function gnl_flatten_attrs($attrs) {
    $out = array();
    if (!is_array($attrs)) return $out;
    foreach ($attrs as $k => $v) {
        if (in_array($k, array('id', 'name', 'alias', 'attributes'), true)) continue;
        $out[$k] = is_array($v) ? (isset($v[0]) ? (string) $v[0] : '') : (string) $v;
    }
    return $out;
}
/* Extrait le claim "organization" -> array('name'=>..., 'attributes'=>array(cle=>valeur)).
   Gère les formats : {"couturemania":{"attributes":{...}}}, {"couturemania":{...}},
   {"name":"..","attributes":{...}} et ["couturemania", ...]. */
function gnl_org_extract($org) {
    $res = array('name' => '', 'attributes' => array());
    if (!is_array($org) || !$org) return $res;
    $keys = array_keys($org);
    if ($keys === range(0, count($org) - 1)) { $res['name'] = (string) $org[0]; return $res; }
    if (isset($org['attributes']) || isset($org['name']) || isset($org['id']) || isset($org['alias'])) {
        $res['name'] = isset($org['name']) ? (string) $org['name'] : (isset($org['alias']) ? (string) $org['alias'] : '');
        $attrs = (isset($org['attributes']) && is_array($org['attributes'])) ? $org['attributes'] : $org;
        $res['attributes'] = gnl_flatten_attrs($attrs);
        return $res;
    }
    foreach ($org as $name => $data) {
        $res['name'] = (string) $name;
        if (is_array($data)) {
            $attrs = (isset($data['attributes']) && is_array($data['attributes'])) ? $data['attributes'] : $data;
            $res['attributes'] = gnl_flatten_attrs($attrs);
        }
        break;
    }
    return $res;
}
function gnl_kc_post($url, $fields) {
    $body = http_build_query($fields);
    $raw = false; $status = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => array('Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6,
        ));
        $raw  = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        unset($ch); // curl_close est déprécié en PHP 8.5
        if ($raw === false) return array('_status' => 0, '_transport' => ($cerr !== '' ? $cerr : 'curl_failed'));
    } else {
        $ctx = stream_context_create(array('http' => array(
            'method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
            'content' => $body, 'timeout' => 12, 'ignore_errors' => true,
        )));
        $raw = @file_get_contents($url, false, $ctx);
        // PHP 8.5 : $http_response_header est déprécié -> on n'analyse pas le code HTTP ici.
        // Le corps JSON d'erreur de Keycloak (error/error_description) suffit au diagnostic.
        if ($raw === false) return array('_status' => 0, '_transport' => 'stream_failed');
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) $json = array('_raw' => substr((string) $raw, 0, 300));
    $json['_status'] = $status;
    return $json;
}
/* Résume l'erreur renvoyée par Keycloak pour l'affichage/les logs */
function gnl_kc_detail($resp) {
    if (!is_array($resp)) return '';
    if (!empty($resp['_transport'])) return 'réseau: ' . $resp['_transport'];
    if (!empty($resp['error'])) return $resp['error'] . (!empty($resp['error_description']) ? ' — ' . $resp['error_description'] : '');
    if (!empty($resp['_status'])) return 'HTTP ' . $resp['_status'];
    return '';
}
function gnl_kc_userinfo($bearer) {
    $url = KC_OIDC . '/userinfo';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_HTTPHEADER => array('Authorization: Bearer ' . $bearer, 'Accept: application/json'),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6,
        ));
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        if ($resp === false || $code < 200 || $code >= 300) return null;
        return json_decode($resp, true);
    }
    $ctx = stream_context_create(array('http' => array(
        'method' => 'GET', 'header' => 'Authorization: Bearer ' . $bearer . "\r\nAccept: application/json\r\n",
        'timeout' => 12, 'ignore_errors' => true,
    )));
    $resp = @file_get_contents($url, false, $ctx);
    return $resp === false ? null : json_decode($resp, true);
}
function gnl_auth_fail($msg) {
    http_response_code(400);
    $b = gnl_site_base();
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>Connexion — GNL Solution</title><style>body{font-family:system-ui,Segoe UI,Roboto,sans-serif;background:#f3f3f3;color:#353535;'
       . 'display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}.b{background:#fff;border:1px solid #e5e5e5;border-radius:14px;'
       . 'padding:2rem 2.2rem;max-width:440px;text-align:center;box-shadow:0 8px 30px rgba(0,0,0,.06)}h1{font-size:1.2rem;margin:.2rem 0 .6rem}'
       . 'a{display:inline-block;margin:.4rem .3rem 0;padding:.6rem 1.1rem;border-radius:9px;text-decoration:none;font-weight:600}'
       . '.p{background:#6c9400;color:#fff}.s{border:1px solid #ddd;color:#353535}</style></head><body><div class="b">'
       . '<h1>Connexion impossible</h1><p style="opacity:.8">' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>'
       . '<a class="p" href="' . htmlspecialchars($b, ENT_QUOTES) . '/keycloak_callback.php?action=login">Réessayer</a>'
       . '<a class="s" href="' . htmlspecialchars($b, ENT_QUOTES) . '/cart">Retour au panier</a>'
       . '</div></body></html>';
    exit;
}

/* ---------- Routage ------------------------------------------------- */
$action = isset($_GET['action']) ? $_GET['action'] : '';
if (isset($_GET['code']) && isset($_GET['state'])) $action = 'callback';
if (isset($_GET['error']) && $action === '')       $action = 'callback';

if ($action === 'login' || $action === 'register') {
    $return    = gnl_safe_return(isset($_GET['return']) ? $_GET['return'] : '/commande');
    $state     = gnl_rand_hex(24);
    $nonce     = gnl_rand_hex(24);
    $verifier  = gnl_b64url(random_bytes(32));
    $challenge = gnl_b64url(hash('sha256', $verifier, true));
    $_SESSION['kc_login'] = array('state'=>$state, 'nonce'=>$nonce, 'verifier'=>$verifier, 'return'=>$return, 't'=>time());

    $endpoint = KC_OIDC . ($action === 'register' ? '/registrations' : '/auth');
    header('Location: ' . $endpoint . '?' . http_build_query(array(
        'client_id'             => KEYCLOAK_CLIENT_ID,
        'redirect_uri'          => KEYCLOAK_REDIRECT_URI,
        'response_type'         => 'code',
        'scope'                 => KEYCLOAK_SCOPES,
        'state'                 => $state,
        'nonce'                 => $nonce,
        'code_challenge'        => $challenge,
        'code_challenge_method' => 'S256',
    )));
    exit;
}

if ($action === 'callback') {
    if (isset($_GET['error'])) {
        gnl_auth_fail('Keycloak a renvoyé une erreur : ' . htmlspecialchars(isset($_GET['error_description']) ? $_GET['error_description'] : $_GET['error']));
    }
    $st = isset($_SESSION['kc_login']) ? $_SESSION['kc_login'] : null;
    if (!$st || !isset($_GET['state']) || !hash_equals($st['state'], (string) $_GET['state'])) {
        gnl_auth_fail('Session de connexion expirée ou invalide. Merci de réessayer.');
    }
    if (time() - (int) $st['t'] > 600) { unset($_SESSION['kc_login']); gnl_auth_fail('Délai de connexion dépassé. Merci de réessayer.'); }

    $tok = gnl_kc_post(KC_OIDC . '/token', array_filter(array(
        'grant_type'    => 'authorization_code',
        'code'          => (string) $_GET['code'],
        'redirect_uri'  => KEYCLOAK_REDIRECT_URI,
        'client_id'     => KEYCLOAK_CLIENT_ID,
        'client_secret' => KEYCLOAK_CLIENT_SECRET !== '' ? KEYCLOAK_CLIENT_SECRET : null,
        'code_verifier' => $st['verifier'],
    ), function ($v) { return $v !== null; }));
    if (!is_array($tok) || empty($tok['access_token'])) {
        error_log('[GNL SSO] token exchange failed: ' . json_encode($tok));
        $detail = gnl_kc_detail($tok);
        if (KEYCLOAK_CLIENT_SECRET === '') {
            $detail = ($detail !== '' ? $detail . ' · ' : '') . 'secret client absent côté PHP (KEYCLOAK_CLIENT_SECRET vide)';
        }
        gnl_auth_fail('Échec de l\'échange du jeton avec Keycloak.' . ($detail !== '' ? ' [' . $detail . ']' : ''));
    }

    $ui = gnl_kc_userinfo($tok['access_token']);
    if (!$ui || empty($ui['sub'])) { error_log('[GNL SSO] userinfo failed'); gnl_auth_fail('Impossible de récupérer votre profil depuis Keycloak.'); }

    $claims = array_merge(!empty($tok['id_token']) ? gnl_jwt_payload($tok['id_token']) : array(), is_array($ui) ? $ui : array());
    $get = function ($k, $d = '') use ($claims) { return (isset($claims[$k]) && $claims[$k] !== null) ? $claims[$k] : $d; };
    $given  = (string) $get('given_name');
    $family = (string) $get('family_name');
    $phone  = $get('phone_number') !== '' ? $get('phone_number') : $get('phone');
    // Données société : portées par l'ORGANISATION (claim "organization"), pas par l'utilisateur
    $org = gnl_org_extract($get('organization', null));
    $oa  = $org['attributes'];
    $A = function ($k) use ($oa) { return isset($oa[$k]) ? (string) $oa[$k] : ''; };
    $voie = trim($A('voie_nbr') . ' ' . $A('voie_name'));

    $_SESSION['gnl_user'] = array(
        'sub'            => $ui['sub'],
        'email'          => (string) $get('email'),
        'given_name'     => $given,
        'family_name'    => $family,
        'name'           => $get('name') !== '' ? (string) $get('name') : trim($given . ' ' . $family),
        'civilite'       => (string) $get('civilite'),
        'phone'          => (string) $phone,
        // --- Société (organisation Keycloak) ---
        'organization'   => $org['name'],
        'raison_social'  => $A('raison') !== '' ? $A('raison') : $A('raison_social'),
        'nom_commercial' => $A('nom_commercial'),
        'entite_legal'   => $A('entite_legal'),
        'siret'          => $A('siret'),
        'siren'          => $A('siren'),
        'tva'            => $A('tva'),
        'ent_email'      => $A('ent_email'),
        'ent_phone'      => $A('telephone'),
        'adr_voie'       => $voie,
        'adr_cp'         => $A('cp'),
        'adr_ville'      => $A('commune'),
        'adr_pays'       => $A('pays'),
        'auth_at'        => time(),
    );
    if (!empty($tok['id_token'])) $_SESSION['gnl_id_token'] = $tok['id_token'];

    $return = gnl_safe_return(isset($st['return']) ? $st['return'] : '/commande');
    unset($_SESSION['kc_login']);
    session_regenerate_id(true); // anti-fixation, conserve les données (dont le panier)
    header('Location: ' . gnl_site_base() . $return);
    exit;
}

if ($action === 'logout') {
    $idt = isset($_SESSION['gnl_id_token']) ? $_SESSION['gnl_id_token'] : '';
    unset($_SESSION['gnl_user'], $_SESSION['gnl_id_token']);
    header('Location: ' . KC_OIDC . '/logout?' . http_build_query(array_filter(array(
        'post_logout_redirect_uri' => KEYCLOAK_POST_LOGOUT,
        'client_id'                => KEYCLOAK_CLIENT_ID,
        'id_token_hint'            => $idt !== '' ? $idt : null,
    ), function ($v) { return $v !== null && $v !== ''; })));
    exit;
}

header('Location: ' . gnl_site_base() . '/');
exit;
