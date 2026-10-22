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
    if ($code === 'FR') return '<img decoding="async" width="20" height="auto" src="./assets/img/icon-flag-edited.png" alt="Datacenter France" style="width:20px;height:auto;vertical-align:middle" />';
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
/* ---- Affichage : nouvelle charte (include/layout.php) ---- */
require_once __DIR__ . '/../include/layout.php';
gnl_ui_open('Panier', '', array('main' => false));
?>
<main id="contenu">
<div class="gnl-config-wrap">

  <p class="gnl-breadcrumb"><a href="/">Accueil</a> &rsaquo; Panier</p>

<?php if (empty($items)): ?>

  <div class="gnl-empty">
    <h2 style="margin-top:0">Votre panier est vide</h2>
    <p>Parcourez nos offres et configurez celle qui vous convient pour l'ajouter au panier.</p>
    <p><a class="gnl-btn primary" style="text-decoration:none" href="/solutions">Voir les offres</a></p>
  </div>

<?php else: ?>

  <h1 class="gnl-config-title">Mon panier <span class="gnl-cart-count">(<?php echo (int) $cartCount; ?> article<?php echo $cartCount > 1 ? 's' : ''; ?>)</span></h1>

  <div class="gnl-layout">

    <!-- Lignes du panier -->
    <div>
      <?php foreach ($items as $it): $p = $it['product']; ?>
        <div class="gnl-cart-item">
          <div class="gnl-ci-main">
            <h3><?php echo gnl_e($p['name']); ?> <?php echo gnl_flag($p['flag']); ?></h3>
            <div style="font-size:.85rem;opacity:.7"><?php echo gnl_money(gnl_num($p['prix'])); ?> / mois</div>
            <ul class="gnl-ci-opts">
              <?php foreach ($it['options'] as $o): ?>
                <li>&#8627; <?php echo gnl_e($o['name']); ?><?php echo $o['unique'] ? ' (unique)' : ''; ?> — <?php echo $o['prix'] > 0 ? '+ ' . gnl_money($o['prix']) : 'incluse'; ?></li>
              <?php endforeach; ?>
              <?php if ($it['domaine']): ?>
                <li>&#8627; Domaine&nbsp;: <strong><?php echo gnl_e($it['domaine']['name']); ?></strong><?php echo $it['domaine']['price'] !== '' ? ' — ' . gnl_e($it['domaine']['price']) : ''; ?></li>
              <?php endif; ?>
            </ul>
          </div>
          <div class="gnl-ci-side">
            <div class="gnl-ci-price"><?php echo gnl_money($it['line_monthly']); ?><small>/ mois</small></div>
            <?php if ($it['line_once'] > 0): ?><div class="gnl-ci-once">+ <?php echo gnl_money($it['line_once']); ?> (frais uniques)</div><?php endif; ?>
            <div class="gnl-qty">
              <a href="cart?action=qty&amp;id=<?php echo rawurlencode($it['id']); ?>&amp;q=<?php echo max(1, $it['qty'] - 1); ?>" aria-label="Diminuer">&minus;</a>
              <span><?php echo (int) $it['qty']; ?></span>
              <a href="cart?action=qty&amp;id=<?php echo rawurlencode($it['id']); ?>&amp;q=<?php echo $it['qty'] + 1; ?>" aria-label="Augmenter">+</a>
            </div>
            <a class="gnl-ci-remove" href="cart?action=remove&amp;id=<?php echo rawurlencode($it['id']); ?>">Retirer</a>
          </div>
        </div>
      <?php endforeach; ?>

      <div class="gnl-btns">
        <a class="gnl-btn ghost" href="/solutions">&larr; Continuer mes achats</a>
        <a class="gnl-btn danger" href="cart?action=clear">Vider le panier</a>
      </div>
    </div>

    <!-- Résumé -->
    <aside>
      <div class="gnl-summary">
        <h3>Récapitulatif</h3>
        <ul class="gnl-sum-lines">
          <?php foreach ($items as $it): ?>
            <li>
              <span><?php echo gnl_e($it['product']['name']); ?><?php echo $it['qty'] > 1 ? ' &times;' . (int) $it['qty'] : ''; ?></span>
              <span><?php echo gnl_money($it['line_monthly']); ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
        <div class="gnl-sum-total"><span>Total <small>/ mois</small></span><span><?php echo gnl_money($cartMonthly); ?></span></div>
        <?php if ($cartOnce > 0): ?>
          <div class="gnl-sum-total" style="font-size:.98rem;font-weight:600"><span>Frais uniques</span><span><?php echo gnl_money($cartOnce); ?></span></div>
        <?php endif; ?>

        <?php $gnlUser = (isset($_SESSION['gnl_user']) && is_array($_SESSION['gnl_user'])) ? $_SESSION['gnl_user'] : null; ?>
        <a class="gnl-cta" style="text-decoration:none" href="commande"><?php echo $gnlUser ? 'Passer la commande' : 'Se connecter et commander'; ?></a>
        <a class="gnl-back" href="/solutions">&larr; Retour à la boutique</a>
      </div>
    </aside>

  </div>

<?php endif; ?>

</div>
</main>
<?php gnl_ui_close(); ?>
