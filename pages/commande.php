<?php
/* =====================================================================
   GNL Solution — Commande & paiement Mollie  (commande.php)
   ---------------------------------------------------------------------
   Reçoit le POST de product-configuration.php :
       produit = <slug>
       config  = JSON { produit, base, options{...}, domaine{...}, total_mensuel }

   Parcours :
     1. "recap"   : récapitulatif + coordonnées client.
     2. "confirm" : validation, enregistrement de la commande (webhook n8n
                    "order.create"), puis création d'un paiement Mollie et
                    redirection vers le checkout Mollie.
     3. retour Mollie (?mollie=return) : affichage du statut de paiement.
     4. webhook Mollie (?mollie=webhook) : notification serveur -> serveur.
                    Si le 1er paiement est réglé, un abonnement récurrent
                    est créé automatiquement selon la périodicité choisie
                    (mensuelle, trimestrielle ou annuelle).

   Les prix sont TOUJOURS recalculés côté serveur (product.csv /
   product_option.csv) : les montants du navigateur ne sont jamais fiables.
   ===================================================================== */

if (!defined('GNL_WEBHOOK_URL'))  define('GNL_WEBHOOK_URL', 'https://api.gnl-solution.fr/webhook/boutique');
if (!defined('GNL_CSV_FALLBACK')) define('GNL_CSV_FALLBACK', __DIR__ . '/product.csv');
if (!defined('GNL_OPT_CSV'))      define('GNL_OPT_CSV', __DIR__ . '/product_option.csv');
if (!defined('GNL_CACHE_TTL'))    define('GNL_CACHE_TTL', 300);

/* ---------- Paiement Mollie -----------------------------------------
   Clé API : https://my.mollie.com/dashboard/developers/api-keys
   (test_xxx pour tester, live_xxx en production).
   GNL_BASE_URL : URL PUBLIQUE du site (indispensable en production pour
   que Mollie puisse joindre le webhook). Ex : https://gnl-solution.fr */
if (!defined('MOLLIE_API_KEY')) define('MOLLIE_API_KEY', getenv('MOLLIE_API_KEY') ?: '');
if (!defined('GNL_BASE_URL'))   define('GNL_BASE_URL',   getenv('GNL_BASE_URL')   ?: '');

/* =================== Chargement catalogue (comme index.php) ========= */
function gnl_fetch_from_webhook($action) {
    $payload = json_encode(array('source' => 'commande', 'action' => $action));
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
        'method' => 'POST', 'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
        'content' => $payload, 'timeout' => 6, 'ignore_errors' => true,
    )));
    $body = @file_get_contents(GNL_WEBHOOK_URL, false, $ctx);
    if ($body === false) return null;
    return gnl_decode_rows($body);
}
function gnl_decode_rows($body) {
    $data = json_decode($body, true);
    if (!is_array($data)) return null;
    foreach (array('data', 'products', 'options', 'rows', 'items', 'result') as $k) {
        if (isset($data[$k]) && is_array($data[$k])) { $data = $data[$k]; break; }
    }
    if (isset($data['id']) || isset($data['name']) || isset($data['slug']) || isset($data['option_name'])) $data = array($data);
    $rows = array();
    foreach ($data as $item) {
        if (!is_array($item)) continue;
        if (isset($item['json']) && is_array($item['json'])) $item = $item['json'];
        $rows[] = $item;
    }
    return $rows ? $rows : null;
}
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
function gnl_normalize($rows) {
    $out = array();
    if (!is_array($rows)) return $out;
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $g = function ($k) use ($r) { return isset($r[$k]) ? $r[$k] : ''; };
        $out[] = array(
            'id' => $g('id'), 'flag' => strtoupper(trim((string) $g('datacenter_flag'))),
            'type' => strtolower(trim((string) $g('type'))), 'slug' => trim((string) $g('slug')),
            'name' => trim((string) $g('name')), 'stitre' => trim((string) $g('stitre')),
            'description' => trim((string) $g('description')), 'categorie' => (int) $g('categorie_id'),
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
            'id' => $g('id'), 'name' => trim((string) $g('option_name')),
            'slug' => strtolower(trim((string) $g('slug'))), 'desc' => trim((string) $g('option_desc')),
            'prix' => gnl_num($g('option_prix')), 'unique' => gnl_bool($g('prix_unique')),
        );
    }
    return $out;
}
/* ---------- Helpers -------------------------------------------------- */
function gnl_e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function gnl_num($v) { if ($v === '' || $v === null) return 0.0; return (float) str_replace(array(' ', ','), array('', '.'), (string) $v); }
function gnl_bool($v) { return in_array(strtolower(trim((string) $v)), array('1','true','oui','yes','on'), true); }
function gnl_money($f) { return number_format((float) $f, 2, ',', ' ') . "\xc2\xa0\xe2\x82\xac"; }
function gnl_flag($code) {
    $code = strtoupper((string) $code);
    if ($code === 'FR') return '<img decoding="async" width="20" height="auto" src="/assets/img/icon-flag-edited.png" alt="Datacenter France" style="width:20px;height:auto;vertical-align:middle" />';
    $emoji = array('DE' => "\xf0\x9f\x87\xa9\xf0\x9f\x87\xaa", 'SW' => "\xf0\x9f\x87\xa8\xf0\x9f\x87\xad", 'CH' => "\xf0\x9f\x87\xa8\xf0\x9f\x87\xad", 'BE' => "\xf0\x9f\x87\xa7\xf0\x9f\x87\xaa");
    $flag = isset($emoji[$code]) ? $emoji[$code] : "\xf0\x9f\x8c\x8d";
    return '<span style="font-size:18px" title="Datacenter ' . gnl_e($code) . '">' . $flag . '</span>';
}
/* ---------- Fréquences de facturation --------------------------------
   L'abonnement repose sur un prix mensuel (recalculé côté serveur). La
   fréquence choisie à la commande multiplie ce montant, définit
   l'intervalle Mollie et la date de renouvellement prévue. */
function gnl_billing_plans() {
    return array(
        'mensuel'     => array('label' => 'Mensuel',     'months' => 1,  'interval' => '1 month',   'suffix' => '/ mois',      'per' => 'par mois'),
        'trimestriel' => array('label' => 'Trimestriel', 'months' => 3,  'interval' => '3 months',  'suffix' => '/ trimestre', 'per' => 'par trimestre'),
        'annuel'      => array('label' => 'Annuel',      'months' => 12, 'interval' => '12 months', 'suffix' => '/ an',        'per' => 'par an'),
    );
}
function gnl_billing_plan($key) {
    $plans = gnl_billing_plans();
    $key = strtolower(trim((string) $key));
    if (!isset($plans[$key])) $key = 'mensuel';
    $p = $plans[$key];
    $p['key'] = $key;
    return $p;
}
/* Date lisible en français, ex. "5 septembre 2026" */
function gnl_fr_date($ts) {
    if (!$ts) return '';
    /* Date affichée dans la langue du visiteur (extension intl), français sinon. */
    if (function_exists('gnl_lang') && gnl_lang() !== 'fr' && class_exists('IntlDateFormatter')) {
        $f = new IntlDateFormatter(gnl_i18n_locale(gnl_region(), gnl_lang()), IntlDateFormatter::LONG, IntlDateFormatter::NONE);
        $out = $f->format($ts);
        if ($out !== false) return $out;
    }
    $mois = array(1=>'janvier',2=>'février',3=>'mars',4=>'avril',5=>'mai',6=>'juin',7=>'juillet',8=>'août',9=>'septembre',10=>'octobre',11=>'novembre',12=>'décembre');
    $m = (int) date('n', $ts);
    return ((int) date('j', $ts)) . ' ' . $mois[$m] . ' ' . date('Y', $ts);
}
function gnl_send_order($payload) {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if (function_exists('curl_init')) {
        $ch = curl_init(GNL_WEBHOOK_URL);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'Accept: application/json'),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4,
        ));
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        return ($body !== false && $code >= 200 && $code < 300);
    }
    $ctx = stream_context_create(array('http' => array(
        'method' => 'POST', 'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
        'content' => $json, 'timeout' => 8, 'ignore_errors' => true,
    )));
    return @file_get_contents(GNL_WEBHOOK_URL, false, $ctx) !== false;
}

/* =================== Persistance légère des commandes ===============
   Un fichier JSON par commande. Idéalement hors racine web : on tente
   __DIR__/data/orders (protégé par .htaccess) sinon le dossier temp. */
function gnl_store_dir() {
    $dir = __DIR__ . '/data/orders';
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    if (is_dir($dir) && is_writable($dir)) {
        $ht = __DIR__ . '/data/.htaccess';
        if (!file_exists($ht)) @file_put_contents($ht, "Require all denied\nDeny from all\n");
        return $dir;
    }
    $tmp = sys_get_temp_dir() . '/gnl-orders';
    if (!is_dir($tmp)) @mkdir($tmp, 0770, true);
    return $tmp;
}
function gnl_order_path($ref) { return gnl_store_dir() . '/' . preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $ref) . '.json'; }
function gnl_store_order($order) { @file_put_contents(gnl_order_path($order['reference']), json_encode($order, JSON_UNESCAPED_UNICODE)); }
function gnl_load_order($ref) {
    $p = gnl_order_path($ref);
    if (!is_readable($p)) return null;
    $d = json_decode(@file_get_contents($p), true);
    return is_array($d) ? $d : null;
}

/* =================== URL absolue (retour / webhook Mollie) ========== */
function gnl_self_url($query) {
    if (defined('GNL_BASE_URL') && GNL_BASE_URL !== '') {
        $base = rtrim(GNL_BASE_URL, '/') . '/commande';
    } else {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
        $host  = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
        $path  = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/commande';
        $base  = ($https ? 'https' : 'http') . '://' . $host . $path;
    }
    return $base . ($query !== '' ? ('?' . $query) : '');
}

/* =================== API Mollie ===================================== */
function gnl_mollie_key() { return (defined('MOLLIE_API_KEY') && MOLLIE_API_KEY !== '') ? MOLLIE_API_KEY : (getenv('MOLLIE_API_KEY') ?: ''); }
function gnl_mollie_enabled() { return gnl_mollie_key() !== ''; }
function gnl_amount($f) { return array('currency' => 'EUR', 'value' => number_format((float) $f, 2, '.', '')); }
function gnl_mollie_request($method, $path, $body = null) {
    $key = gnl_mollie_key();
    if ($key === '') return array('_error' => 'mollie_not_configured');
    if (!function_exists('curl_init')) return array('_error' => 'no_curl');
    $ch = curl_init('https://api.mollie.com/v2' . $path);
    curl_setopt_array($ch, array(
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array('Authorization: Bearer ' . $key, 'Content-Type: application/json', 'Accept: application/json'),
        CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 6,
    ));
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch); // curl_close() est déprécié depuis PHP 8.5 et sans effet depuis 8.0
    if ($resp === false) return array('_error' => 'network');
    $json = json_decode($resp, true);
    if ($code < 200 || $code >= 300) return array('_error' => 'http_' . $code, 'detail' => $json);
    return $json;
}
function gnl_mollie_get_payment($id) { return gnl_mollie_request('GET', '/payments/' . rawurlencode($id)); }
function gnl_mollie_create_subscription($customerId, $amount, $desc, $interval = '1 month', $startDate = null) {
    if ($startDate === null || $startDate === '') $startDate = date('Y-m-d', strtotime('+1 month'));
    return gnl_mollie_request('POST', '/customers/' . rawurlencode($customerId) . '/subscriptions', array(
        'amount' => gnl_amount($amount), 'interval' => $interval, 'description' => $desc,
        'startDate' => $startDate, 'webhookUrl' => gnl_self_url('mollie=webhook'),
    ));
}
/* Charge (une fois) l'aide Keycloak REST. */
function gnl_kc_load() {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        if (is_readable(__DIR__ . '/keycloak_rest.php')) require_once __DIR__ . '/keycloak_rest.php';
    } catch (Throwable $e) {
        error_log('[GNL] keycloak_rest : ' . $e->getMessage());
    }
    return $ok = function_exists('gnl_kc_admin_request');
}
/* Vérifie qu'un client existe chez Mollie (et dans le même mode test/live que
   la clé API : un client test renvoie 404 avec une clé live). */
function gnl_mollie_customer_exists($customerId) {
    $customerId = trim((string) $customerId);
    if (!preg_match('/^cst_[A-Za-z0-9]+$/', $customerId)) return false;
    $c = gnl_mollie_request('GET', '/customers/' . rawurlencode($customerId));
    return is_array($c) && !isset($c['_error']) && !empty($c['id']) && $c['id'] === $customerId;
}
/* Retrouve le client Mollie déjà associé à cette commande ou à l'organisation
   Keycloak (attribut "moliecliid"). Retourne array(id, source) ou null.
   $kcValue reçoit la valeur actuelle de moliecliid ('' si aucune). */
function gnl_mollie_find_existing_customer($order, $orgId, &$kcValue) {
    $kcValue = '';
    // 1) Nouvelle tentative de paiement : la commande connaît déjà son client
    if (!empty($order['mollie_customer_id']) && gnl_mollie_customer_exists($order['mollie_customer_id'])) {
        return array((string) $order['mollie_customer_id'], 'order');
    }
    // 2) Client mémorisé sur l'organisation lors d'une commande précédente
    if ($orgId !== '' && gnl_kc_load() && function_exists('gnl_kc_get_mollie_customer_id')) {
        try { $kcValue = (string) gnl_kc_get_mollie_customer_id($orgId); }
        catch (Throwable $e) { error_log('[GNL] lecture moliecliid : ' . $e->getMessage()); }
        if ($kcValue !== '') {
            if (gnl_mollie_customer_exists($kcValue)) return array($kcValue, 'keycloak');
            error_log('[GNL] moliecliid ' . $kcValue . ' de l\'organisation ' . $orgId . ' introuvable chez Mollie (supprimé ou autre mode test/live) : un nouveau client sera créé');
        }
    }
    return null;
}

/* ---------- Détail de commande envoyé à Mollie ----------------------
   Taux de TVA appliqué aux lignes (les montants facturés sont considérés
   TTC, comme l'exige Mollie : unitPrice « including VAT »). */
if (!defined('GNL_VAT_RATE')) define('GNL_VAT_RATE', (float) (getenv('GNL_VAT_RATE') !== false && getenv('GNL_VAT_RATE') !== '' ? getenv('GNL_VAT_RATE') : 20));

function gnl_mollie_cut($s, $max) {
    $s = trim(preg_replace('/\s+/u', ' ', (string) $s));
    return function_exists('mb_substr') ? mb_substr($s, 0, $max, 'UTF-8') : substr($s, 0, $max);
}
/* URL publique d'une page du site (même base que les URL de retour Mollie). */
function gnl_site_url($path) {
    $self = gnl_self_url('');                           // …/commande
    $base = preg_replace('#/[^/]*$#', '', $self);       // racine du site
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}
/* URL de la page produit (product-configuration.php lit ?slug=…). */
function gnl_product_url($slug) {
    $slug = trim((string) $slug);
    return $slug === '' ? '' : gnl_site_url('product-configuration?slug=' . rawurlencode($slug));
}

/* Une ligne Mollie. Champs envoyés : name (-> "description" dans l'API
   Payments), productUrl, sku, quantity, unitPrice, discountAmount,
   totalAmount, vatRate, vatAmount.
   totalAmount = unitPrice × quantité − remise ; vatAmount selon la formule
   imposée par Mollie : total × taux / (100 + taux).
   $meta (identifiants de la ligne) n'est pas accepté ligne par ligne par
   l'API Payments : il est regroupé dans metadata.lines du paiement. */
function gnl_mollie_line($name, $qty, $unit, $sku = '', $productUrl = '', $discount = 0.0, $meta = array()) {
    $qty      = max(1, (int) $qty);
    $unit     = round((float) $unit, 2);
    $discount = max(0.0, round((float) $discount, 2));
    $total    = round($unit * $qty - $discount, 2);
    $rate     = (float) GNL_VAT_RATE;
    $line = array(
        'type'        => 'digital',
        'description' => gnl_mollie_cut($name, 200),   // "name" de la ligne
        'quantity'    => $qty,
        'unitPrice'   => gnl_amount($unit),
        'totalAmount' => gnl_amount($total),
        'vatRate'     => number_format($rate, 2, '.', ''),
        'vatAmount'   => gnl_amount(round($total * $rate / (100 + $rate), 2)),
    );
    if ($discount > 0)      $line['discountAmount'] = gnl_amount($discount);
    if ($sku !== '')        $line['sku']            = gnl_mollie_cut($sku, 64);
    if ($productUrl !== '') $line['productUrl']     = $productUrl;
    $line['_meta'] = $meta; // retiré avant l'envoi (voir gnl_mollie_split_meta)
    return $line;
}
/* Retire les métadonnées de ligne (non acceptées par Mollie sur une ligne)
   et renvoie leur résumé compact pour metadata.lines du paiement. */
function gnl_mollie_split_meta(&$lines) {
    $meta = array();
    foreach ($lines as $i => $l) {
        $m = (isset($l['_meta']) && is_array($l['_meta'])) ? array_filter($l['_meta'], 'strlen') : array();
        unset($lines[$i]['_meta']);
        if (isset($l['sku'])) $m = array_merge(array('sku' => $l['sku']), $m);
        if ($m) $meta[] = $m;
    }
    $lines = array_values($lines);
    // Limite Mollie ~1 ko pour toutes les métadonnées : on tronque si besoin
    while ($meta && strlen(json_encode($meta)) > 700) array_pop($meta);
    return $meta;
}
/* Lignes du 1er paiement : abonnement de la période (produit + options
   récurrentes) puis une ligne par option à frais unique. */
function gnl_mollie_lines($order) {
    $lines  = array();
    $months = isset($order['billing']['interval_months']) ? max(1, (int) $order['billing']['interval_months']) : 1;
    $label  = isset($order['billing']['label']) ? (string) $order['billing']['label'] : 'Mensuel';
    $per    = $months === 1 ? '1 mois' : ($months . ' mois');
    $items  = (isset($order['items']) && is_array($order['items'])) ? $order['items'] : array();
    foreach ($items as $it) {
        $qty  = isset($it['qty']) ? max(1, (int) $it['qty']) : 1;
        $slug = isset($it['slug']) ? (string) $it['slug'] : '';
        $name = isset($it['name']) && $it['name'] !== '' ? (string) $it['name'] : $slug;
        $url  = gnl_product_url($slug);
        $recOpts = array();
        if (!empty($it['options']) && is_array($it['options'])) {
            foreach ($it['options'] as $o) if (empty($o['unique'])) $recOpts[] = (string) $o['name'];
        }
        // Abonnement : prix unitaire de la période
        $monthlyUnit = (float) $it['line_monthly'] / $qty;
        if ($monthlyUnit > 0) {
            $desc = $name . ($recOpts ? ' + ' . implode(', ', $recOpts) : '')
                  . (!empty($it['domaine']['name']) ? ' — ' . $it['domaine']['name'] : '')
                  . ' — abonnement ' . strtolower($label) . ' (' . $per . ')';
            $lines[] = gnl_mollie_line($desc, $qty, $monthlyUnit * $months, $slug, $url, 0.0,
                array('uid' => isset($it['uid']) ? (string) $it['uid'] : ''));
        }
        // Frais uniques : une ligne par option
        if (!empty($it['options']) && is_array($it['options'])) {
            foreach ($it['options'] as $o) {
                if (empty($o['unique']) || (float) $o['prix'] <= 0) continue;
                $lines[] = gnl_mollie_line($o['name'] . ' — frais unique (' . $name . ')', $qty, (float) $o['prix'],
                    isset($o['slug']) ? (string) $o['slug'] : '', $url, 0.0,
                    array('uid' => isset($o['uid']) ? (string) $o['uid'] : '', 'product_uid' => isset($it['uid']) ? (string) $it['uid'] : ''));
            }
        }
    }
    return $lines;
}
/* Code pays ISO 3166-1 alpha-2 à partir de la saisie ("France", "FR"…). */
function gnl_country_code($v) {
    $v = trim((string) $v);
    if ($v === '') return 'FR';
    if (preg_match('/^[A-Za-z]{2}$/', $v)) return strtoupper($v);
    $k = strtolower($v);
    if (function_exists('iconv')) { $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $k); if ($t) $k = strtolower($t); }
    $map = array('france'=>'FR','belgique'=>'BE','belgium'=>'BE','suisse'=>'CH','switzerland'=>'CH','luxembourg'=>'LU',
                 'allemagne'=>'DE','germany'=>'DE','espagne'=>'ES','spain'=>'ES','italie'=>'IT','italy'=>'IT',
                 'pays-bas'=>'NL','pays bas'=>'NL','netherlands'=>'NL','monaco'=>'MC','portugal'=>'PT','royaume-uni'=>'GB','united kingdom'=>'GB');
    return isset($map[$k]) ? $map[$k] : '';
}
/* Adresse de facturation (entreprise) ; vide si rien d'exploitable. */
function gnl_mollie_billing_address($order) {
    $c = isset($order['client']) && is_array($order['client']) ? $order['client'] : array();
    $g = function ($k) use ($c) { return isset($c[$k]) ? trim((string) $c[$k]) : ''; };
    $a = array();
    /* Nom de facturation = raison sociale (attribut "raison") + entité légale
       (attribut "entite_legal"), ex. "SlapIA SAS". Si la raison se termine déjà
       par la forme juridique, elle n'est pas répétée. Repli : nom commercial,
       puis nom de l'organisation. */
    $raison = $g('raison_social'); $forme = $g('entite_legal');
    if ($raison !== '' && $forme !== '' && !preg_match('/(^|\s)' . preg_quote($forme, '/') . '$/iu', $raison)) $org = $raison . ' ' . $forme;
    elseif ($raison !== '') $org = $raison;
    else $org = $g('nom_commercial') !== '' ? trim($g('nom_commercial') . ' ' . $forme) : (!empty($order['organization']) ? trim((string) $order['organization']) : '');
    if ($org !== '') $a['organizationName'] = gnl_mollie_cut($org, 100);
    // Mollie : prénom / nom d'au moins 2 caractères, pas uniquement des chiffres
    foreach (array('givenName' => 'prenom', 'familyName' => 'nom') as $mk => $ck) {
        $v = $g($ck);
        if ((function_exists('mb_strlen') ? mb_strlen($v, 'UTF-8') : strlen($v)) >= 2 && !ctype_digit($v)) $a[$mk] = gnl_mollie_cut($v, 100);
    }
    $email = filter_var($g('ent_email'), FILTER_VALIDATE_EMAIL) ? $g('ent_email') : $g('email');
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) $a['email'] = $email;
    // Adresse postale uniquement si complète (sinon Mollie la rejette)
    $country = gnl_country_code($g('adr_pays'));
    if ($g('adr_voie') !== '' && $g('adr_ville') !== '' && $country !== '') {
        $a['streetAndNumber'] = gnl_mollie_cut($g('adr_voie'), 100);
        if ($g('adr_cp') !== '') $a['postalCode'] = gnl_mollie_cut($g('adr_cp'), 20);
        $a['city']    = gnl_mollie_cut($g('adr_ville'), 100);
        $a['country'] = $country;
    }
    // Téléphone au format E.164 (numéros français 0X XX XX XX XX -> +33…)
    $tel = preg_replace('/[^0-9+]/', '', $g('tel'));
    if (preg_match('/^0[1-9][0-9]{8}$/', $tel)) $tel = '+33' . substr($tel, 1);
    if (strpos($tel, '00') === 0) $tel = '+' . substr($tel, 2);
    if (preg_match('/^\+[1-9][0-9]{7,14}$/', $tel)) $a['phone'] = $tel;
    return $a;
}

/* Réutilise le client Mollie de l'organisation (sinon en crée un), crée le
   premier paiement (détaillé) et renvoie l'URL de checkout Mollie */
function gnl_mollie_start(&$order) {
    // Organisation Keycloak propriétaire de la commande (UUID issu de la session)
    $orgId   = (isset($order['organization_uid']) && $order['organization_uid'] !== '') ? (string) $order['organization_uid'] : '';
    $kcValue = '';
    $existing = gnl_mollie_find_existing_customer($order, $orgId, $kcValue);
    if ($existing) {
        $customerId = $existing[0];
        $order['mollie_customer_source'] = $existing[1]; // order | keycloak
    } else {
        // Le client Mollie représente l'organisation : raison sociale et e-mail
        // de l'entreprise en priorité, sinon ceux de la personne qui commande.
        $c = $order['client'];
        $name = '';
        foreach (array('raison_social', 'nom_commercial', 'organization') as $k) {
            if ($name === '' && !empty($c[$k])) $name = trim((string) $c[$k]);
        }
        if ($name === '' && !empty($order['organization'])) $name = trim((string) $order['organization']);
        if ($name === '') $name = trim($c['prenom'] . ' ' . $c['nom']);
        $email = (!empty($c['ent_email']) && filter_var($c['ent_email'], FILTER_VALIDATE_EMAIL)) ? $c['ent_email'] : $c['email'];

        $payload = array('name' => $name, 'email' => $email);
        if ($orgId !== '') $payload['metadata'] = array('organization_uid' => $orgId);
        $cust = gnl_mollie_request('POST', '/customers', $payload);
        if (isset($cust['_error']) || empty($cust['id'])) return array('_error' => 'customer', 'detail' => $cust);
        $customerId = $cust['id'];
        $order['mollie_customer_source'] = 'created';
    }

    $periode = isset($order['totaux']['periode']) ? (float) $order['totaux']['periode'] : (float) $order['totaux']['mensuel'];
    $first = round($periode + ((float) $order['totaux']['frais_unique']), 2);
    if ($first <= 0) $first = 0.01; // Mollie exige un montant > 0
    $recurring = (float) $order['recurring_amount'];
    $seq = ($recurring > 0) ? 'first' : 'oneoff'; // "first" -> mandat, pour l'abonnement

    /* Paiement "détaillé" = la commande côté Mollie : lignes produits/options,
       adresse de facturation de l'entreprise et n° de commande GNL-… */
    $lines = gnl_mollie_lines($order);
    $linesMeta = gnl_mollie_split_meta($lines); // identifiants uid / product_uid par ligne
    $linesTotal = 0.0;
    foreach ($lines as $l) $linesTotal += (float) $l['totalAmount']['value'];
    $linesTotal = round($linesTotal, 2);
    if ($lines && abs($linesTotal - $first) >= 0.005) {
        // Mollie exige somme des lignes = montant ; un écart ne vient que d'arrondis
        error_log('[GNL] commande ' . $order['reference'] . ' : total des lignes ' . $linesTotal . ' ≠ ' . $first . ' (arrondis) — montant aligné sur les lignes');
        $first = $linesTotal;
    }

    $body = array(
        'amount'       => gnl_amount($first),
        'description'  => 'Commande ' . $order['reference'],
        'redirectUrl'  => gnl_self_url('mollie=return&ref=' . rawurlencode($order['reference'])),
        'webhookUrl'   => gnl_self_url('mollie=webhook'),
        'customerId'   => $customerId,
        'sequenceType' => $seq,
        'locale'       => 'fr_FR',
        'metadata'     => array(
            'order_ref'        => $order['reference'],
            'organization_uid' => isset($order['organization_uid']) ? (string) $order['organization_uid'] : '',
            'frequence'        => isset($order['billing']['frequence']) ? (string) $order['billing']['frequence'] : 'mensuel',
        ),
    );
    if ($linesMeta) $body['metadata']['lines'] = $linesMeta;
    if ($lines) $body['lines'] = $lines;
    $billing = gnl_mollie_billing_address($order);
    if ($billing) $body['billingAddress'] = $billing;

    $pay = gnl_mollie_request('POST', '/payments', $body);
    /* Filet de sécurité : si Mollie refuse le détail (ligne, adresse…), on
       relance sans lignes ni adresse pour ne jamais bloquer le paiement. */
    if ((isset($pay['_error']) || empty($pay['_links']['checkout']['href'])) && (isset($body['lines']) || isset($body['billingAddress']))) {
        error_log('[GNL] commande ' . $order['reference'] . ' : paiement détaillé refusé par Mollie (' . json_encode(isset($pay['detail']) ? $pay['detail'] : $pay, JSON_UNESCAPED_UNICODE) . ') — relance sans lignes');
        unset($body['lines'], $body['billingAddress']);
        $body['amount'] = gnl_amount(max(0.01, round($periode + ((float) $order['totaux']['frais_unique']), 2)));
        $pay = gnl_mollie_request('POST', '/payments', $body);
        $order['mollie_detail_rejected'] = true;
    }
    if (isset($pay['_error']) || empty($pay['_links']['checkout']['href'])) return array('_error' => 'payment', 'detail' => $pay);

    $order['mollie_customer_id'] = $customerId;
    $order['mollie_payment_id']  = $pay['id'];
    $order['payment_status']     = isset($pay['status']) ? $pay['status'] : 'open';

    /* Renvoie l'identifiant client Mollie (cst_xxx) à Keycloak : attribut
       "moliecliid" de l'ORGANISATION, créé s'il n'existe pas. Inutile si
       l'organisation a déjà cette valeur. Un échec ici ne bloque jamais le
       paiement (simple log). */
    if ($orgId === '') {
        $order['keycloak_moliecliid_synced'] = false;
        error_log('[GNL] commande ' . $order['reference'] . ' sans organization_uid : moliecliid ' . $customerId . ' non enregistré dans Keycloak');
    } elseif ($kcValue === $customerId) {
        $order['keycloak_moliecliid_synced'] = true; // déjà à jour
    } else {
        $ok = false;
        try {
            if (gnl_kc_load() && function_exists('gnl_kc_save_mollie_customer_id')) $ok = gnl_kc_save_mollie_customer_id($orgId, $customerId);
        } catch (Throwable $e) {
            error_log('[GNL] moliecliid : ' . $e->getMessage());
        }
        $order['keycloak_moliecliid_synced'] = $ok;
        if (!$ok) error_log('[GNL] commande ' . $order['reference'] . ' : moliecliid ' . $customerId . ' non enregistré sur l\'organisation ' . $orgId);
    }
    gnl_store_order($order);

    return array('checkout' => $pay['_links']['checkout']['href']);
}

/* =================== Routeurs Mollie (webhook / retour / retry) ===== */

// -- Webhook serveur -> serveur : Mollie envoie l'id du paiement en POST
if (isset($_GET['mollie']) && $_GET['mollie'] === 'webhook') {
    $pid = isset($_POST['id']) ? trim($_POST['id']) : '';
    if ($pid === '') { http_response_code(400); echo 'no id'; exit; }
    $p = gnl_mollie_get_payment($pid);
    if (is_array($p) && isset($p['metadata']['order_ref'])) {
        $ref = $p['metadata']['order_ref'];
        $order = gnl_load_order($ref);
        if ($order) {
            $order['payment_status'] = isset($p['status']) ? $p['status'] : 'unknown';
            // 1er paiement réglé -> on ouvre l'abonnement récurrent (selon la fréquence choisie)
            if (($p['status'] ?? '') === 'paid' && empty($order['subscription_id'])
                && !empty($order['recurring_amount']) && (float) $order['recurring_amount'] > 0
                && !empty($p['customerId'])) {
                $bill      = (isset($order['billing']) && is_array($order['billing'])) ? $order['billing'] : array();
                $interval  = !empty($bill['mollie_interval']) ? $bill['mollie_interval'] : '1 month';
                $freqLabel = !empty($bill['label']) ? $bill['label'] : 'Mensuel';
                $startDate = !empty($order['next_renewal']) ? $order['next_renewal'] : date('Y-m-d', strtotime('+1 month'));
                $sub = gnl_mollie_create_subscription($p['customerId'], $order['recurring_amount'], 'Abonnement GNL Solution (' . $freqLabel . ') - ' . $ref, $interval, $startDate);
                if (is_array($sub) && !empty($sub['id'])) {
                    $order['subscription_id'] = $sub['id'];
                    // Date de renouvellement confirmée par Mollie si disponible
                    if (!empty($sub['nextPaymentDate'])) $order['next_renewal'] = $sub['nextPaymentDate'];
                }
            }
            gnl_store_order($order);
            gnl_send_order(array(
                'action' => 'order.payment', 'reference' => $ref, 'status' => $order['payment_status'],
                // Même organisation que celle envoyée avec order.create
                'organization_uid' => isset($order['organization_uid']) ? $order['organization_uid'] : '',
                'organization'     => isset($order['organization']) ? $order['organization'] : '',
                'mollie_payment_id' => $pid, 'subscription_id' => isset($order['subscription_id']) ? $order['subscription_id'] : null,
                'billing' => isset($order['billing']) ? $order['billing'] : null,
                'next_renewal' => isset($order['next_renewal']) ? $order['next_renewal'] : null,
            ));
        }
    }
    http_response_code(200); echo 'OK'; exit;
}

// -- Nouvelle tentative de paiement
if (isset($_GET['mollie']) && $_GET['mollie'] === 'retry' && isset($_GET['ref'])) {
    $order = gnl_load_order($_GET['ref']);
    if ($order && gnl_mollie_enabled()) {
        $r = gnl_mollie_start($order);
        if (!empty($r['checkout'])) { header('Location: ' . $r['checkout']); exit; }
    }
    header('Location: ' . gnl_self_url('mollie=return&ref=' . rawurlencode((string) $_GET['ref']))); exit;
}

/* =================== Panier (session) + résolution des lignes ======= */
if (session_status() === PHP_SESSION_NONE) session_start();

function gnl_cart_get()   { return (isset($_SESSION['gnl_cart']) && is_array($_SESSION['gnl_cart'])) ? $_SESSION['gnl_cart'] : array(); }
function gnl_cart_clear() {
    unset($_SESSION['gnl_cart']);
    // Supprime aussi le cookie lu par le header, sinon l'icone panier resterait
    // affichee sur les autres pages apres la commande.
    if (!headers_sent()) {
        setcookie('gnl_cart_count', '0', array(
            'expires' => time() - 3600, 'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']), 'httponly' => false, 'samesite' => 'Lax',
        ));
    }
    unset($_COOKIE['gnl_cart_count']);
}

/* Identifiants uniques (ligne produit et option). Ils s'AJOUTENT au slug
   produit et au n° de commande, et sont transmis au webhook n8n. */
function gnl_uid($prefix) {
    if (function_exists('random_bytes')) {
        try { return $prefix . bin2hex(random_bytes(8)); } catch (Exception $e) {}
    }
    return $prefix . str_replace('.', '', uniqid('', true));
}
/* Slug d'une option, stockée en chaîne (ancien format) ou en tableau {slug, uid}. */
function gnl_opt_slug($o) {
    if (is_array($o)) return isset($o['slug']) ? strtolower((string) $o['slug']) : '';
    return strtolower((string) $o);
}

/* Résout une ligne de panier -> prix TOUJOURS recalculés côté serveur */
function gnl_resolve_line($item, $productsIndex, $optIndex) {
    $slug = isset($item['slug']) ? (string) $item['slug'] : '';
    if ($slug === '' || !isset($productsIndex[$slug])) return null;
    $p = $productsIndex[$slug];
    $qty = isset($item['qty']) ? max(1, (int) $item['qty']) : 1;
    $opts = array(); $monthly = gnl_num($p['prix']); $once = 0.0;
    if (!empty($item['options']) && is_array($item['options'])) {
        foreach ($item['options'] as $entry) {
            $os  = gnl_opt_slug($entry);
            $uid = (is_array($entry) && !empty($entry['uid'])) ? (string) $entry['uid'] : gnl_uid('opt_');
            if (isset($optIndex[$os])) {
                $o = $optIndex[$os];
                $o['uid'] = $uid; // identifiant unique de CETTE option dans le panier
                $opts[] = $o;
                if ($o['unique']) $once += $o['prix']; else $monthly += $o['prix'];
            }
        }
    }
    $dom = null;
    if (!empty($item['domaine']['name'])) {
        $dn = preg_replace('/[^a-z0-9.\-]/', '', strtolower((string) $item['domaine']['name']));
        if ($dn !== '') $dom = array('name' => $dn, 'price' => isset($item['domaine']['price']) ? (string) $item['domaine']['price'] : '');
    }
    return array(
        'id' => isset($item['id']) ? $item['id'] : gnl_uid('itm_'),
        'product' => $p, 'options' => $opts, 'domaine' => $dom, 'qty' => $qty,
        'monthly' => $monthly, 'once' => $once,
        'line_monthly' => $monthly * $qty, 'line_once' => $once * $qty,
    );
}

$GNL_PRODUCTS = gnl_load_products();
$productsIndex = array();
foreach ($GNL_PRODUCTS as $p) $productsIndex[$p['slug']] = $p;

$OPT_CATALOG = gnl_load_options();
$optIndex = array();
foreach ($OPT_CATALOG as $o) $optIndex[$o['slug']] = $o;

/* Source : panier de session, sinon config unique postée (achat direct) */
$cartRaw = gnl_cart_get();
if (!$cartRaw) {
    $rawConfig = isset($_POST['config']) ? (string) $_POST['config'] : '';
    $cfg = json_decode($rawConfig, true);
    $slug = isset($_POST['produit']) ? trim($_POST['produit'])
          : ((is_array($cfg) && isset($cfg['produit'])) ? trim((string) $cfg['produit'])
          : (isset($_GET['produit']) ? trim($_GET['produit']) : ''));
    if ($slug !== '' && isset($productsIndex[$slug])) {
        $cartRaw = array(array(
            'slug'    => $slug,
            'options' => (is_array($cfg) && !empty($cfg['options']) && is_array($cfg['options'])) ? array_keys($cfg['options']) : array(),
            'domaine' => (is_array($cfg) && !empty($cfg['domaine'])) ? $cfg['domaine'] : null,
            'qty'     => 1,
        ));
    }
}

$items = array(); $cartMonthly = 0.0; $cartOnce = 0.0;
foreach ($cartRaw as $ci) {
    $line = gnl_resolve_line($ci, $productsIndex, $optIndex);
    if ($line) { $items[] = $line; $cartMonthly += $line['line_monthly']; $cartOnce += $line['line_once']; }
}

/* =================== Vue par défaut ================================ */
$view = 'form';
$errors = array();
$done = false; $orderRef = ''; $orderSent = false; $mollieError = false;
$client = array('civilite'=>'','prenom'=>'','nom'=>'','email'=>'','tel'=>'','raison_social'=>'','nom_commercial'=>'','entite_legal'=>'','siret'=>'','siren'=>'','tva'=>'','ent_email'=>'','adr_voie'=>'','adr_cp'=>'','adr_ville'=>'','adr_pays'=>'','organization'=>'','message'=>'');

/* -- Retour Mollie : affichage du statut ---------------------------- */
$order = null; $payStatus = '';
if (isset($_GET['mollie']) && $_GET['mollie'] === 'return' && isset($_GET['ref'])) {
    $order = gnl_load_order($_GET['ref']);
    if ($order && !empty($order['mollie_payment_id']) && gnl_mollie_enabled()) {
        $p = gnl_mollie_get_payment($order['mollie_payment_id']);
        if (is_array($p) && isset($p['status'])) {
            $payStatus = $p['status'];
            if ($order['payment_status'] !== $payStatus) { $order['payment_status'] = $payStatus; gnl_store_order($order); }
        }
    } elseif ($order) {
        $payStatus = isset($order['payment_status']) ? $order['payment_status'] : '';
    }
    $view = 'status';
}

/* =================== Authentification requise (SSO Keycloak) ======== */
$gnlUser = (isset($_SESSION['gnl_user']) && is_array($_SESSION['gnl_user'])) ? $_SESSION['gnl_user'] : null;
if ($view === 'form' && !$gnlUser) {
    $ret = isset($_SERVER['REQUEST_URI']) ? strtok($_SERVER['REQUEST_URI'], '?') : '/commande';
    header('Location: /connexion?return=' . rawurlencode($ret));
    exit;
}
if ($gnlUser) {
    $prefill = array(
        'civilite' => 'civilite', 'prenom' => 'given_name', 'nom' => 'family_name', 'email' => 'email',
        'raison_social' => 'raison_social', 'nom_commercial' => 'nom_commercial', 'entite_legal' => 'entite_legal',
        'siret' => 'siret', 'siren' => 'siren', 'tva' => 'tva', 'ent_email' => 'ent_email',
        'adr_voie' => 'adr_voie', 'adr_cp' => 'adr_cp', 'adr_ville' => 'adr_ville', 'adr_pays' => 'adr_pays',
        'organization' => 'organization',
    );
    foreach ($prefill as $field => $claim) {
        if ($client[$field] === '' && !empty($gnlUser[$claim])) $client[$field] = (string) $gnlUser[$claim];
    }
    // Téléphone : celui de l'utilisateur, sinon celui de l'entreprise
    if ($client['tel'] === '') $client['tel'] = !empty($gnlUser['phone']) ? (string) $gnlUser['phone'] : (!empty($gnlUser['ent_phone']) ? (string) $gnlUser['ent_phone'] : '');
}

/* =================== Étape de confirmation ========================= */
if ($view === 'form' && !empty($items) && (isset($_POST['step']) && $_POST['step'] === 'confirm')) {
    foreach (array_keys($client) as $k) { if (isset($_POST[$k])) $client[$k] = trim((string) $_POST[$k]); }
    if ($client['prenom'] === '') $errors['prenom'] = true;
    if ($client['nom'] === '')    $errors['nom'] = true;
    if (!filter_var($client['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = true;
    if (empty($_POST['cgv']))     $errors['cgv'] = true;

    if (!$errors) {
        try { $rand = strtoupper(bin2hex(random_bytes(3))); }
        catch (Exception $e) { $rand = strtoupper(substr(md5(uniqid('', true)), 0, 6)); }
        $orderRef = 'GNL-' . date('Ymd') . '-' . $rand;

        $orderItems = array();
        foreach ($items as $it) {
            // Identifiant unique de la ligne produit (en plus du slug + n° de commande).
            $lineUid = (isset($it['id']) && $it['id'] !== '') ? (string) $it['id'] : gnl_uid('itm_');
            $optOut = array();
            foreach ($it['options'] as $o) {
                $optOut[] = array(
                    'uid'          => (isset($o['uid']) && $o['uid'] !== '') ? (string) $o['uid'] : gnl_uid('opt_'),
                    'slug'         => $o['slug'],
                    'name'         => $o['name'],
                    'prix'         => $o['prix'],
                    'unique'       => $o['unique'],
                    'reference'    => $orderRef,               // n° de commande
                    'product_uid'  => $lineUid,                // ligne produit parente
                    'product_slug' => $it['product']['slug'],  // slug du produit parent
                );
            }
            $orderItems[] = array(
                'uid'          => $lineUid,                    // identifiant unique de la ligne produit
                'reference'    => $orderRef,                   // n° de commande
                'slug'         => $it['product']['slug'],
                'name'         => $it['product']['name'],
                'prix_mensuel' => gnl_num($it['product']['prix']),
                'qty'          => $it['qty'],
                'options'      => $optOut,
                'domaine'      => $it['domaine'],
                'line_monthly' => round($it['line_monthly'], 2),
                'line_once'    => round($it['line_once'], 2),
            );
        }

        // Fréquence de facturation choisie par le client (mensuel par défaut)
        $plan        = gnl_billing_plan(isset($_POST['frequence']) ? $_POST['frequence'] : 'mensuel');
        $periodTotal = round($cartMonthly * $plan['months'], 2);
        // Le 1er règlement a lieu aujourd'hui ; le renouvellement suit d'une période complète.
        $nextRenewal = date('Y-m-d', strtotime('+' . $plan['months'] . ' month'));

        /* Organisation (Keycloak) du client : l'UUID vient TOUJOURS de la
           session, jamais du POST, pour qu'il ne puisse pas être falsifié. */
        $orgUid   = ($gnlUser && !empty($gnlUser['organization_uid']))   ? (string) $gnlUser['organization_uid']   : '';
        $orgName  = ($gnlUser && !empty($gnlUser['organization']))       ? (string) $gnlUser['organization']       : '';
        $orgAlias = ($gnlUser && !empty($gnlUser['organization_alias'])) ? (string) $gnlUser['organization_alias'] : '';
        if ($orgUid === '') error_log('[GNL] commande ' . $orderRef . ' sans organization_uid (organisation : ' . ($orgName !== '' ? $orgName : 'aucune') . ')');

        $order = array(
            'reference' => $orderRef, 'date' => date('c'), 'payment_status' => 'created',
            // --- Organisation propriétaire de la commande (attendu par n8n) ---
            'organization_uid'   => $orgUid,      // ex. ef0c8c3b-4d64-4019-9cf3-8aa4e4a4c98b
            'organization'       => $orgName,     // ex. SlapIA
            'organization_alias' => $orgAlias,
            'items'     => $orderItems, 'client' => $client,
            'user'      => $gnlUser ? array('sub'=>$gnlUser['sub'], 'email'=>$gnlUser['email'], 'organization_uid'=>$orgUid) : null,
            'billing'   => array(
                'frequence'       => $plan['key'],       // mensuel | trimestriel | annuel
                'label'           => $plan['label'],
                'interval_months' => $plan['months'],
                'mollie_interval' => $plan['interval'],
            ),
            'next_renewal' => $nextRenewal,              // date de renouvellement prévue (Y-m-d)
            'totaux'    => array(
                'mensuel'      => round($cartMonthly, 2),
                'periode'      => $periodTotal,          // montant facturé par période
                'frais_unique' => round($cartOnce, 2),
                'du_jour'      => round($periodTotal + $cartOnce, 2),
                'devise'       => 'EUR',
            ),
            'recurring_amount' => $periodTotal,
        );
        gnl_store_order($order);
        $orderSent = gnl_send_order(array_merge(array('action'=>'order.create'), $order));
        gnl_cart_clear(); // le panier devient une commande

        if (gnl_mollie_enabled() && ($cartMonthly > 0 || $cartOnce > 0)) {
            $r = gnl_mollie_start($order);
            if (!empty($r['checkout'])) { header('Location: ' . $r['checkout']); exit; }
            // Échec de création du paiement : confirmation dégradée
            $mollieError = true;
            gnl_send_order(array('action'=>'order.payment_error', 'reference'=>$orderRef, 'organization_uid'=>$orgUid, 'detail'=>isset($r['_error'])?$r['_error']:'unknown'));
        }
        $view = 'done'; $done = true; // Mollie non configuré, gratuit, ou erreur -> récap final
    }
}
?>
<?php
/* ---- Affichage : nouvelle charte (include/layout.php) ---- */
require_once __DIR__ . '/../include/layout.php';
gnl_ui_open('Commande', '', array('main' => false));
?>
<main id="contenu">
<div class="gnl-config-wrap">

<?php
/* Rendu d'un récapitulatif à partir d'une commande stockée (vue statut/confirmation) */
function gnl_render_recap_from_order($order) {
    if (!$order) return;
    echo '<ul class="gnl-sum-lines">';
    if (!empty($order['items'])) {
        foreach ($order['items'] as $it) {
            $qty = isset($it['qty']) ? (int) $it['qty'] : 1;
            echo '<li class="gnl-sum-base"><span>' . gnl_e($it['name']) . ($qty > 1 ? ' &times;' . $qty : '') . '</span><span>' . gnl_money($it['prix_mensuel']) . ' / mois</span></li>';
            if (!empty($it['options'])) {
                foreach ($it['options'] as $o) {
                    $val = ($o['prix'] > 0) ? ('+ ' . gnl_money($o['prix'])) : 'incluse';
                    echo '<li><span style="padding-left:.9rem;opacity:.85">&#8627; ' . gnl_e($o['name']) . ($o['unique'] ? ' (unique)' : '') . '</span><span>' . $val . '</span></li>';
                }
            }
            if (!empty($it['domaine']['name'])) {
                $dp = ($it['domaine']['price'] !== '') ? gnl_e($it['domaine']['price']) : '—';
                echo '<li><span style="padding-left:.9rem;opacity:.85">&#8627; Domaine&nbsp;: ' . gnl_e($it['domaine']['name']) . '</span><span>' . $dp . '</span></li>';
            }
        }
    }
    echo '</ul>';
    $bill   = (isset($order['billing']) && is_array($order['billing'])) ? $order['billing'] : array();
    $plan   = gnl_billing_plan(isset($bill['frequence']) ? $bill['frequence'] : 'mensuel');
    $period = isset($order['totaux']['periode']) ? (float) $order['totaux']['periode'] : (float) $order['totaux']['mensuel'];
    echo '<div class="gnl-sum-total"><span>Total <small>' . gnl_e($plan['suffix']) . '</small></span><span>' . gnl_money($period) . '</span></div>';
    if (!empty($order['totaux']['frais_unique']) && $order['totaux']['frais_unique'] > 0) {
        echo '<div class="gnl-sum-total" style="font-size:.95rem;font-weight:600"><span>Frais uniques</span><span>' . gnl_money($order['totaux']['frais_unique']) . '</span></div>';
    }
    echo '<p class="gnl-line-note" style="margin-top:.7rem">Facturation&nbsp;: <strong>' . gnl_e($plan['label']) . '</strong>';
    if (!empty($order['next_renewal'])) {
        $ts = strtotime((string) $order['next_renewal']);
        if ($ts) echo ' &middot; ' . gnl_e(gnl_t('renouvellement le')) . ' <strong translate="no">' . gnl_e(gnl_fr_date($ts)) . '</strong>';
    }
    echo '.</p>';
}
?>

<?php if ($view === 'status'): ?>
  <?php
    $paid    = ($payStatus === 'paid');
    $pending = in_array($payStatus, array('open','pending','authorized'), true);
  ?>
  <div class="gnl-confirm">
    <?php if (!$order): ?>
      <div class="gnl-check ko">!</div>
      <h1>Commande introuvable</h1>
      <p>Nous n'avons pas retrouvé cette commande. <a href="/solutions">Retour à la boutique</a>.</p>
    <?php else: ?>
      <?php if ($paid): ?>
        <div class="gnl-check">&#10003;</div>
        <h1>Paiement confirmé, merci&nbsp;!</h1>
      <?php elseif ($pending): ?>
        <div class="gnl-check warn">&#8987;</div>
        <h1>Paiement en cours de traitement</h1>
      <?php else: ?>
        <div class="gnl-check ko">&#10005;</div>
        <h1>Le paiement n'a pas abouti</h1>
      <?php endif; ?>

      <p>Référence de commande&nbsp;:</p>
      <div class="gnl-ref"><?php echo gnl_e($order['reference']); ?></div>

      <?php if ($paid): ?>
        <p style="opacity:.85">Votre commande est en cours de mise en service.</p>
        <?php if (!empty($order['recurring_amount']) && $order['recurring_amount'] > 0):
              $sBill  = (isset($order['billing']) && is_array($order['billing'])) ? $order['billing'] : array();
              $sPlan  = gnl_billing_plan(isset($sBill['frequence']) ? $sBill['frequence'] : 'mensuel');
              $sRenew = !empty($order['next_renewal']) ? strtotime((string) $order['next_renewal']) : 0; ?>
          <p style="opacity:.85"><?php
            $sTxt = gnl_t('Votre abonnement {formule} est activé ; le prélèvement se renouvellera automatiquement une fois {periode}.');
            echo str_replace(array('{formule}', '{periode}'), array('<strong>' . gnl_e(strtolower(gnl_t($sPlan['label']))) . '</strong>', gnl_e(gnl_t($sPlan['per']))), gnl_e($sTxt));
            if ($sRenew) echo ' ' . gnl_e(str_replace('{date}', date('d/m/Y', $sRenew), gnl_t('Prochain prélèvement le {date}.')));
          ?></p>
        <?php endif; ?>
        <p style="opacity:.85"><?php echo str_replace('{email}', '<strong translate="no">' . gnl_e($order['client']['email']) . '</strong>', gnl_e(gnl_t('Un e-mail de confirmation part vers {email}.'))); ?></p>
      <?php elseif ($pending): ?>
        <p style="opacity:.85">Votre banque finalise l'opération. Vous recevrez un e-mail dès que le paiement est confirmé&nbsp;; aucune action supplémentaire n'est nécessaire de votre part.</p>
      <?php else: ?>
        <p style="opacity:.85">Aucun montant n'a été débité. Vous pouvez relancer le paiement en toute sécurité.</p>
      <?php endif; ?>

      <div class="gnl-card" style="margin-top:1.2rem"><h3 style="margin-top:0">Récapitulatif</h3><?php gnl_render_recap_from_order($order); ?></div>

      <div class="gnl-btns">
        <?php if ($paid || $pending): ?>
          <a class="gnl-btn primary" href="<?php echo gnl_ui_h(GNL_ESPACE_CLIENT_URL); ?>">Accéder à mon espace client</a>
          <a class="gnl-btn" href="/solutions">Retour à la boutique</a>
        <?php else: ?>
          <a class="gnl-btn primary" href="commande?mollie=retry&amp;ref=<?php echo rawurlencode($order['reference']); ?>">Réessayer le paiement</a>
          <a class="gnl-btn" href="/solutions">Retour à la boutique</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

<?php elseif (empty($items)): ?>

  <p class="gnl-breadcrumb"><a href="/">Accueil</a> &rsaquo; Panier</p>
  <div class="gnl-empty">
    <h2 style="margin-top:0">Votre panier est vide</h2>
    <p>Aucune offre à valider. Choisissez une offre pour commencer votre commande.</p>
    <p><a class="gnl-btn primary" style="text-decoration:none" href="/solutions">Voir les offres</a></p>
  </div>

<?php elseif ($done): ?>

  <div class="gnl-confirm">
    <div class="gnl-check<?php echo $mollieError ? ' warn' : ''; ?>"><?php echo $mollieError ? '!' : '&#10003;'; ?></div>
    <h1><?php echo $mollieError ? gnl_t('Commande enregistrée') : gnl_t('Merci, votre commande est enregistrée !'); ?></h1>
    <p>Votre référence de commande&nbsp;:</p>
    <div class="gnl-ref"><?php echo gnl_e($orderRef); ?></div>
    <p style="opacity:.85"><?php
      $mTxt = $mollieError
        ? gnl_t('Le paiement en ligne est momentanément indisponible. Pas d\'inquiétude : votre commande est bien reçue, un conseiller GNL Solution vous recontacte à {email} pour finaliser le règlement.')
        : gnl_t('Nous avons bien reçu votre demande à {email}. Notre équipe procède à la mise en service de votre offre.');
      echo str_replace('{email}', '<strong translate="no">' . gnl_e($client['email']) . '</strong>', gnl_e($mTxt));
    ?></p>
    <div class="gnl-card" style="margin-top:1.2rem"><h3 style="margin-top:0">Récapitulatif</h3><?php if ($order) gnl_render_recap_from_order($order); ?></div>
    <div class="gnl-btns">
      <a class="gnl-btn primary" href="<?php echo gnl_ui_h(GNL_ESPACE_CLIENT_URL); ?>">Accéder à mon espace client</a>
      <a class="gnl-btn" href="/solutions">Retour à la boutique</a>
    </div>
  </div>

<?php else:
    $mollieOn  = gnl_mollie_enabled();
    $ctaLabel  = $mollieOn ? 'Payer en ligne' : 'Confirmer la commande';
    $plans     = gnl_billing_plans();
    $selFreq   = (isset($_POST['frequence']) && isset($plans[$_POST['frequence']])) ? $_POST['frequence'] : 'mensuel';
    $selPlan   = $plans[$selFreq];
    $selPeriod = $cartMonthly * $selPlan['months'];
?>

  <p class="gnl-breadcrumb"><a href="/">Accueil</a> &rsaquo; <a href="cart">Panier</a> &rsaquo; Commande</p>
  <h1 class="gnl-config-title">Finalisez votre commande</h1>
  <p class="gnl-steps"><span>1. Configuration</span> &rsaquo; <b>2. Récapitulatif &amp; coordonnées</b> &rsaquo; <span>3. Paiement</span></p>

  <?php if ($gnlUser): ?>
    <p style="font-size:.85rem;opacity:.8;margin:.1rem 0 1rem">Connecté en tant que <strong translate="no"><?php echo gnl_e($gnlUser['email'] !== '' ? $gnlUser['email'] : $gnlUser['name']); ?></strong> &middot; <a href="/keycloak_callback.php?action=logout" style="color:var(--gnl-teal)">Se déconnecter</a></p>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="gnl-alert">Merci de corriger les champs surlignés ci-dessous
      <?php echo isset($errors['cgv']) ? 'et d\'accepter les conditions générales.' : '.'; ?>
    </div>
  <?php endif; ?>

  <form method="post" action="commande" id="gnl-order">
    <input type="hidden" name="step" value="confirm">

    <div class="gnl-layout">

      <div>
        <div class="gnl-card">
          <h3 style="margin-top:0">Vos coordonnées</h3>
          <?php if ($gnlUser): ?><p style="font-size:.8rem;opacity:.65;margin:.1rem 0 .9rem">Pré-rempli depuis votre compte GNL Solution — modifiable si besoin.</p><?php endif; ?>
          <div class="gnl-form-grid">
            <div class="gnl-field">
              <label>Civilité</label>
              <select name="civilite">
                <?php
                  $civs = array('' => '— Sélectionnez —', 'M.' => 'Monsieur', 'Mme' => 'Madame');
                  if ($client['civilite'] !== '' && !isset($civs[$client['civilite']])) $civs[$client['civilite']] = $client['civilite'];
                  foreach ($civs as $val => $lab) echo '<option value="' . gnl_e($val) . '"' . (($client['civilite'] === $val) ? ' selected' : '') . '>' . gnl_e($lab) . '</option>';
                ?>
              </select>
            </div>
            <div class="gnl-field">
              <label>Téléphone</label>
              <input type="tel" name="tel" value="<?php echo gnl_e($client['tel']); ?>" autocomplete="tel">
            </div>
            <div class="gnl-field <?php echo isset($errors['prenom']) ? 'err' : ''; ?>">
              <label>Prénom <span class="req">*</span></label>
              <input type="text" name="prenom" value="<?php echo gnl_e($client['prenom']); ?>" autocomplete="given-name">
              <?php if (isset($errors['prenom'])): ?><span class="gnl-err-msg">Champ requis.</span><?php endif; ?>
            </div>
            <div class="gnl-field <?php echo isset($errors['nom']) ? 'err' : ''; ?>">
              <label>Nom <span class="req">*</span></label>
              <input type="text" name="nom" value="<?php echo gnl_e($client['nom']); ?>" autocomplete="family-name">
              <?php if (isset($errors['nom'])): ?><span class="gnl-err-msg">Champ requis.</span><?php endif; ?>
            </div>
            <div class="gnl-field gnl-col2 <?php echo isset($errors['email']) ? 'err' : ''; ?>">
              <label>E-mail <span class="req">*</span></label>
              <input type="email" name="email" value="<?php echo gnl_e($client['email']); ?>" autocomplete="email">
              <?php if (isset($errors['email'])): ?><span class="gnl-err-msg">Adresse e-mail invalide.</span><?php endif; ?>
            </div>
          </div>
        </div>

        <div class="gnl-card" style="margin-top:1rem">
          <h3 style="margin-top:0">Votre entreprise</h3>
          <div class="gnl-form-grid">
            <div class="gnl-field gnl-col2">
              <label>Raison sociale</label>
              <input type="text" name="raison_social" value="<?php echo gnl_e($client['raison_social']); ?>" readonly autocomplete="organization" style="opacity:.8;background:#f7f7f7">
            </div>
            <div class="gnl-field">
              <label>Nom commercial</label>
              <input type="text" name="nom_commercial" value="<?php echo gnl_e($client['nom_commercial']); ?>" readonly style="opacity:.8;background:#f7f7f7">
            </div>
            <div class="gnl-field">
              <label>Forme juridique</label>
              <input type="text" name="entite_legal" value="<?php echo gnl_e($client['entite_legal']); ?>" readonly style="opacity:.8;background:#f7f7f7">
            </div>
            <div class="gnl-field">
              <label>SIRET</label>
              <input type="text" name="siret" value="<?php echo gnl_e($client['siret']); ?>" readonly inputmode="numeric" style="opacity:.8;background:#f7f7f7">
            </div>
            <div class="gnl-field">
              <label>SIREN</label>
              <input type="text" name="siren" value="<?php echo gnl_e($client['siren']); ?>" readonly inputmode="numeric" style="opacity:.8;background:#f7f7f7">
            </div>
            <div class="gnl-field">
              <label>N° TVA intracom.</label>
              <input type="text" name="tva" value="<?php echo gnl_e($client['tva']); ?>" readonly style="opacity:.8;background:#f7f7f7">
            </div>
            <div class="gnl-field">
              <label>E-mail de facturation</label>
              <input type="email" name="ent_email" value="<?php echo gnl_e($client['ent_email']); ?>" readonly style="opacity:.8;background:#f7f7f7">
            </div>
            <div class="gnl-field gnl-col2">
              <label>Adresse</label>
              <input type="text" name="adr_voie" value="<?php echo gnl_e($client['adr_voie']); ?>" readonly autocomplete="street-address" style="opacity:.8;background:#f7f7f7">
            </div>
            <div class="gnl-field">
              <label>Code postal</label>
              <input type="text" name="adr_cp" value="<?php echo gnl_e($client['adr_cp']); ?>" readonly autocomplete="postal-code" style="opacity:.8;background:#f7f7f7">
            </div>
            <div class="gnl-field">
              <label>Ville</label>
              <input type="text" name="adr_ville" value="<?php echo gnl_e($client['adr_ville']); ?>" readonly autocomplete="address-level2" style="opacity:.8;background:#f7f7f7">
            </div>
            <div class="gnl-field gnl-col2">
              <label>Pays</label>
              <input type="text" name="adr_pays" value="<?php echo gnl_e($client['adr_pays']); ?>" readonly autocomplete="country-name" style="opacity:.8;background:#f7f7f7">
            </div>
            <?php if ($client['organization'] !== ''): ?>
            <div class="gnl-field gnl-col2">
              <label>Organisation</label>
              <input type="text" name="organization" value="<?php echo gnl_e($client['organization']); ?>" readonly style="opacity:.8;background:#f7f7f7">
            </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="gnl-card" style="margin-top:1rem">
          <div class="gnl-form-grid">
            <div class="gnl-field gnl-col2">
              <label>Une information a corriger ?</label>
              <textarea name="message" placeholder="Précisions sur votre projet, contraintes particulières…"><?php echo gnl_e($client['message']); ?></textarea>
            </div>
          </div>
        </div>
      </div>

      <aside>
        <div class="gnl-summary">
          <h3>Votre commande</h3>
          <ul class="gnl-sum-lines">
            <?php foreach ($items as $it): $pp = $it['product']; ?>
              <li class="gnl-sum-base">
                <span><?php echo gnl_e($pp['name']); ?> <?php echo gnl_flag($pp['flag']); ?><?php if ($it['qty'] > 1) echo ' &times;' . (int) $it['qty']; ?></span>
                <span><?php echo gnl_money(gnl_num($pp['prix'])); ?></span>
              </li>
              <?php foreach ($it['options'] as $o): ?>
                <li>
                  <span style="padding-left:.9rem;opacity:.85">&#8627; <?php echo gnl_e($o['name']); ?><?php echo $o['unique'] ? ' <small style="opacity:.7">(unique)</small>' : ''; ?></span>
                  <span><?php echo $o['prix'] > 0 ? '+ ' . gnl_money($o['prix']) : 'incluse'; ?></span>
                </li>
              <?php endforeach; ?>
              <?php if ($it['domaine']): ?>
                <li><span style="padding-left:.9rem;opacity:.85">&#8627; Domaine&nbsp;: <?php echo gnl_e($it['domaine']['name']); ?></span><span><?php echo $it['domaine']['price'] !== '' ? gnl_e($it['domaine']['price']) : '—'; ?></span></li>
              <?php endif; ?>
            <?php endforeach; ?>
          </ul>

          <?php if ($cartMonthly > 0): ?>
          <div class="gnl-freq" id="gnl-freq"
               data-monthly="<?php echo gnl_e(number_format($cartMonthly, 2, '.', '')); ?>"
               data-once="<?php echo gnl_e(number_format($cartOnce, 2, '.', '')); ?>">
            <span class="gnl-freq-title">Périodicité de facturation</span>
            <div class="gnl-freq-opts">
              <?php foreach ($plans as $fk => $fp): ?>
                <label class="gnl-freq-opt<?php echo $fk === $selFreq ? ' is-active' : ''; ?>">
                  <input type="radio" name="frequence" value="<?php echo gnl_e($fk); ?>" <?php echo $fk === $selFreq ? 'checked' : ''; ?>>
                  <span class="gnl-freq-name"><?php echo gnl_e($fp['label']); ?></span>
                  <span class="gnl-freq-amt"><?php echo gnl_money($cartMonthly * $fp['months']); ?> <small><?php echo gnl_e($fp['suffix']); ?></small></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>

          <div class="gnl-sum-total"><span>Total <small id="gnl-freq-suffix"><?php echo gnl_e($selPlan['suffix']); ?></small></span><span id="gnl-period-total"><?php echo gnl_money($selPeriod); ?></span></div>
          <?php if ($cartOnce > 0): ?>
            <div class="gnl-sum-total" style="font-size:.98rem;font-weight:600"><span>Frais uniques</span><span><?php echo gnl_money($cartOnce); ?></span></div>
          <?php endif; ?>
          <?php if ($mollieOn && ($cartMonthly > 0 || $cartOnce > 0)): ?>
            <p class="gnl-line-note">Aujourd'hui&nbsp;: <strong id="gnl-today-total"><?php echo gnl_money($selPeriod + $cartOnce); ?></strong> (1<sup>re</sup> échéance<?php echo $cartOnce > 0 ? ' + frais uniques' : ''; ?>), puis <span id="gnl-recurr-note"><?php echo gnl_money($selPeriod) . ' ' . gnl_e($selPlan['per']); ?></span>.</p>
            <?php if ($cartMonthly > 0): ?>
            <p class="gnl-line-note" style="margin-top:.45rem">Prochain renouvellement prévu le <strong id="gnl-renew-date" translate="no"><?php echo gnl_e(gnl_fr_date(strtotime('+' . $selPlan['months'] . ' month'))); ?></strong>.</p>
            <?php endif; ?>
          <?php endif; ?>
          <?php $hasDom = false; foreach ($items as $it) { if ($it['domaine']) { $hasDom = true; break; } } ?>
          <?php if ($hasDom): ?>
            <p class="gnl-line-note">Les noms de domaine sont facturés séparément (à l'année) et confirmés lors de la mise en service.</p>
          <?php endif; ?>

          <label class="gnl-cgv <?php echo isset($errors['cgv']) ? 'err' : ''; ?>">
            <input type="checkbox" name="cgv" id="gnl-cgv-box" value="1" <?php echo (!empty($_POST['cgv'])) ? 'checked' : ''; ?>>
            <span>J'accepte les <a href="/cgv" target="_blank" rel="noopener">conditions générales de vente</a> et la <a href="/politique-de-confidentialite" target="_blank" rel="noopener">politique de confidentialité</a>. <span class="req">*</span></span>
          </label>
          <?php if (isset($errors['cgv'])): ?><p class="gnl-err-msg" style="margin:.2rem 0 0">Vous devez accepter les CGV.</p><?php endif; ?>

          <button type="submit" class="gnl-cta gnl-pay" data-gnl-pay="gnl-cgv-box"><?php echo $ctaLabel; ?></button>
          <?php if ($mollieOn): ?>
            <p class="gnl-pay-note">Paiement 100&nbsp;% sécurisé via Mollie</p>
            <p class="gnl-pay-brands">CB • Visa • Mastercard • Bancontact • iDEAL • SEPA</p>
          <?php endif; ?>
          <a class="gnl-back" href="cart">&larr; Modifier mon panier</a>
        </div>
      </aside>

    </div>
  </form>

  <script id="gnl-freq-js">
  (function(){
    var box = document.getElementById('gnl-freq');
    if(!box) return;
    var PLANS = {
      mensuel:     {months:1,  suffix:'/ mois',      per:'par mois'},
      trimestriel: {months:3,  suffix:'/ trimestre', per:'par trimestre'},
      annuel:      {months:12, suffix:'/ an',        per:'par an'}
    };
    var MOIS = ['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
    var monthly = parseFloat(box.getAttribute('data-monthly')) || 0;
    var once    = parseFloat(box.getAttribute('data-once')) || 0;
    function money(v){ return v.toLocaleString('fr-FR',{minimumFractionDigits:2,maximumFractionDigits:2}) + '\u00a0€'; }
    function frDate(months){ var d = new Date(); d.setMonth(d.getMonth()+months); try { return d.toLocaleDateString(window.GNL_LOCALE || 'fr-FR', {day:'numeric', month:'long', year:'numeric'}); } catch (e) { return d.getDate()+' '+MOIS[d.getMonth()]+' '+d.getFullYear(); } }
    function set(id, txt){ var el = document.getElementById(id); if(el) el.textContent = txt; }
    function apply(key){
      var p = PLANS[key] || PLANS.mensuel;
      var period = monthly * p.months;
      set('gnl-freq-suffix', p.suffix);
      set('gnl-period-total', money(period));
      set('gnl-today-total',  money(period + once));
      set('gnl-recurr-note',  money(period) + ' ' + p.per);
      set('gnl-renew-date',   frDate(p.months));
      box.querySelectorAll('.gnl-freq-opt').forEach(function(l){
        var r = l.querySelector('input'); l.classList.toggle('is-active', !!(r && r.checked));
      });
    }
    box.querySelectorAll('input[name="frequence"]').forEach(function(r){
      r.addEventListener('change', function(){ if(r.checked) apply(r.value); });
    });
    var cur = box.querySelector('input[name="frequence"]:checked');
    if(cur) apply(cur.value);
  })();
  </script>

<?php endif; ?>

</div>
</main>
<?php gnl_ui_close(); ?>
