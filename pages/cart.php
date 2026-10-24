<?php
/* =====================================================================
   GNL Solution — Panier  (cart.php)
   ---------------------------------------------------------------------
   Panier multi-produits sauvegardé dans la SESSION PHP (cookie de session
   PHPSESSID). On n'y stocke que des références (slug produit, slugs
   d'options, nom de domaine, quantité) : les prix sont TOUJOURS recalculés
   côté serveur depuis le catalogue -> impossible à falsifier.

   Actions (motif PRG : on agit puis on redirige) :
     ?action=add     (POST produit + config JSON, depuis product-configuration)
     ?action=remove&id=..
     ?action=qty&id=..&q=..
     ?action=clear
   « Passer la commande » mène à /commande qui lit ce même panier de session.
   ===================================================================== */

if (!defined('GNL_WEBHOOK_URL'))  define('GNL_WEBHOOK_URL', 'https://api.gnl-solution.fr/webhook/boutique');
if (!defined('GNL_CSV_FALLBACK')) define('GNL_CSV_FALLBACK', __DIR__ . '/product.csv');
if (!defined('GNL_OPT_CSV'))      define('GNL_OPT_CSV', __DIR__ . '/product_option.csv');
if (!defined('GNL_CACHE_TTL'))    define('GNL_CACHE_TTL', 300);

/* ---------- Catalogue (identique aux autres pages) ------------------ */
function gnl_fetch_from_webhook($action) {
    $payload = json_encode(array('source' => 'cart', 'action' => $action));
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

/* ---------- Panier en session -------------------------------------- */
if (session_status() === PHP_SESSION_NONE) session_start();
function gnl_cart_get()   { return (isset($_SESSION['gnl_cart']) && is_array($_SESSION['gnl_cart'])) ? $_SESSION['gnl_cart'] : array(); }
function gnl_cart_count_total($c) {
    $n = 0;
    foreach ((array) $c as $ci) { $n += isset($ci['qty']) ? max(0, (int) $ci['qty']) : 1; }
    return $n;
}
/* Cookie lisible par JavaScript, valable sur tout le domaine (path=/), pour que
   l'icone panier s'affiche sur TOUTES les pages (y compris celles rendues par
   WordPress, qui n'ont pas acces a la session de ce panier maison). */
function gnl_cart_sync_cookie($n) {
    $n = (int) $n;
    if (headers_sent()) return;
    setcookie('gnl_cart_count', (string) $n, array(
        'expires'  => $n > 0 ? 0 : time() - 3600, // 0 = cookie de session, meme duree que le panier
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => false, // doit rester lisible par JavaScript
        'samesite' => 'Lax',
    ));
    $_COOKIE['gnl_cart_count'] = (string) $n;
}
function gnl_cart_save($c) { $_SESSION['gnl_cart'] = array_values($c); gnl_cart_sync_cookie(gnl_cart_count_total($_SESSION['gnl_cart'])); }

/* Identifiants uniques (ligne produit et option) dans le panier.
   Ils s'AJOUTENT au slug produit et au n° de commande : ils permettent de
   distinguer deux fois le même produit configuré différemment, et sont
   transmis tels quels au webhook n8n (order.create). Générés une seule fois
   à l'ajout au panier puis conservés en session pour rester stables. */
function gnl_uid($prefix) {
    if (function_exists('random_bytes')) {
        try { return $prefix . bin2hex(random_bytes(8)); } catch (Exception $e) {}
    }
    return $prefix . str_replace('.', '', uniqid('', true));
}
/* Slug d'une option, qu'elle soit stockée en chaîne (ancien format) ou en
   tableau {slug, uid} (nouveau format) -> assure la rétro-compatibilité. */
function gnl_opt_slug($o) {
    if (is_array($o)) return isset($o['slug']) ? strtolower((string) $o['slug']) : '';
    return strtolower((string) $o);
}
function gnl_cart_sig($item) {
    $opts = array();
    if (isset($item['options']) && is_array($item['options'])) {
        foreach ($item['options'] as $o) { $s = gnl_opt_slug($o); if ($s !== '') $opts[] = $s; }
    }
    sort($opts);
    $dom = isset($item['domaine']['name']) ? $item['domaine']['name'] : '';
    return strtolower($item['slug'] . '|' . implode(',', $opts) . '|' . $dom);
}
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

/* ---------- Redirection après action (PRG) -------------------------- */
function gnl_cart_redirect() {
    $to = isset($_SERVER['REQUEST_URI']) ? strtok($_SERVER['REQUEST_URI'], '?') : 'cart';
    header('Location: ' . $to);
    exit;
}

/* ---------- Traitement des actions --------------------------------- */
$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
$flash = '';

if ($action === 'add') {
    $rawConfig = isset($_POST['config']) ? (string) $_POST['config'] : '';
    $cfg = json_decode($rawConfig, true);
    $slug = isset($_POST['produit']) ? trim($_POST['produit'])
          : ((is_array($cfg) && isset($cfg['produit'])) ? trim((string) $cfg['produit']) : '');
    if ($slug !== '' && isset($productsIndex[$slug])) {
        $optSlugs = array();
        if (is_array($cfg) && !empty($cfg['options']) && is_array($cfg['options'])) {
            foreach (array_keys($cfg['options']) as $os) {
                $os = strtolower($os);
                // Chaque option reçoit son propre identifiant unique (en plus du slug).
                if (isset($optIndex[$os])) $optSlugs[] = array('slug' => $os, 'uid' => gnl_uid('opt_'));
            }
        }
        $dom = null;
        if (is_array($cfg) && !empty($cfg['domaine']['name'])) {
            $dn = preg_replace('/[^a-z0-9.\-]/', '', strtolower((string) $cfg['domaine']['name']));
            if ($dn !== '') $dom = array('name' => $dn, 'price' => isset($cfg['domaine']['price']) ? (string) $cfg['domaine']['price'] : '');
        }
        // 'id' = identifiant unique de la ligne produit dans le panier.
        $item = array('id' => gnl_uid('itm_'), 'slug' => $slug, 'options' => $optSlugs, 'domaine' => $dom, 'qty' => 1);

        $cart = gnl_cart_get();
        $sig = gnl_cart_sig($item);
        $merged = false;
        foreach ($cart as &$c) {
            if (gnl_cart_sig($c) === $sig) { $c['qty'] = (isset($c['qty']) ? (int) $c['qty'] : 1) + 1; $merged = true; break; }
        }
        unset($c);
        if (!$merged) $cart[] = $item;
        gnl_cart_save($cart);
    }
    gnl_cart_redirect();
}
elseif ($action === 'remove' && isset($_GET['id'])) {
    $id = (string) $_GET['id'];
    $cart = array_filter(gnl_cart_get(), function ($c) use ($id) { return (isset($c['id']) ? $c['id'] : '') !== $id; });
    gnl_cart_save($cart);
    gnl_cart_redirect();
}
elseif ($action === 'qty' && isset($_GET['id'])) {
    $id = (string) $_GET['id']; $q = max(1, (int) (isset($_GET['q']) ? $_GET['q'] : 1));
    $cart = gnl_cart_get();
    foreach ($cart as &$c) { if ((isset($c['id']) ? $c['id'] : '') === $id) { $c['qty'] = $q; break; } }
    unset($c);
    gnl_cart_save($cart);
    gnl_cart_redirect();
}
elseif ($action === 'clear') {
    unset($_SESSION['gnl_cart']);
    gnl_cart_sync_cookie(0);
    gnl_cart_redirect();
}

/* ---------- Résolution du panier pour affichage -------------------- */
$items = array(); $cartMonthly = 0.0; $cartOnce = 0.0;
foreach (gnl_cart_get() as $ci) {
    $line = gnl_resolve_line($ci, $productsIndex, $optIndex);
    if ($line) { $items[] = $line; $cartMonthly += $line['line_monthly']; $cartOnce += $line['line_once']; }
}
$cartCount = 0; foreach ($items as $it) $cartCount += $it['qty'];
gnl_cart_sync_cookie($cartCount);
?>
<?php
/* ---- Affichage : maquette « Votre panier » (canvas Design, artboard Panier).
   La logique ci-dessus (session, actions add/remove/qty/clear, prix recalculés
   côté serveur) est inchangée ; « Passer au paiement » mène toujours à /commande. */
require_once __DIR__ . '/../include/layout.php';
$gnlUser = (isset($_SESSION['gnl_user']) && is_array($_SESSION['gnl_user'])) ? $_SESSION['gnl_user'] : null;
$KICKERS = array(1 => 'HÉBERGEMENT PHP', 2 => 'HÉBERGEMENT WORDPRESS', 3 => 'SPÉCIAL DÉVELOPPEURS');
$cartKicker = function ($p) use ($KICKERS) {
    if ($p['type'] === 'web_edu') return 'ÉTUDIANTS · CHERCHEURS';
    return isset($KICKERS[$p['categorie']]) ? $KICKERS[$p['categorie']] : 'HÉBERGEMENT WEB';
};
gnl_ui_open('Panier', 'cart', array('main' => false, 'head' => '<style>
.gnl-cart-rm{display:inline-flex;align-items:center;gap:6px;min-height:44px;padding:10px 0;color:#5e6684;font:600 14px var(--font-display);text-decoration:none}
.gnl-cart-rm:hover{color:#b42318}
.gnl-cart-qty{display:inline-flex;align-items:center;gap:2px;font-size:14px;color:#5e6684}
.gnl-cart-qty a{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border:1px solid #c5cbd8;border-radius:3px;color:#141a33;text-decoration:none;font-weight:700}
.gnl-cart-qty a:hover{background:#f4f6fa}
.gnl-cart-qty a[aria-disabled="true"]{opacity:.4;pointer-events:none}
.gnl-cart-qty output{min-width:28px;text-align:center;font-weight:700;color:#141a33}
.gnl-pay{display:flex;align-items:center;justify-content:center;width:100%;min-height:52px;border-radius:3px;color:#fff;font:700 16px var(--font-display);text-decoration:none;background-image:linear-gradient(135deg,#1f7a4a 0,#1f7a4a 50%,#5e6684 50%,#5e6684 100%);background-size:300% 300%;background-position:100% 100%;transition:background-position .7s cubic-bezier(.65,0,.35,1)}
.gnl-pay.is-ready{background-position:0 0}
.gnl-pay.is-ready:hover{background-image:linear-gradient(135deg,#17603a 0,#17603a 50%,#5e6684 50%,#5e6684 100%);color:#fff}
.gnl-pay:hover{color:#fff}
@media (prefers-reduced-motion: reduce){.gnl-pay{transition:none}}
</style>'));
$n = $cartCount;
?>
<main id="contenu" style="flex: 1 0 auto; width: 100%; box-sizing: border-box; max-width: 1240px; margin: 0 auto; padding: 56px 24px 96px;">

<p style="margin: 0 0 12px; font-size: 12px; font-weight: 700; letter-spacing: 0.2em; color: #1f7a4a;">COMMANDE</p>
<h1 style="margin: 0 0 8px; font-size: 44px; line-height: 50px; font-weight: 800;">Votre panier</h1>
<p style="margin: 0 0 40px; font-size: 16px; color: #5e6684;"><?php
if ($n === 0)      echo 'Aucun produit pour le moment.';
elseif ($gnlUser)  echo $n > 1 ? $n . ' produits · vous êtes connecté, vous passerez directement au paiement.' : $n . ' produit · vous êtes connecté, vous passerez directement au paiement.';
else               echo $n > 1 ? $n . ' produits · vous pourrez vous connecter ou créer votre compte à l’étape suivante.' : $n . ' produit · vous pourrez vous connecter ou créer votre compte à l’étape suivante.';
?></p>

<?php if (empty($items)): ?>

<!-- Panier vide -->
<section style="background: #ffffff; border: 2px dashed #5e6684; border-radius: 3px; padding: 64px 24px; display: flex; flex-direction: column; gap: 16px; align-items: center; text-align: center;">
<svg aria-hidden="true" width="84" height="72" viewBox="0 0 28 24" fill="none"><path d="M1.5 3.5H5.2L6.4 7" fill="none" stroke="#141a33" stroke-width="1.6" stroke-linecap="square"/><path d="M6.4 7H26L23 16.5H9.4Z" fill="none" stroke="#141a33" stroke-width="1.6"/><path d="M14.1 9.6 12.7 14M18.6 9.6 17.2 14" fill="none" stroke="#c5cbd8" stroke-width="1.8"/><rect x="10" y="19.6" width="2.6" height="2.6" fill="#141a33"/><rect x="20" y="19.6" width="2.6" height="2.6" fill="#141a33"/></svg>
<h2 style="margin: 0; font-size: 26px; line-height: 32px; font-weight: 800;">Votre panier est vide.</h2>
<p style="margin: 0; max-width: 460px; font-size: 16px; line-height: 25px; color: #5e6684;">Trouvez l'hébergement adapté à votre projet : PHP, WordPress, développeur ou Laboratoire.</p>
<p style="margin: 0;"><a href="/solutions" style="display: inline-flex; align-items: center; min-height: 48px; padding: 0 22px; border-radius: 3px; background: #1f7a4a; color: #ffffff; font-weight: 700; font-size: 15px; text-decoration: none;">Voir les offres</a></p>
</section>

<?php else: ?>

<div style="display: flex; flex-wrap: wrap; gap: 32px; align-items: flex-start;">

<!-- Articles -->
<section aria-label="Articles du panier" style="flex: 999 1 560px; min-width: 0; display: flex; flex-direction: column; gap: 16px;">
<?php foreach ($items as $it):
    $p = $it['product'];
    $id = rawurlencode($it['id']);
    $hasOpts = !empty($it['options']) || !empty($it['domaine']); ?>
<article style="background: #ffffff; border: 1px solid #c5cbd8; border-radius: 3px; padding: 24px 28px;">
<div style="display: flex; flex-wrap: wrap; gap: 8px 24px; align-items: baseline; justify-content: space-between;">
<div style="min-width: 0;">
<p style="margin: 0 0 4px; font-size: 11px; font-weight: 700; letter-spacing: 0.2em; color: #1f7a4a;"><?php echo gnl_e($cartKicker($p)); ?></p>
<h2 id="nom-<?php echo gnl_e($it['id']); ?>" style="margin: 0; font-size: 22px; line-height: 28px; font-weight: 800;" translate="no"><?php echo gnl_e($p['name']); ?></h2>
</div>
<p style="margin: 0; font-size: 15px; color: #5e6684; text-align: right;"><strong style="font-size: 20px; color: #141a33;"><?php echo gnl_money($it['line_monthly']); ?></strong> / mois<?php if ($it['line_once'] > 0): ?><br><span style="font-size: 13px;">+ <?php echo gnl_money($it['line_once']); ?> (unique)</span><?php endif; ?></p>
</div>
<?php if ($hasOpts): ?>
<ul style="margin: 16px 0 0; padding: 14px 0 0; list-style: none; border-top: 1px solid #e3e6ee; display: flex; flex-direction: column; gap: 8px; font-size: 14px; line-height: 20px;">
<?php if ($it['domaine']): ?>
<li style="display: flex; gap: 16px; justify-content: space-between;"><span style="display: flex; gap: 10px; align-items: baseline; min-width: 0; overflow-wrap: anywhere;"><svg aria-hidden="true" width="8" height="14" viewBox="0 0 22 40" style="flex: none;"><path d="M13 0h9L9 40H0z" fill="#1f7a4a"/></svg><span>Nom de domaine : <strong translate="no"><?php echo gnl_e($it['domaine']['name']); ?></strong></span></span><span style="color: #5e6684; white-space: nowrap;"><?php echo $it['domaine']['price'] !== '' ? gnl_e($it['domaine']['price']) : 'selon le domaine'; ?></span></li>
<?php endif; ?>
<?php foreach ($it['options'] as $o): ?>
<li style="display: flex; gap: 16px; justify-content: space-between;"><span style="display: flex; gap: 10px; align-items: baseline; min-width: 0;"><svg aria-hidden="true" width="8" height="14" viewBox="0 0 22 40" style="flex: none;"><path d="M13 0h9L9 40H0z" fill="#1f7a4a"/></svg><span translate="no"><?php echo gnl_e($o['name']); ?></span></span><span style="color: #5e6684; white-space: nowrap;"><?php
    if ($o['prix'] <= 0)   echo 'inclus';
    elseif ($o['unique'])  echo gnl_money($o['prix']) . ' (unique)';
    else                   echo '+ ' . gnl_money($o['prix']) . ' / mois';
?></span></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<div style="display: flex; flex-wrap: wrap; gap: 4px 24px; margin-top: 12px; align-items: center;">
<a href="/product-configuration?slug=<?php echo rawurlencode($p['slug']); ?>" style="display: inline-flex; align-items: center; min-height: 44px; font-size: 14px; font-weight: 600; color: #1f7a4a;">Modifier les options</a>
<span class="gnl-cart-qty" role="group" aria-labelledby="qty-<?php echo gnl_e($it['id']); ?>"><div id="qty-<?php echo gnl_e($it['id']); ?>" style="margin-right: 8px;">Quantité</div>
<a href="cart?action=qty&amp;id=<?php echo $id; ?>&amp;q=<?php echo max(1, $it['qty'] - 1); ?>" aria-label="Diminuer"<?php echo $it['qty'] <= 1 ? ' aria-disabled="true" tabindex="-1"' : ''; ?>>&minus;</a>
<output translate="no"><?php echo (int) $it['qty']; ?></output>
<a href="cart?action=qty&amp;id=<?php echo $id; ?>&amp;q=<?php echo $it['qty'] + 1; ?>" aria-label="Augmenter">+</a>
</span>
<a class="gnl-cart-rm" href="cart?action=remove&amp;id=<?php echo $id; ?>" aria-describedby="nom-<?php echo gnl_e($it['id']); ?>"><svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>Retirer</a>
</div>
</article>
<?php endforeach; ?>
<p style="margin: 8px 0 0; display: flex; flex-wrap: wrap; gap: 8px 24px; justify-content: space-between; font-size: 15px;"><a href="/solutions" style="font-weight: 700;">← Continuer mes achats</a><a href="cart?action=clear" style="font-size: 14px; font-weight: 600; color: #5e6684;">Vider le panier</a></p>
</section>

<!-- Récapitulatif -->
<aside aria-label="Récapitulatif" style="flex: 1 1 340px; min-width: 0; background: #ffffff; border: 1px solid #c5cbd8; border-radius: 3px; padding: 28px;">
<h2 style="margin: 0 0 20px; font-size: 20px; line-height: 26px; font-weight: 800;">Récapitulatif</h2>
<dl style="margin: 0 0 20px; display: flex; flex-direction: column; gap: 12px; font-size: 15px;">
<div style="display: flex; justify-content: space-between; gap: 16px;"><dt style="color: #5e6684;">Abonnements</dt><dd style="margin: 0; white-space: nowrap;"><?php echo gnl_money($cartMonthly); ?> / mois</dd></div>
<div style="display: flex; justify-content: space-between; gap: 16px;"><dt style="color: #5e6684;">Paiements uniques</dt><dd style="margin: 0; white-space: nowrap;"><?php echo gnl_money($cartOnce); ?></dd></div>
</dl>
<div style="display: flex; justify-content: space-between; align-items: baseline; gap: 16px; padding-top: 16px; border-top: 2px solid #141a33;">
<span style="font-size: 16px; font-weight: 700;">À payer aujourd'hui</span>
<span style="font-size: 28px; font-weight: 800; white-space: nowrap;"><?php echo gnl_money($cartMonthly + $cartOnce); ?></span>
</div>
<p style="margin: 6px 0 20px; font-size: 13px; line-height: 19px; color: #5e6684;">TVA non applicable, article 293 B du CGI. Facturation mensuelle par défaut : la périodicité se choisit à l'étape suivante.</p>
<label style="display: flex; gap: 12px; align-items: flex-start; font-size: 14px; line-height: 21px; margin-bottom: 16px; cursor: pointer;">
<input type="checkbox" id="gnl-cart-cgv" style="width: 20px; height: 20px; margin: 1px 0 0; flex: none; accent-color: #1f7a4a;">
<span>J'ai lu et j'accepte les <a href="/cgv">conditions générales de vente</a>.</span>
</label>
<a class="gnl-pay" id="gnl-cart-pay" href="commande">Passer au paiement</a>
<p id="gnl-cart-cgv-hint" role="alert" style="margin: 10px 0 0; font-size: 13px; color: #b42318;" hidden>Acceptez les CGV pour continuer.</p>
<p style="margin: 16px 0 0; display: flex; gap: 8px; align-items: center; font-size: 13px; line-height: 19px; color: #5e6684;"><svg aria-hidden="true" width="14" height="16" viewBox="0 0 24 24" fill="none" stroke="#5e6684" stroke-width="2" style="flex: none;"><rect x="4" y="10" width="16" height="11" rx="1"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg><?php echo $gnlUser ? 'Paiement sécurisé par Mollie.' : 'Paiement sécurisé par Mollie. Connexion ou création de compte à l\'étape suivante.'; ?></p>
</aside>

</div>
<script>
(function(){
  var cb = document.getElementById('gnl-cart-cgv'), pay = document.getElementById('gnl-cart-pay'), hint = document.getElementById('gnl-cart-cgv-hint');
  if (!cb || !pay) return;
  function sync(){ pay.classList.toggle('is-ready', cb.checked); pay.setAttribute('aria-disabled', cb.checked ? 'false' : 'true'); if (cb.checked && hint) hint.hidden = true; }
  cb.addEventListener('change', sync);
  pay.addEventListener('click', function(e){ if (!cb.checked){ e.preventDefault(); if (hint) hint.hidden = false; cb.focus(); } });
  sync();
})();
</script>

<?php endif; ?>

</main>
<?php gnl_ui_close(); ?>
