<?php
/* =====================================================================
   GNL Solution — régions, langues et traduction
   ---------------------------------------------------------------------
   Région (sous-domaine)          Langues (préfixe d'URL)
     eu.gnl-solution.com          /bg /cs /da /de /el /en /es /et /fi /fr /ga
                                  /hr /hu /it /lt /lv /mt /nl /pl /pt /ro /sk /sl /sv
     ca.gnl-solution.com          /en /fr      (Québec -> fr, ailleurs -> en)
     ch.gnl-solution.com          /fr /de /it  (selon le canton)
     uk.gnl-solution.com          /en
     in.gnl-solution.com           page « service non disponible dans votre pays »
     gnl-solution.com (racine)    aiguillage automatique vers la bonne région

   Le site est rédigé en français. Pour les autres langues, la page
   produite est traduite à la volée (gnl_i18n_filter) à partir du
   catalogue lang/<code>.php : clé = segment français, valeur = traduction.
   ===================================================================== */

require_once __DIR__ . '/config.php';

/* ------------------------------ Réglages ------------------------------ */
if (!defined('GNL_ROOT_HOST'))     define('GNL_ROOT_HOST', gnl_cfg('GNL_ROOT_HOST', 'gnl-solution.com'));
if (!defined('GNL_COOKIE_DOMAIN')) define('GNL_COOKIE_DOMAIN', gnl_cfg('GNL_COOKIE_DOMAIN', '.gnl-solution.com'));
if (!defined('GNL_GEOIP_DB'))      define('GNL_GEOIP_DB', gnl_cfg('GNL_GEOIP_DB', dirname(__DIR__) . '/geoip/GeoLite2-City.mmdb'));
if (!defined('GNL_GEO_FALLBACK'))  define('GNL_GEO_FALLBACK', gnl_cfg('GNL_GEO_FALLBACK', 'eu'));   // pays inconnu (IP privée, base absente)
if (!defined('GNL_DEV_REGION'))    define('GNL_DEV_REGION', gnl_cfg('GNL_DEV_REGION', 'eu'));       // hôte inconnu (localhost, préproduction)
if (!defined('GNL_GEO_DEBUG'))     define('GNL_GEO_DEBUG', gnl_cfg('GNL_GEO_DEBUG', '0') === '1');  // autorise ?geo=CH-VD pour tester
if (!defined('GNL_TRUST_PROXY'))   define('GNL_TRUST_PROXY', gnl_cfg('GNL_TRUST_PROXY', '0') === '1'); // lire X-Forwarded-For

function gnl_i18n_regions() {
    static $r = null;
    if ($r !== null) return $r;
    $eu = array('bg','cs','da','de','el','en','es','et','fi','fr','ga','hr','hu','it','lt','lv','mt','nl','pl','pt','ro','sk','sl','sv');
    return $r = array(
        'eu' => array('host' => gnl_cfg('GNL_HOST_EU', 'eu.gnl-solution.com'), 'langs' => $eu, 'default' => 'en', 'name' => 'Union européenne'),
        'ch' => array('host' => gnl_cfg('GNL_HOST_CH', 'ch.gnl-solution.com'), 'langs' => array('fr','de','it'), 'default' => 'de', 'name' => 'Suisse'),
        'ca' => array('host' => gnl_cfg('GNL_HOST_CA', 'ca.gnl-solution.com'), 'langs' => array('en','fr'), 'default' => 'en', 'name' => 'Canada'),
        'uk' => array('host' => gnl_cfg('GNL_HOST_UK', 'uk.gnl-solution.com'), 'langs' => array('en'), 'default' => 'en', 'name' => 'Royaume-Uni'),
        'in' => array('host' => gnl_cfg('GNL_HOST_IN', 'in.gnl-solution.com'),  'langs' => $eu, 'default' => 'en', 'name' => 'Autres pays'),
    );
}

function gnl_i18n_lang_names() {
    return array('bg' => 'Български', 'cs' => 'Čeština', 'da' => 'Dansk', 'de' => 'Deutsch', 'el' => 'Ελληνικά', 'en' => 'English',
        'es' => 'Español', 'et' => 'Eesti', 'fi' => 'Suomi', 'fr' => 'Français', 'ga' => 'Gaeilge', 'hr' => 'Hrvatski', 'hu' => 'Magyar',
        'it' => 'Italiano', 'lt' => 'Lietuvių', 'lv' => 'Latviešu', 'mt' => 'Malti', 'nl' => 'Nederlands', 'pl' => 'Polski', 'pt' => 'Português',
        'ro' => 'Română', 'sk' => 'Slovenčina', 'sl' => 'Slovenščina', 'sv' => 'Svenska');
}

/* Balise hreflang / locale par couple région + langue. */
function gnl_i18n_locale($region, $lang) {
    if ($region === 'ch') return $lang . '-CH';
    if ($region === 'ca') return $lang . '-CA';
    if ($region === 'uk') return 'en-GB';
    $map = array('en' => 'en-IE', 'el' => 'el-GR', 'cs' => 'cs-CZ', 'da' => 'da-DK', 'et' => 'et-EE', 'ga' => 'ga-IE', 'sl' => 'sl-SI', 'sv' => 'sv-SE');
    return isset($map[$lang]) ? $map[$lang] : $lang . '-' . strtoupper($lang);
}

/* ----------------------- Pays -> région + langue ----------------------- */
/* Pour chaque pays de l'UE : langue par défaut, puis langues acceptées si le
   navigateur les préfère (pays ou régions multilingues). */
function gnl_i18n_eu_countries() {
    return array(
        'AT' => array('de'), 'BE' => array('fr', 'nl', 'de'), 'BG' => array('bg'), 'HR' => array('hr'), 'CY' => array('el', 'en'),
        'CZ' => array('cs'), 'DK' => array('da'), 'EE' => array('et'), 'FI' => array('fi', 'sv'), 'FR' => array('fr'), 'DE' => array('de'),
        'GR' => array('el'), 'HU' => array('hu'), 'IE' => array('en', 'ga'), 'IT' => array('it'), 'LV' => array('lv'), 'LT' => array('lt'),
        'LU' => array('fr', 'de'), 'MT' => array('mt', 'en'), 'NL' => array('nl'), 'PL' => array('pl'), 'PT' => array('pt'), 'RO' => array('ro'),
        'SK' => array('sk'), 'SI' => array('sl'), 'ES' => array('es'), 'SE' => array('sv'),
        // Régions ultrapériphériques et territoires de l'UE identifiés à part par GeoIP
        'GP' => array('fr'), 'MQ' => array('fr'), 'GF' => array('fr'), 'RE' => array('fr'), 'YT' => array('fr'), 'MF' => array('fr'), 'AX' => array('sv', 'fi'),
    );
}

/* Langues préférées du navigateur (codes primaires, par ordre de préférence). */
function gnl_i18n_browser_langs() {
    $h = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? (string) $_SERVER['HTTP_ACCEPT_LANGUAGE'] : '';
    $out = array();
    foreach (explode(',', $h) as $part) {
        $bits = explode(';', trim($part));
        $code = strtolower(substr(trim($bits[0]), 0, 2));
        if ($code === '' || $code === '*') continue;
        $q = 1.0;
        if (isset($bits[1]) && preg_match('/q=([0-9.]+)/', $bits[1], $m)) $q = (float) $m[1];
        if (!isset($out[$code]) || $out[$code] < $q) $out[$code] = $q;
    }
    arsort($out);
    return array_keys($out);
}
function gnl_i18n_pick($candidates, $default) {
    foreach (gnl_i18n_browser_langs() as $b) if (in_array($b, $candidates, true)) return $b;
    return $default;
}

/* Région + langue cibles pour un pays (ISO 3166-1) et une subdivision (ISO 3166-2). */
function gnl_i18n_target($country, $sub) {
    $country = strtoupper((string) $country); $sub = strtoupper((string) $sub);
    $sub = preg_replace('/^[A-Z]{2}-/', '', $sub);
    // Royaume-Uni et dépendances de la Couronne
    if (in_array($country, array('GB', 'UK', 'IM', 'JE', 'GG'), true)) return array('uk', 'en');
    // Suisse : langue du canton (cantons bilingues : préférence du navigateur)
    if ($country === 'CH') {
        if (in_array($sub, array('GE', 'VD', 'NE', 'JU'), true)) return array('ch', 'fr');
        if ($sub === 'TI') return array('ch', 'it');
        if ($sub === 'FR' || $sub === 'VS') return array('ch', gnl_i18n_pick(array('fr', 'de'), 'fr'));
        if ($sub === 'BE') return array('ch', gnl_i18n_pick(array('de', 'fr'), 'de'));
        if ($sub === 'GR') return array('ch', gnl_i18n_pick(array('de', 'it'), 'de'));
        if ($sub === '')   return array('ch', gnl_i18n_pick(array('de', 'fr', 'it'), 'de'));
        return array('ch', 'de');
    }
    // Canada : Québec francophone, Nouveau-Brunswick bilingue, ailleurs anglophone
    if ($country === 'CA') {
        if ($sub === 'QC') return array('ca', 'fr');
        if ($sub === 'NB' || $sub === '') return array('ca', gnl_i18n_pick(array('en', 'fr'), 'en'));
        return array('ca', 'en');
    }
    // Union européenne
    $eu = gnl_i18n_eu_countries();
    if (isset($eu[$country])) {
        $langs = $eu[$country];
        if ($country === 'BE') {
            $flandre  = array('VLG', 'VAN', 'VBR', 'VLI', 'VOV', 'VWV');
            $wallonie = array('WAL', 'WBR', 'WHT', 'WLG', 'WLX', 'WNA');
            if (in_array($sub, $flandre, true))  return array('eu', 'nl');
            if (in_array($sub, $wallonie, true)) return array('eu', gnl_i18n_pick(array('fr', 'de'), 'fr'));
            return array('eu', gnl_i18n_pick(array('fr', 'nl'), 'fr'));   // Bruxelles-Capitale
        }
        return array('eu', count($langs) > 1 ? gnl_i18n_pick($langs, $langs[0]) : $langs[0]);
    }
    // Reste du monde
    $R = gnl_i18n_regions();
    return array('in', gnl_i18n_pick($R['in']['langs'], 'en'));
}

/* ------------------------- Géolocalisation ---------------------------- */
function gnl_i18n_client_ip() {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    if (GNL_TRUST_PROXY && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = array_map('trim', explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']));
        if (filter_var($parts[0], FILTER_VALIDATE_IP)) $ip = $parts[0];
    }
    return $ip;
}
/* array('country' => 'CH', 'sub' => 'VD') ou null si inconnu. */
function gnl_geo_locate() {
    if (GNL_GEO_DEBUG && !empty($_GET['geo']) && preg_match('/^([A-Za-z]{2})(?:-([A-Za-z0-9]{1,3}))?$/', (string) $_GET['geo'], $m)) {
        return array('country' => strtoupper($m[1]), 'sub' => isset($m[2]) ? strtoupper($m[2]) : '');
    }
    // Variables posées par le serveur web (module geoip2 de nginx / Apache)
    foreach (array('GEOIP2_COUNTRY_CODE', 'MM_COUNTRY_CODE', 'GEOIP_COUNTRY_CODE') as $k) {
        if (!empty($_SERVER[$k])) {
            $sub = '';
            foreach (array('GEOIP2_SUBDIVISION_CODE', 'MM_SUBDIVISION_1_ISO_CODE', 'GEOIP_REGION') as $s) if (!empty($_SERVER[$s])) { $sub = $_SERVER[$s]; break; }
            return array('country' => strtoupper($_SERVER[$k]), 'sub' => strtoupper($sub));
        }
    }
    $ip = gnl_i18n_client_ip();
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return null;
    require_once __DIR__ . '/geoip.php';
    return gnl_geoip_lookup($ip, GNL_GEOIP_DB);
}

function gnl_i18n_is_bot() {
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
    return $ua === '' || (bool) preg_match('/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|embedly|quora link|outbrain|pinterest|vkshare|w3c_validator|lighthouse|curl|wget|python-requests|httpclient/i', $ua);
}

/* ------------------------------ Cookie -------------------------------- */
/* Cookie de préférence « gnl_loc=ch:fr » (13 mois), partagé par les sous-domaines.
   Cookie de personnalisation d'interface : exempté de consentement (CNIL). */
function gnl_i18n_saved() {
    if (empty($_COOKIE['gnl_loc']) || !preg_match('/^([a-z]{2}):([a-z]{2})$/', (string) $_COOKIE['gnl_loc'], $m)) return null;
    $R = gnl_i18n_regions();
    if (!isset($R[$m[1]]) || $m[1] === 'in' || !in_array($m[2], $R[$m[1]]['langs'], true)) return null;
    return array($m[1], $m[2]);
}
function gnl_i18n_save($region, $lang) {
    if (headers_sent() || $region === 'in') return;
    $cur = isset($_COOKIE['gnl_loc']) ? $_COOKIE['gnl_loc'] : '';
    if ($cur === $region . ':' . $lang) return;
    $host = isset($_SERVER['HTTP_HOST']) ? strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'])) : '';
    $cd = ltrim(GNL_COOKIE_DOMAIN, '.');
    $dom = ($cd !== '' && substr($host, -strlen($cd)) === $cd) ? GNL_COOKIE_DOMAIN : '';
    setcookie('gnl_loc', $region . ':' . $lang, array('expires' => time() + 34186669, 'path' => '/', 'domain' => $dom,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => false, 'samesite' => 'Lax'));
    $_COOKIE['gnl_loc'] = $region . ':' . $lang;
}

/* --------------------------- État courant ----------------------------- */
if (!isset($GLOBALS['GNL_I18N'])) {
    $GLOBALS['GNL_I18N'] = array('region' => GNL_DEV_REGION, 'lang' => 'fr', 'rest' => '/', 'dev' => true);
}
function gnl_lang()   { return $GLOBALS['GNL_I18N']['lang']; }
function gnl_region() { return $GLOBALS['GNL_I18N']['region']; }

/* URL d'une page dans une région/langue (sélecteur, hreflang). */
function gnl_i18n_url($region, $lang, $rest = null) {
    if ($rest === null) $rest = $GLOBALS['GNL_I18N']['rest'];
    $R = gnl_i18n_regions();
    $path = '/' . $lang . ($rest === '/' ? '/' : $rest);
    if (!isset($R[$region])) return $path;
    if (!empty($GLOBALS['GNL_I18N']['dev']) || !empty($GLOBALS['GNL_I18N']['single'])) {   // un seul domaine / hôte de test
        return $region === $GLOBALS['GNL_I18N']['region'] ? $path : $path . '?gnl_region=' . $region;
    }
    return 'https://' . $R[$region]['host'] . $path;
}

/* ----------------------------- Catalogue ------------------------------ */
function gnl_i18n_catalog($lang = null) {
    static $cache = array();
    if ($lang === null) $lang = gnl_lang();
    if ($lang === 'fr') return array();
    if (!isset($cache[$lang])) {
        $f = dirname(__DIR__) . '/lang/' . preg_replace('/[^a-z]/', '', $lang) . '.php';
        $cache[$lang] = is_readable($f) ? (array) include $f : array();
    }
    return $cache[$lang];
}
/* Les nombres d'un segment sont remplacés par {0}, {1}… pour que « Plus que
   5 places » et « Plus que 3 places » partagent la même traduction. */
/* Clé de catalogue d'un segment :
   - espaces normalisés ;
   - nombres du texte -> {0}, {1}… ;
   - balises -> <x1>…</x1>, <x2/>… (les attributs, styles et liens ne font pas
     partie de la clé ; ils sont restitués tels quels après traduction) ;
     <br> reste <br>. */
function gnl_i18n_key($s, &$nums = null, &$tags = null) {
    $s = trim(preg_replace('/\s+/u', ' ', (string) $s));
    $nums = array(); $tags = array();
    $i = 0; $t = 0; $stack = array();
    $parts = preg_split('/(<[^>]*>)/', $s, -1, PREG_SPLIT_DELIM_CAPTURE);
    foreach ($parts as $pi => $part) {
        if ($part === '') continue;
        if ($part[0] === '<') {
            if (preg_match('/^<br\s*\/?>$/i', $part)) { $parts[$pi] = '<br>'; continue; }
            if (strpos($part, '<!--') === 0) { $parts[$pi] = ''; continue; }
            if (isset($part[1]) && $part[1] === '/') {
                $n = array_pop($stack);
                if ($n === null) { $parts[$pi] = ''; continue; }
                $tags[$n]['close'] = $part;
                $parts[$pi] = '</x' . $n . '>';
            } else {
                $t++;
                $tags[$t] = array('open' => $part, 'close' => '');
                if (preg_match('/^<(img|input|wbr|hr)\b/i', $part) || substr($part, -2) === '/>') $parts[$pi] = '<x' . $t . '/>';
                else { $stack[] = $t; $parts[$pi] = '<x' . $t . '>'; }
            }
            continue;
        }
        $parts[$pi] = preg_replace_callback('/(?<![\w#&;-])\d+(?:[.,\x{00A0}\x{202F} ]\d+)*(?![\w-])/u', function ($m) use (&$nums, &$i) { $nums[] = $m[0]; return '{' . ($i++) . '}'; }, $part);
    }
    return trim(preg_replace('/\s+/u', ' ', implode('', $parts)));
}
function gnl_i18n_lookup($s) {
    $cat = gnl_i18n_catalog();
    if (!$cat) return null;
    $key = gnl_i18n_key($s, $nums, $tags);
    if ($key === '' || !isset($cat[$key]) || $cat[$key] === '') {
        /* Journal des segments sans traduction : GNL_I18N_MISSING_LOG=/chemin/manquants.log */
        static $logged = array();
        $log = gnl_cfg('GNL_I18N_MISSING_LOG', '');
        if ($log !== '' && $key !== '' && !isset($logged[$key]) && preg_match('/\p{L}{2}/u', strip_tags($key))) {
            $logged[$key] = true;
            @file_put_contents($log, gnl_lang() . "\t" . str_replace(array("\t", "\n"), ' ', $key) . "\n", FILE_APPEND | LOCK_EX);
        }
        return null;
    }
    $out = $cat[$key];
    if ($nums) $out = preg_replace_callback('/\{(\d+)\}/', function ($m) use ($nums) { return isset($nums[(int) $m[1]]) ? $nums[(int) $m[1]] : $m[0]; }, $out);
    if ($tags) $out = preg_replace_callback('/<(\/?)x(\d+)(\/?)>/', function ($m) use ($tags) {
        $n = (int) $m[2];
        if (!isset($tags[$n])) return '';
        return $m[1] === '/' ? $tags[$n]['close'] : $tags[$n]['open'];
    }, $out);
    return $out;
}
/* Traduction d'une chaîne utilisée dans du PHP (données JSON, messages…). */
function gnl_t($s) {
    gnl_i18n_collect($s, 'php');
    $tr = gnl_i18n_lookup($s);
    return $tr === null ? $s : $tr;
}
/* Mode collecte (outil de traduction) : GNL_I18N_COLLECT=/chemin/fichier */
function gnl_i18n_collect($s, $kind) {
    static $f = null;
    if ($f === null) $f = gnl_cfg('GNL_I18N_COLLECT', '');
    if ($f === '') return;
    $key = gnl_i18n_key($s);
    if ($key === '' || !preg_match('/\p{L}{2}/u', strip_tags($key))) return;
    @file_put_contents($f, json_encode(array('k' => $key, 't' => $kind, 'u' => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}

/* ===================== Filtre de sortie HTML ==========================
   1. met en réserve <script>, <style>, <svg>, <textarea> (jamais réécrits) ;
   2. découpe le HTML en arbre léger et traduit chaque « segment » : suite de
      texte et de balises de mise en forme (a, strong, em, br…) ;
   3. traduit les attributs alt, title, placeholder, aria-label, description ;
   4. préfixe les liens internes par la langue : href="/contact" -> "/de/contact" ;
   5. traduit les chaînes des scripts en ligne présentes dans le catalogue.
   Le HTML d'origine est conservé octet pour octet hors des zones traduites. */
function gnl_i18n_is_html_response() {
    foreach (headers_list() as $h) {
        if (stripos($h, 'Content-Type:') === 0) return stripos($h, 'text/html') !== false;
    }
    return true;
}

function gnl_i18n_filter($html) {
    if ($html === '' || !gnl_i18n_is_html_response()) return $html;
    if (stripos($html, '<html') === false && stripos($html, '<body') === false && stripos($html, '<main') === false) return $html;
    $lang = gnl_lang();
    $translate = ($lang !== 'fr') || gnl_cfg('GNL_I18N_COLLECT', '') !== '';
    $stash = array();
    $html = preg_replace_callback('#<(script|style|svg|textarea)\b[^>]*>.*?</\1\s*>#is', function ($m) use (&$stash, $translate) {
        $raw = $m[0];
        $tag = strtolower($m[1]);
        if ($translate && $tag === 'script') $raw = gnl_i18n_script($raw);
        if ($tag === 'svg' || $tag === 'textarea') $raw = preg_replace_callback('/^<[^>]+>/', function ($o) use ($translate) { return gnl_i18n_attrs($o[0], $translate); }, $raw);
        $stash[] = $raw;
        return "\x01" . (count($stash) - 1) . "\x02";
    }, $html);
    $tokens = preg_split('/(<!--.*?-->|<![^>]*>|<\/?[a-zA-Z][^>]*>|\x01\d+\x02)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
    $root = gnl_i18n_tree($tokens);
    $out = gnl_i18n_render($root, $translate);
    $out = preg_replace('/<html\b([^>]*)\blang="[^"]*"/i', '<html$1lang="' . $lang . '"', $out, 1);
    return preg_replace_callback('/\x01(\d+)\x02/', function ($m) use ($stash) { return $stash[(int) $m[1]]; }, $out);
}

function gnl_i18n_tree($tokens) {
    static $void = array('area','base','br','col','embed','hr','img','input','link','meta','source','track','wbr');
    $root = array('t' => 'el', 'name' => '#root', 'open' => '', 'close' => '', 'kids' => array());
    $stack = array(&$root);
    foreach ($tokens as $tok) {
        $top = &$stack[count($stack) - 1];
        if ($tok[0] === '<' && isset($tok[1]) && $tok[1] === '/') {
            $name = strtolower(trim(substr($tok, 2, -1)));
            $found = false;
            for ($i = count($stack) - 1; $i > 0; $i--) {
                if ($stack[$i]['name'] === $name) {
                    $stack[$i]['close'] = $tok;
                    array_splice($stack, $i);
                    $found = true;
                    break;
                }
            }
            if (!$found) $top['kids'][] = array('t' => 'raw', 'raw' => $tok);
        } elseif ($tok[0] === '<' && preg_match('/^<([a-zA-Z][a-zA-Z0-9-]*)/', $tok, $m)) {
            $name = strtolower($m[1]);
            $top['kids'][] = array('t' => 'el', 'name' => $name, 'open' => $tok, 'close' => '', 'kids' => array());
            if (!in_array($name, $void, true) && substr($tok, -2) !== '/>') {
                $stack[] = &$top['kids'][count($top['kids']) - 1];
            }
        } elseif ($tok[0] === "\x01") {
            $top['kids'][] = array('t' => 'stash', 'raw' => $tok);
        } elseif ($tok[0] === '<') {
            $top['kids'][] = array('t' => 'raw', 'raw' => $tok);
        } else {
            $top['kids'][] = array('t' => 'text', 'raw' => $tok);
        }
        unset($top);
    }
    return $root;
}

function gnl_i18n_inline_ok($n) {
    static $inline = array('a','abbr','b','bdi','bdo','br','cite','code','data','dfn','em','i','kbd','mark','q','s','samp','small','span','strong','sub','sup','time','u','var','wbr');
    if ($n['t'] === 'text') return true;
    if ($n['t'] === 'raw') return strpos($n['raw'], '<!--') === 0;
    if ($n['t'] !== 'el') return false;
    if (!in_array($n['name'], $inline, true)) return false;
    if (stripos($n['open'], 'translate="no"') !== false) return false;
    foreach ($n['kids'] as $k) if (!gnl_i18n_inline_ok($k)) return false;
    return true;
}

/* $mode : 'tr' (traduire), 'links' (préfixer les liens seulement), 'raw' (tel quel). */
function gnl_i18n_render($n, $translate, $mode = null) {
    if ($mode === null) $mode = $translate ? 'tr' : 'links';
    if ($n['t'] !== 'el') return $n['raw'];
    $open = $n['open'];
    if ($mode !== 'raw' && $open !== '') $open = gnl_i18n_attrs($open, $mode === 'tr');
    if ($mode === 'tr' && stripos($n['open'], 'translate="no"') !== false) $mode = 'links';
    $inner = '';
    if ($mode !== 'tr') {
        foreach ($n['kids'] as $k) $inner .= ($k['t'] === 'el') ? gnl_i18n_render($k, false, $mode) : $k['raw'];
        return $open . $inner . $n['close'];
    }
    $run = array();
    $flush = function () use (&$run, &$inner) {
        if (!$run) return;
        $src = '';
        foreach ($run as $k) $src .= ($k['t'] === 'el') ? gnl_i18n_render($k, false, 'raw') : $k['raw'];
        $plain = trim(html_entity_decode(strip_tags($src), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $done = false;
        if ($plain !== '' && preg_match('/\p{L}/u', $plain)) {
            gnl_i18n_collect($src, 'html');
            $tr = gnl_i18n_lookup($src);
            if ($tr !== null) {
                preg_match('/^\s*/u', $src, $a); preg_match('/\s*$/u', $src, $b);
                $inner .= $a[0] . gnl_i18n_links($tr) . $b[0];
                $done = true;
            }
        }
        if (!$done) foreach ($run as $k) $inner .= ($k['t'] === 'el') ? gnl_i18n_render($k, false, 'links') : $k['raw'];
        $run = array();
    };
    foreach ($n['kids'] as $k) {
        if (gnl_i18n_inline_ok($k)) { $run[] = $k; continue; }
        $flush();
        $inner .= gnl_i18n_render($k, true, 'tr');
    }
    $flush();
    return $open . $inner . $n['close'];
}

/* Attributs traduisibles + préfixe de langue sur les liens internes. */
function gnl_i18n_attrs($tag, $translate = true) {
    if ($translate) {
        $isMeta = stripos($tag, '<meta') === 0;
        $isBtn  = (bool) preg_match('/^<input\b[^>]*\btype="(?:submit|button|reset)"/i', $tag);
        $tag = preg_replace_callback('/\s(alt|title|placeholder|aria-label|content|value)="([^"]*)"/i', function ($m) use ($isMeta, $isBtn, $tag) {
            $attr = strtolower($m[1]);
            if ($attr === 'content' && !($isMeta && preg_match('/\bname="(?:description|og:description|twitter:description)"|property="og:(?:title|description)"/i', $tag))) return $m[0];
            if ($attr === 'value' && !$isBtn) return $m[0];
            $val = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!preg_match('/\p{L}{2}/u', $val)) return $m[0];
            gnl_i18n_collect($val, 'attr');
            $tr = gnl_i18n_lookup($val);
            return $tr === null ? $m[0] : ' ' . $m[1] . '="' . htmlspecialchars($tr, ENT_QUOTES, 'UTF-8') . '"';
        }, $tag);
    }
    return gnl_i18n_links($tag);
}

/* href="/x" ou action="/x" -> "/<lang>/x" (hors ressources et URL techniques). */
function gnl_i18n_links($html) {
    $lang = gnl_lang();
    $R = gnl_i18n_regions();
    $langs = implode('|', $R['eu']['langs']);
    return preg_replace_callback('/\b(href|action|formaction)="\/(?!\/)([^"]*)"/i', function ($m) use ($lang, $langs) {
        $p = $m[2];
        if (preg_match('#^(?:assets/|keycloak_callback\.php|entreprise-lookup|favicon|robots\.txt|sitemap)#', $p)) return $m[0];
        if (preg_match('#^(?:' . $langs . ')(?:/|$|\?|\#)#', $p)) return $m[0];
        return $m[1] . '="/' . $lang . '/' . $p . '"';
    }, $html);
}

/* Chaînes des scripts en ligne : '…' et "…" présentes dans le catalogue. */
function gnl_i18n_script($raw) {
    if (preg_match('#^<script\b[^>]*type="application/(?:ld\+)?json"#i', $raw)) return $raw;
    if (strpos($raw, 'window.GNL_I18N=') !== false) return $raw;   // dictionnaire : clés françaises à conserver
    $loc = gnl_i18n_locale(gnl_region(), gnl_lang());
    $raw = str_replace(array("'fr-FR'", '"fr-FR"'), array("'" . $loc . "'", '"' . $loc . '"'), $raw);
    return preg_replace_callback('/\'((?:\\\\.|[^\'\\\\\n])*)\'|"((?:\\\\.|[^"\\\\\n])*)"/u', function ($m) {
        $dq = isset($m[2]) && $m[2] !== '';
        $body = $dq ? $m[2] : $m[1];
        if (!preg_match('/\p{L}{2}/u', $body) || !preg_match('/[\s\x{00C0}-\x{017F}]/u', $body)) return $m[0];
        $dec = json_decode('"' . ($dq ? $body : str_replace(array("\\'", '"'), array("'", '\\"'), $body)) . '"');
        if (!is_string($dec)) return $m[0];
        gnl_i18n_collect($dec, 'js');
        $tr = gnl_i18n_lookup($dec);
        return $tr === null ? $m[0] : json_encode($tr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }, $raw);
}

/* Réécrit l'en-tête Location des redirections internes avec le préfixe de langue. */
function gnl_i18n_fix_location() {
    foreach (headers_list() as $h) {
        if (stripos($h, 'Location:') !== 0) continue;
        $url = trim(substr($h, 9));
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
        $path = null; $prefix = '';
        if ($url !== '' && $url[0] === '/' && (!isset($url[1]) || $url[1] !== '/')) { $path = $url; }
        elseif ($host !== '' && preg_match('#^(https?://' . preg_quote($host, '#') . ')(/.*)?$#i', $url, $m)) { $prefix = $m[1]; $path = isset($m[2]) ? $m[2] : '/'; }
        if ($path === null) return;
        $new = substr(gnl_i18n_links('href="' . $path . '"'), 6, -1);
        if ($new !== $path) header('Location: ' . $prefix . $new, true, http_response_code() >= 300 ? http_response_code() : 302);
        return;
    }
}

/* Dictionnaire des chaînes JS externes (gnl.js, solutions.js) : window.gnlT(). */
/* Dictionnaire des scripts : fichier externe /<langue>/js/i18n.js (mis en cache), plus de bloc en ligne. */
function gnl_i18n_js_dict() {
    return gnl_js('i18n');
}
function gnl_i18n_js_dict_code() {
    $f = dirname(__DIR__) . '/lang/_js_keys.php';
    $keys = is_readable($f) ? (array) include $f : array();
    $cat = gnl_i18n_catalog();
    $d = array();
    foreach ($keys as $k) if (isset($cat[$k])) $d[$k] = $cat[$k];
    $json = json_encode((object) $d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    return 'window.GNL_LANG=' . json_encode(gnl_lang()) . ';window.GNL_LOCALE=' . json_encode(gnl_i18n_locale(gnl_region(), gnl_lang()))
         . ';window.GNL_I18N=' . $json . ';window.gnlT=function(s){var t=window.GNL_I18N[s];return t===undefined?s:t;};' . "\n";
}

/* ===================== Ressources externes (GreenIT) ==================
   Feuilles et scripts propres à une page : assets/gnl/page/<nom>.css|js.
   Le paramètre ?v= change à chaque modification : cache navigateur d'un an. */
function gnl_asset($p) {
    $f = dirname(__DIR__) . $p;
    return $p . (is_file($f) ? '?v=' . base_convert((string) filemtime($f), 10, 36) : '');
}
function gnl_css($name) {
    return '<link rel="stylesheet" href="' . htmlspecialchars(gnl_asset('/assets/gnl/page/' . $name . '.css'), ENT_QUOTES) . '">';
}
/* Script de page servi par le routeur (/<langue>/js/<nom>.js) : ses chaînes
   sont traduites comme l'étaient celles des scripts en ligne. */
function gnl_js($name) {
    $root = dirname(__DIR__);
    $f = $root . '/assets/gnl/page/' . $name . '.js';
    $l = $root . '/lang/' . gnl_lang() . '.php';
    $v = (is_file($f) ? filemtime($f) : 0) + (is_file($l) ? filemtime($l) : 0) + (is_file(__FILE__) ? filemtime(__FILE__) : 0);
    return '<script src="/' . gnl_lang() . '/js/' . $name . '.js?v=' . base_convert((string) $v, 10, 36) . '" defer></script>';
}
function gnl_i18n_serve_js($name) {
    header('Content-Type: application/javascript; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    if ($name === 'i18n') {
        $js = gnl_i18n_js_dict_code();
    } else {
        $f = dirname(__DIR__) . '/assets/gnl/page/' . $name . '.js';
        if (!preg_match('/^[a-z0-9-]+$/', $name) || !is_file($f)) {
            http_response_code(404); header('Cache-Control: no-store'); echo '/* introuvable */'; return;
        }
        $js = (string) file_get_contents($f);
        if (gnl_lang() !== 'fr' || gnl_cfg('GNL_I18N_COLLECT', '') !== '') {
            $w = gnl_i18n_script('<script>' . $js . '</script>');
            $js = substr($w, 8, -9);
        }
    }
    header('Cache-Control: public, max-age=31536000, immutable');
    header('ETag: "' . substr(md5($js), 0, 16) . '"');
    echo $js;
}

/* Balises <link rel="alternate" hreflang> de la page courante. */
function gnl_i18n_hreflang() {
    if (!empty($GLOBALS['GNL_I18N']['dev'])) return '';
    $out = '';
    if (!empty($GLOBALS['GNL_I18N']['single'])) {          // un seul domaine : une version par langue, sans région
        $R = gnl_i18n_regions();
        foreach ($R['eu']['langs'] as $l) {
            $out .= '<link rel="alternate" hreflang="' . $l . '" href="' . htmlspecialchars('/' . $l . ($GLOBALS['GNL_I18N']['rest'] === '/' ? '/' : $GLOBALS['GNL_I18N']['rest'])) . '">' . "\n";
        }
        return $out;
    }
    foreach (gnl_i18n_regions() as $rk => $r) {
        if ($rk === 'in') continue;
        foreach ($r['langs'] as $l) {
            $hl = ($rk === 'eu') ? $l : gnl_i18n_locale($rk, $l);
            $out .= '<link rel="alternate" hreflang="' . $hl . '" href="' . htmlspecialchars(gnl_i18n_url($rk, $l)) . '">' . "\n";
        }
    }
    return $out . '<link rel="alternate" hreflang="x-default" href="https://' . GNL_ROOT_HOST . '/">' . "\n";
}
