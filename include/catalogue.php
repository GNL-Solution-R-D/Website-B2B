<?php
/* =====================================================================
   GNL Solution — Catalogue (produits + partenaires)
   Repris tel quel de l'ancien index.php ; utilisé par la page d'accueil
   et par /solutions.
   ---------------------------------------------------------------------
   Ancien en-tête :
   GNL Solution — Boutique dynamique
   ---------------------------------------------------------------------
   La liste des produits provient de la datatable n8n "product",
   interrogée via le webhook POST :
        https://n8n.gnl-cloud.us/webhook/boutique
   En cas d'indisponibilité du webhook, on retombe automatiquement sur
   le fichier local product.csv (même structure de colonnes).

   Colonnes attendues :
   id, datacenter_flag, type, slug, name, stitre, description,
   categorie_id, stock, stock_limite, option, prix_mensuel,
   kubernete_config, createdAt, updatedAt

   type        : web_pro | web_edu | soon
   categorie_id: 1=PHP  2=WordPress  3=DEV  4=Étudiant  5=Cloud/À venir
   ===================================================================== */

if (!defined('GNL_WEBHOOK_URL')) {
    define('GNL_WEBHOOK_URL', 'https://n8n.gnl-cloud.us/webhook/boutique');
}
if (!defined('GNL_CSV_FALLBACK')) {
    define('GNL_CSV_FALLBACK', is_readable(dirname(__DIR__) . '/product.csv') ? dirname(__DIR__) . '/product.csv' : dirname(__DIR__) . '/pages/product.csv');
}
if (!defined('GNL_CACHE_TTL')) {
    define('GNL_CACHE_TTL', 300); // durée du cache local en secondes
}

/* --- Partenaires ("Nous avons contribué à leurs projets") -----------
   Source : datatable n8n "partner" via le MÊME webhook que la boutique
        https://n8n.gnl-cloud.us/webhook/boutique   (action = partner.list)
   Repli automatique sur partner.csv (mêmes colonnes) si indisponible.
   Colonnes attendues : id, comercial_name, url, banner_logo_file,
                        createdAt, updatedAt
   ------------------------------------------------------------------ */
if (!defined('GNL_PARTNER_CSV_FALLBACK')) {
    define('GNL_PARTNER_CSV_FALLBACK', dirname(__DIR__) . '/partner.csv');
}

/* --- Appel du webhook n8n (POST) -> corps brut de la réponse --------- */
function gnl_fetch_from_webhook() {
    $payload = json_encode(array('source' => 'site', 'action' => 'product.list'));

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
            return gnl_decode_rows($body);
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
    return gnl_decode_rows($body);
}

/* --- Décodage tolérant de la réponse JSON en liste de lignes --------- */
function gnl_decode_rows($body) {
    $data = json_decode($body, true);
    if (!is_array($data)) return null;

    // Déballe les enveloppes n8n les plus courantes
    foreach (array('data', 'products', 'rows', 'items', 'result') as $k) {
        if (isset($data[$k]) && is_array($data[$k])) { $data = $data[$k]; break; }
    }
    // Objet unique -> liste à un élément
    if (isset($data['id']) || isset($data['name']) || isset($data['slug'])) {
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

/* --- Repli sur product.csv ------------------------------------------ */
function gnl_fetch_from_csv() {
    if (!is_readable(GNL_CSV_FALLBACK)) return array();
    $rows = array();
    if (($h = fopen(GNL_CSV_FALLBACK, 'r')) !== false) {
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

/* --- Chargement (webhook -> cache -> csv) + normalisation ------------ */
function gnl_load_products() {
    $cacheFile = sys_get_temp_dir() . '/gnl_products_cache.json';

    // Cache frais ?
    if (is_readable($cacheFile) && (time() - filemtime($cacheFile) < GNL_CACHE_TTL)) {
        $cached = json_decode(@file_get_contents($cacheFile), true);
        if (is_array($cached) && $cached) return gnl_normalize($cached);
    }

    $rows = gnl_fetch_from_webhook();
    if ($rows) {
        @file_put_contents($cacheFile, json_encode($rows)); // best effort
    } else {
        $rows = gnl_fetch_from_csv();
    }
    return gnl_normalize($rows);
}

/* --- Normalisation d'une ligne (clés stables) ----------------------- */
function gnl_normalize($rows) {
    $out = array();
    if (!is_array($rows)) return $out;
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $g = function ($k) use ($r) { return isset($r[$k]) ? $r[$k] : ''; };
        /* Colonnes traduites facultatives dans la datatable n8n : name_de, stitre_de, description_de… */
        $lg = function_exists('gnl_lang') ? gnl_lang() : 'fr';
        $gt = function ($k) use ($r, $lg) { return (isset($r[$k . '_' . $lg]) && trim((string) $r[$k . '_' . $lg]) !== '') ? $r[$k . '_' . $lg] : (isset($r[$k]) ? $r[$k] : ''); };
        $out[] = array(
            'id'           => $g('id'),
            'flag'         => strtoupper(trim((string) $g('datacenter_flag'))),
            'type'         => strtolower(trim((string) $g('type'))),
            'slug'         => trim((string) $g('slug')),
            'name'         => trim((string) $gt('name')),
            'stitre'       => trim((string) $gt('stitre')),
            'description'  => trim((string) $gt('description')),
            'categorie'    => (int) $g('categorie_id'),
            'stock'        => $g('stock'),
            'stock_limite' => $g('stock_limite'),
            'option'       => $g('option'),
            'prix'         => $g('prix_mensuel'),
        );
    }
    return $out;
}

/* --- Sélection ------------------------------------------------------- */
function gnl_where($products, $type = null, $categorie = null) {
    $res = array();
    foreach ($products as $p) {
        if ($type !== null && $p['type'] !== $type) continue;
        if ($categorie !== null && $p['categorie'] !== (int) $categorie) continue;
        $res[] = $p;
    }
    return $res;
}

/* --- Helpers d'affichage -------------------------------------------- */
function gnl_e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

function gnl_price($v) {
    if ($v === '' || $v === null) return '';
    $f = (float) str_replace(array(' ', ','), array('', '.'), (string) $v);
    if ($f <= 0) return '';
    return number_format($f, 2, ',', ' ') . "\xc2\xa0€";
}

function gnl_desc_html($d) {
    $d = trim(strip_tags((string) $d));
    if ($d === '') return '';
    return nl2br(gnl_e($d));
}

function gnl_flag($code) {
    $code = strtoupper((string) $code);
    if ($code === 'FR') {
        return '<figure class="wp-block-image alignright size-full is-resized" style="margin-bottom:0">'
             . '<img decoding="async" width="1000" height="563" src="/assets/img/icon-flag-edited.png" '
             . 'alt="Datacenter France" class="wp-image-573" style="width:20px;height:auto" /></figure>';
    }
    $emoji = array('DE' => '🇩🇪', 'SW' => '🇨🇭', 'CH' => '🇨🇭', 'BE' => '🇧🇪', 'FR' => '🇫🇷');
    $flag = isset($emoji[$code]) ? $emoji[$code] : '🌍';
    return '<figure class="wp-block-image alignright is-resized" style="margin:0 0 0 auto">'
         . '<span style="font-size:18px;line-height:1" title="Datacenter ' . gnl_e($code) . '">' . $flag . '</span></figure>';
}

/* --- Partenaires : webhook n8n (POST) -> corps brut ---------------- */
function gnl_fetch_partners_from_webhook() {
    $payload = json_encode(array('source' => 'site', 'action' => 'partner.list'));

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
            return gnl_decode_partner_rows($body);
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
    return gnl_decode_partner_rows($body);
}

/* --- Décodage tolérant de la réponse JSON partenaires -------------- */
function gnl_decode_partner_rows($body) {
    $data = json_decode($body, true);
    if (!is_array($data)) return null;

    // Déballe les enveloppes n8n les plus courantes
    foreach (array('data', 'partners', 'partner', 'rows', 'items', 'result') as $k) {
        if (isset($data[$k]) && is_array($data[$k])) { $data = $data[$k]; break; }
    }
    // Objet unique -> liste à un élément
    if (isset($data['id']) || isset($data['comercial_name']) || isset($data['banner_logo_file'])) {
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

/* --- Repli sur partner.csv ----------------------------------------- */
function gnl_fetch_partners_from_csv() {
    if (!is_readable(GNL_PARTNER_CSV_FALLBACK)) return array();
    $rows = array();
    if (($h = fopen(GNL_PARTNER_CSV_FALLBACK, 'r')) !== false) {
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

/* --- Chargement partenaires (webhook -> cache -> csv) -------------- */
function gnl_load_partners() {
    $cacheFile = sys_get_temp_dir() . '/gnl_partners_cache.json';

    // Cache frais ?
    if (is_readable($cacheFile) && (time() - filemtime($cacheFile) < GNL_CACHE_TTL)) {
        $cached = json_decode(@file_get_contents($cacheFile), true);
        if (is_array($cached) && $cached) return gnl_normalize_partners($cached);
    }

    $rows = gnl_fetch_partners_from_webhook();
    if ($rows) {
        @file_put_contents($cacheFile, json_encode($rows)); // best effort
    } else {
        $rows = gnl_fetch_partners_from_csv();
    }
    return gnl_normalize_partners($rows);
}

/* --- Normalisation d'une ligne partenaire (clés stables) ---------- */
function gnl_normalize_partners($rows) {
    $out = array();
    if (!is_array($rows)) return $out;
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $g = function ($k) use ($r) { return isset($r[$k]) ? $r[$k] : ''; };
        $name = trim((string) $g('comercial_name'));
        $logo = trim((string) $g('banner_logo_file'));
        if ($name === '' && $logo === '') continue; // ligne vide -> ignorée
        $out[] = array(
            'id'   => $g('id'),
            'name' => $name,
            'url'  => trim((string) $g('url')),
            'logo' => $logo,
        );
    }
    return $out;
}


/* =====================================================================
   Aides d'affichage pour la nouvelle charte
   ===================================================================== */

/* Prix mensuel le plus bas d'un type d'offre (web_pro, web_edu…), formaté. */
function gnl_cat_min_price($products, $type) {
    $min = null;
    foreach (gnl_where($products, $type) as $p) {
        $f = (float) str_replace(array(' ', ','), array('', '.'), (string) $p['prix']);
        if ($f > 0 && ($min === null || $f < $min)) $min = $f;
    }
    return $min === null ? '' : number_format($min, 2, ',', ' ') . "\xc2\xa0€";
}

/* Logos partenaires (assets/img/partener_brand/<fichier>), en grille. */
/* Logo réduit (128 px de haut, WebP) dans assets/img/partener_brand/mini/ : les originaux
   pèsent jusqu'à 1,3 Mo pour un affichage de 64 px. Créé automatiquement si GD le permet. */
function gnl_cat_partner_logo($logo) {
    $dir  = dirname(__DIR__) . '/assets/img/partener_brand/';
    $base = basename($logo);
    $mini = 'mini/' . pathinfo($base, PATHINFO_FILENAME) . '.webp';
    if (!is_file($dir . $mini) && is_file($dir . $base) && function_exists('imagewebp') && is_writable($dir . 'mini')) {
        $data = @file_get_contents($dir . $base);
        $im = $data !== false ? @imagecreatefromstring($data) : false;
        if ($im) {
            $w = imagesx($im); $h = imagesy($im);
            $nh = min(128, $h); $nw = max(1, (int) round($w * $nh / $h));
            if ($nw > 360) { $nw = 360; $nh = max(1, (int) round($h * $nw / $w)); }
            $out = imagecreatetruecolor($nw, $nh);
            imagealphablending($out, false); imagesavealpha($out, true);
            imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
            imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
            @imagewebp($out, $dir . $mini, 82);
        }
    }
    if (is_file($dir . $mini)) return array('/assets/img/partener_brand/' . $mini, $dir . $mini);
    return array('/assets/img/partener_brand/' . $base, $dir . $base);
}

function gnl_cat_render_partners($partners) {
    foreach ((array) $partners as $p) {
        $logo = ltrim((string) $p['logo'], '/');
        if ($logo === '') continue;
        $name = gnl_e($p['name']);
        /* Petits logos (≤ 6 Ko) intégrés dans la page : une requête HTTP de moins par partenaire (EcoIndex). */
        list($src, $file) = gnl_cat_partner_logo($logo);
        $mime = array('webp' => 'image/webp', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'svg' => 'image/svg+xml', 'gif' => 'image/gif');
        $ext  = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (isset($mime[$ext]) && is_file($file) && filesize($file) <= 6144) {
            $src = 'data:' . $mime[$ext] . ';base64,' . base64_encode((string) file_get_contents($file));
        }
        $img  = '<img decoding="async" src="' . gnl_e($src) . '" alt="' . $name . '" style="max-width:100%;max-height:64px;width:auto;object-fit:contain">';
        $box  = 'height:88px;border:1px solid #e3e6ee;border-radius:3px;display:flex;align-items:center;justify-content:center;padding:12px;background:#fff';
        if (trim((string) $p['url']) !== '') {
            echo '<a href="' . gnl_e($p['url']) . '" title="' . $name . '" target="_blank" rel="noopener noreferrer" style="' . $box . '">' . $img . '</a>' . "\n";
        } else {
            echo '<div style="' . $box . '">' . $img . '</div>' . "\n";
        }
    }
}

/* Options de produit (webhook "option.list" -> cache -> product_option.csv),
   mêmes colonnes que dans pages/product-configuration.php. */
function gnl_cat_load_options() {
    $cacheFile = sys_get_temp_dir() . '/gnl_options_cache.json';
    $rows = null;
    if (is_readable($cacheFile) && (time() - filemtime($cacheFile) < GNL_CACHE_TTL)) {
        $cached = json_decode(@file_get_contents($cacheFile), true);
        if (is_array($cached) && $cached) $rows = $cached;
    }
    if ($rows === null) {
        $payload = json_encode(array('source' => 'site', 'action' => 'option.list'));
        $body = false;
        if (function_exists('curl_init')) {
            $ch = curl_init(GNL_WEBHOOK_URL);
            curl_setopt_array($ch, array(
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'Accept: application/json'),
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4,
            ));
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($code < 200 || $code >= 300) $body = false;
        }
        if ($body !== false) {
            $data = json_decode($body, true);
            if (is_array($data)) {
                foreach (array('data', 'options', 'rows', 'items', 'result') as $k) {
                    if (isset($data[$k]) && is_array($data[$k])) { $data = $data[$k]; break; }
                }
                if (isset($data['slug']) || isset($data['option_name'])) $data = array($data);
                $rows = array();
                foreach ($data as $item) {
                    if (!is_array($item)) continue;
                    if (isset($item['json']) && is_array($item['json'])) $item = $item['json'];
                    $rows[] = $item;
                }
                if ($rows) @file_put_contents($cacheFile, json_encode($rows));
            }
        }
        if (!$rows) {
            $csv = dirname(__DIR__) . '/pages/product_option.csv';
            $rows = array();
            if (is_readable($csv) && ($h = fopen($csv, 'r')) !== false) {
                $header = fgetcsv($h);
                if ($header) {
                    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
                    while (($line = fgetcsv($h)) !== false) {
                        if ($line === array(null)) continue;
                        $line = array_slice(array_pad($line, count($header), ''), 0, count($header));
                        $rows[] = array_combine($header, $line);
                    }
                }
                fclose($h);
            }
        }
    }
    $out = array();
    foreach ((array) $rows as $r) {
        if (!is_array($r)) continue;
        $g = function ($k) use ($r) { return isset($r[$k]) ? $r[$k] : ''; };
        $slug = strtolower(trim((string) $g('slug')));
        if ($slug === '') continue;
        $out[] = array(
            'slug'   => $slug,
            'name'   => trim((string) $g('option_name')),
            'desc'   => trim((string) $g('option_desc')),
            'prix'   => (float) str_replace(array(' ', ','), array('', '.'), (string) $g('option_prix')),
            'unique' => in_array(strtolower(trim((string) $g('prix_unique'))), array('1', 'true', 'oui', 'yes', 'on'), true),
        );
    }
    return $out;
}

/* Une offre propose-t-elle des options ? (même règle que product-configuration.php) */
function gnl_cat_has_options($p) {
    $v = strtolower(trim((string) $p['option']));
    return in_array($v, array('1', 'true', 'oui', 'yes', 'on'), true) || $p['type'] === 'web_pro';
}
