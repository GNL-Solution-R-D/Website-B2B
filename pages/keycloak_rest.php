<?php
/* =====================================================================
   GNL Solution — Keycloak par l'API REST  (keycloak_rest.php)
   ---------------------------------------------------------------------
   Fichier d'AIDE partagé, inclus par /connexion (connexion.php) et
   /inscription (inscription.php). Il ne s'affiche pas seul.

   Contrairement à keycloak_callback.php (qui redirige le visiteur vers
   les pages hébergées par Keycloak), ici TOUT passe par l'API REST :

     • Connexion    -> grant "password" (Direct Access Grant / ROPC)
                       POST {issuer}/protocol/openid-connect/token
     • Inscription  -> Admin REST API (jeton service-account via
                       grant "client_credentials") puis POST /users
     • Mot de passe oublié -> Admin REST API execute-actions-email

   La SESSION produite ($_SESSION['gnl_user']) est IDENTIQUE à celle de
   keycloak_callback.php : /commande et /cart fonctionnent sans changement.

   -------------------- Configuration Keycloak requise -----------------
   Client OIDC "siteweb" (KEYCLOAK_CLIENT_ID) :
     - "Client authentication" = ON (client confidentiel, avec secret)
     - "Direct access grants"  = ON   (indispensable pour la connexion REST)

   Pour l'inscription / mot de passe oublié, un compte de service ayant le
   rôle realm-management "manage-users" est nécessaire. Deux options :
     A) Réutiliser "siteweb" : activez "Service accounts roles" et
        attribuez-lui "manage-users" (+ "view-users"). Laissez alors
        KEYCLOAK_ADMIN_CLIENT_ID / _SECRET vides : on retombe sur siteweb.
     B) Créer un client dédié (ex. "siteweb-admin", confidentiel, service
        account + manage-users) et renseigner KEYCLOAK_ADMIN_CLIENT_ID /
        KEYCLOAK_ADMIN_CLIENT_SECRET.

   Variables d'environnement lues (voir gnl_env plus bas) :
     KEYCLOAK_ISSUER, KEYCLOAK_CLIENT_ID, KEYCLOAK_CLIENT_SECRET,
     KEYCLOAK_SCOPES, KEYCLOAK_ADMIN_CLIENT_ID, KEYCLOAK_ADMIN_CLIENT_SECRET,
     KEYCLOAK_REG_EMAIL_VERIFIED (1/0), KEYCLOAK_REG_VERIFY_EMAIL (1/0),
     KEYCLOAK_LOGO_URL.
   ===================================================================== */

if (!headers_sent()) { /* rien : ce fichier est un include, pas de sortie ici */ }

/* -------- Lecture robuste des variables d'environnement (PHP-FPM) ---- */
if (!function_exists('gnl_env')) {
    function gnl_env($key, $default = '') {
        $v = getenv($key);
        if ($v !== false && $v !== '') return $v;
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return $_SERVER[$key];
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') return $_ENV[$key];
        return $default;
    }
}

/* ---------------------------- Configuration ------------------------- */
if (!defined('KEYCLOAK_ISSUER'))        define('KEYCLOAK_ISSUER',        gnl_env('KEYCLOAK_ISSUER', 'https://auth.gnl-solution.fr/auth/realms/client-auth'));
if (!defined('KEYCLOAK_CLIENT_ID'))     define('KEYCLOAK_CLIENT_ID',     gnl_env('KEYCLOAK_CLIENT_ID', 'siteweb'));
if (!defined('KEYCLOAK_CLIENT_SECRET')) define('KEYCLOAK_CLIENT_SECRET', gnl_env('KEYCLOAK_CLIENT_SECRET', ''));
if (!defined('KEYCLOAK_SCOPES'))        define('KEYCLOAK_SCOPES',        gnl_env('KEYCLOAK_SCOPES', 'openid profile email organization:*'));

/* Compte de service pour l'Admin REST API (repli sur "siteweb" si vide). */
if (!defined('KEYCLOAK_ADMIN_CLIENT_ID'))     define('KEYCLOAK_ADMIN_CLIENT_ID',     gnl_env('KEYCLOAK_ADMIN_CLIENT_ID', KEYCLOAK_CLIENT_ID));
if (!defined('KEYCLOAK_ADMIN_CLIENT_SECRET')) define('KEYCLOAK_ADMIN_CLIENT_SECRET', gnl_env('KEYCLOAK_ADMIN_CLIENT_SECRET', KEYCLOAK_CLIENT_SECRET));

/* Comportement à l'inscription.
   REG_EMAIL_VERIFIED=1  -> le compte est créé "email vérifié" => connexion
                            immédiate possible (bon défaut pour un tunnel
                            d'achat). Passez à 0 pour exiger une vérification.
   REG_VERIFY_EMAIL=1    -> envoie l'e-mail de vérification (VERIFY_EMAIL). */
if (!defined('KEYCLOAK_REG_EMAIL_VERIFIED')) define('KEYCLOAK_REG_EMAIL_VERIFIED', gnl_env('KEYCLOAK_REG_EMAIL_VERIFIED', '1') === '1');
if (!defined('KEYCLOAK_REG_VERIFY_EMAIL'))   define('KEYCLOAK_REG_VERIFY_EMAIL',   gnl_env('KEYCLOAK_REG_VERIFY_EMAIL', '0') === '1');

/* Suffixe de domaine synthétisé pour les organisations créées à l'inscription.
   Keycloak exige (selon la version) au moins un domaine unique par organisation ;
   on génère <alias>.<suffixe> pour garantir unicité et validité. */
if (!defined('KEYCLOAK_ORG_DOMAIN_SUFFIX')) define('KEYCLOAK_ORG_DOMAIN_SUFFIX', gnl_env('KEYCLOAK_ORG_DOMAIN_SUFFIX', 'clients.gnl-solution.fr'));

if (!defined('KEYCLOAK_LOGO_URL')) define('KEYCLOAK_LOGO_URL', gnl_env('KEYCLOAK_LOGO_URL', '/assets/gnl/img/logo-gnl-marine.svg'));

if (!defined('KC_OIDC')) define('KC_OIDC', rtrim(KEYCLOAK_ISSUER, '/') . '/protocol/openid-connect');

if (session_status() === PHP_SESSION_NONE) session_start();

/* ============================ Utilitaires ========================== */
if (!function_exists('gnl_e')) {
    function gnl_e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('gnl_site_base')) {
    function gnl_site_base() {
        static $b = null;
        if ($b !== null) return $b;
        $u = parse_url(KEYCLOAK_ISSUER);
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $host  = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
        return $b = ($https ? 'https' : 'http') . '://' . $host;
    }
}
/* N'autorise que des chemins internes ("/xxx"), défaut /commande. */
if (!function_exists('gnl_safe_return')) {
    function gnl_safe_return($v) {
        $v = (string) $v;
        if ($v === '' || $v[0] !== '/' || (isset($v[1]) && $v[1] === '/')) return '/commande';
        return $v;
    }
}
if (!function_exists('gnl_rand_hex')) {
    function gnl_rand_hex($bytes = 24) {
        try { return bin2hex(random_bytes($bytes)); }
        catch (Exception $e) { return substr(md5(uniqid('', true) . mt_rand()), 0, $bytes * 2); }
    }
}
/* Décode la charge utile d'un JWT (jeton reçu de Keycloak en TLS). */
if (!function_exists('gnl_jwt_payload')) {
    function gnl_jwt_payload($jwt) {
        $parts = explode('.', (string) $jwt);
        if (count($parts) < 2) return array();
        $p = strtr($parts[1], '-_', '+/');
        $p .= str_repeat('=', (4 - strlen($p) % 4) % 4);
        $data = json_decode(base64_decode($p), true);
        return is_array($data) ? $data : array();
    }
}
/* Aplati une table d'attributs Keycloak ({cle:[val]} ou {cle:val}). */
if (!function_exists('gnl_flatten_attrs')) {
    function gnl_flatten_attrs($attrs) {
        $out = array();
        if (!is_array($attrs)) return $out;
        foreach ($attrs as $k => $v) {
            if (in_array($k, array('id', 'name', 'alias', 'attributes'), true)) continue;
            $out[$k] = is_array($v) ? (isset($v[0]) ? (string) $v[0] : '') : (string) $v;
        }
        return $out;
    }
}
/* Identifiant (UUID Keycloak) d'une organisation, quelle que soit la forme
   du claim : {"id":..}, {"uid":..} ou attribut "organization_uid"/"uid". */
if (!function_exists('gnl_org_uid_from')) {
    function gnl_org_uid_from($data) {
        if (!is_array($data)) return '';
        foreach (array('id', 'uid', 'organization_uid', 'organization_id') as $k) {
            if (isset($data[$k])) {
                $v = is_array($data[$k]) ? (isset($data[$k][0]) ? $data[$k][0] : '') : $data[$k];
                $v = trim((string) $v);
                if ($v !== '') return $v;
            }
        }
        if (isset($data['attributes']) && is_array($data['attributes'])) {
            return gnl_org_uid_from($data['attributes']);
        }
        return '';
    }
}
/* Extrait le claim "organization" -> array('name'=>.., 'id'=>.., 'alias'=>.., 'attributes'=>[..]). */
if (!function_exists('gnl_org_extract')) {
    function gnl_org_extract($org) {
        $res = array('name' => '', 'id' => '', 'alias' => '', 'attributes' => array());
        if (!is_array($org) || !$org) return $res;
        $keys = array_keys($org);
        if ($keys === range(0, count($org) - 1)) { $res['name'] = (string) $org[0]; return $res; }
        if (isset($org['attributes']) || isset($org['name']) || isset($org['id']) || isset($org['alias'])) {
            $res['name']  = isset($org['name']) ? (string) $org['name'] : (isset($org['alias']) ? (string) $org['alias'] : '');
            $res['alias'] = isset($org['alias']) ? (string) $org['alias'] : '';
            $res['id']    = gnl_org_uid_from($org);
            $attrs = (isset($org['attributes']) && is_array($org['attributes'])) ? $org['attributes'] : $org;
            $res['attributes'] = gnl_flatten_attrs($attrs);
            return $res;
        }
        foreach ($org as $name => $data) {
            $res['name'] = (string) $name;
            if (is_array($data)) {
                $res['id']    = gnl_org_uid_from($data);
                $res['alias'] = isset($data['alias']) ? (string) $data['alias'] : '';
                $attrs = (isset($data['attributes']) && is_array($data['attributes'])) ? $data['attributes'] : $data;
                $res['attributes'] = gnl_flatten_attrs($attrs);
            }
            break;
        }
        return $res;
    }
}
/* Extrait TOUTES les organisations du claim "organization".
   Retourne une liste array( array('name'=>.., 'attributes'=>[..]), ... ).
   Gère : ["orgA","orgB"], [{name,attributes},..], {"orgA":{..},"orgB":{..}}
   et l'objet unique auto-descriptif {"name":..,"attributes":{..}}. */
if (!function_exists('gnl_org_extract_all')) {
    function gnl_org_extract_all($org) {
        $list = array();
        if (!is_array($org) || !$org) return $list;
        $keys = array_keys($org);
        // Tableau séquentiel : noms simples ou objets
        if ($keys === range(0, count($org) - 1)) {
            foreach ($org as $v) {
                if (is_array($v)) {
                    $name  = isset($v['name']) ? (string) $v['name'] : (isset($v['alias']) ? (string) $v['alias'] : '');
                    $attrs = (isset($v['attributes']) && is_array($v['attributes'])) ? $v['attributes'] : $v;
                    $list[] = array('name' => $name, 'id' => gnl_org_uid_from($v),
                                    'alias' => isset($v['alias']) ? (string) $v['alias'] : '',
                                    'attributes' => gnl_flatten_attrs($attrs));
                } else {
                    $list[] = array('name' => (string) $v, 'id' => '', 'alias' => '', 'attributes' => array());
                }
            }
            return $list;
        }
        // Objet unique auto-descriptif : {"name":..,"attributes":{..}}
        if (isset($org['attributes']) || isset($org['name']) || isset($org['id']) || isset($org['alias'])) {
            $name  = isset($org['name']) ? (string) $org['name'] : (isset($org['alias']) ? (string) $org['alias'] : '');
            $attrs = (isset($org['attributes']) && is_array($org['attributes'])) ? $org['attributes'] : $org;
            $list[] = array('name' => $name, 'id' => gnl_org_uid_from($org),
                            'alias' => isset($org['alias']) ? (string) $org['alias'] : '',
                            'attributes' => gnl_flatten_attrs($attrs));
            return $list;
        }
        // Map indexée par nom d'organisation : {"orgA":{..}, "orgB":{..}}
        foreach ($org as $name => $data) {
            $attrs = array();
            if (is_array($data)) {
                $attrs = (isset($data['attributes']) && is_array($data['attributes'])) ? $data['attributes'] : $data;
            }
            $list[] = array('name' => (string) $name,
                            'id'    => is_array($data) ? gnl_org_uid_from($data) : '',
                            'alias' => (is_array($data) && isset($data['alias'])) ? (string) $data['alias'] : '',
                            'attributes' => gnl_flatten_attrs($attrs));
        }
        return $list;
    }
}
/* Champs "société" d'une organisation -> tableau prêt pour $_SESSION['gnl_user']. */
if (!function_exists('gnl_org_fields')) {
    function gnl_org_fields($org) {
        $oa = (isset($org['attributes']) && is_array($org['attributes'])) ? $org['attributes'] : array();
        $A = function ($k) use ($oa) { return isset($oa[$k]) ? (string) $oa[$k] : ''; };
        $voie = trim($A('voie_nbr') . ' ' . $A('voie_name'));
        return array(
            'organization'       => isset($org['name']) ? (string) $org['name'] : '',
            // UUID Keycloak de l'organisation : transmis tel quel à n8n avec la commande.
            'organization_uid'   => isset($org['id']) ? (string) $org['id'] : '',
            'organization_alias' => (isset($org['alias']) && $org['alias'] !== '') ? (string) $org['alias'] : $A('namespace'),
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
        );
    }
}
/* Libellé lisible d'une organisation pour la page de choix. */
if (!function_exists('gnl_org_label')) {
    function gnl_org_label($org) {
        $oa = (isset($org['attributes']) && is_array($org['attributes'])) ? $org['attributes'] : array();
        $A = function ($k) use ($oa) { return isset($oa[$k]) ? trim((string) $oa[$k]) : ''; };
        $title = $A('nom_commercial');
        if ($title === '') $title = $A('raison');
        if ($title === '') $title = $A('raison_social');
        if ($title === '') $title = isset($org['name']) ? (string) $org['name'] : '';
        if ($title === '') $title = 'Organisation';
        $bits = array();
        if ($A('entite_legal') !== '') $bits[] = $A('entite_legal');
        $loc = trim($A('cp') . ' ' . $A('commune'));
        if ($loc !== '') $bits[] = $loc;
        if ($A('siret') !== '') $bits[] = 'SIRET ' . $A('siret');
        return array('title' => $title, 'sub' => implode(' · ', $bits));
    }
}

/* ---------- Base de l'Admin REST API à partir de l'issuer ------------
   issuer = https://host/auth/realms/<realm>
   -> base admin = https://host/auth/admin/realms/<realm>
      realm      = <realm> */
if (!function_exists('gnl_kc_admin')) {
    function gnl_kc_admin() {
        static $cache = null;
        if ($cache !== null) return $cache;
        $iss = rtrim(KEYCLOAK_ISSUER, '/');
        $realm = ''; $server = $iss;
        $pos = strpos($iss, '/realms/');
        if ($pos !== false) {
            $server = substr($iss, 0, $pos);                 // https://host/auth
            $realm  = trim(substr($iss, $pos + strlen('/realms/')), '/');
        }
        return $cache = array(
            'server' => $server,
            'realm'  => $realm,
            'base'   => $server . '/admin/realms/' . rawurlencode($realm),
        );
    }
}

/* ======================= Transport HTTP (cURL + repli) ==============
   Retourne toujours un tableau : ['_status'=>int, ...json..., '_raw'=>..,
   '_transport'=>..]. Gère GET/POST/PUT, corps form ou JSON, en-tête Auth. */
if (!function_exists('gnl_http')) {
    function gnl_http($method, $url, $body = null, $headers = array()) {
        $method = strtoupper($method);
        $raw = false; $status = 0; $cerr = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $opt = array(
                CURLOPT_CUSTOMREQUEST   => $method,
                CURLOPT_RETURNTRANSFER  => true,
                CURLOPT_TIMEOUT         => 15,
                CURLOPT_CONNECTTIMEOUT  => 6,
                CURLOPT_HTTPHEADER      => $headers,
            );
            if ($body !== null && $body !== '') $opt[CURLOPT_POSTFIELDS] = $body;
            if ($method === 'HEAD') $opt[CURLOPT_NOBODY] = true;
            curl_setopt_array($ch, $opt);
            $raw    = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $cerr   = curl_error($ch);
            $loc    = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            $ehdr   = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            unset($ch); // curl_close déprécié (PHP 8.5)
            if ($raw === false) return array('_status' => 0, '_transport' => ($cerr !== '' ? $cerr : 'curl_failed'));
        } else {
            $hdr = '';
            foreach ($headers as $h) $hdr .= $h . "\r\n";
            $ctx = stream_context_create(array('http' => array(
                'method' => $method, 'header' => $hdr, 'content' => (string) $body,
                'timeout' => 15, 'ignore_errors' => true,
            )));
            $raw = @file_get_contents($url, false, $ctx);
            $status = 0;
            /* Code HTTP récupéré via http_get_last_response_headers() (PHP 8.5+).
               On ne référence JAMAIS la variable http_response_header : sa simple
               présence dans le code fait que PHP 8.5 la peuple et émet une
               dépréciation. Sur PHP < 8.5 (sans cette fonction), le statut reste
               0 en mode flux : le corps JSON d'erreur de Keycloak suffit. */
            if (function_exists('http_get_last_response_headers')) {
                $h = http_get_last_response_headers();
                if (is_array($h)) {
                    foreach ($h as $line) {
                        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) $status = (int) $m[1];
                    }
                }
                if (function_exists('http_clear_last_response_headers')) http_clear_last_response_headers();
            }
            if ($raw === false) return array('_status' => $status, '_transport' => 'stream_failed');
        }
        $json = json_decode((string) $raw, true);
        if (!is_array($json)) $json = array();
        $json['_status'] = $status;
        $json['_raw']    = (string) $raw;
        return $json;
    }
}

/* Résume l'erreur renvoyée par Keycloak, pour l'affichage / les logs. */
if (!function_exists('gnl_kc_detail')) {
    function gnl_kc_detail($resp) {
        if (!is_array($resp)) return '';
        if (!empty($resp['_transport']))        return 'réseau: ' . $resp['_transport'];
        if (!empty($resp['error_description']))  return (string) $resp['error_description'];
        if (!empty($resp['error']))              return (string) $resp['error'];
        if (!empty($resp['errorMessage']))       return (string) $resp['errorMessage'];
        if (!empty($resp['_status']))            return 'HTTP ' . $resp['_status'];
        return '';
    }
}

/* Message d'erreur DÉTAILLÉ de l'Admin REST API (formats variés selon la
   version : errorMessage, errors[], field/error, ou corps brut). */
if (!function_exists('gnl_kc_error_message')) {
    function gnl_kc_error_message($resp) {
        if (!is_array($resp)) return '';
        if (!empty($resp['_transport'])) return 'réseau: ' . $resp['_transport'];
        foreach (array('errorMessage', 'error_description', 'error') as $k) {
            if (!empty($resp[$k]) && is_string($resp[$k])) return $resp[$k];
        }
        if (!empty($resp['errors']) && is_array($resp['errors'])) {
            $parts = array();
            foreach ($resp['errors'] as $e) {
                if (!is_array($e)) continue;
                $m = isset($e['errorMessage']) ? $e['errorMessage'] : (isset($e['error']) ? $e['error'] : '');
                $f = isset($e['field']) ? $e['field'] : (isset($e['attribute']) ? $e['attribute'] : '');
                if ($m !== '') $parts[] = ($f !== '' ? $f . ' : ' : '') . $m;
            }
            if ($parts) return implode(' ; ', $parts);
        }
        if (!empty($resp['field'])) {
            return trim(((string) $resp['field']) . ' : ' . (isset($resp['error']) ? (string) $resp['error'] : ''));
        }
        if (!empty($resp['_raw']) && is_string($resp['_raw'])) {
            $s = trim($resp['_raw']);
            if ($s !== '' && $s[0] !== '<') return substr($s, 0, 300); // pas du HTML
        }
        if (!empty($resp['_status'])) return 'HTTP ' . $resp['_status'];
        return '';
    }
}

/* --------------------------- userinfo ------------------------------- */
if (!function_exists('gnl_kc_userinfo')) {
    function gnl_kc_userinfo($bearer) {
        $r = gnl_http('GET', KC_OIDC . '/userinfo', null, array(
            'Authorization: Bearer ' . $bearer, 'Accept: application/json',
        ));
        if (!is_array($r) || empty($r['_status']) || $r['_status'] < 200 || $r['_status'] >= 300) return null;
        unset($r['_status'], $r['_raw']);
        return $r;
    }
}

/* ================= Grant "password" (connexion REST) ================ */
if (!function_exists('gnl_kc_password_grant')) {
    function gnl_kc_password_grant($username, $password, $scope = null) {
        $scope = $scope !== null ? $scope : KEYCLOAK_SCOPES;
        $do = function ($scope) use ($username, $password) {
            $fields = array(
                'grant_type' => 'password',
                'client_id'  => KEYCLOAK_CLIENT_ID,
                'username'   => $username,
                'password'   => $password,
                'scope'      => $scope,
            );
            if (KEYCLOAK_CLIENT_SECRET !== '') $fields['client_secret'] = KEYCLOAK_CLIENT_SECRET;
            return gnl_http('POST', KC_OIDC . '/token', http_build_query($fields), array(
                'Content-Type: application/x-www-form-urlencoded', 'Accept: application/json',
            ));
        };
        $r = $do($scope);
        /* Si le serveur ne supporte pas le scope dynamique "organization:*"
           (invalid_scope), on retente une fois avec "organization" simple. */
        if (is_array($r) && isset($r['error']) && $r['error'] === 'invalid_scope'
            && strpos($scope, 'organization:*') !== false) {
            $fallback = trim(str_replace('organization:*', 'organization', $scope));
            $r2 = $do($fallback);
            if (is_array($r2) && !empty($r2['access_token'])) return $r2;
        }
        return $r;
    }
}

/* ============= Jeton d'administration (client_credentials) ========== */
if (!function_exists('gnl_kc_admin_token')) {
    function gnl_kc_admin_token() {
        static $tok = null; static $exp = 0;
        if ($tok !== null && time() < $exp - 15) return $tok;
        $fields = array(
            'grant_type'    => 'client_credentials',
            'client_id'     => KEYCLOAK_ADMIN_CLIENT_ID,
            'client_secret' => KEYCLOAK_ADMIN_CLIENT_SECRET,
        );
        $r = gnl_http('POST', KC_OIDC . '/token', http_build_query($fields), array(
            'Content-Type: application/x-www-form-urlencoded', 'Accept: application/json',
        ));
        if (!is_array($r) || empty($r['access_token'])) {
            error_log('[GNL REST] admin token failed: ' . gnl_kc_detail($r));
            return null;
        }
        $tok = (string) $r['access_token'];
        $exp = time() + (isset($r['expires_in']) ? (int) $r['expires_in'] : 60);
        return $tok;
    }
}

/* Requête vers l'Admin REST API (JSON), authentifiée par le service account. */
if (!function_exists('gnl_kc_admin_request')) {
    function gnl_kc_admin_request($method, $path, $payload = null) {
        $bearer = gnl_kc_admin_token();
        if ($bearer === null) return array('_status' => 0, '_transport' => 'no_admin_token');
        $adm  = gnl_kc_admin();
        $url  = $adm['base'] . $path;
        $headers = array('Authorization: Bearer ' . $bearer, 'Accept: application/json');
        $body = null;
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
            $body = json_encode($payload);
        }
        return gnl_http($method, $url, $body, $headers);
    }
}

/* Recherche un utilisateur existant (par e-mail ou identifiant), exact. */
if (!function_exists('gnl_kc_find_user')) {
    function gnl_kc_find_user($field, $value) {
        $q = http_build_query(array($field => $value, 'exact' => 'true', 'max' => 1));
        $r = gnl_http('GET', gnl_kc_admin()['base'] . '/users?' . $q, null, array(
            'Authorization: Bearer ' . gnl_kc_admin_token(), 'Accept: application/json',
        ));
        // gnl_http range la liste JSON dans les clés numériques + _status
        $list = array();
        foreach ($r as $k => $v) { if (is_int($k)) $list[] = $v; }
        return !empty($list) ? $list[0] : null;
    }
}

/* Crée un utilisateur. $attributes = attributs Keycloak (téléphone, civilité…).
   Retourne array('id'=>.., ) en succès, ou array('error'=>message). */
if (!function_exists('gnl_kc_create_user')) {
    function gnl_kc_create_user($email, $password, $firstName, $lastName, $attributes = array()) {
        $required = array();
        if (KEYCLOAK_REG_VERIFY_EMAIL) $required[] = 'VERIFY_EMAIL';

        $build = function ($withAttrs) use ($email, $firstName, $lastName, $password, $attributes, $required) {
            $p = array(
                'username'      => $email,
                'email'         => $email,
                'firstName'     => $firstName,
                'lastName'      => $lastName,
                'enabled'       => true,
                'emailVerified' => (bool) KEYCLOAK_REG_EMAIL_VERIFIED,
                'credentials'   => array(array('type' => 'password', 'value' => $password, 'temporary' => false)),
            );
            if ($withAttrs && !empty($attributes)) $p['attributes'] = (object) $attributes;
            if ($required) $p['requiredActions'] = $required;
            return $p;
        };
        $post = function ($payload) {
            return gnl_http('POST', gnl_kc_admin()['base'] . '/users', json_encode($payload),
                array('Authorization: Bearer ' . gnl_kc_admin_token(),
                      'Content-Type: application/json', 'Accept: application/json'));
        };

        $r  = $post($build(true));
        $st = isset($r['_status']) ? (int) $r['_status'] : 0;

        /* Keycloak 24+ : le User Profile déclaratif refuse les attributs non
           déclarés (civilite, phone…) par un 400. Si des attributs custom
           étaient envoyés, on retente SANS eux pour que le compte se crée
           quand même (attributs abandonnés, à déclarer côté realm si voulus). */
        $attrDropped = false;
        if ($st === 400 && !empty($attributes)) {
            $r2  = $post($build(false));
            $st2 = isset($r2['_status']) ? (int) $r2['_status'] : 0;
            if ($st2 === 201) { $r = $r2; $st = 201; $attrDropped = true; }
        }

        if ($st === 201) {
            $u = gnl_kc_find_user('email', $email);
            return array('id' => $u ? (isset($u['id']) ? $u['id'] : '') : '', 'attr_dropped' => $attrDropped);
        }
        if ($st === 409) return array('error' => 'exists');
        $msg = gnl_kc_error_message($r);
        return array('error' => $msg !== '' ? $msg : ('HTTP ' . $st));
    }
}

/* Déclenche un e-mail d'actions requises (ex. mot de passe oublié). */
if (!function_exists('gnl_kc_execute_actions_email')) {
    function gnl_kc_execute_actions_email($userId, $actions) {
        $q = http_build_query(array('client_id' => KEYCLOAK_CLIENT_ID));
        return gnl_kc_admin_request('PUT', '/users/' . rawurlencode($userId) . '/execute-actions-email?' . $q, $actions);
    }
}

/* ============ Attributs d'ORGANISATION (Admin REST API) ==============
   Les attributs d'une organisation Keycloak sont libres (pas de User Profile
   à déclarer) : écrire une clé absente suffit à créer l'attribut.
   Nécessite le rôle realm-management "manage-organizations" (+ "view-
   organizations") sur le compte de service. */

/* Lit un attribut (1re valeur) d'une organisation. Retourne '' s'il est
   absent, null si Keycloak ne répond pas correctement. */
if (!function_exists('gnl_kc_get_org_attribute')) {
    function gnl_kc_get_org_attribute($orgId, $name) {
        if ($orgId === '' || $name === '') return '';
        $r  = gnl_kc_admin_request('GET', '/organizations/' . rawurlencode($orgId));
        $st = isset($r['_status']) ? (int) $r['_status'] : 0;
        if ($st < 200 || $st >= 300) {
            error_log('[GNL REST] lecture de "' . $name . '" impossible pour l\'organisation ' . $orgId . ' : ' . gnl_kc_error_message($r));
            return null;
        }
        if (!isset($r['attributes'][$name])) return '';
        $v = $r['attributes'][$name];
        return trim(is_array($v) ? (isset($v[0]) ? (string) $v[0] : '') : (string) $v);
    }
}

/* Écrit (ou crée) un attribut sur une organisation, sans toucher aux autres
   attributs ni aux domaines. Retourne true en cas de succès. */
if (!function_exists('gnl_kc_set_org_attribute')) {
    function gnl_kc_set_org_attribute($orgId, $name, $value) {
        if ($orgId === '' || $name === '') return false;
        $path = '/organizations/' . rawurlencode($orgId);
        $r  = gnl_kc_admin_request('GET', $path);
        $st = isset($r['_status']) ? (int) $r['_status'] : 0;
        if ($st < 200 || $st >= 300 || empty($r['id'])) {
            error_log('[GNL REST] organisation ' . $orgId . ' introuvable : ' . gnl_kc_error_message($r));
            return false;
        }
        // Décodage en OBJETS depuis la réponse brute : la représentation est
        // renvoyée à l'identique (domaines, {} vides…), seul l'attribut change.
        $org = json_decode(isset($r['_raw']) ? (string) $r['_raw'] : '', false);
        if (!is_object($org)) return false;
        if (!isset($org->attributes) || !is_object($org->attributes)) $org->attributes = new stdClass();
        $org->attributes->{$name} = array((string) $value);
        unset($org->members, $org->identityProviders); // gérés par d'autres endpoints

        $u  = gnl_kc_admin_request('PUT', $path, $org);
        $st = isset($u['_status']) ? (int) $u['_status'] : 0;
        if ($st >= 200 && $st < 300) return true;
        error_log('[GNL REST] écriture de l\'attribut "' . $name . '" sur l\'organisation ' . $orgId . ' impossible : '
            . ($st === 403 ? 'rôle manage-organizations manquant sur le compte de service' : gnl_kc_error_message($u)));
        return false;
    }
}

/* Identifiant client Mollie (ex. cst_KKaFdk527v) de l'organisation ('' si aucun). */
if (!function_exists('gnl_kc_get_mollie_customer_id')) {
    function gnl_kc_get_mollie_customer_id($orgId) {
        $v = gnl_kc_get_org_attribute((string) $orgId, 'moliecliid');
        return is_string($v) ? $v : '';
    }
}

/* Enregistre l'identifiant client Mollie dans l'attribut "moliecliid" de
   l'organisation (créé s'il n'existe pas). */
if (!function_exists('gnl_kc_save_mollie_customer_id')) {
    function gnl_kc_save_mollie_customer_id($orgId, $customerId) {
        $orgId = (string) $orgId; $customerId = (string) $customerId;
        if ($orgId === '' || $customerId === '') return false;
        return gnl_kc_set_org_attribute($orgId, 'moliecliid', $customerId);
    }
}

/* ==================== Organisations (Admin REST API) ================
   Nécessite que le compte de service porte, en plus de manage-users, les
   rôles realm-management "manage-organizations" (+ "view-organizations"). */

/* Slug ASCII minuscule pour l'alias d'organisation ([a-z0-9-]). */
if (!function_exists('gnl_slug')) {
    function gnl_slug($s) {
        $s = (string) $s;
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if ($t !== false && $t !== '') $s = $t;
        }
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        $s = trim((string) $s, '-');
        return $s !== '' ? $s : ('org-' . substr(gnl_rand_hex(4), 0, 8));
    }
}

/* Retrouve une organisation par son alias exact (ou null). */
if (!function_exists('gnl_kc_find_organization')) {
    function gnl_kc_find_organization($alias) {
        $q = http_build_query(array('search' => $alias, 'max' => 50));
        $r = gnl_http('GET', gnl_kc_admin()['base'] . '/organizations?' . $q, null, array(
            'Authorization: Bearer ' . gnl_kc_admin_token(), 'Accept: application/json',
        ));
        foreach ($r as $k => $v) {
            if (is_int($k) && is_array($v) && isset($v['alias']) && $v['alias'] === $alias) return $v;
        }
        return null;
    }
}

/* Crée une organisation avec ses attributs. $attributes = tableau
   cle => array(valeur). Retourne array('id'=>.., 'alias'=>..) ou array('error'=>..). */
if (!function_exists('gnl_kc_create_organization')) {
    function gnl_kc_create_organization($name, $alias, $attributes) {
        $base = gnl_kc_admin()['base'];
        $hdr  = array('Authorization: Bearer ' . gnl_kc_admin_token(), 'Content-Type: application/json', 'Accept: application/json');
        $suffix = KEYCLOAK_ORG_DOMAIN_SUFFIX;
        $build = function ($alias, $withDomain) use ($name, $attributes, $suffix) {
            $p = array('name' => $name, 'alias' => $alias, 'enabled' => true, 'attributes' => (object) $attributes);
            if ($withDomain) $p['domains'] = array(array('name' => $alias . '.' . $suffix, 'verified' => false));
            return $p;
        };
        $post = function ($payload) use ($base, $hdr) {
            return gnl_http('POST', $base . '/organizations', json_encode($payload), $hdr);
        };

        $usedAlias = $alias;
        $r  = $post($build($usedAlias, true));
        $st = isset($r['_status']) ? (int) $r['_status'] : 0;

        // Conflit (nom / alias / domaine déjà pris) -> alias + domaine suffixés
        if ($st === 409) {
            $usedAlias = $alias . '-' . substr(gnl_rand_hex(3), 0, 6);
            $r  = $post($build($usedAlias, true));
            $st = isset($r['_status']) ? (int) $r['_status'] : 0;
        }
        // Domaine refusé par cette version de Keycloak -> réessai sans domaine
        if ($st !== 201 && stripos(gnl_kc_detail($r), 'domain') !== false) {
            $r  = $post($build($usedAlias, false));
            $st = isset($r['_status']) ? (int) $r['_status'] : 0;
        }

        if ($st === 201) {
            $org = gnl_kc_find_organization($usedAlias);
            return array('id' => ($org && isset($org['id'])) ? $org['id'] : '', 'alias' => $usedAlias);
        }
        if ($st === 403) return array('error' => 'droits insuffisants (rôle manage-organizations manquant sur le compte de service)');
        $msg = gnl_kc_error_message($r);
        return array('error' => $msg !== '' ? $msg : ('HTTP ' . $st));
    }
}

/* Ajoute un utilisateur comme membre d'une organisation. */
if (!function_exists('gnl_kc_org_add_member')) {
    function gnl_kc_org_add_member($orgId, $userId) {
        $url  = gnl_kc_admin()['base'] . '/organizations/' . rawurlencode($orgId) . '/members';
        $auth = 'Authorization: Bearer ' . gnl_kc_admin_token();
        // Corps = id du membre en chaîne JSON.
        $r  = gnl_http('POST', $url, json_encode($userId), array($auth, 'Content-Type: application/json', 'Accept: application/json'));
        $st = isset($r['_status']) ? (int) $r['_status'] : 0;
        if ($st === 201 || $st === 204 || $st === 409) return true; // 409 = déjà membre
        // Repli : certaines versions attendent l'id brut en text/plain.
        $r  = gnl_http('POST', $url, (string) $userId, array($auth, 'Content-Type: text/plain', 'Accept: application/json'));
        $st = isset($r['_status']) ? (int) $r['_status'] : 0;
        return ($st === 201 || $st === 204 || $st === 409);
    }
}

/* Supprime une organisation (utilisé pour nettoyer en cas d'échec). */
if (!function_exists('gnl_kc_delete_organization')) {
    function gnl_kc_delete_organization($orgId) {
        if ($orgId === '') return false;
        $r = gnl_http('DELETE', gnl_kc_admin()['base'] . '/organizations/' . rawurlencode($orgId), null,
            array('Authorization: Bearer ' . gnl_kc_admin_token(), 'Accept: application/json'));
        $st = isset($r['_status']) ? (int) $r['_status'] : 0;
        return ($st >= 200 && $st < 300);
    }
}

/* ============ Construit $_SESSION['gnl_user'] depuis un jeton ========
   Retourne :
     false     -> échec (jeton/userinfo invalide)
     'done'    -> connexion finalisée (0 ou 1 organisation)
     'choose'  -> l'utilisateur appartient à ≥ 2 organisations : un état
                  d'attente est mémorisé et /organisation doit être affiché.
   La session produite reste IDENTIQUE à keycloak_callback.php une fois
   finalisée : /commande et /cart sont inchangés. */
if (!function_exists('gnl_kc_populate_session')) {
    function gnl_kc_populate_session($tok) {
        if (!is_array($tok) || empty($tok['access_token'])) return false;
        $ui = gnl_kc_userinfo($tok['access_token']);
        if (!$ui || empty($ui['sub'])) return false;

        // Claims fusionnés depuis les TROIS sources possibles. Selon la
        // configuration du mapper Keycloak, le claim "organization" peut
        // n'être présent que dans l'access token (cas fréquent) : on le lit
        // donc aussi, sinon les organisations passent inaperçues.
        $atc = !empty($tok['access_token']) ? gnl_jwt_payload($tok['access_token']) : array();
        $itc = !empty($tok['id_token'])     ? gnl_jwt_payload($tok['id_token'])     : array();
        $uic = is_array($ui) ? $ui : array();
        // userinfo/id_token en dernier -> l'identité qu'ils portent l'emporte.
        $claims = array_merge($atc, $itc, $uic);
        $get = function ($k, $d = '') use ($claims) { return (isset($claims[$k]) && $claims[$k] !== null) ? $claims[$k] : $d; };
        $given  = (string) $get('given_name');
        $family = (string) $get('family_name');
        $phone  = $get('phone_number') !== '' ? $get('phone_number') : $get('phone');

        // Identité (indépendante de l'organisation)
        $base = array(
            'sub'         => $ui['sub'],
            'email'       => (string) $get('email'),
            'given_name'  => $given,
            'family_name' => $family,
            'name'        => $get('name') !== '' ? (string) $get('name') : trim($given . ' ' . $family),
            'civilite'    => (string) $get('civilite'),
            'phone'       => (string) $phone,
        );
        $idToken = !empty($tok['id_token']) ? (string) $tok['id_token'] : '';

        // Organisation : on retient la source qui en fournit le PLUS (une
        // source peut ne donner qu'un booléen ou un seul nom, une autre la
        // liste complète avec attributs).
        $orgs = array();
        foreach (array($atc, $itc, $uic) as $src) {
            if (isset($src['organization'])) {
                $cand = gnl_org_extract_all($src['organization']);
                if (count($cand) > count($orgs)) $orgs = $cand;
            }
        }
        if (gnl_env('KEYCLOAK_DEBUG') === '1') {
            error_log('[GNL REST] organization — access=' . json_encode(isset($atc['organization']) ? $atc['organization'] : null)
                . ' | id=' . json_encode(isset($itc['organization']) ? $itc['organization'] : null)
                . ' | userinfo=' . json_encode(isset($uic['organization']) ? $uic['organization'] : null)
                . ' => ' . count($orgs) . ' org(s) détectée(s)');
        }

        // 0 ou 1 organisation : on finalise immédiatement.
        if (count($orgs) <= 1) {
            $org = count($orgs) === 1 ? $orgs[0] : array('name' => '', 'attributes' => array());
            gnl_finalize_login($base, $org, $idToken);
            return 'done';
        }

        // ≥ 2 organisations : on mémorise l'état d'attente, l'utilisateur
        // choisira sur /organisation. Tant qu'aucun choix n'est fait, il
        // n'est PAS considéré connecté (gnl_user absent).
        $_SESSION['gnl_pending_auth'] = array(
            'base'     => $base,
            'orgs'     => $orgs,
            'id_token' => $idToken,
            't'        => time(),
        );
        unset($_SESSION['gnl_user']);
        session_regenerate_id(true); // anti-fixation dès la vérification des identifiants
        return 'choose';
    }
}

/* Finalise la connexion avec une organisation donnée (0..1 org). */
if (!function_exists('gnl_finalize_login')) {
    function gnl_finalize_login($base, $org, $idToken = '') {
        $fields = gnl_org_fields(is_array($org) ? $org : array('name' => '', 'attributes' => array()));
        /* Repli : si le mapper Keycloak n'ajoute pas l'id de l'organisation au
           jeton ("Add organization id" désactivé), on le retrouve via l'Admin
           REST API à partir de l'alias / namespace. Sans cela, organization_uid
           serait vide dans la commande envoyée à n8n. */
        if ($fields['organization_uid'] === '' && $fields['organization_alias'] !== ''
            && function_exists('gnl_kc_find_organization')) {
            $found = gnl_kc_find_organization($fields['organization_alias']);
            if (is_array($found) && !empty($found['id'])) $fields['organization_uid'] = (string) $found['id'];
        }
        if ($fields['organization_uid'] === '' && $fields['organization'] !== '') {
            error_log('[GNL REST] organization_uid introuvable pour "' . $fields['organization'] . '" — activez « Add organization id » sur le mapper du scope organization.');
        }
        $_SESSION['gnl_user'] = array_merge($base, $fields, array('auth_at' => time()));
        if ($idToken !== '') $_SESSION['gnl_id_token'] = $idToken;
        unset($_SESSION['gnl_pending_auth']);
        session_regenerate_id(true); // anti-fixation, conserve le panier
        return true;
    }
}

/* État d'attente "choix d'organisation" (ou null si absent/expiré, 15 min). */
if (!function_exists('gnl_pending_auth')) {
    function gnl_pending_auth() {
        if (empty($_SESSION['gnl_pending_auth']) || !is_array($_SESSION['gnl_pending_auth'])) return null;
        $p = $_SESSION['gnl_pending_auth'];
        if (empty($p['orgs']) || !is_array($p['orgs'])) { unset($_SESSION['gnl_pending_auth']); return null; }
        if (time() - (int) (isset($p['t']) ? $p['t'] : 0) > 900) { unset($_SESSION['gnl_pending_auth']); return null; }
        return $p;
    }
}

/* Valide et applique le choix d'organisation (index dans la liste en attente). */
if (!function_exists('gnl_finalize_org_choice')) {
    function gnl_finalize_org_choice($idx) {
        $p = gnl_pending_auth();
        if (!$p) return false;
        $orgs = $p['orgs'];
        $idx = (int) $idx;
        if ($idx < 0 || $idx >= count($orgs)) return false;
        gnl_finalize_login($p['base'], $orgs[$idx], isset($p['id_token']) ? $p['id_token'] : '');
        return true;
    }
}

/* Traduit les erreurs Keycloak en messages FR compréhensibles. */
if (!function_exists('gnl_login_error_fr')) {
    function gnl_login_error_fr($resp) {
        $err  = is_array($resp) && isset($resp['error']) ? (string) $resp['error'] : '';
        $desc = is_array($resp) && isset($resp['error_description']) ? (string) $resp['error_description'] : '';
        $d = strtolower($desc);
        if (!empty($resp['_transport']))                              return "Service d'authentification injoignable. Réessayez dans un instant.";
        if ($err === 'invalid_grant' && strpos($d, 'not fully set up') !== false)
                                                                       return "Votre compte n'est pas encore finalisé (e-mail à vérifier ou action requise). Consultez votre boîte mail.";
        if ($err === 'invalid_grant' && strpos($d, 'disabled') !== false)
                                                                       return "Ce compte est désactivé. Contactez le support.";
        if ($err === 'invalid_grant')                                 return "Identifiant ou mot de passe incorrect.";
        if ($err === 'unauthorized_client' || strpos($d, 'direct access') !== false)
                                                                       return "La connexion directe n'est pas activée côté serveur (Direct access grants).";
        if ($err === 'invalid_client')                                return "Configuration client invalide (secret manquant ou erroné).";
        if ($desc !== '')                                             return $desc;
        return "Connexion impossible. Réessayez.";
    }
}

/* ------------------------------ CSRF -------------------------------- */
if (!function_exists('gnl_csrf_token')) {
    function gnl_csrf_token() {
        if (empty($_SESSION['gnl_csrf'])) $_SESSION['gnl_csrf'] = gnl_rand_hex(24);
        return $_SESSION['gnl_csrf'];
    }
}
if (!function_exists('gnl_csrf_check')) {
    function gnl_csrf_check() {
        $t = isset($_POST['csrf']) ? (string) $_POST['csrf'] : '';
        return $t !== '' && !empty($_SESSION['gnl_csrf']) && hash_equals($_SESSION['gnl_csrf'], $t);
    }
}

/* ==================== Gabarit visuel (maquette GNL) ===================
   Reprend la fenêtre « Connexion aux services GNL » de la maquette (canvas
   Design, artboard Panier) en page autonome : fond voilé marine, carte
   blanche à bandeau marine, onglets « J'ai un compte / Créer un compte »,
   panneau « Informations de l'organisation » à gauche pour les entreprises.
   La logique (Keycloak, CSRF, redirections) est inchangée. */
if (!function_exists('gnl_auth_head')) {
    function gnl_auth_head($title, $active = 'connexion', $bare = false, $mainClass = '') {
        $GLOBALS['gnl_auth_bare'] = $bare;
        header('Content-Type: text/html; charset=UTF-8');
        $lang = function_exists('gnl_lang') ? gnl_lang() : 'fr';
        ?><!DOCTYPE html>
<html lang="<?php echo gnl_e($lang); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo gnl_e($title); ?> — GNL Solution</title>
<link rel="icon" href="/assets/gnl/img/logo-gnl-icone.svg" type="image/svg+xml">
<link rel="icon" href="/assets/gnl/img/favicon-32.png" sizes="32x32">
<link rel="stylesheet" href="/assets/gnl/gnl.css">
<style>
/* Fond : la page du site voilée par rgba(20,26,51,.72), comme la fenêtre de la maquette */
body{margin:0;min-height:100vh;background:#535869;color:var(--navy-950);font-family:var(--font-display);display:flex;flex-direction:column}
.gnl-auth{flex:1 0 auto;width:100%;box-sizing:border-box;padding:24px;display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:16px}
.gnl-auth *{box-sizing:border-box}
.gnl-auth a{color:var(--green-700)}
.gnl-auth a:hover{color:var(--navy-950)}
.gnl-auth input:focus-visible,.gnl-auth select:focus-visible,.gnl-auth button:focus-visible,.gnl-auth a:focus-visible{outline:2px solid var(--green-700);outline-offset:2px}
/* Fenêtre principale */
.gnl-dlg{flex:0 1 500px;min-width:0;background:#fff;color:var(--navy-950);border-radius:3px;overflow:hidden}
.gnl-dlg-head{background:var(--navy-950);color:#fff;padding:24px 28px;display:flex;gap:16px;align-items:flex-start;justify-content:space-between}
.gnl-dlg-head img{height:48px;width:auto;margin:-6px 0 6px -14px;display:block}
.gnl-dlg-title{margin:0;font-size:22px;line-height:28px;font-weight:800;color:#fff}
.gnl-close{flex:none;width:44px;height:44px;margin:-6px -10px 0 0;border-radius:3px;color:#fff !important;display:flex;align-items:center;justify-content:center}
.gnl-close:hover{background:var(--navy-800)}
.gnl-dlg-body{padding:28px}
.gnl-dlg-intro{margin:0 0 20px;font-size:15px;line-height:23px;color:var(--slate-500)}
.gnl-dlg-foot{margin:0;padding:14px 28px;border-top:1px solid var(--line-200);display:flex;gap:8px;align-items:center;font-size:13px;color:var(--slate-500)}
.gnl-dlg-body>h1{margin:0 0 6px;font-size:22px;line-height:28px;font-weight:800}
.gnl-dlg-body>.sub{margin:0 0 20px;font-size:15px;line-height:23px;color:var(--slate-500)}
.gnl-alt{margin:20px 0 0;text-align:center;font-size:15px;color:var(--slate-500)}
.gnl-alt a{font-weight:700}
/* Onglets */
.gnl-tabs{display:grid;grid-template-columns:1fr 1fr;border:1px solid var(--silver-300);border-radius:3px;padding:4px;gap:4px;margin:0 0 24px}
.gnl-tabs a{min-height:40px;display:flex;align-items:center;justify-content:center;text-align:center;padding:0 8px;border-radius:2px;font:700 14px var(--font-display);color:var(--navy-950) !important;text-decoration:none}
.gnl-tabs a:hover{background:var(--paper-50)}
.gnl-tabs a[aria-current]{background:var(--navy-950);color:#fff !important}
/* Formulaires */
.gnl-form{display:flex;flex-direction:column;gap:16px;margin:0}
.gnl-form.login{gap:18px}
.gnl-form h2{margin:0 0 4px;font-size:18px;line-height:24px;font-weight:800}
.gnl-form .sub,.gnl-orgpanel .sub{margin:0;font-size:14px;color:var(--slate-500)}
.gnl-lbl{display:block;margin:0;font-size:14px;line-height:20px;font-weight:700;color:var(--navy-950);min-width:0}
.gnl-lbl>.gnl-in{display:block;margin-top:8px}
.gnl-hint.gnl-under{margin-top:-8px}
.gnl-form>.gnl-hint.gnl-under+.gnl-hint.gnl-under,.gnl-orgpanel>.gnl-hint.gnl-under+.gnl-hint.gnl-under{margin-top:-12px}
.gnl-hint:empty{display:none}
.gnl-lbl .opt{font-weight:400;color:var(--slate-500)}
.gnl-in{width:100%;min-height:46px;padding:0 14px;border:1px solid var(--slate-500);border-radius:3px;font:400 15px var(--font-display);color:var(--navy-950);background:#fff}
.gnl-in::placeholder{color:#7a809a}
select.gnl-in{appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath d='M1.5 4 6 8l4.5-4' stroke='%23141a33' stroke-width='1.5' fill='none'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center;padding-right:32px}
.gnl-in.gnl-derived,.gnl-in[readonly]{border:1px dashed var(--silver-300);background:var(--paper-50);color:var(--slate-500)}
.gnl-hint{margin:0;font-weight:400;font-size:13px;line-height:19px;color:var(--slate-500)}
.gnl-g2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.gnl-g-civ{display:grid;grid-template-columns:minmax(0,120px) minmax(0,1fr) minmax(0,1fr);gap:14px}
.gnl-g-num{display:grid;grid-template-columns:minmax(0,90px) minmax(0,1fr);gap:14px}
.gnl-g-cp{display:grid;grid-template-columns:minmax(0,130px) minmax(0,1fr);gap:14px}
.gnl-row-between{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;font-size:14px}
.gnl-check{display:flex;gap:8px;align-items:center;margin:0;font-size:14px;font-weight:500;color:var(--navy-950);cursor:pointer}
.gnl-check input{width:18px;height:18px;margin:0;accent-color:var(--green-700);flex:none}
.gnl-link{font-weight:600}
.gnl-btn{display:flex;align-items:center;justify-content:center;width:100%;min-height:50px;padding:0 20px;border:0;border-radius:3px;background:var(--green-700);color:#fff;font:700 16px var(--font-display);cursor:pointer}
.gnl-btn:hover{background:#196540}
.gnl-btn[disabled]{opacity:.6;cursor:progress}
.gnl-btn.alt{background:#fff;color:var(--navy-950);border:1px solid var(--silver-300);font-size:15px;min-height:46px}
.gnl-btn.alt:hover{background:var(--paper-50)}
.gnl-legal{margin:0;font-size:12px;line-height:18px;color:var(--slate-500)}
.gnl-sep{display:flex;align-items:center;gap:12px;color:var(--slate-500);font-size:12px;font-weight:700;letter-spacing:.15em;text-transform:uppercase;margin:24px 0 16px}
.gnl-sep::before,.gnl-sep::after{content:"";height:1px;background:var(--line-200);flex:1}
.gnl-msg{border-radius:3px;padding:12px 14px;font-size:14px;line-height:21px;margin:0 0 20px;display:flex;gap:10px;align-items:flex-start}
.gnl-msg svg{flex:none;margin-top:1px}
.gnl-msg.err{background:#fef3f2;border:1px solid var(--danger);color:#7a1610}
.gnl-msg.ok{background:#eef8f2;border:1px solid var(--green-700);color:#14532d}
/* « Vous êtes » : boutons radio présentés comme dans la maquette */
.gnl-fs{border:0;margin:0;padding:0;min-width:0}
.gnl-fs legend{margin:0 0 8px;padding:0;font-size:14px;font-weight:700}
.gnl-kinds{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}
.gnl-kind{position:relative;display:block;margin:0;cursor:pointer}
.gnl-kind input{position:absolute;opacity:0;width:1px;height:1px}
.gnl-kind span{display:flex;align-items:center;justify-content:center;text-align:center;min-height:44px;padding:0 8px;border:1px solid var(--silver-300);border-radius:3px;background:#fff;font:700 14px var(--font-display);color:var(--navy-950)}
.gnl-kind input:checked+span{border:2px solid var(--green-700);background:#eef8f2}
.gnl-kind input:focus-visible+span{outline:2px solid var(--green-700);outline-offset:2px}
/* Encadrés */
.gnl-note{display:flex;gap:12px;padding:14px 16px;border:1px solid var(--warning);border-radius:3px;background:#fff8f1}
.gnl-note svg{flex:none;margin-top:1px}
.gnl-note p{margin:0;font-size:13px;line-height:20px;color:var(--navy-950)}
.gnl-tip{margin:0;padding:12px 14px;background:#eef8f2;border-radius:3px;font-size:13px;line-height:19px}
[hidden]{display:none !important}
.gnl-sr{position:absolute;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0}
/* Panneau organisation (à gauche) */
.gnl-orgpanel{flex:0 1 460px;min-width:0;background:#fff;color:var(--navy-950);border-radius:3px;border-top:4px solid var(--green-700);padding:24px 28px 28px;display:flex;flex-direction:column;gap:16px;order:-1}
.gnl-orgpanel .kicker{margin:0 0 6px;font-size:11px;font-weight:700;letter-spacing:.2em;color:var(--green-700)}
.gnl-orgpanel h2{margin:0 0 4px;font-size:22px;line-height:28px;font-weight:800}
.gnl-auth:not(.has-org) .gnl-orgpanel{display:none}
.gnl-auth.has-org .gnl-orgpanel{animation:gnlslide .35s cubic-bezier(.2,.7,.2,1)}
@keyframes gnlslide{from{opacity:0;transform:translateX(24px)}to{opacity:1;transform:none}}
@media (prefers-reduced-motion:reduce){.gnl-auth.has-org .gnl-orgpanel{animation:none}}
@media (max-width:1000px){.gnl-auth{align-items:flex-start}}
@media (max-width:520px){.gnl-auth{padding:16px}.gnl-dlg-head,.gnl-dlg-body,.gnl-orgpanel{padding-left:20px;padding-right:20px}.gnl-dlg-foot{padding:14px 20px}.gnl-g-civ{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}.gnl-g-civ>:first-child{grid-column:1/-1}.gnl-g2{grid-template-columns:minmax(0,1fr)}}
</style>
</head>
<body>
<main class="gnl-auth<?php echo $mainClass !== '' ? ' ' . gnl_e($mainClass) : ''; ?>" id="gnl-auth">
<?php if (!$bare) { gnl_auth_dlg_open('/'); echo '<div class="gnl-dlg-body">'; } ?>
<?php
    }
}

/* Bandeau marine de la fenêtre : logo, titre, bouton fermer. */
if (!function_exists('gnl_auth_dlg_open')) {
    function gnl_auth_dlg_open($closeUrl = '/') {
        $logo = gnl_e(KEYCLOAK_LOGO_URL);
        ?>
<div class="gnl-dlg" role="region" aria-labelledby="titre-connexion">
  <div class="gnl-dlg-head">
    <div>
      <img src="<?php echo $logo; ?>" alt="GNL Solution" width="150" height="48">
      <p class="gnl-dlg-title" id="titre-connexion">Connexion aux services GNL</p>
    </div>
    <a class="gnl-close" href="<?php echo gnl_e($closeUrl); ?>" aria-label="Fermer"><svg aria-hidden="true" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></a>
  </div>
<?php
    }
}
if (!function_exists('gnl_auth_dlg_close')) {
    function gnl_auth_dlg_close() {
        ?>
  <p class="gnl-dlg-foot"><svg aria-hidden="true" width="14" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="10" width="16" height="11" rx="1"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>Connexion chiffrée · comptes gérés sur nos serveurs à Besançon</p>
</div>
<?php
    }
}
/* Onglets « J'ai un compte / Créer un compte » (liens entre /connexion et /inscription). */
if (!function_exists('gnl_auth_tabs')) {
    function gnl_auth_tabs($active, $return) {
        $q = '?return=' . rawurlencode($return);
        ?>
<nav class="gnl-tabs" aria-label="Connexion ou inscription">
  <a href="/connexion<?php echo gnl_e($q); ?>"<?php echo $active === 'connexion' ? ' aria-current="page"' : ''; ?>>J'ai un compte</a>
  <a href="/inscription<?php echo gnl_e($q); ?>"<?php echo $active === 'inscription' ? ' aria-current="page"' : ''; ?>>Créer un compte</a>
</nav>
<?php
    }
}
/* Où mène la croix « Fermer » : retour au panier si l'on venait de la commande. */
if (!function_exists('gnl_auth_close_url')) {
    function gnl_auth_close_url($return) {
        return (strpos((string) $return, '/commande') === 0) ? '/cart' : '/';
    }
}

if (!function_exists('gnl_auth_foot')) {
    function gnl_auth_foot() {
        if (empty($GLOBALS['gnl_auth_bare'])) { echo '</div>'; gnl_auth_dlg_close(); }
        ?>
</main>
<script>
document.querySelectorAll('form[data-gnl-auth]').forEach(function(f){
  f.addEventListener('submit', function(){
    var b = f.querySelector('button[type=submit]');
    if(b){ b.disabled = true; b.dataset.lbl = b.textContent; b.textContent = 'Veuillez patienter…'; }
  });
});
</script>
</body>
</html>
<?php
    }
}

/* Icônes SVG réutilisables pour les messages. */
if (!function_exists('gnl_icon')) {
    function gnl_icon($type) {
        if ($type === 'ok') return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';
        return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
    }
}
