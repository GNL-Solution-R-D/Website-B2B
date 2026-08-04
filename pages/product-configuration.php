<?php
/* =====================================================================
   GNL Solution — Configuration produit  (product-configuration.php)
   ---------------------------------------------------------------------
   Page atteinte depuis index.php quand le client clique sur une offre.
   URL attendue :  product-configuration.php?slug=<slug_du_produit>

   Déroulé :
     1. On charge le produit (webhook n8n "product.list" -> repli product.csv)
        et les options disponibles (webhook "option.list" -> repli
        product_option.csv), exactement comme index.php.
     2. Si le produit n'a pas d'option -> on affiche directement le résumé.
     3. Sinon -> on affiche les options (choix Oui / Non).
          - L'option "Nom de domaine" (slug: domaine), si le client répond
            "Oui", fait apparaître un champ de recherche de nom de domaine
            qui interroge l'API OVH via le proxy AJAX ci-dessous (les
            identifiants OVH restent ainsi côté serveur, jamais exposés).

   >>> Pour que le clic sur une carte produit mène ici, remplacez dans
       index.php (fonction gnl_render_pro_card) :
            href="products/{$slug}/"
       par :
            href="product-configuration.php?slug={$slug}"
   ===================================================================== */

/* ---------- Config (identique à index.php) -------------------------- */
if (!defined('GNL_WEBHOOK_URL'))  define('GNL_WEBHOOK_URL', 'https://api.gnl-solution.fr/webhook/boutique');
if (!defined('GNL_CSV_FALLBACK')) define('GNL_CSV_FALLBACK', __DIR__ . '/product.csv');
if (!defined('GNL_OPT_CSV'))      define('GNL_OPT_CSV', __DIR__ . '/product_option.csv');
if (!defined('GNL_CACHE_TTL'))    define('GNL_CACHE_TTL', 300);

/* ---------- Identifiants API OVH (à renseigner) --------------------
   Créez un token ici : https://api.ovh.com/createToken/
   Droits nécessaires :  GET/POST sur /order/cart*  (et GET /auth/time).
   Renseignez soit directement les define() ci-dessous, soit via des
   variables d'environnement OVH_APP_KEY / OVH_APP_SECRET / OVH_CONSUMER_KEY. */
if (!defined('OVH_ENDPOINT'))     define('OVH_ENDPOINT', 'https://eu.api.ovh.com/1.0');
if (!defined('OVH_APP_KEY'))      define('OVH_APP_KEY',      getenv('OVH_APP_KEY')      ?: '');
if (!defined('OVH_APP_SECRET'))   define('OVH_APP_SECRET',   getenv('OVH_APP_SECRET')   ?: '');
if (!defined('OVH_CONSUMER_KEY')) define('OVH_CONSUMER_KEY', getenv('OVH_CONSUMER_KEY') ?: '');
if (!defined('OVH_SUBSIDIARY'))   define('OVH_SUBSIDIARY', 'FR');

/* ---------- Récupération webhook n8n (POST) ------------------------- */
function gnl_fetch_from_webhook($action) {
    $payload = json_encode(array('source' => 'product-configuration', 'action' => $action));
    if (function_exists('curl_init')) {
        $ch = curl_init(GNL_WEBHOOK_URL);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'Accept: application/json'),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4,
        ));
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        if ($body !== false && $code >= 200 && $code < 300) return gnl_decode_rows($body);
        return null;
    }
    $ctx = stream_context_create(array('http' => array(
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
        'content' => $payload, 'timeout' => 6, 'ignore_errors' => true,
    )));
    $body = @file_get_contents(GNL_WEBHOOK_URL, false, $ctx);
    if ($body === false) return null;
    return gnl_decode_rows($body);
}

/* ---------- Décodage tolérant JSON -> lignes ------------------------ */
function gnl_decode_rows($body) {
    $data = json_decode($body, true);
    if (!is_array($data)) return null;
    foreach (array('data', 'products', 'options', 'rows', 'items', 'result') as $k) {
        if (isset($data[$k]) && is_array($data[$k])) { $data = $data[$k]; break; }
    }
    if (isset($data['id']) || isset($data['name']) || isset($data['slug']) || isset($data['option_name'])) {
        $data = array($data);
    }
    $rows = array();
    foreach ($data as $item) {
        if (!is_array($item)) continue;
        if (isset($item['json']) && is_array($item['json'])) $item = $item['json'];
        $rows[] = $item;
    }
    return $rows ? $rows : null;
}

/* ---------- Repli CSV générique ------------------------------------- */
function gnl_fetch_from_csv($path) {
    if (!is_readable($path)) return array();
    $rows = array();
    if (($h = fopen($path, 'r')) !== false) {
        $header = fgetcsv($h);
        if ($header) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
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

/* ---------- Chargement produits ------------------------------------- */
function gnl_load_products() {
    $cacheFile = sys_get_temp_dir() . '/gnl_products_cache.json';
    if (is_readable($cacheFile) && (time() - filemtime($cacheFile) < GNL_CACHE_TTL)) {
        $cached = json_decode(@file_get_contents($cacheFile), true);
        if (is_array($cached) && $cached) return gnl_normalize($cached);
    }
    $rows = gnl_fetch_from_webhook('product.list');
    if ($rows) @file_put_contents($cacheFile, json_encode($rows));
    else       $rows = gnl_fetch_from_csv(GNL_CSV_FALLBACK);
    return gnl_normalize($rows);
}

/* ---------- Chargement options -------------------------------------- */
function gnl_load_options() {
    $cacheFile = sys_get_temp_dir() . '/gnl_options_cache.json';
    if (is_readable($cacheFile) && (time() - filemtime($cacheFile) < GNL_CACHE_TTL)) {
        $cached = json_decode(@file_get_contents($cacheFile), true);
        if (is_array($cached) && $cached) return gnl_normalize_options($cached);
    }
    $rows = gnl_fetch_from_webhook('option.list');
    if ($rows) @file_put_contents($cacheFile, json_encode($rows));
    else       $rows = gnl_fetch_from_csv(GNL_OPT_CSV);
    return gnl_normalize_options($rows);
}

/* ---------- Normalisation ------------------------------------------- */
function gnl_normalize($rows) {
    $out = array();
    if (!is_array($rows)) return $out;
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $g = function ($k) use ($r) { return isset($r[$k]) ? $r[$k] : ''; };
        $out[] = array(
            'id' => $g('id'),
            'flag' => strtoupper(trim((string) $g('datacenter_flag'))),
            'type' => strtolower(trim((string) $g('type'))),
            'slug' => trim((string) $g('slug')),
            'name' => trim((string) $g('name')),
            'stitre' => trim((string) $g('stitre')),
            'description' => trim((string) $g('description')),
            'categorie' => (int) $g('categorie_id'),
            'stock' => $g('stock'),
            'option' => $g('option'),
            'prix' => $g('prix_mensuel'),
        );
    }
    return $out;
}
function gnl_normalize_options($rows) {
    $out = array();
    if (!is_array($rows)) return $out;
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $g = function ($k) use ($r) { return isset($r[$k]) ? $r[$k] : ''; };
        $out[] = array(
            'id' => $g('id'),
            'name' => trim((string) $g('option_name')),
            'slug' => strtolower(trim((string) $g('slug'))),
            'desc' => trim((string) $g('option_desc')),
            'prix' => gnl_num($g('option_prix')),
            'unique' => gnl_bool($g('prix_unique')),
            'oui' => trim((string) $g('oui')),
            'non' => trim((string) $g('non')),
        );
    }
    return $out;
}

/* ---------- Petits helpers ------------------------------------------ */
function gnl_e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function gnl_num($v) {
    if ($v === '' || $v === null) return 0.0;
    return (float) str_replace(array(' ', ','), array('', '.'), (string) $v);
}
function gnl_bool($v) {
    return in_array(strtolower(trim((string) $v)), array('1', 'true', 'oui', 'yes', 'on'), true);
}
function gnl_price($v) {
    $f = gnl_num($v);
    if ($f <= 0) return '';
    return number_format($f, 2, ',', ' ') . "\xc2\xa0\xe2\x82\xac";
}
function gnl_flag($code) {
    $code = strtoupper((string) $code);
    if ($code === 'FR') {
        return '<img decoding="async" width="20" height="auto" src="./assets/img/icon-flag-edited.png" '
             . 'alt="Datacenter France" style="width:20px;height:auto;vertical-align:middle" />';
    }
    $emoji = array('DE' => "\xf0\x9f\x87\xa9\xf0\x9f\x87\xaa", 'SW' => "\xf0\x9f\x87\xa8\xf0\x9f\x87\xad", 'CH' => "\xf0\x9f\x87\xa8\xf0\x9f\x87\xad", 'BE' => "\xf0\x9f\x87\xa7\xf0\x9f\x87\xaa");
    $flag = isset($emoji[$code]) ? $emoji[$code] : "\xf0\x9f\x8c\x8d";
    return '<span style="font-size:18px" title="Datacenter ' . gnl_e($code) . '">' . $flag . '</span>';
}

/* ===================================================================
   PROXY OVH — appels signés (secrets côté serveur uniquement)
   =================================================================== */
function ovh_time() {
    static $delta = null;
    if ($delta === null) {
        $t = @file_get_contents(OVH_ENDPOINT . '/auth/time');
        $delta = ($t !== false && is_numeric(trim($t))) ? (int) trim($t) - time() : 0;
    }
    return time() + $delta;
}
function ovh_request($method, $path, $body = null) {
    if (OVH_APP_KEY === '' || OVH_APP_SECRET === '' || OVH_CONSUMER_KEY === '') {
        return array('_error' => 'ovh_not_configured');
    }
    $url     = OVH_ENDPOINT . $path;
    $bodyStr = ($body === null) ? '' : json_encode($body);
    $ts      = ovh_time();
    $sig     = '$1$' . sha1(OVH_APP_SECRET . '+' . OVH_CONSUMER_KEY . '+' . $method . '+' . $url . '+' . $bodyStr . '+' . $ts);
    $headers = array(
        'Content-Type: application/json',
        'X-Ovh-Application: ' . OVH_APP_KEY,
        'X-Ovh-Timestamp: ' . $ts,
        'X-Ovh-Signature: ' . $sig,
        'X-Ovh-Consumer: ' . OVH_CONSUMER_KEY,
    );
    if (!function_exists('curl_init')) return array('_error' => 'no_curl');
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5,
    ));
    if ($bodyStr !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyStr);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch); // curl_close() deprecie depuis PHP 8.5, sans effet depuis 8.0
    if ($resp === false) return array('_error' => 'network');
    $json = json_decode($resp, true);
    if ($code < 200 || $code >= 300) return array('_error' => 'http_' . $code, 'detail' => $json);
    return $json;
}
function ovh_domain_check($domain) {
    $cart = ovh_request('POST', '/order/cart', array('ovhSubsidiary' => OVH_SUBSIDIARY, 'description' => 'gnl-domain-check'));
    if (isset($cart['_error'])) return $cart;
    if (empty($cart['cartId'])) return array('_error' => 'cart_failed');
    $cartId = $cart['cartId'];
    return ovh_request('GET', '/order/cart/' . rawurlencode($cartId) . '/domain?domain=' . rawurlencode($domain));
}

/* ---------- Routeur AJAX : recherche de domaine --------------------- */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'domain') {
    header('Content-Type: application/json; charset=utf-8');
    $domain = isset($_GET['domain']) ? strtolower(trim($_GET['domain'])) : '';
    if (!preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9-]+)+$/', $domain)) {
        echo json_encode(array('ok' => false, 'error' => 'invalid')); exit;
    }
    $r = ovh_domain_check($domain);
    if (isset($r['_error'])) { echo json_encode(array('ok' => false, 'error' => $r['_error'])); exit; }
    $out = array();
    foreach ((array) $r as $offer) {
        if (!is_array($offer) || !isset($offer['domain'])) continue;
        $price = ''; $priceNum = 0;
        if (!empty($offer['prices']) && is_array($offer['prices'])) {
            foreach ($offer['prices'] as $p) {
                if (isset($p['label']) && $p['label'] === 'TOTAL') {
                    $price = isset($p['price']['text']) ? $p['price']['text'] : '';
                    $priceNum = isset($p['price']['value']) ? (float) $p['price']['value'] : 0;
                    break;
                }
            }
            if ($price === '' && isset($offer['prices'][0]['price']['text'])) {
                $price = $offer['prices'][0]['price']['text'];
                $priceNum = isset($offer['prices'][0]['price']['value']) ? (float) $offer['prices'][0]['price']['value'] : 0;
            }
        }
        $out[] = array(
            'domain' => $offer['domain'],
            'orderable' => !empty($offer['orderable']),
            'action' => isset($offer['action']) ? $offer['action'] : '',
            'price' => $price,
            'priceNum' => $priceNum,
        );
    }
    echo json_encode(array('ok' => true, 'results' => $out)); exit;
}

/* ---------- Résolution du produit demandé --------------------------- */
$GNL_PRODUCTS = gnl_load_products();
$slug = isset($_GET['slug']) ? trim($_GET['slug']) : (isset($_GET['product']) ? trim($_GET['product']) : '');
$pid  = isset($_GET['id']) ? trim($_GET['id']) : '';
$product = null;
foreach ($GNL_PRODUCTS as $p) {
    if ($slug !== '' && $p['slug'] === $slug) { $product = $p; break; }
    if ($pid  !== '' && (string) $p['id'] === $pid) { $product = $p; break; }
}

/* Le produit propose-t-il des options ?
   NB : dans le CSV, la colonne "option" est vide et le booléen se trouve
   côté "stock_limite" ; on se base donc sur le type d'offre (les offres
   "web_pro" sont configurables, contrairement à "web_edu" et "soon").
   Si un webhook renvoie explicitement option=true, c'est aussi pris en compte. */
$hasOptions = $product && (gnl_bool($product['option']) || $product['type'] === 'web_pro');
$OPTIONS    = $hasOptions ? gnl_load_options() : array();
$basePrice  = $product ? gnl_num($product['prix']) : 0.0;
?>
<!DOCTYPE html>
<html lang="fr-FR">

<!-- Mirrored from gnl-solution.fr/ by HTTrack Website Copier/3.x [XR&CO'2014], Sat, 18 Jul 2026 22:22:58 GMT -->
<!-- Added by HTTrack --><meta http-equiv="content-type" content="text/html;charset=UTF-8" /><!-- /Added by HTTrack -->
<head>
	<meta charset="UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name='robots' content='max-image-preview:large' />
<title>Configuration de votre offre — GNL Solution</title>
<link rel='dns-prefetch' href='http://js.surecart.com/' />
<link rel='dns-prefetch' href='http://cdn.elementor.com/' />
<link rel="alternate" type="application/rss+xml" title="GNL Solution &raquo; Flux" href="feed/index.html" />
<link rel="alternate" type="application/rss+xml" title="GNL Solution &raquo; Flux des commentaires" href="comments/feed/index.html" />
<link rel="alternate" title="oEmbed (JSON)" type="application/json+oembed" href="wp-json/oembed/1.0/embedb69b.html?url=https%3A%2F%2Fgnl-solution.fr%2F" />
<link rel="alternate" title="oEmbed (XML)" type="text/xml+oembed" href="wp-json/oembed/1.0/embed486b.html?url=https%3A%2F%2Fgnl-solution.fr%2F&amp;format=xml" />
<style id='wp-img-auto-sizes-contain-inline-css'>
img:is([sizes=auto i],[sizes^="auto," i]){contain-intrinsic-size:3000px 1500px}
/*# sourceURL=wp-img-auto-sizes-contain-inline-css */
</style>
<style id='surecart-cart-close-button-style-inline-css'>
.wp-block-surecart-cart-close-button{color:var(--sc-input-help-text-color);cursor:pointer;font-size:20px}.wp-block-surecart-cart-close-button svg{height:1em;width:1em}

/*# sourceURL=https://gnl-solution.fr/wp-content/plugins/surecart/packages/blocks-next/build/blocks/cart-close-button/style-index.css */
</style>
<style id='wp-block-paragraph-inline-css'>
.is-small-text{font-size:.875em}.is-regular-text{font-size:1em}.is-large-text{font-size:2.25em}.is-larger-text{font-size:3em}.has-drop-cap:not(:focus):first-letter{float:left;font-size:8.4em;font-style:normal;font-weight:100;line-height:.68;margin:.05em .1em 0 0;text-transform:uppercase}body.rtl .has-drop-cap:not(:focus):first-letter{float:none;margin-left:.1em}p.has-drop-cap.has-background{overflow:hidden}:root :where(p.has-background){padding:1.25em 2.375em}:where(p.has-text-color:not(.has-link-color)) a{color:inherit}p.has-text-align-left[style*="writing-mode:vertical-lr"],p.has-text-align-right[style*="writing-mode:vertical-rl"]{rotate:180deg}
/*# sourceURL=https://gnl-solution.fr/wp-includes/blocks/paragraph/style.min.css */
</style>
<style id='surecart-cart-count-style-inline-css'>
.wp-block-surecart-cart-count{background-color:var(--sc-panel-background-color);border:1px solid var(--sc-input-border-color);color:var(--sc-cart-main-label-text-color)}

/*# sourceURL=https://gnl-solution.fr/wp-content/plugins/surecart/packages/blocks-next/build/blocks/cart-count/style-index.css */
</style>
<style id='wp-block-group-inline-css'>
.wp-block-group{box-sizing:border-box}:where(.wp-block-group.wp-block-group-is-layout-constrained){position:relative}
/*# sourceURL=https://gnl-solution.fr/wp-includes/blocks/group/style.min.css */
</style>
<style id='surecart-cart-line-item-image-style-inline-css'>
.wp-block-surecart-cart-line-item-image{border-color:var(--sc-color-gray-300);-webkit-box-sizing:border-box;box-sizing:border-box;height:auto;max-width:100%;vertical-align:bottom}.wp-block-surecart-cart-line-item-image.sc-is-covered{-o-object-fit:cover;object-fit:cover}.wp-block-surecart-cart-line-item-image.sc-is-contained{-o-object-fit:contain;object-fit:contain}.sc-cart-line-item-image-wrap{-ms-flex-negative:0;flex-shrink:0}

/*# sourceURL=https://gnl-solution.fr/wp-content/plugins/surecart/packages/blocks-next/build/blocks/cart-line-item-image/style-index.css */
</style>
<style id='surecart-cart-line-item-title-style-inline-css'>
.wp-block-surecart-cart-line-item-title{color:var(--sc-cart-main-label-text-color);text-wrap:wrap}

/*# sourceURL=https://gnl-solution.fr/wp-content/plugins/surecart/packages/blocks-next/build/blocks/cart-line-item-title/style-index.css */
</style>
<style id='surecart-cart-line-item-price-name-style-inline-css'>
.wp-block-surecart-cart-line-item-price-name{color:var(--sc-input-help-text-color);text-wrap:auto}

/*# sourceURL=https://gnl-solution.fr/wp-content/plugins/surecart/packages/blocks-next/build/blocks/cart-line-item-price-name/style-index.css */
</style>
<style id='surecart-cart-line-item-variant-style-inline-css'>
.wp-block-surecart-cart-line-item-variant{color:var(--sc-input-help-text-color)}

/*# sourceURL=https://gnl-solution.fr/wp-content/plugins/surecart/packages/blocks-next/build/blocks/cart-line-item-variant/style-index.css */
</style>
<style id='surecart-cart-line-item-note-style-inline-css'>
.wp-block-surecart-cart-line-item-note{color:var(--sc-input-help-text-color);display:-webkit-box;display:-ms-flexbox;display:flex;margin-top:var(--sc-spacing-x-small);position:relative;-webkit-box-pack:center;-ms-flex-pack:center;justify-content:center;-webkit-box-align:start;-ms-flex-align:start;align-items:flex-start;gap:.25em;min-height:1.5em}.wp-block-surecart-cart-line-item-note[hidden]{display:none!important}.wp-block-surecart-cart-line-item-note[disabled]{pointer-events:none}.wp-block-surecart-cart-line-item-note .line-item-note__text{color:var(--sc-color-gray-500);line-height:1.4;-webkit-box-flex:1;display:-webkit-box;-ms-flex:1;flex:1;-webkit-box-orient:vertical;line-clamp:1;-webkit-line-clamp:1;overflow:hidden;text-overflow:ellipsis;word-wrap:break-word;max-width:100%;-webkit-transition:all .2s;transition:all .2s;white-space:normal;width:100%}.wp-block-surecart-cart-line-item-note .line-item-note__toggle{background:none;border:none;color:var(--sc-color-gray-500);cursor:pointer;padding:0;-ms-flex-item-align:start;align-self:flex-start;border-radius:var(--sc-border-radius-small);-webkit-transition:opacity .2s ease;transition:opacity .2s ease}.wp-block-surecart-cart-line-item-note .sc-icon{display:none;-webkit-transition:-webkit-transform .2s;transition:-webkit-transform .2s;transition:transform .2s;transition:transform .2s,-webkit-transform .2s}.wp-block-surecart-cart-line-item-note .sc-icon--rotated{-webkit-transform:rotate(180deg);-ms-transform:rotate(180deg);transform:rotate(180deg)}.wp-block-surecart-cart-line-item-note.line-item-note--is-collapsible,.wp-block-surecart-cart-line-item-note.line-item-note--is-expanded{cursor:pointer}.wp-block-surecart-cart-line-item-note.line-item-note--is-collapsible .sc-icon,.wp-block-surecart-cart-line-item-note.line-item-note--is-expanded .sc-icon{display:-webkit-inline-box!important;display:-ms-inline-flexbox!important;display:inline-flex!important}.wp-block-surecart-cart-line-item-note.line-item-note--is-expanded .line-item-note__text{line-clamp:unset;-webkit-line-clamp:unset;overflow:visible;text-overflow:unset}

/*# sourceURL=https://gnl-solution.fr/wp-content/plugins/surecart/packages/blocks-next/build/blocks/cart-line-item-note/style-index.css */
</style>
<style id='surecart-cart-line-item-status-style-inline-css'>
.wp-block-surecart-cart-line-item-status{--sc-cart-line-item-status-color:var(--sc-color-danger-600);display:-webkit-inline-box;display:-ms-inline-flexbox;display:inline-flex;-webkit-box-align:center;-ms-flex-align:center;align-items:center;color:var(--sc-cart-line-item-status-color);font-size:var(--sc-font-size-small);font-weight:var(--sc-font-weight-semibold);gap:.25em}.surecart-theme-dark .wp-block-surecart-cart-line-item-status{--sc-cart-line-item-status-color:var(--sc-color-danger-400)}

/*# sourceURL=https://gnl-solution.fr/wp-content/plugins/surecart/packages/blocks-next/build/blocks/cart-line-item-status/style-index.css */
</style>
<style id='surecart-cart-line-item-scratch-amount-style-inline-css'>
.wp-block-surecart-cart-line-item-scratch-amount{color:var(--sc-input-help-text-color);text-decoration:line-through}

/*# sourceURL=https://gnl-solution.fr/wp-content/plugins/surecart/packages/blocks-next/build/blocks/cart-line-item-scratch-amount/style-index.css */
</style>
<style id='surecart-cart-line-item-amount-style-inline-css'>
.wp-block-surecart-cart-line-item-amount{color:var(--sc-cart-main-label-text-color)}

/*# sourceURL=https://gnl-solution.fr/wp-content/plugins/surecart/packages/blocks-next/build/blocks/cart-line-item-amount/style-index.css */
</style>
<style id='surecart-cart-line-item-interval-style-inline-css'>
.wp-block-surecart-cart-line-item-interval{color:var(--sc-input-help-text-color)}

/*# sourceURL=https://gnl-solution.fr/wp-content/plugins/surecart/packages/blocks-next/build/blocks/cart-line-item-interval/style-index.css */
</style>
<style id='surecart-cart-line-item-trial-style-inline-css'>
.wp-block-surecart-cart-line-item-trial{color:var(--sc-input-help-text-color)}

/*# sourceURL=https://gnl-solution.fr/wp-content/plugins/surecart/packages/blocks-next/build/blocks/cart-line-item-trial/style-index.css */
</style>
<style id='surecart-cart-line-item-fees-style-inline-css'>
div.wp-block-surecart-cart-line-item-fees{color:var(--sc-input-help-text-color);display:-webkit-box;max-width:100%;-webkit-box-orient:vertical;-webkit-line-clamp:2;line-clamp:2;overflow:hidden;text-overflow:ellipsis;word-break:break-word}div.wp-block-surecart-cart-line-item-fees.has-text-align-right{text-align:right}span.wp-block-surecart-cart-line-item-fees{display:inline}

/*# sourceURL=https://gnl-solution.fr/wp-content/plugins/surecart/packages/blocks-next/build/blocks/cart-line-item-fees/style-index.css */
</style>
<style id='surecart-cart-line-item-quantity-style-inline-css'>
.wp-block-surecart-cart-line-item-quantity{color:var(--sc-input-color)}.wp-block-surecart-cart-line-item-quantity.sc-input-group{border:none;-webkit-box-shadow:none;box-shadow:none}.wp-block-surecart-cart-line-item-quantity input[type=number].sc-form-control.sc-quantity-selector__control,.wp-block-surecart-cart-line-item-quantity.sc-input-group-text{color:inherit}

/*# sourceURL=https://gnl-solution.fr/wp-content/plugins/surecart/packages/blocks-next/build/blocks/cart-line-item-quantity/style-index.css */
</style>
<style id='surecart-cart-line-item-remove-style-inline-css'>
.wp-block-surecart-cart-line-item-remove{cursor:pointer;display:-webkit-inline-box;display:-ms-inline-flexbox;display:inline-flex;-webkit-box-align:center;-ms-flex-align:center;align-items:center;color:var(--sc-input-help-text-color);font-size:var(--sc-font-size-medium);font-weight:var(--sc-font-weight-semibold);gap:.25em}.wp-block-surecart-cart-line-item-remove__icon{height:1.1em;width:1.1em}

/*# sourceURL=https://gnl-solution.fr/wp-content/plugins/surecart/packages/blocks-next/build/blocks/cart-line-item-remove/style-index.css */
</style>
<link rel='stylesheet' id='surecart-line-item-css' href='wp-content/plugins/surecart/packages/blocks-next/build/styles/line-iteme6ad.css?ver=1777926551' media='all' />
<link rel='stylesheet' id='surecart-product-line-item-css' href='wp-content/plugins/surecart/packages/blocks-next/build/styles/product-line-iteme6ad.css?ver=1777926551' media='all' />
<link rel='stylesheet' id='surecart-input-group-css' href='wp-content/plugins/surecart/packages/blocks-next/build/styles/input-groupe6ad.css?ver=1777926551' media='all' />
<link rel='stylesheet' id='surecart-quantity-selector-css' href='wp-content/plugins/surecart/packages/blocks-next/build/styles/quantity-selectore6ad.css?ver=1777926551' media='all' />
<link rel='stylesheet' id='surecart-toggle-css' href='wp-content/plugins/surecart/packages/blocks-next/build/styles/togglee6ad.css?ver=1777926551' media='all' />
<style id='surecart-slide-out-cart-line-items-style-6-inline-css'>
.wp-block-surecart-slide-out-cart-line-items{-webkit-box-flex:1;display:-webkit-box;display:-ms-flexbox;display:flex;-ms-flex:1 0 140px;flex:1 0 140px;overflow:auto;-webkit-box-orient:vertical;-webkit-box-direction:normal;-ms-flex-direction:column;flex-direction:column}.wp-block-surecart-slide-out-cart-line-items .sc-quantity-selector[hidden]{display:none}.sc-product-line-item--has-swap{background:var(--sc-panel-background-color);border:1px solid var(--sc-input-border-color);border-radius:var(--sc-border-radius-medium);gap:0;padding:0}.sc-product-line-item--has-swap .sc-product-line-item__content{border-bottom:solid var(--sc-input-border-width) var(--sc-input-border-color);border-radius:var(--sc-border-radius-medium) var(--sc-border-radius-medium) 0 0;padding:var(--sc-spacing-medium)}.sc-product-line-item--has-swap .sc-product-line-item__swap{background:var(--sc-panel-background-color);display:-webkit-box;display:-ms-flexbox;display:flex;font-size:var(--sc-font-size-small);line-height:1;padding:var(--sc-spacing-medium);-webkit-box-align:center;-ms-flex-align:center;align-items:center;-webkit-box-pack:justify;-ms-flex-pack:justify;border-radius:0 0 var(--sc-border-radius-medium) var(--sc-border-radius-medium);color:var(--sc-input-label-color);justify-content:space-between;text-wrap:auto}.sc-product-line-item--has-swap .sc-product-line-item__swap .sc-product-line-item__swap-content{display:-webkit-box;display:-ms-flexbox;display:flex;-webkit-box-align:center;-ms-flex-align:center;align-items:center;gap:var(--sc-spacing-small)}.sc-product-line-item--has-swap .sc-product-line-item__swap .sc-product-line-item__swap-amount-value{font-weight:var(--sc-font-weight-bold)}

/*# sourceURL=https://gnl-solution.fr/wp-content/plugins/surecart/packages/blocks-next/build/blocks/cart-line-items/style-index.css */
</style>
<style id='surecart-cart-subtotal-amount-style-inline-css'>
.wp-block-surecart-cart-subtotal-amount{color:var(--sc-cart-main-label-text-color)}

/*# sourceURL=https://gnl-solution.fr/wp-content/plugins/surecart/packages/blocks-next/build/blocks/cart-subtotal-amount/style-index.css */
</style>
<link rel='stylesheet' id='surecart-wp-buttons-css' href='wp-content/plugins/surecart/packages/blocks-next/build/styles/wp-buttonse6ad.css?ver=1777926551' media='all' />
<link rel='stylesheet' id='surecart-wp-button-css' href='wp-content/plugins/surecart/packages/blocks-next/build/styles/wp-buttone6ad.css?ver=1777926551' media='all' />
<style id='surecart-slide-out-cart-items-submit-style-3-inline-css'>
.sc-cart-items-submit__wrapper .wp-block-button__link,.sc-cart-items-submit__wrapper a.wp-block-button__link{background:var(--sc-color-primary-500);-webkit-box-sizing:border-box;box-sizing:border-box;color:#fff;display:block;position:relative;text-align:center;text-decoration:none;width:100%}.sc-cart-items-submit__wrapper .wp-block-button__link:focus,.sc-cart-items-submit__wrapper a.wp-block-button__link:focus{-webkit-box-shadow:0 0 0 var(--sc-focus-ring-width) var(--sc-focus-ring-color-primary);box-shadow:0 0 0 var(--sc-focus-ring-width) var(--sc-focus-ring-color-primary);outline:none}.sc-cart-items-submit__wrapper.wp-block-buttons>.wp-block-button{display:block;text-decoration:none!important;width:100%}

/*# sourceURL=https://gnl-solution.fr/wp-content/plugins/surecart/packages/blocks-next/build/blocks/cart-items-submit/style-index.css */
</style>
<style id='wp-block-button-inline-css'>
.wp-block-button__link{align-content:center;box-sizing:border-box;cursor:pointer;display:inline-block;height:100%;text-align:center;word-break:break-word}.wp-block-button__link.aligncenter{text-align:center}.wp-block-button__link.alignright{text-align:right}:where(.wp-block-button__link){border-radius:9999px;box-shadow:none;padding:calc(.667em + 2px) calc(1.333em + 2px);text-decoration:none}.wp-block-button[style*=text-decoration] .wp-block-button__link{text-decoration:inherit}.wp-block-buttons>.wp-block-button.has-custom-width{max-width:none}.wp-block-buttons>.wp-block-button.has-custom-width .wp-block-button__link{width:100%}.wp-block-buttons>.wp-block-button.has-custom-font-size .wp-block-button__link{font-size:inherit}.wp-block-buttons>.wp-block-button.wp-block-button__width-25{width:calc(25% - var(--wp--style--block-gap, .5em)*.75)}.wp-block-buttons>.wp-block-button.wp-block-button__width-50{width:calc(50% - var(--wp--style--block-gap, .5em)*.5)}.wp-block-buttons>.wp-block-button.wp-block-button__width-75{width:calc(75% - var(--wp--style--block-gap, .5em)*.25)}.wp-block-buttons>.wp-block-button.wp-block-button__width-100{flex-basis:100%;width:100%}.wp-block-buttons.is-vertical>.wp-block-button.wp-block-button__width-25{width:25%}.wp-block-buttons.is-vertical>.wp-block-button.wp-block-button__width-50{width:50%}.wp-block-buttons.is-vertical>.wp-block-button.wp-block-button__width-75{width:75%}.wp-block-button.is-style-squared,.wp-block-button__link.wp-block-button.is-style-squared{border-radius:0}.wp-block-button.no-border-radius,.wp-block-button__link.no-border-radius{border-radius:0!important}:root :where(.wp-block-button .wp-block-button__link.is-style-outline),:root :where(.wp-block-button.is-style-outline>.wp-block-button__link){border:2px solid;padding:.667em 1.333em}:root :where(.wp-block-button .wp-block-button__link.is-style-outline:not(.has-text-color)),:root :where(.wp-block-button.is-style-outline>.wp-block-button__link:not(.has-text-color)){color:currentColor}:root :where(.wp-block-button .wp-block-button__link.is-style-outline:not(.has-background)),:root :where(.wp-block-button.is-style-outline>.wp-block-button__link:not(.has-background)){background-color:initial;background-image:none}
/*# sourceURL=https://gnl-solution.fr/wp-includes/blocks/button/style.min.css */
</style>
<link rel='stylesheet' id='surecart-drawer-css' href='wp-content/plugins/surecart/packages/blocks-next/build/styles/drawere6ad.css?ver=1777926551' media='all' />
<link rel='stylesheet' id='surecart-block-ui-css' href='wp-content/plugins/surecart/packages/blocks-next/build/styles/block-uie6ad.css?ver=1777926551' media='all' />
<link rel='stylesheet' id='surecart-alert-css' href='wp-content/plugins/surecart/packages/blocks-next/build/styles/alerte6ad.css?ver=1777926551' media='all' />
<style id='surecart-slide-out-cart-style-4-inline-css'>
.wp-block-surecart-slide-out-cart{-webkit-box-flex:1;border:var(--sc-drawer-border);-webkit-box-shadow:0 1px 2px rgba(13,19,30,.102);box-shadow:0 1px 2px rgba(13,19,30,.102);color:var(--sc-cart-main-label-text-color);-ms-flex:1 1 auto;flex:1 1 auto;font-size:16px;margin:auto;overflow:auto;width:100%}.wp-block-surecart-slide-out-cart .sc-alert{border-radius:0}.wp-block-surecart-slide-out-cart .sc-alert__icon svg{height:24px;width:24px}.wp-block-surecart-slide-out-cart .sc-alert :not(:first-child){margin-bottom:0}html:has(.sc-drawer.open){overflow:hidden;scrollbar-gutter:stable}:where(.sc-cart-drawer) :where(.is-layout-flex){display:-webkit-box;display:-ms-flexbox;display:flex;gap:var(--wp--style--block-gap,.5em)}:where(.sc-cart-drawer) :where(.is-layout-grid){display:-ms-grid;display:grid}:where(.sc-cart-drawer) :where(.is-layout-flow)>:first-child{-webkit-margin-before:0;margin-block-start:0}:where(.sc-cart-drawer) :where(.is-layout-flow)>*+*{-webkit-margin-before:var(--wp--style--block-gap,.5em);margin-block-start:var(--wp--style--block-gap,.5em)}:where(.sc-cart-drawer) :where(.wp-block-surecart-slide-out-cart-line-items)>*+*{-webkit-margin-before:var(--wp--style--block-gap,2em);margin-block-start:var(--wp--style--block-gap,2em)}

/*# sourceURL=https://gnl-solution.fr/wp-content/plugins/surecart/packages/blocks-next/build/blocks/cart/style-index.css */
</style>
<link rel='stylesheet' id='surecart-theme-base-css' href='wp-content/plugins/surecart/packages/blocks-next/build/styles/theme-basee6ad.css?ver=1777926551' media='all' />
<style id='surecart-theme-base-inline-css'>
@-webkit-keyframes sheen{0%{background-position:200% 0}to{background-position:-200% 0}}@keyframes sheen{0%{background-position:200% 0}to{background-position:-200% 0}}sc-form{display:block}sc-form>:not(:last-child){margin-bottom:var(--sc-form-row-spacing,.75em)}sc-form>:not(:last-child).wp-block-spacer{margin-bottom:0}sc-invoice-details:not(.hydrated),sc-invoice-details:not(:defined){display:none}sc-customer-email:not(.hydrated),sc-customer-email:not(:defined),sc-customer-name:not(.hydrated),sc-customer-name:not(:defined),sc-input:not(.hydrated),sc-input:not(:defined){-webkit-animation:sheen 3s ease-in-out infinite;animation:sheen 3s ease-in-out infinite;background:-webkit-gradient(linear,right top,left top,from(rgba(75,85,99,.2)),color-stop(rgba(75,85,99,.1)),color-stop(rgba(75,85,99,.1)),to(rgba(75,85,99,.2)));background:linear-gradient(270deg,rgba(75,85,99,.2),rgba(75,85,99,.1),rgba(75,85,99,.1),rgba(75,85,99,.2));background-size:400% 100%;border-radius:var(--sc-input-border-radius-medium);display:block;height:var(--sc-input-height-medium)}sc-button:not(.hydrated),sc-button:not(:defined),sc-order-submit:not(.hydrated),sc-order-submit:not(:defined){-webkit-animation:sheen 3s ease-in-out infinite;animation:sheen 3s ease-in-out infinite;background:-webkit-gradient(linear,right top,left top,from(rgba(75,85,99,.2)),color-stop(rgba(75,85,99,.1)),color-stop(rgba(75,85,99,.1)),to(rgba(75,85,99,.2)));background:linear-gradient(270deg,rgba(75,85,99,.2),rgba(75,85,99,.1),rgba(75,85,99,.1),rgba(75,85,99,.2));background-size:400% 100%;border-radius:var(--sc-input-border-radius-medium);color:rgba(0,0,0,0);display:block;height:var(--sc-input-height-large);text-align:center;width:auto}sc-order-summary:not(.hydrated),sc-order-summary:not(:defined){-webkit-animation:sheen 3s ease-in-out infinite;animation:sheen 3s ease-in-out infinite;background:-webkit-gradient(linear,right top,left top,from(rgba(75,85,99,.2)),color-stop(rgba(75,85,99,.1)),color-stop(rgba(75,85,99,.1)),to(rgba(75,85,99,.2)));background:linear-gradient(270deg,rgba(75,85,99,.2),rgba(75,85,99,.1),rgba(75,85,99,.1),rgba(75,85,99,.2));background-size:400% 100%;border-radius:var(--sc-input-border-radius-medium);color:rgba(0,0,0,0);display:block;height:var(--sc-input-height-large);text-align:center;width:auto}sc-tab-group:not(.hydrated),sc-tab-group:not(:defined),sc-tab:not(.hydrated),sc-tab:not(:defined){visibility:hidden}sc-column:not(.hydrated),sc-column:not(:defined){opacity:0;visibility:hidden}sc-columns{-webkit-box-sizing:border-box;box-sizing:border-box;display:-webkit-box;display:-ms-flexbox;display:flex;-ms-flex-wrap:wrap!important;flex-wrap:wrap!important;gap:var(--sc-column-spacing,var(--sc-spacing-xxxx-large));margin-left:auto;margin-right:auto;width:100%;-webkit-box-align:initial!important;-ms-flex-align:initial!important;align-items:normal!important}@media(min-width:782px){sc-columns{-ms-flex-wrap:nowrap!important;flex-wrap:nowrap!important}}sc-columns.are-vertically-aligned-top{-webkit-box-align:start;-ms-flex-align:start;align-items:flex-start}sc-columns.are-vertically-aligned-center{-webkit-box-align:center;-ms-flex-align:center;align-items:center}sc-columns.are-vertically-aligned-bottom{-webkit-box-align:end;-ms-flex-align:end;align-items:flex-end}@media(max-width:781px){sc-columns:not(.is-not-stacked-on-mobile).is-full-height>sc-column{padding:30px!important}sc-columns:not(.is-not-stacked-on-mobile)>sc-column{-ms-flex-preferred-size:100%!important;flex-basis:100%!important}}@media(min-width:782px){sc-columns:not(.is-not-stacked-on-mobile)>sc-column{-ms-flex-preferred-size:0;flex-basis:0;-webkit-box-flex:1;-ms-flex-positive:1;flex-grow:1}sc-columns:not(.is-not-stacked-on-mobile)>sc-column[style*=flex-basis]{-webkit-box-flex:0;-ms-flex-positive:0;flex-grow:0}}sc-columns.is-not-stacked-on-mobile{-ms-flex-wrap:nowrap!important;flex-wrap:nowrap!important}sc-columns.is-not-stacked-on-mobile>sc-column{-ms-flex-preferred-size:0;flex-basis:0;-webkit-box-flex:1;-ms-flex-positive:1;flex-grow:1}sc-columns.is-not-stacked-on-mobile>sc-column[style*=flex-basis]{-webkit-box-flex:0;-ms-flex-positive:0;flex-grow:0}sc-column{display:block;-webkit-box-flex:1;-ms-flex-positive:1;flex-grow:1;min-width:0;overflow-wrap:break-word;word-break:break-word}sc-column.is-vertically-aligned-top{-ms-flex-item-align:start;align-self:flex-start}sc-column.is-vertically-aligned-center{-ms-flex-item-align:center;-ms-grid-row-align:center;align-self:center}sc-column.is-vertically-aligned-bottom{-ms-flex-item-align:end;align-self:flex-end}sc-column.is-vertically-aligned-bottom,sc-column.is-vertically-aligned-center,sc-column.is-vertically-aligned-top{width:100%}@media(min-width:782px){sc-column.is-sticky{position:sticky!important;-ms-flex-item-align:start;align-self:flex-start;top:0}}sc-column>:not(.wp-block-spacer):not(:last-child):not(.is-empty):not(style){margin-bottom:var(--sc-form-row-spacing,.75em)}sc-column>:not(.wp-block-spacer):not(:last-child):not(.is-empty):not(style):not(.is-layout-flex){display:block}.hydrated{visibility:inherit}
:root {--sc-color-primary-500: #119edd;--sc-focus-ring-color-primary: #119edd;--sc-input-border-color-focus: #119edd;--sc-color-gray-900: #000;--sc-color-primary-text: #ffffff;}
/*# sourceURL=surecart-theme-base-inline-css */
</style>
<style id='wp-block-site-logo-inline-css'>
.wp-block-site-logo{box-sizing:border-box;line-height:0}.wp-block-site-logo a{display:inline-block;line-height:0}.wp-block-site-logo.is-default-size img{height:auto;width:120px}.wp-block-site-logo img{height:auto;max-width:100%}.wp-block-site-logo a,.wp-block-site-logo img{border-radius:inherit}.wp-block-site-logo.aligncenter{margin-left:auto;margin-right:auto;text-align:center}:root :where(.wp-block-site-logo.is-style-rounded){border-radius:9999px}
/*# sourceURL=https://gnl-solution.fr/wp-includes/blocks/site-logo/style.min.css */
</style>
<style id='wp-block-navigation-link-inline-css'>
.wp-block-navigation .wp-block-navigation-item__label{overflow-wrap:break-word}.wp-block-navigation .wp-block-navigation-item__description{display:none}.link-ui-tools{outline:1px solid #f0f0f0;padding:8px}.link-ui-block-inserter{padding-top:8px}.link-ui-block-inserter__back{margin-left:8px;text-transform:uppercase}
/*# sourceURL=https://gnl-solution.fr/wp-includes/blocks/navigation-link/style.min.css */
</style>
<link rel='stylesheet' id='wp-block-navigation-css' href='wp-includes/blocks/navigation/style.minb34e.css?ver=6.9.4' media='all' />
<style id='surecart-cart-menu-icon-button-style-inline-css'>
.wp-block-surecart-cart-menu-icon-button{color:inherit;cursor:pointer;display:inline-block;line-height:1;position:relative;vertical-align:middle}.wp-block-surecart-cart-menu-icon-button[hidden]{display:none!important}.wp-block-surecart-cart-menu-icon-button .sc-cart-icon{cursor:pointer;font-size:var(--sc-cart-icon-size,1.1em);position:relative}.wp-block-surecart-cart-menu-icon-button .sc-cart-icon svg{height:20px;width:20px}.wp-block-surecart-cart-menu-icon-button .sc-cart-icon>:first-child{line-height:inherit}.wp-block-surecart-cart-menu-icon-button .sc-cart-count{background:var(--sc-cart-icon-counter-background,var(--sc-color-primary-500));border-radius:var(--sc-cart-icon-counter-border-radius,9999px);-webkit-box-shadow:var(--sc-cart-icon-box-shadow,var(--sc-shadow-x-large));box-shadow:var(--sc-cart-icon-box-shadow,var(--sc-shadow-x-large));-webkit-box-sizing:border-box;box-sizing:border-box;color:var(--sc-cart-icon-counter-color,var(--sc-color-primary-text,var(--sc-color-white)));font-size:10px;font-weight:700;inset:-12px -16px auto auto;line-height:14px;min-width:14px;padding:2px 6px;position:absolute;text-align:center;z-index:1}

/*# sourceURL=https://gnl-solution.fr/wp-content/plugins/surecart/packages/blocks-next/build/blocks/cart-menu-button/style-index.css */
</style>
<style id='wp-block-heading-inline-css'>
h1:where(.wp-block-heading).has-background,h2:where(.wp-block-heading).has-background,h3:where(.wp-block-heading).has-background,h4:where(.wp-block-heading).has-background,h5:where(.wp-block-heading).has-background,h6:where(.wp-block-heading).has-background{padding:1.25em 2.375em}h1.has-text-align-left[style*=writing-mode]:where([style*=vertical-lr]),h1.has-text-align-right[style*=writing-mode]:where([style*=vertical-rl]),h2.has-text-align-left[style*=writing-mode]:where([style*=vertical-lr]),h2.has-text-align-right[style*=writing-mode]:where([style*=vertical-rl]),h3.has-text-align-left[style*=writing-mode]:where([style*=vertical-lr]),h3.has-text-align-right[style*=writing-mode]:where([style*=vertical-rl]),h4.has-text-align-left[style*=writing-mode]:where([style*=vertical-lr]),h4.has-text-align-right[style*=writing-mode]:where([style*=vertical-rl]),h5.has-text-align-left[style*=writing-mode]:where([style*=vertical-lr]),h5.has-text-align-right[style*=writing-mode]:where([style*=vertical-rl]),h6.has-text-align-left[style*=writing-mode]:where([style*=vertical-lr]),h6.has-text-align-right[style*=writing-mode]:where([style*=vertical-rl]){rotate:180deg}
/*# sourceURL=https://gnl-solution.fr/wp-includes/blocks/heading/style.min.css */
</style>
<link rel='stylesheet' id='wp-block-cover-css' href='wp-includes/blocks/cover/style.minb34e.css?ver=6.9.4' media='all' />
<style id='wp-block-columns-inline-css'>
.wp-block-columns{box-sizing:border-box;display:flex;flex-wrap:wrap!important}@media (min-width:782px){.wp-block-columns{flex-wrap:nowrap!important}}.wp-block-columns{align-items:normal!important}.wp-block-columns.are-vertically-aligned-top{align-items:flex-start}.wp-block-columns.are-vertically-aligned-center{align-items:center}.wp-block-columns.are-vertically-aligned-bottom{align-items:flex-end}@media (max-width:781px){.wp-block-columns:not(.is-not-stacked-on-mobile)>.wp-block-column{flex-basis:100%!important}}@media (min-width:782px){.wp-block-columns:not(.is-not-stacked-on-mobile)>.wp-block-column{flex-basis:0;flex-grow:1}.wp-block-columns:not(.is-not-stacked-on-mobile)>.wp-block-column[style*=flex-basis]{flex-grow:0}}.wp-block-columns.is-not-stacked-on-mobile{flex-wrap:nowrap!important}.wp-block-columns.is-not-stacked-on-mobile>.wp-block-column{flex-basis:0;flex-grow:1}.wp-block-columns.is-not-stacked-on-mobile>.wp-block-column[style*=flex-basis]{flex-grow:0}:where(.wp-block-columns){margin-bottom:1.75em}:where(.wp-block-columns.has-background){padding:1.25em 2.375em}.wp-block-column{flex-grow:1;min-width:0;overflow-wrap:break-word;word-break:break-word}.wp-block-column.is-vertically-aligned-top{align-self:flex-start}.wp-block-column.is-vertically-aligned-center{align-self:center}.wp-block-column.is-vertically-aligned-bottom{align-self:flex-end}.wp-block-column.is-vertically-aligned-stretch{align-self:stretch}.wp-block-column.is-vertically-aligned-bottom,.wp-block-column.is-vertically-aligned-center,.wp-block-column.is-vertically-aligned-top{width:100%}
/*# sourceURL=https://gnl-solution.fr/wp-includes/blocks/columns/style.min.css */
</style>
<style id='surecart-product-title-style-inline-css'>
.wp-block-surecart-product-title{margin:0;width:100%}.wp-block-surecart-product-title a{color:var(--sc-cart-main-label-text-color);text-decoration:none}

/*# sourceURL=https://gnl-solution.fr/wp-content/plugins/surecart/packages/blocks-next/build/blocks/product-title/style-index.css */
</style>
<style id='wp-block-image-inline-css'>
.wp-block-image>a,.wp-block-image>figure>a{display:inline-block}.wp-block-image img{box-sizing:border-box;height:auto;max-width:100%;vertical-align:bottom}@media not (prefers-reduced-motion){.wp-block-image img.hide{visibility:hidden}.wp-block-image img.show{animation:show-content-image .4s}}.wp-block-image[style*=border-radius] img,.wp-block-image[style*=border-radius]>a{border-radius:inherit}.wp-block-image.has-custom-border img{box-sizing:border-box}.wp-block-image.aligncenter{text-align:center}.wp-block-image.alignfull>a,.wp-block-image.alignwide>a{width:100%}.wp-block-image.alignfull img,.wp-block-image.alignwide img{height:auto;width:100%}.wp-block-image .aligncenter,.wp-block-image .alignleft,.wp-block-image .alignright,.wp-block-image.aligncenter,.wp-block-image.alignleft,.wp-block-image.alignright{display:table}.wp-block-image .aligncenter>figcaption,.wp-block-image .alignleft>figcaption,.wp-block-image .alignright>figcaption,.wp-block-image.aligncenter>figcaption,.wp-block-image.alignleft>figcaption,.wp-block-image.alignright>figcaption{caption-side:bottom;display:table-caption}.wp-block-image .alignleft{float:left;margin:.5em 1em .5em 0}.wp-block-image .alignright{float:right;margin:.5em 0 .5em 1em}.wp-block-image .aligncenter{margin-left:auto;margin-right:auto}.wp-block-image :where(figcaption){margin-bottom:1em;margin-top:.5em}.wp-block-image.is-style-circle-mask img{border-radius:9999px}@supports ((-webkit-mask-image:none) or (mask-image:none)) or (-webkit-mask-image:none){.wp-block-image.is-style-circle-mask img{border-radius:0;-webkit-mask-image:url('data:image/svg+xml;utf8,<svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg"><circle cx="50" cy="50" r="50"/></svg>');mask-image:url('data:image/svg+xml;utf8,<svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg"><circle cx="50" cy="50" r="50"/></svg>');mask-mode:alpha;-webkit-mask-position:center;mask-position:center;-webkit-mask-repeat:no-repeat;mask-repeat:no-repeat;-webkit-mask-size:contain;mask-size:contain}}:root :where(.wp-block-image.is-style-rounded img,.wp-block-image .is-style-rounded img){border-radius:9999px}.wp-block-image figure{margin:0}.wp-lightbox-container{display:flex;flex-direction:column;position:relative}.wp-lightbox-container img{cursor:zoom-in}.wp-lightbox-container img:hover+button{opacity:1}.wp-lightbox-container button{align-items:center;backdrop-filter:blur(16px) saturate(180%);background-color:#5a5a5a40;border:none;border-radius:4px;cursor:zoom-in;display:flex;height:20px;justify-content:center;opacity:0;padding:0;position:absolute;right:16px;text-align:center;top:16px;width:20px;z-index:100}@media not (prefers-reduced-motion){.wp-lightbox-container button{transition:opacity .2s ease}}.wp-lightbox-container button:focus-visible{outline:3px auto #5a5a5a40;outline:3px auto -webkit-focus-ring-color;outline-offset:3px}.wp-lightbox-container button:hover{cursor:pointer;opacity:1}.wp-lightbox-container button:focus{opacity:1}.wp-lightbox-container button:focus,.wp-lightbox-container button:hover,.wp-lightbox-container button:not(:hover):not(:active):not(.has-background){background-color:#5a5a5a40;border:none}.wp-lightbox-overlay{box-sizing:border-box;cursor:zoom-out;height:100vh;left:0;overflow:hidden;position:fixed;top:0;visibility:hidden;width:100%;z-index:100000}.wp-lightbox-overlay .close-button{align-items:center;cursor:pointer;display:flex;justify-content:center;min-height:40px;min-width:40px;padding:0;position:absolute;right:calc(env(safe-area-inset-right) + 16px);top:calc(env(safe-area-inset-top) + 16px);z-index:5000000}.wp-lightbox-overlay .close-button:focus,.wp-lightbox-overlay .close-button:hover,.wp-lightbox-overlay .close-button:not(:hover):not(:active):not(.has-background){background:none;border:none}.wp-lightbox-overlay .lightbox-image-container{height:var(--wp--lightbox-container-height);left:50%;overflow:hidden;position:absolute;top:50%;transform:translate(-50%,-50%);transform-origin:top left;width:var(--wp--lightbox-container-width);z-index:9999999999}.wp-lightbox-overlay .wp-block-image{align-items:center;box-sizing:border-box;display:flex;height:100%;justify-content:center;margin:0;position:relative;transform-origin:0 0;width:100%;z-index:3000000}.wp-lightbox-overlay .wp-block-image img{height:var(--wp--lightbox-image-height);min-height:var(--wp--lightbox-image-height);min-width:var(--wp--lightbox-image-width);width:var(--wp--lightbox-image-width)}.wp-lightbox-overlay .wp-block-image figcaption{display:none}.wp-lightbox-overlay button{background:none;border:none}.wp-lightbox-overlay .scrim{background-color:#fff;height:100%;opacity:.9;position:absolute;width:100%;z-index:2000000}.wp-lightbox-overlay.active{visibility:visible}@media not (prefers-reduced-motion){.wp-lightbox-overlay.active{animation:turn-on-visibility .25s both}.wp-lightbox-overlay.active img{animation:turn-on-visibility .35s both}.wp-lightbox-overlay.show-closing-animation:not(.active){animation:turn-off-visibility .35s both}.wp-lightbox-overlay.show-closing-animation:not(.active) img{animation:turn-off-visibility .25s both}.wp-lightbox-overlay.zoom.active{animation:none;opacity:1;visibility:visible}.wp-lightbox-overlay.zoom.active .lightbox-image-container{animation:lightbox-zoom-in .4s}.wp-lightbox-overlay.zoom.active .lightbox-image-container img{animation:none}.wp-lightbox-overlay.zoom.active .scrim{animation:turn-on-visibility .4s forwards}.wp-lightbox-overlay.zoom.show-closing-animation:not(.active){animation:none}.wp-lightbox-overlay.zoom.show-closing-animation:not(.active) .lightbox-image-container{animation:lightbox-zoom-out .4s}.wp-lightbox-overlay.zoom.show-closing-animation:not(.active) .lightbox-image-container img{animation:none}.wp-lightbox-overlay.zoom.show-closing-animation:not(.active) .scrim{animation:turn-off-visibility .4s forwards}}@keyframes show-content-image{0%{visibility:hidden}99%{visibility:hidden}to{visibility:visible}}@keyframes turn-on-visibility{0%{opacity:0}to{opacity:1}}@keyframes turn-off-visibility{0%{opacity:1;visibility:visible}99%{opacity:0;visibility:visible}to{opacity:0;visibility:hidden}}@keyframes lightbox-zoom-in{0%{transform:translate(calc((-100vw + var(--wp--lightbox-scrollbar-width))/2 + var(--wp--lightbox-initial-left-position)),calc(-50vh + var(--wp--lightbox-initial-top-position))) scale(var(--wp--lightbox-scale))}to{transform:translate(-50%,-50%) scale(1)}}@keyframes lightbox-zoom-out{0%{transform:translate(-50%,-50%) scale(1);visibility:visible}99%{visibility:visible}to{transform:translate(calc((-100vw + var(--wp--lightbox-scrollbar-width))/2 + var(--wp--lightbox-initial-left-position)),calc(-50vh + var(--wp--lightbox-initial-top-position))) scale(var(--wp--lightbox-scale));visibility:hidden}}
/*# sourceURL=https://gnl-solution.fr/wp-includes/blocks/image/style.min.css */
</style>
<style id='surecart-product-list-price-style-inline-css'>
.wp-block-surecart-product-list-price{color:var(--sc-color-gray-700);margin:0}

/*# sourceURL=https://gnl-solution.fr/wp-content/plugins/surecart/packages/blocks-next/build/blocks/product-price/style-index.css */
</style>
<style id='wp-block-details-inline-css'>
.wp-block-details{box-sizing:border-box}.wp-block-details summary{cursor:pointer}
/*# sourceURL=https://gnl-solution.fr/wp-includes/blocks/details/style.min.css */
</style>
<link rel='stylesheet' id='surecart-prose-css' href='wp-content/plugins/surecart/packages/blocks-next/build/styles/prosee6ad.css?ver=1777926551' media='all' />
<style id='surecart-product-template-style-inline-css'>
.wp-block-surecart-product-template{list-style:none!important;margin:0!important;max-width:100%;padding:0!important;width:100%}.sc-product-item{-webkit-box-sizing:border-box;box-sizing:border-box;height:100%;margin:0!important}.sc-product-item.sc-has-animation-fade-up{-webkit-animation-duration:var(--sc-transition-fast);animation-duration:var(--sc-transition-fast);-webkit-animation-fill-mode:both;animation-fill-mode:both;-webkit-animation-name:fadeInUp;animation-name:fadeInUp;-webkit-animation-timing-function:cubic-bezier(.4,0,.2,1);animation-timing-function:cubic-bezier(.4,0,.2,1);opacity:0}.sc-product-item.sc-has-animation-fade-up:nth-child(2n){-webkit-animation-delay:.05s;animation-delay:.05s}.sc-product-item.sc-has-animation-fade-up:nth-child(3n){-webkit-animation-delay:75ms;animation-delay:75ms}.sc-product-item.sc-has-animation-fade-up:nth-child(4n){-webkit-animation-delay:.1s;animation-delay:.1s}.sc-product-item.sc-has-animation-fade-up:nth-child(5n){-webkit-animation-delay:.125s;animation-delay:.125s}.sc-product-item.sc-has-animation-fade-up:nth-child(6n){-webkit-animation-delay:.15s;animation-delay:.15s}.sc-product-item.sc-has-animation-fade-up:nth-child(7n){-webkit-animation-delay:.175s;animation-delay:.175s}.sc-product-item.sc-has-animation-fade-up:nth-child(8n){-webkit-animation-delay:.2s;animation-delay:.2s}.sc-product-item.sc-has-animation-fade-up:nth-child(9n){-webkit-animation-delay:.225s;animation-delay:.225s}.sc-product-item.sc-has-animation-fade-up:nth-child(10n){-webkit-animation-delay:.25s;animation-delay:.25s}.sc-product-item.sc-has-animation-fade-up:nth-child(11n){-webkit-animation-delay:.275s;animation-delay:.275s}.sc-product-item.sc-has-animation-fade-up:nth-child(12n){-webkit-animation-delay:.3s;animation-delay:.3s}.sc-product-item.sc-has-animation-fade-up:nth-child(13n){-webkit-animation-delay:.325s;animation-delay:.325s}.sc-product-item.sc-has-animation-fade-up:nth-child(14n){-webkit-animation-delay:.35s;animation-delay:.35s}.sc-product-item.sc-has-animation-fade-up:nth-child(15n){-webkit-animation-delay:.375s;animation-delay:.375s}.sc-product-item.sc-has-animation-fade-up:nth-child(16n){-webkit-animation-delay:.4s;animation-delay:.4s}.sc-product-item.sc-has-animation-fade-up:nth-child(17n){-webkit-animation-delay:.425s;animation-delay:.425s}.sc-product-item.sc-has-animation-fade-up:nth-child(18n){-webkit-animation-delay:.45s;animation-delay:.45s}.sc-product-item.sc-has-animation-fade-up:nth-child(19n){-webkit-animation-delay:.475s;animation-delay:.475s}.sc-product-item.sc-has-animation-fade-up:nth-child(20n){-webkit-animation-delay:.5s;animation-delay:.5s}.sc-product-item-link,a.sc-product-item-link{-webkit-box-sizing:border-box;box-sizing:border-box;color:inherit;display:block;height:100%;text-decoration:none!important}.sc-product-item-link:hover,a.sc-product-item-link:hover{color:inherit}.sc-product-item-link:focus:not(:focus-visible),a.sc-product-item-link:focus:not(:focus-visible){outline:none}@-webkit-keyframes fadeInUp{0%{opacity:0;-webkit-transform:translate3d(0,20px,0);transform:translate3d(0,20px,0)}to{opacity:1;-webkit-transform:translateZ(0);transform:translateZ(0)}}@keyframes fadeInUp{0%{opacity:0;-webkit-transform:translate3d(0,20px,0);transform:translate3d(0,20px,0)}to{opacity:1;-webkit-transform:translateZ(0);transform:translateZ(0)}}@media(prefers-reduced-motion){.sc-product-item{-webkit-animation-name:none;animation-name:none;opacity:1}}@media(max-width:480px){.wp-block-surecart-product-template{-ms-grid-columns:1fr!important;grid-template-columns:1fr!important}}@media(min-width:480px)and (max-width:768px){.wp-block-surecart-product-template.sc-product-template-columns-10,.wp-block-surecart-product-template.sc-product-template-columns-11,.wp-block-surecart-product-template.sc-product-template-columns-12,.wp-block-surecart-product-template.sc-product-template-columns-13,.wp-block-surecart-product-template.sc-product-template-columns-14,.wp-block-surecart-product-template.sc-product-template-columns-15,.wp-block-surecart-product-template.sc-product-template-columns-16,.wp-block-surecart-product-template.sc-product-template-columns-4,.wp-block-surecart-product-template.sc-product-template-columns-5,.wp-block-surecart-product-template.sc-product-template-columns-6,.wp-block-surecart-product-template.sc-product-template-columns-7,.wp-block-surecart-product-template.sc-product-template-columns-8,.wp-block-surecart-product-template.sc-product-template-columns-9{-ms-grid-columns:(1fr)[3];grid-template-columns:repeat(3,1fr)}}

/*# sourceURL=https://gnl-solution.fr/wp-content/plugins/surecart/packages/blocks-next/build/blocks/product-template/style-index.css */
</style>
<style id='surecart-product-pagination-previous-style-inline-css'>
.wp-block-surecart-product-pagination-previous{display:-webkit-inline-box;display:-ms-inline-flexbox;display:inline-flex;-webkit-box-align:center;-ms-flex-align:center;align-items:center;color:inherit;gap:var(--sc-spacing-xx-small);text-decoration:none!important}.wp-block-surecart-product-pagination-previous__icon{height:1em;width:1em}.wp-block-surecart-product-pagination-previous:focus:not(:focus-visible){outline:none}.wp-block-surecart-product-pagination-previous[aria-disabled]{opacity:.5;pointer-events:none;text-decoration:none}

/*# sourceURL=https://gnl-solution.fr/wp-content/plugins/surecart/packages/blocks-next/build/blocks/product-pagination-previous/style-index.css */
</style>
<style id='surecart-product-pagination-next-style-inline-css'>
.wp-block-surecart-product-pagination-next{display:-webkit-inline-box;display:-ms-inline-flexbox;display:inline-flex;-webkit-box-align:center;-ms-flex-align:center;align-items:center;color:inherit;gap:var(--sc-spacing-xx-small);text-decoration:none!important}.wp-block-surecart-product-pagination-next__icon{height:1em;width:1em}.wp-block-surecart-product-pagination-next:focus:not(:focus-visible){outline:none}.wp-block-surecart-product-pagination-next[aria-disabled]{opacity:.5;pointer-events:none;text-decoration:none}

/*# sourceURL=https://gnl-solution.fr/wp-content/plugins/surecart/packages/blocks-next/build/blocks/product-pagination-next/style-index.css */
</style>
<style id='surecart-product-pagination-style-inline-css'>
.wp-block-surecart-product-pagination{margin-top:1em}.wp-block-surecart-product-pagination>*{margin-bottom:.5em;margin-right:.5em}.wp-block-surecart-product-pagination>:last-child{margin-right:0}.wp-block-surecart-product-pagination>.disabled{opacity:.5;pointer-events:none;text-decoration:none}.wp-block-surecart-product-pagination.is-content-justification-space-between>.wp-block-surecart-product-pagination-next:last-of-type{-webkit-margin-start:auto;margin-inline-start:auto}.wp-block-surecart-product-pagination.is-content-justification-space-between>.wp-block-surecart-product-pagination-previous:first-child{-webkit-margin-end:auto;margin-inline-end:auto}.wp-block-surecart-product-pagination .wp-block-surecart-product-pagination-previous-arrow{display:inline-block;margin-right:1ch}.wp-block-surecart-product-pagination .wp-block-surecart-product-pagination-previous-arrow:not(.is-arrow-chevron){-webkit-transform:scaleX(1);-ms-transform:scaleX(1);transform:scaleX(1)}.wp-block-surecart-product-pagination .wp-block-surecart-product-pagination-next-arrow{display:inline-block;margin-left:1ch}.wp-block-surecart-product-pagination .wp-block-surecart-product-pagination-next-arrow:not(.is-arrow-chevron){-webkit-transform:scaleX(1);-ms-transform:scaleX(1);transform:scaleX(1)}.wp-block-surecart-product-pagination.aligncenter{-webkit-box-pack:center;-ms-flex-pack:center;justify-content:center}

/*# sourceURL=https://gnl-solution.fr/wp-content/plugins/surecart/packages/blocks-next/build/blocks/product-pagination/style-index.css */
</style>
<style id='surecart-product-list-style-inline-css'>
.wp-block-surecart-product-list{-webkit-box-sizing:border-box;box-sizing:border-box;position:relative}.alignwide.wp-block-group:has(+.wp-block-surecart-product-list),.alignwide.wp-block-group:has(.wp-block-surecart-product-list),.alignwide.wp-block-surecart-product-list{margin-left:auto;margin-right:auto;max-width:100%}.is-layout-flex{display:-webkit-box;display:-ms-flexbox;display:flex}.is-layout-grid{display:-ms-grid;display:grid}

/*# sourceURL=https://gnl-solution.fr/wp-content/plugins/surecart/packages/blocks-next/build/blocks/product-list/style-index.css */
</style>
<link rel='stylesheet' id='surecart-tag-css' href='wp-content/plugins/surecart/packages/blocks-next/build/styles/tage6ad.css?ver=1777926551' media='all' />
<style id='wp-block-list-inline-css'>
ol,ul{box-sizing:border-box}:root :where(.wp-block-list.has-background){padding:1.25em 2.375em}

				ul.is-style-checkmark-list {
					list-style-type: "\2713";
				}

				ul.is-style-checkmark-list li {
					padding-inline-start: 1ch;
				}
/*# sourceURL=wp-block-list-inline-css */
</style>
<style id='wp-block-spacer-inline-css'>
.wp-block-spacer{clear:both}
/*# sourceURL=https://gnl-solution.fr/wp-includes/blocks/spacer/style.min.css */
</style>
<style id='wp-block-post-content-inline-css'>
.wp-block-post-content{display:flow-root}
/*# sourceURL=https://gnl-solution.fr/wp-includes/blocks/post-content/style.min.css */
</style>
<style id='wp-block-post-template-inline-css'>
.wp-block-post-template{box-sizing:border-box;list-style:none;margin-bottom:0;margin-top:0;max-width:100%;padding:0}.wp-block-post-template.is-flex-container{display:flex;flex-direction:row;flex-wrap:wrap;gap:1.25em}.wp-block-post-template.is-flex-container>li{margin:0;width:100%}@media (min-width:600px){.wp-block-post-template.is-flex-container.is-flex-container.columns-2>li{width:calc(50% - .625em)}.wp-block-post-template.is-flex-container.is-flex-container.columns-3>li{width:calc(33.33333% - .83333em)}.wp-block-post-template.is-flex-container.is-flex-container.columns-4>li{width:calc(25% - .9375em)}.wp-block-post-template.is-flex-container.is-flex-container.columns-5>li{width:calc(20% - 1em)}.wp-block-post-template.is-flex-container.is-flex-container.columns-6>li{width:calc(16.66667% - 1.04167em)}}@media (max-width:600px){.wp-block-post-template-is-layout-grid.wp-block-post-template-is-layout-grid.wp-block-post-template-is-layout-grid.wp-block-post-template-is-layout-grid{grid-template-columns:1fr}}.wp-block-post-template-is-layout-constrained>li>.alignright,.wp-block-post-template-is-layout-flow>li>.alignright{float:right;margin-inline-end:0;margin-inline-start:2em}.wp-block-post-template-is-layout-constrained>li>.alignleft,.wp-block-post-template-is-layout-flow>li>.alignleft{float:left;margin-inline-end:2em;margin-inline-start:0}.wp-block-post-template-is-layout-constrained>li>.aligncenter,.wp-block-post-template-is-layout-flow>li>.aligncenter{margin-inline-end:auto;margin-inline-start:auto}
/*# sourceURL=https://gnl-solution.fr/wp-includes/blocks/post-template/style.min.css */
</style>
<style id='wp-interactivity-router-animations-inline-css'>
			.wp-interactivity-router-loading-bar {
				position: fixed;
				top: 0;
				left: 0;
				margin: 0;
				padding: 0;
				width: 100vw;
				max-width: 100vw !important;
				height: 4px;
				background-color: #000;
				opacity: 0
			}
			.wp-interactivity-router-loading-bar.start-animation {
				animation: wp-interactivity-router-loading-bar-start-animation 30s cubic-bezier(0.03, 0.5, 0, 1) forwards
			}
			.wp-interactivity-router-loading-bar.finish-animation {
				animation: wp-interactivity-router-loading-bar-finish-animation 300ms ease-in
			}
			@keyframes wp-interactivity-router-loading-bar-start-animation {
				0% { transform: scaleX(0); transform-origin: 0 0; opacity: 1 }
				100% { transform: scaleX(1); transform-origin: 0 0; opacity: 1 }
			}
			@keyframes wp-interactivity-router-loading-bar-finish-animation {
				0% { opacity: 1 }
				50% { opacity: 1 }
				100% { opacity: 0 }
			}
/*# sourceURL=wp-interactivity-router-animations-inline-css */
</style>
<link rel='stylesheet' id='wp-block-social-links-css' href='wp-includes/blocks/social-links/style.minb34e.css?ver=6.9.4' media='all' />
<style id='wp-block-library-inline-css'>
:root{--wp-block-synced-color:#7a00df;--wp-block-synced-color--rgb:122,0,223;--wp-bound-block-color:var(--wp-block-synced-color);--wp-editor-canvas-background:#ddd;--wp-admin-theme-color:#007cba;--wp-admin-theme-color--rgb:0,124,186;--wp-admin-theme-color-darker-10:#006ba1;--wp-admin-theme-color-darker-10--rgb:0,107,160.5;--wp-admin-theme-color-darker-20:#005a87;--wp-admin-theme-color-darker-20--rgb:0,90,135;--wp-admin-border-width-focus:2px}@media (min-resolution:192dpi){:root{--wp-admin-border-width-focus:1.5px}}.wp-element-button{cursor:pointer}:root .has-very-light-gray-background-color{background-color:#eee}:root .has-very-dark-gray-background-color{background-color:#313131}:root .has-very-light-gray-color{color:#eee}:root .has-very-dark-gray-color{color:#313131}:root .has-vivid-green-cyan-to-vivid-cyan-blue-gradient-background{background:linear-gradient(135deg,#00d084,#0693e3)}:root .has-purple-crush-gradient-background{background:linear-gradient(135deg,#34e2e4,#4721fb 50%,#ab1dfe)}:root .has-hazy-dawn-gradient-background{background:linear-gradient(135deg,#faaca8,#dad0ec)}:root .has-subdued-olive-gradient-background{background:linear-gradient(135deg,#fafae1,#67a671)}:root .has-atomic-cream-gradient-background{background:linear-gradient(135deg,#fdd79a,#004a59)}:root .has-nightshade-gradient-background{background:linear-gradient(135deg,#330968,#31cdcf)}:root .has-midnight-gradient-background{background:linear-gradient(135deg,#020381,#2874fc)}:root{--wp--preset--font-size--normal:16px;--wp--preset--font-size--huge:42px}.has-regular-font-size{font-size:1em}.has-larger-font-size{font-size:2.625em}.has-normal-font-size{font-size:var(--wp--preset--font-size--normal)}.has-huge-font-size{font-size:var(--wp--preset--font-size--huge)}.has-text-align-center{text-align:center}.has-text-align-left{text-align:left}.has-text-align-right{text-align:right}.has-fit-text{white-space:nowrap!important}#end-resizable-editor-section{display:none}.aligncenter{clear:both}.items-justified-left{justify-content:flex-start}.items-justified-center{justify-content:center}.items-justified-right{justify-content:flex-end}.items-justified-space-between{justify-content:space-between}.screen-reader-text{border:0;clip-path:inset(50%);height:1px;margin:-1px;overflow:hidden;padding:0;position:absolute;width:1px;word-wrap:normal!important}.screen-reader-text:focus{background-color:#ddd;clip-path:none;color:#444;display:block;font-size:1em;height:auto;left:5px;line-height:normal;padding:15px 23px 14px;text-decoration:none;top:5px;width:auto;z-index:100000}html :where(.has-border-color){border-style:solid}html :where([style*=border-top-color]){border-top-style:solid}html :where([style*=border-right-color]){border-right-style:solid}html :where([style*=border-bottom-color]){border-bottom-style:solid}html :where([style*=border-left-color]){border-left-style:solid}html :where([style*=border-width]){border-style:solid}html :where([style*=border-top-width]){border-top-style:solid}html :where([style*=border-right-width]){border-right-style:solid}html :where([style*=border-bottom-width]){border-bottom-style:solid}html :where([style*=border-left-width]){border-left-style:solid}html :where(img[class*=wp-image-]){height:auto;max-width:100%}:where(figure){margin:0 0 1em}html :where(.is-position-sticky){--wp-admin--admin-bar--position-offset:var(--wp-admin--admin-bar--height,0px)}@media screen and (max-width:600px){html :where(.is-position-sticky){--wp-admin--admin-bar--position-offset:0px}}
/*# sourceURL=/wp-includes/css/dist/block-library/common.min.css */
</style>
<style id='global-styles-inline-css'>
:root{--wp--preset--aspect-ratio--square: 1;--wp--preset--aspect-ratio--4-3: 4/3;--wp--preset--aspect-ratio--3-4: 3/4;--wp--preset--aspect-ratio--3-2: 3/2;--wp--preset--aspect-ratio--2-3: 2/3;--wp--preset--aspect-ratio--16-9: 16/9;--wp--preset--aspect-ratio--9-16: 9/16;--wp--preset--color--black: #000000;--wp--preset--color--cyan-bluish-gray: #abb8c3;--wp--preset--color--white: #ffffff;--wp--preset--color--pale-pink: #f78da7;--wp--preset--color--vivid-red: #cf2e2e;--wp--preset--color--luminous-vivid-orange: #ff6900;--wp--preset--color--luminous-vivid-amber: #fcb900;--wp--preset--color--light-green-cyan: #7bdcb5;--wp--preset--color--vivid-green-cyan: #00d084;--wp--preset--color--pale-cyan-blue: #8ed1fc;--wp--preset--color--vivid-cyan-blue: #0693e3;--wp--preset--color--vivid-purple: #9b51e0;--wp--preset--color--base: #FFFFFF;--wp--preset--color--contrast: #111111;--wp--preset--color--accent-1: #FFEE58;--wp--preset--color--accent-2: #F6CFF4;--wp--preset--color--accent-3: #503AA8;--wp--preset--color--accent-4: #686868;--wp--preset--color--accent-5: #FBFAF3;--wp--preset--color--accent-6: color-mix(in srgb, currentColor 20%, transparent);--wp--preset--gradient--vivid-cyan-blue-to-vivid-purple: linear-gradient(135deg,rgb(6,147,227) 0%,rgb(155,81,224) 100%);--wp--preset--gradient--light-green-cyan-to-vivid-green-cyan: linear-gradient(135deg,rgb(122,220,180) 0%,rgb(0,208,130) 100%);--wp--preset--gradient--luminous-vivid-amber-to-luminous-vivid-orange: linear-gradient(135deg,rgb(252,185,0) 0%,rgb(255,105,0) 100%);--wp--preset--gradient--luminous-vivid-orange-to-vivid-red: linear-gradient(135deg,rgb(255,105,0) 0%,rgb(207,46,46) 100%);--wp--preset--gradient--very-light-gray-to-cyan-bluish-gray: linear-gradient(135deg,rgb(238,238,238) 0%,rgb(169,184,195) 100%);--wp--preset--gradient--cool-to-warm-spectrum: linear-gradient(135deg,rgb(74,234,220) 0%,rgb(151,120,209) 20%,rgb(207,42,186) 40%,rgb(238,44,130) 60%,rgb(251,105,98) 80%,rgb(254,248,76) 100%);--wp--preset--gradient--blush-light-purple: linear-gradient(135deg,rgb(255,206,236) 0%,rgb(152,150,240) 100%);--wp--preset--gradient--blush-bordeaux: linear-gradient(135deg,rgb(254,205,165) 0%,rgb(254,45,45) 50%,rgb(107,0,62) 100%);--wp--preset--gradient--luminous-dusk: linear-gradient(135deg,rgb(255,203,112) 0%,rgb(199,81,192) 50%,rgb(65,88,208) 100%);--wp--preset--gradient--pale-ocean: linear-gradient(135deg,rgb(255,245,203) 0%,rgb(182,227,212) 50%,rgb(51,167,181) 100%);--wp--preset--gradient--electric-grass: linear-gradient(135deg,rgb(202,248,128) 0%,rgb(113,206,126) 100%);--wp--preset--gradient--midnight: linear-gradient(135deg,rgb(2,3,129) 0%,rgb(40,116,252) 100%);--wp--preset--font-size--small: 0.875rem;--wp--preset--font-size--medium: clamp(1rem, 1rem + ((1vw - 0.2rem) * 0.196), 1.125rem);--wp--preset--font-size--large: clamp(1.125rem, 1.125rem + ((1vw - 0.2rem) * 0.392), 1.375rem);--wp--preset--font-size--x-large: clamp(1.75rem, 1.75rem + ((1vw - 0.2rem) * 0.392), 2rem);--wp--preset--font-size--xx-large: clamp(2.15rem, 2.15rem + ((1vw - 0.2rem) * 1.333), 3rem);--wp--preset--font-family--manrope: Manrope, sans-serif;--wp--preset--font-family--fira-code: "Fira Code", monospace;--wp--preset--spacing--20: 10px;--wp--preset--spacing--30: 20px;--wp--preset--spacing--40: 30px;--wp--preset--spacing--50: clamp(30px, 5vw, 50px);--wp--preset--spacing--60: clamp(30px, 7vw, 70px);--wp--preset--spacing--70: clamp(50px, 7vw, 90px);--wp--preset--spacing--80: clamp(70px, 10vw, 140px);--wp--preset--shadow--natural: 6px 6px 9px rgba(0, 0, 0, 0.2);--wp--preset--shadow--deep: 12px 12px 50px rgba(0, 0, 0, 0.4);--wp--preset--shadow--sharp: 6px 6px 0px rgba(0, 0, 0, 0.2);--wp--preset--shadow--outlined: 6px 6px 0px -3px rgb(255, 255, 255), 6px 6px rgb(0, 0, 0);--wp--preset--shadow--crisp: 6px 6px 0px rgb(0, 0, 0);}:root { --wp--style--global--content-size: 645px;--wp--style--global--wide-size: 1340px; }:where(body) { margin: 0; }.wp-site-blocks { padding-top: var(--wp--style--root--padding-top); padding-bottom: var(--wp--style--root--padding-bottom); }.has-global-padding { padding-right: var(--wp--style--root--padding-right); padding-left: var(--wp--style--root--padding-left); }.has-global-padding > .alignfull { margin-right: calc(var(--wp--style--root--padding-right) * -1); margin-left: calc(var(--wp--style--root--padding-left) * -1); }.has-global-padding :where(:not(.alignfull.is-layout-flow) > .has-global-padding:not(.wp-block-block, .alignfull)) { padding-right: 0; padding-left: 0; }.has-global-padding :where(:not(.alignfull.is-layout-flow) > .has-global-padding:not(.wp-block-block, .alignfull)) > .alignfull { margin-left: 0; margin-right: 0; }.wp-site-blocks > .alignleft { float: left; margin-right: 2em; }.wp-site-blocks > .alignright { float: right; margin-left: 2em; }.wp-site-blocks > .aligncenter { justify-content: center; margin-left: auto; margin-right: auto; }:where(.wp-site-blocks) > * { margin-block-start: 1.2rem; margin-block-end: 0; }:where(.wp-site-blocks) > :first-child { margin-block-start: 0; }:where(.wp-site-blocks) > :last-child { margin-block-end: 0; }:root { --wp--style--block-gap: 1.2rem; }:root :where(.is-layout-flow) > :first-child{margin-block-start: 0;}:root :where(.is-layout-flow) > :last-child{margin-block-end: 0;}:root :where(.is-layout-flow) > *{margin-block-start: 1.2rem;margin-block-end: 0;}:root :where(.is-layout-constrained) > :first-child{margin-block-start: 0;}:root :where(.is-layout-constrained) > :last-child{margin-block-end: 0;}:root :where(.is-layout-constrained) > *{margin-block-start: 1.2rem;margin-block-end: 0;}:root :where(.is-layout-flex){gap: 1.2rem;}:root :where(.is-layout-grid){gap: 1.2rem;}.is-layout-flow > .alignleft{float: left;margin-inline-start: 0;margin-inline-end: 2em;}.is-layout-flow > .alignright{float: right;margin-inline-start: 2em;margin-inline-end: 0;}.is-layout-flow > .aligncenter{margin-left: auto !important;margin-right: auto !important;}.is-layout-constrained > .alignleft{float: left;margin-inline-start: 0;margin-inline-end: 2em;}.is-layout-constrained > .alignright{float: right;margin-inline-start: 2em;margin-inline-end: 0;}.is-layout-constrained > .aligncenter{margin-left: auto !important;margin-right: auto !important;}.is-layout-constrained > :where(:not(.alignleft):not(.alignright):not(.alignfull)){max-width: var(--wp--style--global--content-size);margin-left: auto !important;margin-right: auto !important;}.is-layout-constrained > .alignwide{max-width: var(--wp--style--global--wide-size);}body .is-layout-flex{display: flex;}.is-layout-flex{flex-wrap: wrap;align-items: center;}.is-layout-flex > :is(*, div){margin: 0;}body .is-layout-grid{display: grid;}.is-layout-grid > :is(*, div){margin: 0;}body{background-color: var(--wp--preset--color--base);color: var(--wp--preset--color--contrast);font-family: var(--wp--preset--font-family--manrope);font-size: var(--wp--preset--font-size--large);font-weight: 300;letter-spacing: -0.1px;line-height: 1.4;--wp--style--root--padding-top: 0px;--wp--style--root--padding-right: var(--wp--preset--spacing--50);--wp--style--root--padding-bottom: 0px;--wp--style--root--padding-left: var(--wp--preset--spacing--50);}a:where(:not(.wp-element-button)){color: currentColor;text-decoration: underline;}:root :where(a:where(:not(.wp-element-button)):hover){text-decoration: none;}h1, h2, h3, h4, h5, h6{font-weight: 400;letter-spacing: -0.1px;line-height: 1.125;}h1{font-size: var(--wp--preset--font-size--xx-large);}h2{font-size: var(--wp--preset--font-size--x-large);}h3{font-size: var(--wp--preset--font-size--large);}h4{font-size: var(--wp--preset--font-size--medium);}h5{font-size: var(--wp--preset--font-size--small);letter-spacing: 0.5px;}h6{font-size: var(--wp--preset--font-size--small);font-weight: 700;letter-spacing: 1.4px;text-transform: uppercase;}:root :where(.wp-element-button, .wp-block-button__link){background-color: var(--wp--preset--color--contrast);border-width: 0;color: var(--wp--preset--color--base);font-family: inherit;font-size: var(--wp--preset--font-size--medium);font-style: inherit;font-weight: inherit;letter-spacing: inherit;line-height: inherit;padding-top: 1rem;padding-right: 2.25rem;padding-bottom: 1rem;padding-left: 2.25rem;text-decoration: none;text-transform: inherit;}:root :where(.wp-element-button:hover, .wp-block-button__link:hover){background-color: color-mix(in srgb, var(--wp--preset--color--contrast) 85%, transparent);border-color: transparent;color: var(--wp--preset--color--base);}:root :where(.wp-element-button:focus, .wp-block-button__link:focus){outline-color: var(--wp--preset--color--accent-4);outline-offset: 2px;}:root :where(.wp-element-caption, .wp-block-audio figcaption, .wp-block-embed figcaption, .wp-block-gallery figcaption, .wp-block-image figcaption, .wp-block-table figcaption, .wp-block-video figcaption){font-size: var(--wp--preset--font-size--small);line-height: 1.4;}.has-black-color{color: var(--wp--preset--color--black) !important;}.has-cyan-bluish-gray-color{color: var(--wp--preset--color--cyan-bluish-gray) !important;}.has-white-color{color: var(--wp--preset--color--white) !important;}.has-pale-pink-color{color: var(--wp--preset--color--pale-pink) !important;}.has-vivid-red-color{color: var(--wp--preset--color--vivid-red) !important;}.has-luminous-vivid-orange-color{color: var(--wp--preset--color--luminous-vivid-orange) !important;}.has-luminous-vivid-amber-color{color: var(--wp--preset--color--luminous-vivid-amber) !important;}.has-light-green-cyan-color{color: var(--wp--preset--color--light-green-cyan) !important;}.has-vivid-green-cyan-color{color: var(--wp--preset--color--vivid-green-cyan) !important;}.has-pale-cyan-blue-color{color: var(--wp--preset--color--pale-cyan-blue) !important;}.has-vivid-cyan-blue-color{color: var(--wp--preset--color--vivid-cyan-blue) !important;}.has-vivid-purple-color{color: var(--wp--preset--color--vivid-purple) !important;}.has-base-color{color: var(--wp--preset--color--base) !important;}.has-contrast-color{color: var(--wp--preset--color--contrast) !important;}.has-accent-1-color{color: var(--wp--preset--color--accent-1) !important;}.has-accent-2-color{color: var(--wp--preset--color--accent-2) !important;}.has-accent-3-color{color: var(--wp--preset--color--accent-3) !important;}.has-accent-4-color{color: var(--wp--preset--color--accent-4) !important;}.has-accent-5-color{color: var(--wp--preset--color--accent-5) !important;}.has-accent-6-color{color: var(--wp--preset--color--accent-6) !important;}.has-black-background-color{background-color: var(--wp--preset--color--black) !important;}.has-cyan-bluish-gray-background-color{background-color: var(--wp--preset--color--cyan-bluish-gray) !important;}.has-white-background-color{background-color: var(--wp--preset--color--white) !important;}.has-pale-pink-background-color{background-color: var(--wp--preset--color--pale-pink) !important;}.has-vivid-red-background-color{background-color: var(--wp--preset--color--vivid-red) !important;}.has-luminous-vivid-orange-background-color{background-color: var(--wp--preset--color--luminous-vivid-orange) !important;}.has-luminous-vivid-amber-background-color{background-color: var(--wp--preset--color--luminous-vivid-amber) !important;}.has-light-green-cyan-background-color{background-color: var(--wp--preset--color--light-green-cyan) !important;}.has-vivid-green-cyan-background-color{background-color: var(--wp--preset--color--vivid-green-cyan) !important;}.has-pale-cyan-blue-background-color{background-color: var(--wp--preset--color--pale-cyan-blue) !important;}.has-vivid-cyan-blue-background-color{background-color: var(--wp--preset--color--vivid-cyan-blue) !important;}.has-vivid-purple-background-color{background-color: var(--wp--preset--color--vivid-purple) !important;}.has-base-background-color{background-color: var(--wp--preset--color--base) !important;}.has-contrast-background-color{background-color: var(--wp--preset--color--contrast) !important;}.has-accent-1-background-color{background-color: var(--wp--preset--color--accent-1) !important;}.has-accent-2-background-color{background-color: var(--wp--preset--color--accent-2) !important;}.has-accent-3-background-color{background-color: var(--wp--preset--color--accent-3) !important;}.has-accent-4-background-color{background-color: var(--wp--preset--color--accent-4) !important;}.has-accent-5-background-color{background-color: var(--wp--preset--color--accent-5) !important;}.has-accent-6-background-color{background-color: var(--wp--preset--color--accent-6) !important;}.has-black-border-color{border-color: var(--wp--preset--color--black) !important;}.has-cyan-bluish-gray-border-color{border-color: var(--wp--preset--color--cyan-bluish-gray) !important;}.has-white-border-color{border-color: var(--wp--preset--color--white) !important;}.has-pale-pink-border-color{border-color: var(--wp--preset--color--pale-pink) !important;}.has-vivid-red-border-color{border-color: var(--wp--preset--color--vivid-red) !important;}.has-luminous-vivid-orange-border-color{border-color: var(--wp--preset--color--luminous-vivid-orange) !important;}.has-luminous-vivid-amber-border-color{border-color: var(--wp--preset--color--luminous-vivid-amber) !important;}.has-light-green-cyan-border-color{border-color: var(--wp--preset--color--light-green-cyan) !important;}.has-vivid-green-cyan-border-color{border-color: var(--wp--preset--color--vivid-green-cyan) !important;}.has-pale-cyan-blue-border-color{border-color: var(--wp--preset--color--pale-cyan-blue) !important;}.has-vivid-cyan-blue-border-color{border-color: var(--wp--preset--color--vivid-cyan-blue) !important;}.has-vivid-purple-border-color{border-color: var(--wp--preset--color--vivid-purple) !important;}.has-base-border-color{border-color: var(--wp--preset--color--base) !important;}.has-contrast-border-color{border-color: var(--wp--preset--color--contrast) !important;}.has-accent-1-border-color{border-color: var(--wp--preset--color--accent-1) !important;}.has-accent-2-border-color{border-color: var(--wp--preset--color--accent-2) !important;}.has-accent-3-border-color{border-color: var(--wp--preset--color--accent-3) !important;}.has-accent-4-border-color{border-color: var(--wp--preset--color--accent-4) !important;}.has-accent-5-border-color{border-color: var(--wp--preset--color--accent-5) !important;}.has-accent-6-border-color{border-color: var(--wp--preset--color--accent-6) !important;}.has-vivid-cyan-blue-to-vivid-purple-gradient-background{background: var(--wp--preset--gradient--vivid-cyan-blue-to-vivid-purple) !important;}.has-light-green-cyan-to-vivid-green-cyan-gradient-background{background: var(--wp--preset--gradient--light-green-cyan-to-vivid-green-cyan) !important;}.has-luminous-vivid-amber-to-luminous-vivid-orange-gradient-background{background: var(--wp--preset--gradient--luminous-vivid-amber-to-luminous-vivid-orange) !important;}.has-luminous-vivid-orange-to-vivid-red-gradient-background{background: var(--wp--preset--gradient--luminous-vivid-orange-to-vivid-red) !important;}.has-very-light-gray-to-cyan-bluish-gray-gradient-background{background: var(--wp--preset--gradient--very-light-gray-to-cyan-bluish-gray) !important;}.has-cool-to-warm-spectrum-gradient-background{background: var(--wp--preset--gradient--cool-to-warm-spectrum) !important;}.has-blush-light-purple-gradient-background{background: var(--wp--preset--gradient--blush-light-purple) !important;}.has-blush-bordeaux-gradient-background{background: var(--wp--preset--gradient--blush-bordeaux) !important;}.has-luminous-dusk-gradient-background{background: var(--wp--preset--gradient--luminous-dusk) !important;}.has-pale-ocean-gradient-background{background: var(--wp--preset--gradient--pale-ocean) !important;}.has-electric-grass-gradient-background{background: var(--wp--preset--gradient--electric-grass) !important;}.has-midnight-gradient-background{background: var(--wp--preset--gradient--midnight) !important;}.has-small-font-size{font-size: var(--wp--preset--font-size--small) !important;}.has-medium-font-size{font-size: var(--wp--preset--font-size--medium) !important;}.has-large-font-size{font-size: var(--wp--preset--font-size--large) !important;}.has-x-large-font-size{font-size: var(--wp--preset--font-size--x-large) !important;}.has-xx-large-font-size{font-size: var(--wp--preset--font-size--xx-large) !important;}.has-manrope-font-family{font-family: var(--wp--preset--font-family--manrope) !important;}.has-fira-code-font-family{font-family: var(--wp--preset--font-family--fira-code) !important;}
:root :where(.wp-block-columns-is-layout-flow) > :first-child{margin-block-start: 0;}:root :where(.wp-block-columns-is-layout-flow) > :last-child{margin-block-end: 0;}:root :where(.wp-block-columns-is-layout-flow) > *{margin-block-start: var(--wp--preset--spacing--50);margin-block-end: 0;}:root :where(.wp-block-columns-is-layout-constrained) > :first-child{margin-block-start: 0;}:root :where(.wp-block-columns-is-layout-constrained) > :last-child{margin-block-end: 0;}:root :where(.wp-block-columns-is-layout-constrained) > *{margin-block-start: var(--wp--preset--spacing--50);margin-block-end: 0;}:root :where(.wp-block-columns-is-layout-flex){gap: var(--wp--preset--spacing--50);}:root :where(.wp-block-columns-is-layout-grid){gap: var(--wp--preset--spacing--50);}
:root :where(.wp-block-navigation){font-size: var(--wp--preset--font-size--medium);}
:root :where(.wp-block-navigation a:where(:not(.wp-element-button))){text-decoration: none;}
:root :where(.wp-block-navigation a:where(:not(.wp-element-button)):hover){text-decoration: underline;}
:root :where(.wp-block-list li){margin-top: 0.5rem;}
/*# sourceURL=global-styles-inline-css */
</style>
<style id='block-style-variation-styles-inline-css'>
:root :where(.is-style-section-4--4 .wp-element-button, .is-style-section-4--4 .wp-block-button__link){background-color: var(--wp--preset--color--accent-2);color: var(--wp--preset--color--accent-3);}:root :where(.is-style-section-4--4 .wp-element-button:hover, .is-style-section-4--4 .wp-block-button__link:hover){background-color: color-mix(in srgb, var(--wp--preset--color--accent-2) 85%, transparent);color: var(--wp--preset--color--accent-3);}:root :where(.is-style-section-4--4 .wp-block-separator){color: color-mix(in srgb, currentColor 25%, transparent);}:root :where(.is-style-section-4--4 .wp-block-post-author-name){color: currentColor;}:root :where(.is-style-section-4--4 .wp-block-post-author-name a:where(:not(.wp-element-button))){color: currentColor;}:root :where(.is-style-section-4--4 .wp-block-post-date){color: color-mix(in srgb, currentColor 85%, transparent);}:root :where(.is-style-section-4--4 .wp-block-post-date a:where(:not(.wp-element-button))){color: color-mix(in srgb, currentColor 85%, transparent);}:root :where(.is-style-section-4--4 .wp-block-post-terms){color: currentColor;}:root :where(.is-style-section-4--4 .wp-block-post-terms a:where(:not(.wp-element-button))){color: currentColor;}:root :where(.is-style-section-4--4 .wp-block-comment-author-name){color: currentColor;}:root :where(.is-style-section-4--4 .wp-block-comment-author-name a:where(:not(.wp-element-button))){color: currentColor;}:root :where(.is-style-section-4--4 .wp-block-comment-date){color: currentColor;}:root :where(.is-style-section-4--4 .wp-block-comment-date a:where(:not(.wp-element-button))){color: currentColor;}:root :where(.is-style-section-4--4 .wp-block-comment-edit-link){color: currentColor;}:root :where(.is-style-section-4--4 .wp-block-comment-edit-link a:where(:not(.wp-element-button))){color: currentColor;}:root :where(.is-style-section-4--4 .wp-block-comment-reply-link){color: currentColor;}:root :where(.is-style-section-4--4 .wp-block-comment-reply-link a:where(:not(.wp-element-button))){color: currentColor;}:root :where(.is-style-section-4--4 .wp-block-pullquote){color: currentColor;}:root :where(.is-style-section-4--4 .wp-block-quote){color: currentColor;}:root :where(.wp-block-group.is-style-section-4--4){background-color: var(--wp--preset--color--accent-3);color: var(--wp--preset--color--accent-2);}
:root :where(.is-style-section-1--5 .wp-block-separator){color: color-mix(in srgb, currentColor 25%, transparent);}:root :where(.is-style-section-1--5 .wp-block-site-title){color: currentColor;}:root :where(.is-style-section-1--5 .wp-block-site-title a:where(:not(.wp-element-button))){color: currentColor;}:root :where(.is-style-section-1--5 .wp-block-post-author-name){color: currentColor;}:root :where(.is-style-section-1--5 .wp-block-post-author-name a:where(:not(.wp-element-button))){color: currentColor;}:root :where(.is-style-section-1--5 .wp-block-post-date){color: color-mix(in srgb, currentColor 85%, transparent);}:root :where(.is-style-section-1--5 .wp-block-post-date a:where(:not(.wp-element-button))){color: color-mix(in srgb, currentColor 85%, transparent);}:root :where(.is-style-section-1--5 .wp-block-post-terms){color: currentColor;}:root :where(.is-style-section-1--5 .wp-block-post-terms a:where(:not(.wp-element-button))){color: currentColor;}:root :where(.is-style-section-1--5 .wp-block-comment-author-name){color: currentColor;}:root :where(.is-style-section-1--5 .wp-block-comment-author-name a:where(:not(.wp-element-button))){color: currentColor;}:root :where(.is-style-section-1--5 .wp-block-comment-date){color: currentColor;}:root :where(.is-style-section-1--5 .wp-block-comment-date a:where(:not(.wp-element-button))){color: currentColor;}:root :where(.is-style-section-1--5 .wp-block-comment-edit-link){color: currentColor;}:root :where(.is-style-section-1--5 .wp-block-comment-edit-link a:where(:not(.wp-element-button))){color: currentColor;}:root :where(.is-style-section-1--5 .wp-block-comment-reply-link){color: currentColor;}:root :where(.is-style-section-1--5 .wp-block-comment-reply-link a:where(:not(.wp-element-button))){color: currentColor;}:root :where(.is-style-section-1--5 .wp-block-pullquote){color: currentColor;}:root :where(.is-style-section-1--5 .wp-block-quote){color: currentColor;}:root :where(.wp-block-group.is-style-section-1--5){background-color: var(--wp--preset--color--accent-5);color: var(--wp--preset--color--contrast);}
/*# sourceURL=block-style-variation-styles-inline-css */
</style>
<style id='wp-emoji-styles-inline-css'>

	img.wp-smiley, img.emoji {
		display: inline !important;
		border: none !important;
		box-shadow: none !important;
		height: 1em !important;
		width: 1em !important;
		margin: 0 0.07em !important;
		vertical-align: -0.1em !important;
		background: none !important;
		padding: 0 !important;
	}
/*# sourceURL=wp-emoji-styles-inline-css */
</style>
<style id='core-block-supports-inline-css'>
.wp-elements-809bc659be72665e235ad3d36b365503 a:where(:not(.wp-element-button)){color:#828c99;}.wp-elements-ee4dbd5951330c8c9241783d4e28cfa7 a:where(:not(.wp-element-button)){color:#4b5563;}.wp-container-core-group-is-layout-09de181c{flex-wrap:nowrap;justify-content:space-between;}.wp-container-content-962be591{flex-basis:80px;}.wp-elements-60f7f467eabd4c6ec970fcc2eaa8cf6a a:where(:not(.wp-element-button)){color:#4b5563;}.wp-elements-a60d3354043cc3943fcff11dd368558f a:where(:not(.wp-element-button)){color:#828c99;}.wp-elements-3b6c2dd07fb824da6869ab9db90509fd a:where(:not(.wp-element-button)){color:#828c99;}.wp-elements-f801e5793ad9d8c2ddc6dac370ea114d a:where(:not(.wp-element-button)){color:#828c99;}.wp-container-core-group-is-layout-d6743c7d > *{margin-block-start:0;margin-block-end:0;}.wp-container-core-group-is-layout-d6743c7d > * + *{margin-block-start:0px;margin-block-end:0;}.wp-elements-c4d961c5a887fd6426f97e83fb398cda a:where(:not(.wp-element-button)){color:var(--wp--preset--color--vivid-red);}.wp-container-content-0733e5d0{flex-basis:50%;}.wp-elements-4846c9d71354b9440ca2a19d5be871d2 a:where(:not(.wp-element-button)){color:#828c99;}.wp-elements-769386e2adcb8f0c0c8d072132bffb7d a:where(:not(.wp-element-button)){color:#4b5563;}.wp-elements-52fdf9e8f041aab881863ee85b983f94 a:where(:not(.wp-element-button)){color:#828c99;}.wp-container-core-group-is-layout-f8a47911{flex-wrap:nowrap;gap:4px;justify-content:flex-end;}.wp-elements-cb427a178eb71aa290fe69000ceebd3a a:where(:not(.wp-element-button)){color:#828c99;}.wp-elements-522ec5f8f335c00760dbead241e51319 a:where(:not(.wp-element-button)){color:#828c99;}.wp-container-content-9cfa9a5a{flex-grow:1;}.wp-container-core-group-is-layout-d63c796e{flex-wrap:nowrap;justify-content:space-between;align-items:stretch;}.wp-elements-b7aa8caee5d0c6fb532f9a9a0f686a58 a:where(:not(.wp-element-button)){color:#6b7280;}.wp-container-core-group-is-layout-4269a6fd{gap:0px;flex-direction:column;align-items:flex-end;}.wp-container-core-group-is-layout-c0dd7891{flex-wrap:nowrap;justify-content:space-between;align-items:center;}.wp-container-core-group-is-layout-a46423eb{flex-wrap:nowrap;gap:5px;flex-direction:column;align-items:stretch;justify-content:flex-start;}.wp-container-core-group-is-layout-bd3f9bef{flex-wrap:nowrap;align-items:stretch;}.wp-container-surecart-slide-out-cart-line-items-is-layout-546f3c6d > *{margin-block-start:0;margin-block-end:0;}.wp-container-surecart-slide-out-cart-line-items-is-layout-546f3c6d > * + *{margin-block-start:2em;margin-block-end:0;}.wp-elements-36129bdfc2a06ca5886177756478eff4 a:where(:not(.wp-element-button)){color:#828c99;}.wp-elements-3b9a68b7fb804ca71ada88a0c435b71f a:where(:not(.wp-element-button)){color:#4b5563;}.wp-container-surecart-slide-out-cart-items-subtotal-is-layout-7351673c{flex-wrap:nowrap;justify-content:space-between;align-items:flex-start;}.wp-container-surecart-slide-out-cart-is-layout-d6743c7d > *{margin-block-start:0;margin-block-end:0;}.wp-container-surecart-slide-out-cart-is-layout-d6743c7d > * + *{margin-block-start:0px;margin-block-end:0;}.wp-container-core-group-is-layout-c0d5ccf6{flex-wrap:nowrap;gap:0;}.wp-container-core-navigation-is-layout-fc306653{justify-content:flex-end;}.wp-container-core-group-is-layout-82baacbd{flex-wrap:nowrap;gap:var(--wp--preset--spacing--20);justify-content:flex-end;}.wp-container-core-group-is-layout-55cd4bd1{flex-wrap:nowrap;justify-content:space-between;}.wp-container-3{top:calc(0px + var(--wp-admin--admin-bar--position-offset, 0px));position:sticky;z-index:10;}.wp-container-core-group-is-layout-12dd3699 > :where(:not(.alignleft):not(.alignright):not(.alignfull)){margin-left:0 !important;}.wp-container-core-cover-is-layout-d89aad35 > .alignfull{margin-right:calc(var(--wp--preset--spacing--50) * -1);margin-left:calc(var(--wp--preset--spacing--50) * -1);}.wp-elements-a280a41802cd9e6a208f0d57cdea031a a:where(:not(.wp-element-button)){color:var(--wp--preset--color--base);}.wp-container-core-columns-is-layout-28f84493{flex-wrap:nowrap;}.wp-container-core-group-is-layout-1cdfcc08 > .alignfull{margin-right:calc(0px * -1);margin-left:calc(0px * -1);}.wp-container-core-group-is-layout-1cdfcc08 > *{margin-block-start:0;margin-block-end:0;}.wp-container-core-group-is-layout-1cdfcc08 > * + *{margin-block-start:var(--wp--preset--spacing--20);margin-block-end:0;}.wp-elements-514aab0ce0e68704f84fe66c6668af13 a:where(:not(.wp-element-button)){color:var(--wp--preset--color--base);}.wp-container-core-group-is-layout-e21e8193 > .alignfull{margin-right:calc(0px * -1);margin-left:calc(0px * -1);}.wp-container-core-group-is-layout-e21e8193 > *{margin-block-start:0;margin-block-end:0;}.wp-container-core-group-is-layout-e21e8193 > * + *{margin-block-start:var(--wp--preset--spacing--50);margin-block-end:0;}.wp-container-content-b4c5012d{grid-column:span 1;grid-row:span 1;}.wp-container-core-group-is-layout-4b827052{gap:0;flex-direction:column;align-items:flex-start;}.wp-container-content-015a91b6{grid-column:span 1;grid-row:span 2;}.wp-container-core-group-is-layout-16a37519{gap:0;flex-direction:column;align-items:flex-end;}.wp-container-core-group-is-layout-a1cc4303{grid-template-columns:repeat(2, minmax(0, 1fr));gap:0.5em;}.wp-container-core-group-is-layout-ade1e76d > *{margin-block-start:0;margin-block-end:0;}.wp-container-core-group-is-layout-ade1e76d > * + *{margin-block-start:0;margin-block-end:0;}.wp-container-surecart-product-template-is-layout-fe22ff3d{grid-template-columns:repeat(auto-fill, minmax(min(20rem, 100%), 1fr));container-type:inline-size;gap:30px;}.wp-elements-8776c4b3a315144cfde1d2fc76ebd014 a:where(:not(.wp-element-button)){color:#000000;}.wp-container-surecart-product-pagination-is-layout-3b33ff5f{flex-wrap:nowrap;justify-content:space-between;}.wp-container-surecart-product-list-is-layout-c457cd91 > *{margin-block-start:0;margin-block-end:0;}.wp-container-surecart-product-list-is-layout-c457cd91 > * + *{margin-block-start:var(--wp--preset--spacing--30);margin-block-end:0;}.wp-container-surecart-product-template-is-layout-b7428002{grid-template-columns:repeat(auto-fill, minmax(min(20rem, 100%), 1fr));container-type:inline-size;gap:30px;}.wp-container-surecart-product-list-is-layout-ffcc2830 > *{margin-block-start:0;margin-block-end:0;}.wp-container-surecart-product-list-is-layout-ffcc2830 > * + *{margin-block-start:var(--wp--preset--spacing--30);margin-block-end:0;}.wp-container-core-group-is-layout-394c5b21 > *{margin-block-start:0;margin-block-end:0;}.wp-container-core-group-is-layout-394c5b21 > * + *{margin-block-start:0;margin-block-end:0;}.wp-elements-c08e49442605c767f2e2d2791784c900 a:where(:not(.wp-element-button)){color:#000000;}.wp-container-core-columns-is-layout-81213e5a{flex-wrap:nowrap;gap:0 2em;}.wp-container-core-column-is-layout-860428b2 > .alignfull{margin-right:calc(var(--wp--preset--spacing--40) * -1);margin-left:calc(var(--wp--preset--spacing--40) * -1);}.wp-container-core-columns-is-layout-729e4efa{flex-wrap:nowrap;gap:var(--wp--preset--spacing--30) var(--wp--preset--spacing--50);}.wp-container-core-columns-is-layout-f476b11c{flex-wrap:nowrap;gap:var(--wp--preset--spacing--30) var(--wp--preset--spacing--50);}.wp-container-core-group-is-layout-ca122f7f > *{margin-block-start:0;margin-block-end:0;}.wp-container-core-group-is-layout-ca122f7f > * + *{margin-block-start:var(--wp--preset--spacing--20);margin-block-end:0;}.wp-elements-1ed71c052aa8720800ca98718a397fce a:where(:not(.wp-element-button)){color:var(--wp--preset--color--base);}.wp-elements-e3b256cef02dac0762f4abf21de54357 a:where(:not(.wp-element-button)){color:var(--wp--preset--color--base);}.wp-container-core-group-is-layout-2c471116{gap:var(--wp--preset--spacing--20);justify-content:center;}.wp-container-core-group-is-layout-04428bc3 > *{margin-block-start:0;margin-block-end:0;}.wp-container-core-group-is-layout-04428bc3 > * + *{margin-block-start:0;margin-block-end:0;}.wp-elements-ef64ccd28389c753cdfac03b1015e7c7 a:where(:not(.wp-element-button)){color:var(--wp--preset--color--base);}.wp-container-content-d50df0bc{flex-basis:120px;}.wp-elements-88fc59decd3fd59e0e326cfc6ed651e5 a:where(:not(.wp-element-button)){color:#353535;}.wp-elements-f895323b203e15eb2b0914f64c0af975 a:where(:not(.wp-element-button)){color:#353535;}.wp-container-core-social-links-is-layout-8e381028{justify-content:flex-start;}.wp-container-core-group-is-layout-a67678a9{flex-direction:column;align-items:flex-start;justify-content:flex-start;}.wp-container-core-navigation-is-layout-4b827052{gap:0;flex-direction:column;align-items:flex-start;}.wp-container-core-navigation-is-layout-69c550ab{gap:0;flex-direction:column;align-items:stretch;}.wp-container-core-group-is-layout-cf56d299{flex-wrap:nowrap;gap:var(--wp--preset--spacing--70);justify-content:flex-end;align-items:center;}.wp-elements-ebb266593344d8ec9bd6b1371d85e3a5 a:where(:not(.wp-element-button)){color:#353535;}.wp-container-core-group-is-layout-ff778368 > .alignfull{margin-right:calc(var(--wp--preset--spacing--30) * -1);margin-left:calc(var(--wp--preset--spacing--30) * -1);}.wp-container-core-group-is-layout-a611d27a > .alignfull{margin-right:calc(0px * -1);}.wp-duotone-unset-1.wp-block-surecart-cart-line-item-image{filter:unset;}
/*# sourceURL=core-block-supports-inline-css */
</style>
<link rel='stylesheet' id='ea11y-widget-fonts-css' href='wp-content/plugins/pojo-accessibility/assets/build/fonts0235.css?ver=4.1.1' media='all' />
<link rel='stylesheet' id='ea11y-skip-link-css' href='wp-content/plugins/pojo-accessibility/assets/build/skip-link0235.css?ver=4.1.1' media='all' />
<style id='twentytwentyfive-style-inline-css'>
a{text-decoration-thickness:1px!important;text-underline-offset:.1em}:where(.wp-site-blocks :focus){outline-style:solid;outline-width:2px}.wp-block-navigation .wp-block-navigation-submenu .wp-block-navigation-item:not(:last-child){margin-bottom:3px}.wp-block-navigation .wp-block-navigation-item .wp-block-navigation-item__content{outline-offset:4px}.wp-block-navigation .wp-block-navigation-item ul.wp-block-navigation__submenu-container .wp-block-navigation-item__content{outline-offset:0}blockquote,caption,figcaption,h1,h2,h3,h4,h5,h6,p{text-wrap:pretty}.more-link{display:block}:where(pre){overflow-x:auto}
/*# sourceURL=https://gnl-solution.fr/wp-content/themes/twentytwentyfive/style.min.css */
</style>
<script id="cookie-law-info-js-extra">
var _ckyConfig = {"_ipData":[],"_assetsURL":"https://gnl-solution.fr/wp-content/plugins/cookie-law-info/lite/frontend/images/","_publicURL":"https://gnl-solution.fr","_expiry":"365","_categories":[{"name":"Necessary","slug":"necessary","isNecessary":true,"ccpaDoNotSell":true,"cookies":[],"active":true,"defaultConsent":{"gdpr":true,"ccpa":true}},{"name":"Functional","slug":"functional","isNecessary":false,"ccpaDoNotSell":true,"cookies":[],"active":true,"defaultConsent":{"gdpr":false,"ccpa":false}},{"name":"Analytics","slug":"analytics","isNecessary":false,"ccpaDoNotSell":true,"cookies":[],"active":true,"defaultConsent":{"gdpr":false,"ccpa":false}},{"name":"Performance","slug":"performance","isNecessary":false,"ccpaDoNotSell":true,"cookies":[],"active":true,"defaultConsent":{"gdpr":false,"ccpa":false}},{"name":"Advertisement","slug":"advertisement","isNecessary":false,"ccpaDoNotSell":true,"cookies":[],"active":true,"defaultConsent":{"gdpr":false,"ccpa":false}}],"_activeLaw":"gdpr","_rootDomain":"","_block":"1","_showBanner":"1","_bannerConfig":{"settings":{"type":"box","preferenceCenterType":"popup","position":"bottom-left","applicableLaw":"gdpr"},"behaviours":{"reloadBannerOnAccept":false,"loadAnalyticsByDefault":false,"animations":{"onLoad":"animate","onHide":"sticky"}},"config":{"revisitConsent":{"status":true,"tag":"revisit-consent","position":"bottom-left","meta":{"url":"#"},"styles":{"background-color":"#0056A7"},"elements":{"title":{"type":"text","tag":"revisit-consent-title","status":true,"styles":{"color":"#0056a7"}}}},"preferenceCenter":{"toggle":{"status":true,"tag":"detail-category-toggle","type":"toggle","states":{"active":{"styles":{"background-color":"#1863DC"}},"inactive":{"styles":{"background-color":"#D0D5D2"}}}}},"categoryPreview":{"status":false,"toggle":{"status":true,"tag":"detail-category-preview-toggle","type":"toggle","states":{"active":{"styles":{"background-color":"#1863DC"}},"inactive":{"styles":{"background-color":"#D0D5D2"}}}}},"videoPlaceholder":{"status":true,"styles":{"background-color":"#000000","border-color":"#000000","color":"#ffffff"}},"readMore":{"status":false,"tag":"readmore-button","type":"link","meta":{"noFollow":true,"newTab":true},"styles":{"color":"#1863DC","background-color":"transparent","border-color":"transparent"}},"showMore":[],"showLess":[],"alwaysActive":[],"manualLinks":[],"auditTable":{"status":true},"optOption":{"status":true,"toggle":{"status":true,"tag":"optout-option-toggle","type":"toggle","states":{"active":{"styles":{"background-color":"#1863dc"}},"inactive":{"styles":{"background-color":"#FFFFFF"}}}}}}},"_version":"3.4.2","_logConsent":"1","_tags":[{"tag":"accept-button","styles":{"color":"#FFFFFF","background-color":"#1863DC","border-color":"#1863DC"}},{"tag":"reject-button","styles":{"color":"#1863DC","background-color":"transparent","border-color":"#1863DC"}},{"tag":"settings-button","styles":{"color":"#1863DC","background-color":"transparent","border-color":"#1863DC"}},{"tag":"readmore-button","styles":{"color":"#1863DC","background-color":"transparent","border-color":"transparent"}},{"tag":"donotsell-button","styles":{"color":"#1863DC","background-color":"transparent","border-color":"transparent"}},{"tag":"show-desc-button","styles":[]},{"tag":"hide-desc-button","styles":[]},{"tag":"cky-always-active","styles":[]},{"tag":"cky-link","styles":[]},{"tag":"accept-button","styles":{"color":"#FFFFFF","background-color":"#1863DC","border-color":"#1863DC"}},{"tag":"revisit-consent","styles":{"background-color":"#0056A7"}}],"_shortCodes":[{"key":"cky_readmore","content":"\u003Ca href=\"#\" class=\"cky-policy\" aria-label=\"Politique relative aux cookies\" target=\"_blank\" rel=\"noopener\" data-cky-tag=\"readmore-button\"\u003EPolitique relative aux cookies\u003C/a\u003E","tag":"readmore-button","status":false,"attributes":{"rel":"nofollow","target":"_blank"}},{"key":"cky_show_desc","content":"\u003Cbutton class=\"cky-show-desc-btn\" data-cky-tag=\"show-desc-button\" aria-label=\"Afficher plus\"\u003EAfficher plus\u003C/button\u003E","tag":"show-desc-button","status":true,"attributes":[]},{"key":"cky_hide_desc","content":"\u003Cbutton class=\"cky-show-desc-btn\" data-cky-tag=\"hide-desc-button\" aria-label=\"Afficher moins\"\u003EAfficher moins\u003C/button\u003E","tag":"hide-desc-button","status":true,"attributes":[]},{"key":"cky_optout_show_desc","content":"[cky_optout_show_desc]","tag":"optout-show-desc-button","status":true,"attributes":[]},{"key":"cky_optout_hide_desc","content":"[cky_optout_hide_desc]","tag":"optout-hide-desc-button","status":true,"attributes":[]},{"key":"cky_category_toggle_label","content":"[cky_{{status}}_category_label] [cky_preference_{{category_slug}}_title]","tag":"","status":true,"attributes":[]},{"key":"cky_enable_category_label","content":"Enable","tag":"","status":true,"attributes":[]},{"key":"cky_disable_category_label","content":"Disable","tag":"","status":true,"attributes":[]},{"key":"cky_video_placeholder","content":"\u003Cdiv class=\"video-placeholder-normal\" data-cky-tag=\"video-placeholder\" id=\"[UNIQUEID]\"\u003E\u003Cp class=\"video-placeholder-text-normal\" data-cky-tag=\"placeholder-title\"\u003EVeuillez accepter les cookies pour acc\u00e9der \u00e0 ce contenu\u003C/p\u003E\u003C/div\u003E","tag":"","status":true,"attributes":[]},{"key":"cky_enable_optout_label","content":"Enable","tag":"","status":true,"attributes":[]},{"key":"cky_disable_optout_label","content":"Disable","tag":"","status":true,"attributes":[]},{"key":"cky_optout_toggle_label","content":"[cky_{{status}}_optout_label] [cky_optout_option_title]","tag":"","status":true,"attributes":[]},{"key":"cky_optout_option_title","content":"Do Not Sell or Share My Personal Information","tag":"","status":true,"attributes":[]},{"key":"cky_optout_close_label","content":"Close","tag":"","status":true,"attributes":[]},{"key":"cky_preference_close_label","content":"Close","tag":"","status":true,"attributes":[]}],"_rtl":"","_language":"en","_providersToBlock":[]};
var _ckyStyles = {"css":".cky-overlay{background: #000000; opacity: 0.4; position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 99999999;}.cky-hide{display: none;}.cky-btn-revisit-wrapper{display: flex; align-items: center; justify-content: center; width: 45px; height: 45px; border-radius: 50%; position: fixed; z-index: 999999; cursor: pointer;}.cky-revisit-bottom-left{bottom: 15px; left: 15px;}.cky-revisit-bottom-right{bottom: 15px; right: 15px;}.cky-btn-revisit-wrapper .cky-btn-revisit{display: flex; align-items: center; justify-content: center; background: none; border: none; cursor: pointer; position: relative; margin: 0; padding: 0;}.cky-btn-revisit-wrapper .cky-btn-revisit img{max-width: fit-content; margin: 0; height: 30px; width: 30px;}.cky-revisit-bottom-left:hover::before{content: attr(data-tooltip); position: absolute; background: #4e4b66; color: #ffffff; left: calc(100% + 7px); font-size: 12px; line-height: 16px; width: max-content; padding: 4px 8px; border-radius: 4px;}.cky-revisit-bottom-left:hover::after{position: absolute; content: \"\"; border: 5px solid transparent; left: calc(100% + 2px); border-left-width: 0; border-right-color: #4e4b66;}.cky-revisit-bottom-right:hover::before{content: attr(data-tooltip); position: absolute; background: #4e4b66; color: #ffffff; right: calc(100% + 7px); font-size: 12px; line-height: 16px; width: max-content; padding: 4px 8px; border-radius: 4px;}.cky-revisit-bottom-right:hover::after{position: absolute; content: \"\"; border: 5px solid transparent; right: calc(100% + 2px); border-right-width: 0; border-left-color: #4e4b66;}.cky-revisit-hide{display: none;}.cky-consent-container{position: fixed; width: 440px; box-sizing: border-box; z-index: 9999999; border-radius: 6px;}.cky-consent-container .cky-consent-bar{background: #ffffff; border: 1px solid; padding: 20px 26px; box-shadow: 0 -1px 10px 0 #acabab4d; border-radius: 6px;}.cky-box-bottom-left{bottom: 40px; left: 40px;}.cky-box-bottom-right{bottom: 40px; right: 40px;}.cky-box-top-left{top: 40px; left: 40px;}.cky-box-top-right{top: 40px; right: 40px;}.cky-custom-brand-logo-wrapper .cky-custom-brand-logo{width: 100px; height: auto; margin: 0 0 12px 0;}.cky-notice .cky-title{color: #212121; font-weight: 700; font-size: 18px; line-height: 24px; margin: 0 0 12px 0;}.cky-notice-des *,.cky-preference-content-wrapper *,.cky-accordion-header-des *,.cky-gpc-wrapper .cky-gpc-desc *{font-size: 14px;}.cky-notice-des{color: #212121; font-size: 14px; line-height: 24px; font-weight: 400;}.cky-notice-des img{height: 25px; width: 25px;}.cky-consent-bar .cky-notice-des p,.cky-gpc-wrapper .cky-gpc-desc p,.cky-preference-body-wrapper .cky-preference-content-wrapper p,.cky-accordion-header-wrapper .cky-accordion-header-des p,.cky-cookie-des-table li div:last-child p{color: inherit; margin-top: 0; overflow-wrap: break-word;}.cky-notice-des P:last-child,.cky-preference-content-wrapper p:last-child,.cky-cookie-des-table li div:last-child p:last-child,.cky-gpc-wrapper .cky-gpc-desc p:last-child{margin-bottom: 0;}.cky-notice-des a.cky-policy,.cky-notice-des button.cky-policy{font-size: 14px; color: #1863dc; white-space: nowrap; cursor: pointer; background: transparent; border: 1px solid; text-decoration: underline;}.cky-notice-des button.cky-policy{padding: 0;}.cky-notice-des a.cky-policy:focus-visible,.cky-notice-des button.cky-policy:focus-visible,.cky-preference-content-wrapper .cky-show-desc-btn:focus-visible,.cky-accordion-header .cky-accordion-btn:focus-visible,.cky-preference-header .cky-btn-close:focus-visible,.cky-switch input[type=\"checkbox\"]:focus-visible,.cky-footer-wrapper a:focus-visible,.cky-btn:focus-visible{outline: 2px solid #1863dc; outline-offset: 2px;}.cky-btn:focus:not(:focus-visible),.cky-accordion-header .cky-accordion-btn:focus:not(:focus-visible),.cky-preference-content-wrapper .cky-show-desc-btn:focus:not(:focus-visible),.cky-btn-revisit-wrapper .cky-btn-revisit:focus:not(:focus-visible),.cky-preference-header .cky-btn-close:focus:not(:focus-visible),.cky-consent-bar .cky-banner-btn-close:focus:not(:focus-visible){outline: 0;}button.cky-show-desc-btn:not(:hover):not(:active){color: #1863dc; background: transparent;}button.cky-accordion-btn:not(:hover):not(:active),button.cky-banner-btn-close:not(:hover):not(:active),button.cky-btn-revisit:not(:hover):not(:active),button.cky-btn-close:not(:hover):not(:active){background: transparent;}.cky-consent-bar button:hover,.cky-modal.cky-modal-open button:hover,.cky-consent-bar button:focus,.cky-modal.cky-modal-open button:focus{text-decoration: none;}.cky-notice-btn-wrapper{display: flex; justify-content: flex-start; align-items: center; flex-wrap: wrap; margin-top: 16px;}.cky-notice-btn-wrapper .cky-btn{text-shadow: none; box-shadow: none;}.cky-btn{flex: auto; max-width: 100%; font-size: 14px; font-family: inherit; line-height: 24px; padding: 8px; font-weight: 500; margin: 0 8px 0 0; border-radius: 2px; cursor: pointer; text-align: center; text-transform: none; min-height: 0;}.cky-btn:hover{opacity: 0.8;}.cky-btn-customize{color: #1863dc; background: transparent; border: 2px solid #1863dc;}.cky-btn-reject{color: #1863dc; background: transparent; border: 2px solid #1863dc;}.cky-btn-accept{background: #1863dc; color: #ffffff; border: 2px solid #1863dc;}.cky-btn:last-child{margin-right: 0;}@media (max-width: 576px){.cky-box-bottom-left{bottom: 0; left: 0;}.cky-box-bottom-right{bottom: 0; right: 0;}.cky-box-top-left{top: 0; left: 0;}.cky-box-top-right{top: 0; right: 0;}}@media (max-height: 480px){.cky-consent-container{max-height: 100vh;overflow-y: scroll}.cky-notice-des{max-height: unset !important;overflow-y: unset !important}.cky-preference-center{height: 100vh;overflow: auto !important}.cky-preference-center .cky-preference-body-wrapper{overflow: unset}}@media (max-width: 440px){.cky-box-bottom-left, .cky-box-bottom-right, .cky-box-top-left, .cky-box-top-right{width: 100%; max-width: 100%;}.cky-consent-container .cky-consent-bar{padding: 20px 0;}.cky-custom-brand-logo-wrapper, .cky-notice .cky-title, .cky-notice-des, .cky-notice-btn-wrapper{padding: 0 24px;}.cky-notice-des{max-height: 40vh; overflow-y: scroll;}.cky-notice-btn-wrapper{flex-direction: column; margin-top: 0;}.cky-btn{width: 100%; margin: 10px 0 0 0;}.cky-notice-btn-wrapper .cky-btn-customize{order: 2;}.cky-notice-btn-wrapper .cky-btn-reject{order: 3;}.cky-notice-btn-wrapper .cky-btn-accept{order: 1; margin-top: 16px;}}@media (max-width: 352px){.cky-notice .cky-title{font-size: 16px;}.cky-notice-des *{font-size: 12px;}.cky-notice-des, .cky-btn{font-size: 12px;}}.cky-modal.cky-modal-open{display: flex; visibility: visible; -webkit-transform: translate(-50%, -50%); -moz-transform: translate(-50%, -50%); -ms-transform: translate(-50%, -50%); -o-transform: translate(-50%, -50%); transform: translate(-50%, -50%); top: 50%; left: 50%; transition: all 1s ease;}.cky-modal{box-shadow: 0 32px 68px rgba(0, 0, 0, 0.3); margin: 0 auto; position: fixed; max-width: 100%; background: #ffffff; top: 50%; box-sizing: border-box; border-radius: 6px; z-index: 999999999; color: #212121; -webkit-transform: translate(-50%, 100%); -moz-transform: translate(-50%, 100%); -ms-transform: translate(-50%, 100%); -o-transform: translate(-50%, 100%); transform: translate(-50%, 100%); visibility: hidden; transition: all 0s ease;}.cky-preference-center{max-height: 79vh; overflow: hidden; width: 845px; overflow: hidden; flex: 1 1 0; display: flex; flex-direction: column; border-radius: 6px;}.cky-preference-header{display: flex; align-items: center; justify-content: space-between; padding: 22px 24px; border-bottom: 1px solid;}.cky-preference-header .cky-preference-title{font-size: 18px; font-weight: 700; line-height: 24px;}.cky-preference-header .cky-btn-close{margin: 0; cursor: pointer; vertical-align: middle; padding: 0; background: none; border: none; width: 24px; height: 24px; min-height: 0; line-height: 0; text-shadow: none; box-shadow: none;}.cky-preference-header .cky-btn-close img{margin: 0; height: 10px; width: 10px;}.cky-preference-body-wrapper{padding: 0 24px; flex: 1; overflow: auto; box-sizing: border-box;}.cky-preference-content-wrapper,.cky-gpc-wrapper .cky-gpc-desc{font-size: 14px; line-height: 24px; font-weight: 400; padding: 12px 0;}.cky-preference-content-wrapper{border-bottom: 1px solid;}.cky-preference-content-wrapper img{height: 25px; width: 25px;}.cky-preference-content-wrapper .cky-show-desc-btn{font-size: 14px; font-family: inherit; color: #1863dc; text-decoration: none; line-height: 24px; padding: 0; margin: 0; white-space: nowrap; cursor: pointer; background: transparent; border-color: transparent; text-transform: none; min-height: 0; text-shadow: none; box-shadow: none;}.cky-accordion-wrapper{margin-bottom: 10px;}.cky-accordion{border-bottom: 1px solid;}.cky-accordion:last-child{border-bottom: none;}.cky-accordion .cky-accordion-item{display: flex; margin-top: 10px;}.cky-accordion .cky-accordion-body{display: none;}.cky-accordion.cky-accordion-active .cky-accordion-body{display: block; padding: 0 22px; margin-bottom: 16px;}.cky-accordion-header-wrapper{cursor: pointer; width: 100%;}.cky-accordion-item .cky-accordion-header{display: flex; justify-content: space-between; align-items: center;}.cky-accordion-header .cky-accordion-btn{font-size: 16px; font-family: inherit; color: #212121; line-height: 24px; background: none; border: none; font-weight: 700; padding: 0; margin: 0; cursor: pointer; text-transform: none; min-height: 0; text-shadow: none; box-shadow: none;}.cky-accordion-header .cky-always-active{color: #008000; font-weight: 600; line-height: 24px; font-size: 14px;}.cky-accordion-header-des{font-size: 14px; line-height: 24px; margin: 10px 0 16px 0;}.cky-accordion-chevron{margin-right: 22px; position: relative; cursor: pointer;}.cky-accordion-chevron-hide{display: none;}.cky-accordion .cky-accordion-chevron i::before{content: \"\"; position: absolute; border-right: 1.4px solid; border-bottom: 1.4px solid; border-color: inherit; height: 6px; width: 6px; -webkit-transform: rotate(-45deg); -moz-transform: rotate(-45deg); -ms-transform: rotate(-45deg); -o-transform: rotate(-45deg); transform: rotate(-45deg); transition: all 0.2s ease-in-out; top: 8px;}.cky-accordion.cky-accordion-active .cky-accordion-chevron i::before{-webkit-transform: rotate(45deg); -moz-transform: rotate(45deg); -ms-transform: rotate(45deg); -o-transform: rotate(45deg); transform: rotate(45deg);}.cky-audit-table{background: #f4f4f4; border-radius: 6px;}.cky-audit-table .cky-empty-cookies-text{color: inherit; font-size: 12px; line-height: 24px; margin: 0; padding: 10px;}.cky-audit-table .cky-cookie-des-table{font-size: 12px; line-height: 24px; font-weight: normal; padding: 15px 10px; border-bottom: 1px solid; border-bottom-color: inherit; margin: 0;}.cky-audit-table .cky-cookie-des-table:last-child{border-bottom: none;}.cky-audit-table .cky-cookie-des-table li{list-style-type: none; display: flex; padding: 3px 0;}.cky-audit-table .cky-cookie-des-table li:first-child{padding-top: 0;}.cky-cookie-des-table li div:first-child{width: 100px; font-weight: 600; word-break: break-word; word-wrap: break-word;}.cky-cookie-des-table li div:last-child{flex: 1; word-break: break-word; word-wrap: break-word; margin-left: 8px;}.cky-footer-shadow{display: block; width: 100%; height: 40px; background: linear-gradient(180deg, rgba(255, 255, 255, 0) 0%, #ffffff 100%); position: absolute; bottom: calc(100% - 1px);}.cky-footer-wrapper{position: relative;}.cky-prefrence-btn-wrapper{display: flex; flex-wrap: wrap; align-items: center; justify-content: center; padding: 22px 24px; border-top: 1px solid;}.cky-prefrence-btn-wrapper .cky-btn{flex: auto; max-width: 100%; text-shadow: none; box-shadow: none;}.cky-btn-preferences{color: #1863dc; background: transparent; border: 2px solid #1863dc;}.cky-preference-header,.cky-preference-body-wrapper,.cky-preference-content-wrapper,.cky-accordion-wrapper,.cky-accordion,.cky-accordion-wrapper,.cky-footer-wrapper,.cky-prefrence-btn-wrapper{border-color: inherit;}@media (max-width: 845px){.cky-modal{max-width: calc(100% - 16px);}}@media (max-width: 576px){.cky-modal{max-width: 100%;}.cky-preference-center{max-height: 100vh;}.cky-prefrence-btn-wrapper{flex-direction: column;}.cky-accordion.cky-accordion-active .cky-accordion-body{padding-right: 0;}.cky-prefrence-btn-wrapper .cky-btn{width: 100%; margin: 10px 0 0 0;}.cky-prefrence-btn-wrapper .cky-btn-reject{order: 3;}.cky-prefrence-btn-wrapper .cky-btn-accept{order: 1; margin-top: 0;}.cky-prefrence-btn-wrapper .cky-btn-preferences{order: 2;}}@media (max-width: 425px){.cky-accordion-chevron{margin-right: 15px;}.cky-notice-btn-wrapper{margin-top: 0;}.cky-accordion.cky-accordion-active .cky-accordion-body{padding: 0 15px;}}@media (max-width: 352px){.cky-preference-header .cky-preference-title{font-size: 16px;}.cky-preference-header{padding: 16px 24px;}.cky-preference-content-wrapper *, .cky-accordion-header-des *{font-size: 12px;}.cky-preference-content-wrapper, .cky-preference-content-wrapper .cky-show-more, .cky-accordion-header .cky-always-active, .cky-accordion-header-des, .cky-preference-content-wrapper .cky-show-desc-btn, .cky-notice-des a.cky-policy{font-size: 12px;}.cky-accordion-header .cky-accordion-btn{font-size: 14px;}}.cky-switch{display: flex;}.cky-switch input[type=\"checkbox\"]{position: relative; width: 44px; height: 24px; margin: 0; background: #d0d5d2; -webkit-appearance: none; border-radius: 50px; cursor: pointer; outline: 0; border: none; top: 0;}.cky-switch input[type=\"checkbox\"]:checked{background: #1863dc;}.cky-switch input[type=\"checkbox\"]:before{position: absolute; content: \"\"; height: 20px; width: 20px; left: 2px; bottom: 2px; border-radius: 50%; background-color: white; -webkit-transition: 0.4s; transition: 0.4s; margin: 0;}.cky-switch input[type=\"checkbox\"]:after{display: none;}.cky-switch input[type=\"checkbox\"]:checked:before{-webkit-transform: translateX(20px); -ms-transform: translateX(20px); transform: translateX(20px);}@media (max-width: 425px){.cky-switch input[type=\"checkbox\"]{width: 38px; height: 21px;}.cky-switch input[type=\"checkbox\"]:before{height: 17px; width: 17px;}.cky-switch input[type=\"checkbox\"]:checked:before{-webkit-transform: translateX(17px); -ms-transform: translateX(17px); transform: translateX(17px);}}.cky-consent-bar .cky-banner-btn-close{position: absolute; right: 9px; top: 5px; background: none; border: none; cursor: pointer; padding: 0; margin: 0; min-height: 0; line-height: 0; height: 24px; width: 24px; text-shadow: none; box-shadow: none;}.cky-consent-bar .cky-banner-btn-close img{height: 9px; width: 9px; margin: 0;}.cky-notice-group{font-size: 14px; line-height: 24px; font-weight: 400; color: #212121;}.cky-notice-btn-wrapper .cky-btn-do-not-sell{font-size: 14px; line-height: 24px; padding: 6px 0; margin: 0; font-weight: 500; background: none; border-radius: 2px; border: none; cursor: pointer; text-align: left; color: #1863dc; background: transparent; border-color: transparent; box-shadow: none; text-shadow: none;}.cky-consent-bar .cky-banner-btn-close:focus-visible,.cky-notice-btn-wrapper .cky-btn-do-not-sell:focus-visible,.cky-opt-out-btn-wrapper .cky-btn:focus-visible,.cky-opt-out-checkbox-wrapper input[type=\"checkbox\"].cky-opt-out-checkbox:focus-visible{outline: 2px solid #1863dc; outline-offset: 2px;}@media (max-width: 440px){.cky-consent-container{width: 100%;}}@media (max-width: 352px){.cky-notice-des a.cky-policy, .cky-notice-btn-wrapper .cky-btn-do-not-sell{font-size: 12px;}}.cky-opt-out-wrapper{padding: 12px 0;}.cky-opt-out-wrapper .cky-opt-out-checkbox-wrapper{display: flex; align-items: center;}.cky-opt-out-checkbox-wrapper .cky-opt-out-checkbox-label{font-size: 16px; font-weight: 700; line-height: 24px; margin: 0 0 0 12px; cursor: pointer;}.cky-opt-out-checkbox-wrapper input[type=\"checkbox\"].cky-opt-out-checkbox{background-color: #ffffff; border: 1px solid black; width: 20px; height: 18.5px; margin: 0; -webkit-appearance: none; position: relative; display: flex; align-items: center; justify-content: center; border-radius: 2px; cursor: pointer;}.cky-opt-out-checkbox-wrapper input[type=\"checkbox\"].cky-opt-out-checkbox:checked{background-color: #1863dc; border: none;}.cky-opt-out-checkbox-wrapper input[type=\"checkbox\"].cky-opt-out-checkbox:checked::after{left: 6px; bottom: 4px; width: 7px; height: 13px; border: solid #ffffff; border-width: 0 3px 3px 0; border-radius: 2px; -webkit-transform: rotate(45deg); -ms-transform: rotate(45deg); transform: rotate(45deg); content: \"\"; position: absolute; box-sizing: border-box;}.cky-opt-out-checkbox-wrapper.cky-disabled .cky-opt-out-checkbox-label,.cky-opt-out-checkbox-wrapper.cky-disabled input[type=\"checkbox\"].cky-opt-out-checkbox{cursor: no-drop;}.cky-gpc-wrapper{margin: 0 0 0 32px;}.cky-footer-wrapper .cky-optout-action-area{padding:0 24px 22px 24px;box-sizing:border-box;border-color:inherit}.cky-footer-wrapper .cky-opt-out-btn-wrapper{padding-top:22px;border-top:1px solid;border-color:inherit}.cky-footer-wrapper .cky-opt-out-btn-wrapper{display: flex; flex-wrap: wrap; align-items: center; justify-content: center;}.cky-opt-out-btn-wrapper .cky-btn{flex: auto; max-width: 100%; text-shadow: none; box-shadow: none;}.cky-opt-out-btn-wrapper .cky-btn-cancel{border: 1px solid #dedfe0; background: transparent; color: #858585;}.cky-opt-out-btn-wrapper .cky-btn-confirm{background: #1863dc; color: #ffffff; border: 1px solid #1863dc;}\n.cky-optout-success{width:798px;max-width:100%;border-radius:8px;padding:8px 12px;margin:0 auto;box-sizing:border-box;outline:none}.cky-optout-success .cky-optout-success-inner{display:flex;flex-direction:column;gap:4px}.cky-optout-success .cky-optout-success-row{display:flex;align-items:flex-start}.cky-optout-success .cky-optout-success-icon{width:20px;flex-shrink:0}.cky-optout-success .cky-optout-success-text{margin:0;margin-inline-start:8px;margin-top:1px;font-weight:400;font-size:13px;line-height:20px}.cky-optout-success .cky-optout-success-text p{margin:0}.cky-optout-success .cky-optout-success-subtext{margin:0;font-weight:400;font-size:12px;line-height:20px}@media (max-width: 352px){.cky-opt-out-checkbox-wrapper .cky-opt-out-checkbox-label{font-size: 14px;}.cky-gpc-wrapper .cky-gpc-desc, .cky-gpc-wrapper .cky-gpc-desc *{font-size: 12px;}.cky-opt-out-checkbox-wrapper input[type=\"checkbox\"].cky-opt-out-checkbox{width: 16px; height: 16px;}.cky-opt-out-checkbox-wrapper input[type=\"checkbox\"].cky-opt-out-checkbox:checked::after{left: 5px; bottom: 4px; width: 3px; height: 9px;}.cky-gpc-wrapper{margin: 0 0 0 28px;}}.video-placeholder-youtube{background-size: 100% 100%; background-position: center; background-repeat: no-repeat; background-color: #b2b0b059; position: relative; display: flex; align-items: center; justify-content: center; max-width: 100%;}.video-placeholder-text-youtube{text-align: center; align-items: center; padding: 10px 16px; background-color: #000000cc; color: #ffffff; border: 1px solid; border-radius: 2px; cursor: pointer;}.video-placeholder-normal{background-image: url(\"/wp-content/plugins/cookie-law-info/lite/frontend/images/placeholder.svg\"); background-size: 80px; background-position: center; background-repeat: no-repeat; background-color: #b2b0b059; position: relative; display: flex; align-items: flex-end; justify-content: center; max-width: 100%;}.video-placeholder-text-normal{align-items: center; padding: 10px 16px; text-align: center; border: 1px solid; border-radius: 2px; cursor: pointer;}.cky-rtl{direction: rtl; text-align: right;}.cky-rtl .cky-banner-btn-close{left: 9px; right: auto;}.cky-rtl .cky-notice-btn-wrapper .cky-btn:last-child{margin-right: 8px;}.cky-rtl .cky-notice-btn-wrapper .cky-btn:first-child{margin-right: 0;}.cky-rtl .cky-notice-btn-wrapper{margin-left: 0; margin-right: 15px;}.cky-rtl .cky-prefrence-btn-wrapper .cky-btn{margin-right: 8px;}.cky-rtl .cky-prefrence-btn-wrapper .cky-btn:first-child{margin-right: 0;}.cky-rtl .cky-accordion .cky-accordion-chevron i::before{border: none; border-left: 1.4px solid; border-top: 1.4px solid; left: 12px;}.cky-rtl .cky-accordion.cky-accordion-active .cky-accordion-chevron i::before{-webkit-transform: rotate(-135deg); -moz-transform: rotate(-135deg); -ms-transform: rotate(-135deg); -o-transform: rotate(-135deg); transform: rotate(-135deg);}@media (max-width: 768px){.cky-rtl .cky-notice-btn-wrapper{margin-right: 0;}}@media (max-width: 576px){.cky-rtl .cky-notice-btn-wrapper .cky-btn:last-child{margin-right: 0;}.cky-rtl .cky-prefrence-btn-wrapper .cky-btn{margin-right: 0;}.cky-rtl .cky-accordion.cky-accordion-active .cky-accordion-body{padding: 0 22px 0 0;}}@media (max-width: 425px){.cky-rtl .cky-accordion.cky-accordion-active .cky-accordion-body{padding: 0 15px 0 0;}}.cky-rtl .cky-opt-out-btn-wrapper .cky-btn{margin-right: 12px;}.cky-rtl .cky-opt-out-btn-wrapper .cky-btn:first-child{margin-right: 0;}.cky-rtl .cky-opt-out-checkbox-wrapper .cky-opt-out-checkbox-label{margin: 0 12px 0 0;}"};
//# sourceURL=cookie-law-info-js-extra
</script>
<script src="../assets/js/script.minccfb.js?ver=3.4.2" id="cookie-law-info-js"></script>
<script id="surecart-affiliate-tracking-js-before">
window.SureCartAffiliatesConfig = {
				"publicToken": "pt_T6ACqoG9WXjHagA1jsVNXqJZ",
				"baseURL":"https://api.surecart.com/v1"
			};
//# sourceURL=surecart-affiliate-tracking-js-before
</script>
<script src="https://js.surecart.com/v1/affiliates?ver=1.1" id="surecart-affiliate-tracking-js" defer data-wp-strategy="defer"></script>
<link rel="https://api.w.org/" href="wp-json/index.html" /><link rel="alternate" title="JSON" type="application/json" href="wp-json/wp/v2/pages/6.html" /><link rel="EditURI" type="application/rsd+xml" title="RSD" href="xmlrpc0db0.php?rsd" />
<meta name="generator" content="WordPress 6.9.4" />
<link rel="canonical" href="index.html" />
<link rel='shortlink' href='index.html' />
<style id="cky-style-inline">[data-cky-tag]{visibility:hidden;}</style><script type="importmap" id="wp-importmap">
{"imports":{"@wordpress/interactivity":"https://gnl-solution.fr/wp-includes/js/dist/script-modules/interactivity/index.min.js?ver=66c613f68580994bb00a","@surecart/checkout":"https://gnl-solution.fr/wp-content/plugins/surecart/packages/blocks-next/build/scripts/checkout/index.js?ver=3bbe28b8db1e11147c67","@surecart/checkout-events":"https://gnl-solution.fr/wp-content/plugins/surecart/packages/blocks-next/build/scripts/checkout-events/index.js?ver=ed9647bd6c7865efe2ad","@surecart/checkout-service":"https://gnl-solution.fr/wp-content/plugins/surecart/packages/blocks-next/build/scripts/checkout-actions/index.js?ver=e445a0ee0396d75d52c0","@surecart/google-events":"https://gnl-solution.fr/wp-content/plugins/surecart/packages/blocks-next/build/scripts/google/index.js?ver=d92e383a18bcf54ea538","@surecart/facebook-events":"https://gnl-solution.fr/wp-content/plugins/surecart/packages/blocks-next/build/scripts/facebook/index.js?ver=cf5c6499cb7b867894c1","@wordpress/a11y":"https://gnl-solution.fr/wp-includes/js/dist/script-modules/a11y/index.min.js?ver=b7d06936b8bc23cff2ad","@wordpress/interactivity-router":"https://gnl-solution.fr/wp-includes/js/dist/script-modules/interactivity-router/index.min.js?ver=ae0663e15cc8d4b56150","@surecart/api-fetch":"https://gnl-solution.fr/wp-content/plugins/surecart/packages/blocks-next/build/scripts/fetch/index.js?ver=1bfba8ea0694a193022a"}}
</script>
<script type="module" src="wp-content/plugins/surecart/packages/blocks-next/build/scripts/line-item-note/index6cc4.js?ver=af6cf14267b5a9ad219f" id="@surecart/line-item-note-js-module"></script>
<script type="module" src="wp-content/plugins/surecart/packages/blocks-next/build/scripts/checkout/indexb3e7.js?ver=3bbe28b8db1e11147c67" id="@surecart/checkout-js-module"></script>
<script type="module" src="wp-content/plugins/surecart/packages/blocks-next/build/scripts/cart/indexad82.js?ver=c2f35b71b4309df849fe" id="@surecart/cart-js-module"></script>
<script type="module" src="wp-content/plugins/surecart/packages/blocks-next/build/scripts/product-list/indexcbce.js?ver=5a425c660accbf7c3812" id="@surecart/product-list-js-module"></script>
<script type="module" src="wp-content/plugins/surecart/packages/blocks-next/build/scripts/product-page/index799a.js?ver=00c073a9832eab40c928" id="@surecart/product-page-js-module"></script>
<link rel="modulepreload" href="wp-includes/js/dist/script-modules/interactivity/index.min9db3.js?ver=66c613f68580994bb00a" id="@wordpress/interactivity-js-modulepreload" data-wp-fetchpriority="low">
<style class='wp-fonts-local'>
@font-face{font-family:Manrope;font-style:normal;font-weight:200 800;font-display:fallback;src:url('wp-content/themes/twentytwentyfive/assets/fonts/manrope/Manrope-VariableFont_wght.woff2') format('woff2');}
@font-face{font-family:"Fira Code";font-style:normal;font-weight:300 700;font-display:fallback;src:url('wp-content/themes/twentytwentyfive/assets/fonts/fira-code/FiraCode-VariableFont_wght.woff2') format('woff2');}
</style>
<link rel="icon" href="./assets/img/cropped-Sans-titre37-32x32.png" sizes="32x32" />
<link rel="icon" href="./assets/img/cropped-Sans-titre37-192x192.png" sizes="192x192" />
<link rel="apple-touch-icon" href="./assets/img/cropped-Sans-titre37-180x180.png" />
<meta name="msapplication-TileImage" content="https://gnl-solution.fr/./assets/img/cropped-Sans-titre37-270x270.png" />
<style id="gnl-carousel-css">
.gnl-cat{margin-top:var(--wp--preset--spacing--40,2rem)}
.gnl-cat-title{margin:0 0 var(--wp--preset--spacing--20,1rem);font-weight:600;line-height:1.2}
.gnl-carousel{position:relative}
.gnl-viewport{overflow:hidden;width:100%}
.gnl-track{box-sizing:border-box;list-style:none;margin:0;padding:2px 0;display:flex!important;flex-wrap:nowrap!important;grid-template-columns:none!important;gap:30px;transition:transform .35s ease;will-change:transform}
.gnl-track>li{flex:0 0 calc((100% - 60px)/3);max-width:calc((100% - 60px)/3);min-width:0;box-sizing:border-box}
@media(max-width:900px){.gnl-track>li{flex-basis:calc((100% - 30px)/2);max-width:calc((100% - 30px)/2)}}
@media(max-width:600px){.gnl-track>li{flex-basis:100%;max-width:100%}}
.gnl-nav{display:flex;justify-content:space-between;align-items:center;gap:1rem;margin-top:var(--wp--preset--spacing--20,1rem)}
.gnl-nav[hidden]{display:none!important}
.gnl-prev,.gnl-next{position:relative;z-index:2;cursor:pointer;pointer-events:auto!important;display:inline-flex;align-items:center;justify-content:center;color:inherit;line-height:0;-webkit-user-select:none;user-select:none;transition:opacity .15s ease}
.gnl-prev svg,.gnl-next svg{pointer-events:none}
.gnl-prev[aria-disabled="true"],.gnl-next[aria-disabled="true"]{opacity:.3;cursor:default;pointer-events:none!important}
.wp-block-surecart-product-list>.wp-block-surecart-product-pagination{display:none!important}
</style>
<script id="gnl-carousel-js">
document.addEventListener('DOMContentLoaded',function(){
  document.querySelectorAll('.gnl-carousel').forEach(function(car){
    var track=car.querySelector('.gnl-track'),nav=car.querySelector('.gnl-nav'),vp=car.querySelector('.gnl-viewport');
    if(!track||!nav||!vp)return;
    var prev=nav.querySelector('.gnl-prev'),next=nav.querySelector('.gnl-next');
    var index=0,GAP=30;
    function slides(){return track.children;}
    function slideW(){var s=slides()[0];return s?s.getBoundingClientRect().width:vp.clientWidth;}
    function perView(){var w=slideW();return Math.max(1,Math.round((vp.clientWidth+GAP)/(w+GAP)));}
    function maxIndex(){return Math.max(0,slides().length-perView());}
    function apply(){
      var mi=maxIndex();
      if(index>mi)index=mi; if(index<0)index=0;
      track.style.transform='translateX(-'+(index*(slideW()+GAP))+'px)';
      if(mi<=0){nav.setAttribute('hidden','');}else{nav.removeAttribute('hidden');}
      prev.setAttribute('aria-disabled',index<=0?'true':'false');
      next.setAttribute('aria-disabled',index>=mi?'true':'false');
    }
    prev.addEventListener('click',function(e){e.preventDefault();index--;apply();});
    next.addEventListener('click',function(e){e.preventDefault();index++;apply();});
    [prev,next].forEach(function(b){b.addEventListener('keydown',function(e){if(e.key==='Enter'||e.key===' '){e.preventDefault();b.click();}});});
    window.addEventListener('resize',function(){index=0;apply();});
    window.addEventListener('load',apply);
    apply();
  });
});
</script>
<style id="gnl-config-css">
:root{--gnl-green:#6c9400;--gnl-teal:#009494;--gnl-ink:#353535;--gnl-line:color-mix(in srgb, currentColor 18%, transparent);}
.gnl-config-wrap{max-width:1100px;margin:0 auto;padding:var(--wp--preset--spacing--40,2rem) var(--wp--preset--spacing--30,1.2rem) var(--wp--preset--spacing--60,3rem);}
.gnl-breadcrumb{font-size:.85rem;opacity:.7;margin:0 0 var(--wp--preset--spacing--20,1rem);}
.gnl-breadcrumb a{color:inherit;text-decoration:none;}
.gnl-breadcrumb a:hover{text-decoration:underline;}
.gnl-config-title{font-weight:600;line-height:1.15;margin:0 0 .3rem;}
.gnl-config-sub{opacity:.75;margin:0 0 var(--wp--preset--spacing--40,2rem);}
.gnl-layout{display:grid;grid-template-columns:1fr 340px;gap:var(--wp--preset--spacing--40,2rem);align-items:start;}
@media(max-width:820px){.gnl-layout{grid-template-columns:1fr;}}
.gnl-card{background:#fff;border:1px solid var(--gnl-line);border-radius:12px;padding:1.15rem 1.25rem;margin-bottom:1.1rem;}
.gnl-recap{display:flex;gap:1rem;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;}
.gnl-recap h2{margin:0 0 .25rem;font-size:1.25rem;font-weight:600;}
.gnl-recap .gnl-recap-desc{margin:.35rem 0 0;font-size:.9rem;opacity:.8;max-width:60ch;}
.gnl-recap .gnl-recap-price{font-weight:700;font-size:1.15rem;white-space:nowrap;}
.gnl-recap .gnl-recap-price small{display:block;font-weight:400;font-size:.7rem;opacity:.7;text-align:right;}
.gnl-section-h{font-weight:600;margin:1.6rem 0 .8rem;font-size:1.05rem;}
.gnl-opt{background:#fff;border:1px solid var(--gnl-line);border-radius:12px;padding:1.05rem 1.15rem;margin-bottom:.9rem;transition:border-color .2s,box-shadow .2s;}
.gnl-opt.is-on{border-color:var(--gnl-green);box-shadow:0 0 0 1px var(--gnl-green) inset;}
.gnl-opt-head{display:flex;justify-content:space-between;align-items:baseline;gap:1rem;}
.gnl-opt-head h3{margin:0;font-size:1rem;font-weight:600;}
.gnl-opt-price{font-weight:600;color:var(--gnl-green);white-space:nowrap;font-size:.95rem;}
.gnl-opt-price.gnl-quote{color:var(--gnl-teal);}
.gnl-opt-desc{margin:.4rem 0 .8rem;font-size:.88rem;opacity:.82;}
.gnl-choices{display:flex;gap:.6rem;flex-wrap:wrap;}
.gnl-choice{flex:1 1 0;min-width:130px;cursor:pointer;border:1px solid var(--gnl-line);background:#fafafa;border-radius:9px;padding:.6rem .8rem;font:inherit;font-size:.85rem;color:inherit;text-align:center;transition:all .15s;}
.gnl-choice:hover{border-color:currentColor;}
.gnl-choice.is-active[data-val="oui"]{background:var(--gnl-green);border-color:var(--gnl-green);color:#fff;}
.gnl-choice.is-active[data-val="non"]{background:var(--gnl-ink);border-color:var(--gnl-ink);color:#fff;}
.gnl-domain{margin-top:1rem;padding-top:1rem;border-top:1px dashed var(--gnl-line);}
.gnl-domain[hidden]{display:none;}
.gnl-domain-search{display:flex;gap:.5rem;flex-wrap:wrap;}
.gnl-domain-search input{flex:1 1 220px;border:1px solid var(--gnl-line);border-radius:9px;padding:.6rem .8rem;font:inherit;font-size:.95rem;}
.gnl-domain-search button{border:none;background:var(--gnl-teal);color:#fff;border-radius:9px;padding:.6rem 1.1rem;font:inherit;font-weight:600;cursor:pointer;}
.gnl-domain-search button:disabled{opacity:.5;cursor:default;}
.gnl-domain-hint{font-size:.78rem;opacity:.65;margin:.5rem 0 0;}
.gnl-domain-results{margin:.9rem 0 0;display:flex;flex-direction:column;gap:.45rem;}
.gnl-dom{display:flex;justify-content:space-between;align-items:center;gap:.75rem;border:1px solid var(--gnl-line);border-radius:9px;padding:.55rem .8rem;font-size:.9rem;}
.gnl-dom.is-ok{border-color:color-mix(in srgb,var(--gnl-green) 55%,transparent);}
.gnl-dom.is-ko{opacity:.55;}
.gnl-dom-name{font-weight:600;}
.gnl-dom-right{display:flex;align-items:center;gap:.7rem;white-space:nowrap;}
.gnl-dom .gnl-add{border:none;background:var(--gnl-green);color:#fff;border-radius:7px;padding:.35rem .8rem;font:inherit;font-size:.82rem;font-weight:600;cursor:pointer;}
.gnl-dom .gnl-add.is-added{background:var(--gnl-ink);}
.gnl-badge{font-size:.72rem;padding:.15rem .5rem;border-radius:20px;font-weight:600;}
.gnl-badge.ok{background:color-mix(in srgb,var(--gnl-green) 18%,transparent);color:var(--gnl-green);}
.gnl-badge.ko{background:color-mix(in srgb,#c0392b 15%,transparent);color:#c0392b;}
.gnl-note{font-size:.82rem;background:color-mix(in srgb,var(--gnl-teal) 8%,transparent);border:1px solid color-mix(in srgb,var(--gnl-teal) 30%,transparent);border-radius:9px;padding:.6rem .8rem;margin:.6rem 0 0;}
.gnl-spin{display:inline-block;width:14px;height:14px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:gnlspin .7s linear infinite;vertical-align:-2px;}
@keyframes gnlspin{to{transform:rotate(360deg)}}
/* ---- Résumé ---- */
.gnl-summary{position:sticky;top:1rem;background:#fff;border:1px solid var(--gnl-line);border-radius:12px;padding:1.15rem 1.25rem;}
.gnl-summary h3{margin:0 0 .8rem;font-size:1.05rem;font-weight:600;}
.gnl-sum-lines{list-style:none;margin:0;padding:0;}
.gnl-sum-lines li{display:flex;justify-content:space-between;gap:1rem;font-size:.9rem;padding:.4rem 0;border-bottom:1px solid var(--gnl-line);}
.gnl-sum-lines li span:last-child{white-space:nowrap;font-weight:600;}
.gnl-sum-lines li.gnl-sum-base span:first-child{opacity:.9;}
.gnl-sum-lines li.gnl-muted{opacity:.6;font-style:italic;border-bottom:none;}
.gnl-sum-total{display:flex;justify-content:space-between;align-items:baseline;margin:.9rem 0 0;font-weight:700;font-size:1.15rem;}
.gnl-sum-total small{font-weight:400;font-size:.72rem;opacity:.65;}
.gnl-cta{display:block;width:100%;margin-top:1.1rem;border:none;background:var(--gnl-green);color:#fff;border-radius:10px;padding:.85rem 1rem;font:inherit;font-weight:700;font-size:1rem;cursor:pointer;text-align:center;transition:filter .15s;}
.gnl-cta:hover{filter:brightness(1.06);}
.gnl-after-add{display:flex;gap:1rem;justify-content:center;flex-wrap:wrap;margin-top:.8rem;}
.gnl-back{display:inline-block;margin-top:.8rem;font-size:.85rem;color:inherit;opacity:.7;text-decoration:none;}
.gnl-back:hover{opacity:1;text-decoration:underline;}
.gnl-empty{background:#fff;border:1px dashed var(--gnl-line);border-radius:12px;padding:1.4rem;text-align:center;}
</style>
</head>
<body class="home wp-singular page-template-default page page-id-6 wp-custom-logo wp-embed-responsive wp-theme-twentytwentyfive surecart-theme-light">
		<script>
			const onSkipLinkClick = () => {
				const htmlElement = document.querySelector('html');

				htmlElement.style['scroll-behavior'] = 'smooth';

				setTimeout( () => htmlElement.style['scroll-behavior'] = null, 1000 );
			}
			document.addEventListener("DOMContentLoaded", () => {
				if (!document.querySelector('#content')) {
					document.querySelector('.ea11y-skip-to-content-link').remove();
				}
			});
		</script>
		<nav aria-label="Skip to content navigation">
			<a class="ea11y-skip-to-content-link"
				href="#content"
				tabindex="-1"
				onclick="onSkipLinkClick()"
			>
				Aller au contenu principal
				<svg width="24" height="24" viewBox="0 0 24 24" fill="none" role="presentation">
					<path d="M18 6V12C18 12.7956 17.6839 13.5587 17.1213 14.1213C16.5587 14.6839 15.7956 15 15 15H5M5 15L9 11M5 15L9 19"
								stroke="black"
								stroke-width="1.5"
								stroke-linecap="round"
								stroke-linejoin="round"
					/>
				</svg>
			</a>
			<div class="ea11y-skip-to-content-backdrop"></div>
		</nav>

		
<div class="wp-site-blocks">
  <?php
// Pied de page commun (identique à index.php)
if (is_readable('../include/header.php')) {
    include '../include/header.php';
}
?>

<main class="wp-block-group has-background has-global-padding is-layout-constrained wp-block-group-is-layout-constrained" style="background-color:#f3f3f3;margin-top:0">
<div class="gnl-config-wrap">

<?php if (!$product): ?>

  <p class="gnl-breadcrumb"><a href="./">Accueil</a> &rsaquo; Configuration</p>
  <div class="gnl-empty">
    <h2 style="margin-top:0">Offre introuvable</h2>
    <p>Nous n'avons pas retrouvé l'offre demandée. Elle a peut-être été retirée du catalogue.</p>
    <p><a class="gnl-cta" style="display:inline-block;width:auto;text-decoration:none" href="./">Retour à la boutique</a></p>
  </div>

<?php else:
    $name  = gnl_e($product['name']);
    $price = gnl_price($product['prix']);
    $desc  = $product['stitre'] !== '' ? gnl_e($product['stitre']) : gnl_e(strip_tags($product['description']));

    // Données transmises au JS
    $jsData = array(
        'product' => array('slug' => $product['slug'], 'name' => $product['name'], 'price' => $basePrice),
        'currency' => 'EUR',
    );
?>

  <p class="gnl-breadcrumb"><a href="./">Accueil</a> &rsaquo; Configuration &rsaquo; <?php echo $name; ?></p>
  <h1 class="gnl-config-title">Configurez votre offre</h1>
  <p class="gnl-config-sub">Ajustez les options puis validez votre commande.</p>

  <div class="gnl-layout">

    <!-- ================= Colonne principale ================= -->
    <div>
      <div class="gnl-card gnl-recap">
        <div>
          <h2><?php echo $name; ?> <?php echo gnl_flag($product['flag']); ?></h2>
          <?php if ($desc !== ''): ?><p class="gnl-recap-desc"><?php echo $desc; ?></p><?php endif; ?>
        </div>
        <div class="gnl-recap-price">
          <?php echo $price !== '' ? $price : 'Sur devis'; ?>
          <?php if ($price !== ''): ?><small>/ mois</small><?php endif; ?>
        </div>
      </div>

      <?php if ($hasOptions && count($OPTIONS) > 0): ?>
        <h2 class="gnl-section-h">Options</h2>

        <?php foreach ($OPTIONS as $opt):
            $isDomain = ($opt['slug'] === 'domaine');
            $ouiLabel = $opt['oui'] !== '' ? gnl_e($opt['oui']) : 'Oui';
            $nonLabel = $opt['non'] !== '' ? gnl_e($opt['non']) : 'Non merci';
            if ($opt['prix'] > 0) {
                $suffix    = $opt['unique'] ? ' <small style="font-weight:400;opacity:.7">(paiement unique)</small>' : '/ mois';
                $priceHtml = '+ ' . gnl_price($opt['prix']) . ($opt['unique'] ? '' : ' ') . ($opt['unique'] ? '' : '');
                $priceHtml = '<span class="gnl-opt-price">+ ' . gnl_price($opt['prix']) . ($opt['unique'] ? ' <small style="font-weight:400;opacity:.7">(unique)</small>' : ' / mois') . '</span>';
            } elseif ($isDomain) {
                $priceHtml = '<span class="gnl-opt-price gnl-quote">selon le domaine</span>';
            } else {
                $priceHtml = '';
            }
        ?>
        <div class="gnl-opt"
             data-slug="<?php echo gnl_e($opt['slug']); ?>"
             data-name="<?php echo gnl_e($opt['name']); ?>"
             data-prix="<?php echo htmlspecialchars(number_format($opt['prix'], 2, '.', ''), ENT_QUOTES); ?>"
             data-unique="<?php echo $opt['unique'] ? '1' : '0'; ?>"
             <?php echo $isDomain ? 'data-domain="1"' : ''; ?>>
          <div class="gnl-opt-head">
            <h3><?php echo gnl_e($opt['name']); ?></h3>
            <?php echo $priceHtml; ?>
          </div>
          <?php if ($opt['desc'] !== ''): ?><p class="gnl-opt-desc"><?php echo gnl_e($opt['desc']); ?></p><?php endif; ?>
          <div class="gnl-choices">
            <button type="button" class="gnl-choice is-active" data-val="non"><?php echo $nonLabel; ?></button>
            <button type="button" class="gnl-choice" data-val="oui"><?php echo $ouiLabel; ?></button>
          </div>

          <?php if ($isDomain): ?>
          <div class="gnl-domain" hidden>
            <div class="gnl-domain-search">
              <input type="text" class="gnl-domain-input" placeholder="ex. mon-entreprise.fr" autocomplete="off" spellcheck="false" />
              <button type="button" class="gnl-domain-btn">Vérifier</button>
            </div>
            <p class="gnl-domain-hint">Saisissez le nom de domaine souhaité (avec son extension&nbsp;: .fr, .com, .eu&hellip;). La disponibilité et le tarif sont vérifiés en direct via OVH.</p>
            <div class="gnl-domain-results" aria-live="polite"></div>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>

      <?php else: ?>
        <div class="gnl-empty">
          <p style="margin:0">Cette offre ne comporte pas d'option supplémentaire.<br>Vous pouvez directement valider votre commande.</p>
        </div>
      <?php endif; ?>
    </div>

    <!-- ================= Résumé ================= -->
    <aside>
      <div class="gnl-summary">
        <h3>Résumé de la commande</h3>
        <ul class="gnl-sum-lines" id="gnl-sum-lines"><!-- rempli par JS --></ul>
        <div class="gnl-sum-total">
          <span>Total <small>/ mois</small></span>
          <span id="gnl-total"></span>
        </div>
        <form method="post" action="cart" id="gnl-order-form">
          <input type="hidden" name="action"  value="add">
          <input type="hidden" name="produit" value="<?php echo gnl_e($product['slug']); ?>">
          <input type="hidden" name="config"  id="gnl-config-field" value="">
          <button type="submit" class="gnl-cta">Ajouter au panier</button>
        </form>
        <div class="gnl-after-add">
          <a class="gnl-back" href="cart">Voir mon panier</a>
          <a class="gnl-back" href="./">Choisir une autre offre</a>
        </div>
      </div>
    </aside>

  </div>

  <script type="application/json" id="gnl-config-data"><?php echo json_encode($jsData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>

<?php endif; ?>

</div>
</main>

<?php
// Pied de page commun (identique à index.php)
if (is_readable('../include/footer.php')) {
    include '../include/footer.php';
}
?>
</div><!-- .wp-site-blocks -->

<script id="gnl-config-js">
(function(){
  var dataEl = document.getElementById('gnl-config-data');
  if (!dataEl) return;
  var DATA = {};
  try { DATA = JSON.parse(dataEl.textContent); } catch(e){ return; }

  var fmt = new Intl.NumberFormat('fr-FR', { style:'currency', currency: DATA.currency || 'EUR' });
  var base = (DATA.product && typeof DATA.product.price === 'number') ? DATA.product.price : 0;

  var state = { options:{}, domain:null };

  var linesEl = document.getElementById('gnl-sum-lines');
  var totalEl = document.getElementById('gnl-total');
  var cfgField = document.getElementById('gnl-config-field');

  function li(label, value, cls){
    var l = document.createElement('li');
    if (cls) l.className = cls;
    var a = document.createElement('span'); a.textContent = label;
    var b = document.createElement('span'); b.textContent = value;
    l.appendChild(a); l.appendChild(b);
    return l;
  }

  function render(){
    var total = base;
    linesEl.innerHTML = '';
    linesEl.appendChild(li(DATA.product.name, fmt.format(base), 'gnl-sum-base'));

    Object.keys(state.options).forEach(function(slug){
      var o = state.options[slug];
      var label = o.name + (o.unique ? ' (unique)' : '');
      var val = o.prix > 0 ? '+ ' + fmt.format(o.prix) : 'incluse';
      linesEl.appendChild(li(label, val));
      if (!o.unique) total += o.prix; // les frais uniques ne comptent pas dans le /mois
    });

    if (state.domain){
      linesEl.appendChild(li('Domaine : ' + state.domain.name, state.domain.price || '—'));
      if (state.domain.priceNum) total += 0; // tarif domaine généralement annuel : affiché mais non ajouté au /mois
    }

    if (!Object.keys(state.options).length && !state.domain){
      linesEl.appendChild(li('Aucune option', '', 'gnl-muted'));
    }

    totalEl.textContent = fmt.format(total);

    if (cfgField){
      cfgField.value = JSON.stringify({
        produit: DATA.product.slug,
        base: base,
        options: state.options,
        domaine: state.domain,
        total_mensuel: total
      });
    }
  }

  // -------- Choix Oui / Non par option --------
  document.querySelectorAll('.gnl-opt').forEach(function(card){
    var slug   = card.getAttribute('data-slug');
    var name   = card.getAttribute('data-name');
    var prix   = parseFloat(card.getAttribute('data-prix')) || 0;
    var unique = card.getAttribute('data-unique') === '1';
    var isDom  = card.getAttribute('data-domain') === '1';
    var domBox = card.querySelector('.gnl-domain');

    card.querySelectorAll('.gnl-choice').forEach(function(btn){
      btn.addEventListener('click', function(){
        card.querySelectorAll('.gnl-choice').forEach(function(b){ b.classList.remove('is-active'); });
        btn.classList.add('is-active');
        var yes = btn.getAttribute('data-val') === 'oui';
        card.classList.toggle('is-on', yes);

        if (yes){ state.options[slug] = { name:name, prix:prix, unique:unique }; }
        else    { delete state.options[slug]; if (isDom) state.domain = null; }

        if (isDom && domBox){
          domBox.hidden = !yes;
          if (!yes){ domBox.querySelector('.gnl-domain-results').innerHTML = ''; }
        }
        render();
      });
    });

    // -------- Recherche de domaine (proxy OVH) --------
    if (isDom && domBox){
      var input   = domBox.querySelector('.gnl-domain-input');
      var btn     = domBox.querySelector('.gnl-domain-btn');
      var results = domBox.querySelector('.gnl-domain-results');

      function search(){
        var q = (input.value || '').trim().toLowerCase();
        results.innerHTML = '';
        if (!/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9-]+)+$/.test(q)){
          results.innerHTML = '<div class="gnl-note">Entrez un domaine valide avec son extension, par exemple <strong>mon-site.fr</strong>.</div>';
          return;
        }
        btn.disabled = true;
        results.innerHTML = '<div class="gnl-note"><span class="gnl-spin"></span> Vérification de « ' + q +' » auprès d\'OVH&hellip;</div>';

        fetch('product-configuration.php?ajax=domain&domain=' + encodeURIComponent(q), { headers:{'Accept':'application/json'} })
          .then(function(r){ return r.json(); })
          .then(function(res){
            btn.disabled = false;
            results.innerHTML = '';
            if (!res.ok){
              if (res.error === 'ovh_not_configured'){
                manualFallback(q, 'La recherche automatique OVH n\'est pas encore active. Vous pouvez réserver ce domaine, notre équipe confirmera sa disponibilité.');
              } else if (res.error === 'invalid'){
                results.innerHTML = '<div class="gnl-note">Domaine invalide.</div>';
              } else {
                manualFallback(q, 'Impossible de vérifier la disponibilité pour le moment. Vous pouvez tout de même demander ce domaine.');
              }
              return;
            }
            if (!res.results || !res.results.length){
              manualFallback(q, 'Aucun résultat renvoyé. Vous pouvez demander ce domaine, nous le confirmerons.');
              return;
            }
            res.results.forEach(function(item){ results.appendChild(domRow(item)); });
          })
          .catch(function(){
            btn.disabled = false;
            manualFallback(q, 'Erreur réseau pendant la vérification. Vous pouvez demander ce domaine malgré tout.');
          });
      }

      function domRow(item){
        var row = document.createElement('div');
        row.className = 'gnl-dom ' + (item.orderable ? 'is-ok' : 'is-ko');
        var left = document.createElement('div');
        left.innerHTML = '<span class="gnl-dom-name">' + item.domain + '</span>';
        var right = document.createElement('div'); right.className = 'gnl-dom-right';
        if (item.orderable){
          var badge = document.createElement('span'); badge.className = 'gnl-badge ok'; badge.textContent = 'Disponible';
          right.appendChild(badge);
          if (item.price){ var pr = document.createElement('span'); pr.textContent = item.price; right.appendChild(pr); }
          var add = document.createElement('button'); add.type = 'button'; add.className = 'gnl-add'; add.textContent = 'Choisir';
          add.addEventListener('click', function(){
            results.querySelectorAll('.gnl-add').forEach(function(b){ b.classList.remove('is-added'); b.textContent = 'Choisir'; });
            add.classList.add('is-added'); add.textContent = 'Choisi ✓';
            state.domain = { name:item.domain, price:item.price || '', priceNum:item.priceNum || 0 };
            render();
          });
          right.appendChild(add);
        } else {
          var b2 = document.createElement('span'); b2.className = 'gnl-badge ko'; b2.textContent = 'Indisponible';
          right.appendChild(b2);
        }
        row.appendChild(left); row.appendChild(right);
        return row;
      }

      function manualFallback(q, msg){
        var note = document.createElement('div'); note.className = 'gnl-note'; note.textContent = msg;
        results.appendChild(note);
        var row = document.createElement('div'); row.className = 'gnl-dom is-ok';
        row.innerHTML = '<span class="gnl-dom-name">' + q + '</span>';
        var right = document.createElement('div'); right.className = 'gnl-dom-right';
        var add = document.createElement('button'); add.type = 'button'; add.className = 'gnl-add'; add.textContent = 'Demander';
        add.addEventListener('click', function(){
          add.classList.add('is-added'); add.textContent = 'Demandé ✓';
          state.domain = { name:q, price:'à confirmer', priceNum:0 };
          render();
        });
        right.appendChild(add); row.appendChild(right); results.appendChild(row);
      }

      btn.addEventListener('click', search);
      input.addEventListener('keydown', function(e){ if (e.key === 'Enter'){ e.preventDefault(); search(); } });
    }
  });

  render();
})();
</script>

</body>
<!-- product-configuration.php — GNL Solution -->
</html>
