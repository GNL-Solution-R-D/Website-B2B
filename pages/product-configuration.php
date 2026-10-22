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
<?php
/* ---- Affichage : nouvelle charte (include/layout.php) ---- */
require_once __DIR__ . '/../include/layout.php';
gnl_ui_open('Configuration de votre offre', 'solutions', array('main' => false));
?>
<main id="contenu">
<div class="gnl-config-wrap">

<?php if (!$product): ?>

  <p class="gnl-breadcrumb"><a href="/">Accueil</a> &rsaquo; Configuration</p>
  <div class="gnl-empty">
    <h2 style="margin-top:0">Offre introuvable</h2>
    <p>Nous n'avons pas retrouvé l'offre demandée. Elle a peut-être été retirée du catalogue.</p>
    <p><a class="gnl-cta" style="display:inline-block;width:auto;text-decoration:none" href="/solutions">Retour à la boutique</a></p>
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

  <p class="gnl-breadcrumb"><a href="/">Accueil</a> &rsaquo; Configuration &rsaquo; <?php echo $name; ?></p>
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
          <a class="gnl-back" href="/solutions">Choisir une autre offre</a>
        </div>
      </div>
    </aside>

  </div>

  <script type="application/json" id="gnl-config-data"><?php echo json_encode($jsData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>

<?php endif; ?>

</div>
</main>
<?php ob_start(); ?>
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

        fetch('/product-configuration?ajax=domain&domain=' + encodeURIComponent(q), { headers:{'Accept':'application/json'} })
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
<?php gnl_ui_close(ob_get_clean()); ?>
