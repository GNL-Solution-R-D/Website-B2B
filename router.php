<?php
/* =====================================================================
   GNL Solution — contrôleur frontal (toutes les URL passent par ici)
   ---------------------------------------------------------------------
   https://<région>.gnl-solution.com/<langue>/<page>
     1. hôte -> région (eu, ch, ca, uk, in ; racine = aiguillage) ;
     2. première visite (pas de cookie gnl_loc), hors robots : si la
        géolocalisation désigne une autre région, redirection vers elle ;
     3. URL sans langue -> redirection vers /<langue>/… (cookie, sinon
        géolocalisation, sinon navigateur, sinon langue par défaut) ;
     4. la page PHP est exécutée, sa sortie est traduite et ses liens
        internes reçoivent le préfixe de langue (include/i18n.php).
   ===================================================================== */

require __DIR__ . '/include/i18n.php';

$R      = gnl_i18n_regions();
$host   = strtolower(preg_replace('/:\d+$/', '', isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : ''));
$uri    = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
$path   = (string) parse_url($uri, PHP_URL_PATH);
if ($path === '/router.php' || $path === '/index.php') $path = '/';   // appel direct du fichier = page d'accueil
$query  = (string) parse_url($uri, PHP_URL_QUERY);
$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
$isBot  = gnl_i18n_is_bot();
$qs     = $query !== '' ? '?' . $query : '';

$GLOBALS['GNL_ROUTED'] = true;   // index.php appelé directement (Apache sans .htaccess) repasse par ici

/* ---------- Région d'après l'hôte ---------- */
$region = null; $dev = false;
/* Par défaut : un sous-domaine par région (eu./ch./ca./uk.). GNL_SINGLE_HOST=1 = tout sur un seul
   domaine, région choisie par géolocalisation, cookie ou ?gnl_region (dépannage, préproduction). */
$single = (gnl_cfg('GNL_SINGLE_HOST', '0') === '1');
if (!$single) {
    foreach ($R as $rk => $r) if ($host === $r['host']) { $region = $rk; break; }
    if ($region === null) {
        if ($host === GNL_ROOT_HOST || $host === 'www.' . GNL_ROOT_HOST) $region = 'root';
        else { $region = GNL_DEV_REGION; $dev = true; }    // localhost, préproduction : une seule adresse
    }
}

/* ---------- Domaine unique (GNL_SINGLE_HOST=1) ou hôte de test ----------
   La région ne vient pas du sous-domaine : ?gnl_region=ch (lien de la fenêtre
   « Pays et langue »), sinon choix mémorisé, sinon géolocalisation. */
if ($single || $dev) {
    $want = isset($_GET['gnl_region']) ? (string) $_GET['gnl_region'] : '';
    if ($want !== '' && isset($R[$want])) {
        if ($single) {
            $sv = gnl_i18n_saved();
            $l0 = ($sv && in_array($sv[1], $R[$want]['langs'], true)) ? $sv[1] : $R[$want]['default'];
            gnl_i18n_save($want, $l0);
        } else {
            setcookie('gnl_dev_region', $want, array('expires' => 0, 'path' => '/', 'samesite' => 'Lax'));
        }
        $cleanQ = $_GET; unset($cleanQ['gnl_region']);
        $cleanQ = $cleanQ ? '?' . http_build_query($cleanQ) : '';
        header('Location: ' . $path . $cleanQ, true, 302);
        header('Cache-Control: private, no-store');
        exit;
    }
    if ($single) {
        $sv = gnl_i18n_saved();
        if ($sv) {
            $region = $sv[0];
        } else {
            list($region, $geoLang) = gnl_router_target();
            if ($region !== 'in') $GLOBALS['gnl_router_geo_lang'] = $geoLang;
        }
    } else {
        $dr = isset($_COOKIE['gnl_dev_region']) ? (string) $_COOKIE['gnl_dev_region'] : '';
        if (isset($R[$dr])) $region = $dr;
    }
}

/* ---------- Fichiers techniques hors langue ---------- */
if ($path === '/keycloak_callback.php') { require __DIR__ . '/keycloak_callback.php'; return; }
if ($path === '/entreprise-lookup' || $path === '/entreprise_lookup') { chdir(__DIR__ . '/pages'); require __DIR__ . '/pages/entreprise_lookup.php'; return; }

/* ---------- Langue dans l'URL ---------- */
$langs = $R['eu']['langs'];
$lang = null; $rest = $path;
if (preg_match('#^/([a-z]{2})(/.*)?$#', $path, $m) && in_array($m[1], $langs, true)) {
    $lang = $m[1];
    $rest = isset($m[2]) && $m[2] !== '' ? $m[2] : '/';
}
if ($rest === '/router.php' || $rest === '/index.php') $rest = '/';   // /fr/router.php = accueil

/* ---------- Scripts de page traduits : /<langue>/js/<nom>.js (avant toute redirection) ---------- */
if ($lang !== null && $region !== 'root' && preg_match('#^/js/([a-z0-9-]+)\.js$#', $rest, $jm)) {
    $jr = ($region === 'in' || !isset($R[$region])) ? 'eu' : $region;
    $GLOBALS['GNL_I18N'] = array('region' => $jr, 'lang' => $lang, 'rest' => $rest, 'dev' => $dev, 'single' => $single);
    gnl_i18n_serve_js($jm[1]);
    return;
}

function gnl_router_redirect($url, $code = 302) {
    header('Location: ' . $url, true, $code);
    header('Vary: Accept-Language, Cookie');
    header('Cache-Control: private, no-store');
    exit;
}
/* Cible de la visite : choix mémorisé, sinon géolocalisation, sinon repli. */
function gnl_router_target() {
    $saved = gnl_i18n_saved();
    if ($saved) return $saved;
    $geo = gnl_geo_locate();
    if ($geo) return gnl_i18n_target($geo['country'], $geo['sub']);
    $R = gnl_i18n_regions();
    $fb = isset($R[GNL_GEO_FALLBACK]) ? GNL_GEO_FALLBACK : 'eu';
    return array($fb, gnl_i18n_pick($R[$fb]['langs'], $R[$fb]['default']));
}

/* ---------- Domaine racine : aiguillage ---------- */
if ($region === 'root') {
    if ($isBot && !gnl_i18n_saved()) {                       // robots : page de choix du pays
        $GLOBALS['GNL_I18N'] = array('region' => 'eu', 'lang' => 'fr', 'rest' => '/', 'dev' => false);
        chdir(__DIR__ . '/pages');
        ob_start('gnl_i18n_filter');
        require __DIR__ . '/pages/choisir-pays.php';
        return;
    }
    list($tr, $tl) = gnl_router_target();
    gnl_router_redirect('https://' . $R[$tr]['host'] . '/' . $tl . ($lang !== null ? $rest : ($path === '' ? '/' : $path)) . $qs);
}

/* ---------- Région « in » : service non disponible ---------- */
if ($region === 'in') {
    if ($lang === null) gnl_router_redirect('/' . gnl_i18n_pick($R['in']['langs'], 'en') . '/');
    $GLOBALS['GNL_I18N'] = array('region' => 'in', 'lang' => $lang, 'rest' => '/', 'dev' => $dev, 'single' => $single);
    http_response_code(451);
    chdir(__DIR__ . '/pages');
    ob_start('gnl_i18n_filter');
    require __DIR__ . '/pages/indisponible.php';
    return;
}

$allowed = $R[$region]['langs'];

/* ---------- Première visite : bonne région ? ---------- */
if (!$dev && !$single && !$isBot && ($method === 'GET' || $method === 'HEAD') && !gnl_i18n_saved()) {
    $geo = gnl_geo_locate();
    if ($geo) {
        list($tr, $tl) = gnl_i18n_target($geo['country'], $geo['sub']);
        if ($tr !== $region) {
            $target = ($tr === 'in') ? 'https://' . $R['in']['host'] . '/' . $tl . '/'
                                     : 'https://' . $R[$tr]['host'] . '/' . $tl . ($lang !== null ? $rest : $path) . $qs;
            gnl_router_redirect($target);
        }
        if (in_array($tl, $allowed, true)) $GLOBALS['gnl_router_geo_lang'] = $tl;  // même région : langue géolocalisée
    }
}

/* ---------- Langue absente ou non proposée dans la région ---------- */
$wantsHtml = stripos(isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : '', 'text/html') !== false;
$pickLang = function () use ($region, $allowed, $R) {
    $saved = gnl_i18n_saved();
    if ($saved && $saved[0] === $region) return $saved[1];
    return gnl_i18n_pick($allowed, $R[$region]['default']);
};
if ($lang === null || !in_array($lang, $allowed, true)) {
    $target = ($lang !== null) ? $pickLang() : (isset($GLOBALS['gnl_router_geo_lang']) ? $GLOBALS['gnl_router_geo_lang'] : $pickLang());
    $isAjax = isset($_GET['ajax']) || !$wantsHtml;
    if (($method === 'GET' || $method === 'HEAD') && !$isAjax) {
        gnl_router_redirect('/' . $target . ($lang !== null ? $rest : $path) . $qs);
    }
    // POST ou appel AJAX sans préfixe : on sert directement, dans la langue mémorisée
    if ($lang === null) $rest = ($path === '' ? '/' : $path);
    $lang = $target;
}

/* ---------- Page demandée ---------- */
$GLOBALS['GNL_I18N'] = array('region' => $region, 'lang' => $lang, 'rest' => $rest, 'dev' => $dev, 'single' => $single);
if (!$dev && !$isBot) gnl_i18n_save($region, $lang);
header('Content-Language: ' . $lang);

$slug = trim($rest, '/');
if (substr($slug, -4) === '.php') $slug = substr($slug, 0, -4);
$blocked = array('config-legal', 'keycloak_rest', 'choisir-pays', 'indisponible', 'index');
if ($slug === '') {
    $file = __DIR__ . '/index.php';
} elseif (preg_match('/^[a-z0-9_-]+$/', $slug) && !in_array($slug, $blocked, true) && is_file(__DIR__ . '/pages/' . $slug . '.php')) {
    $file = __DIR__ . '/pages/' . $slug . '.php';
} else {
    $file = __DIR__ . '/pages/404.php';
    http_response_code(404);
}

$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/' . $lang . ($slug === '' ? '/' : '/' . $slug);
header_register_callback('gnl_i18n_fix_location');
ob_start('gnl_i18n_filter');
chdir(dirname($file));
require $file;
