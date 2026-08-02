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
if (!defined('KEYCLOAK_SCOPES'))        define('KEYCLOAK_SCOPES',        gnl_env('KEYCLOAK_SCOPES', 'openid profile email organization'));

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

if (!defined('KEYCLOAK_LOGO_URL')) define('KEYCLOAK_LOGO_URL', gnl_env('KEYCLOAK_LOGO_URL', 'https://gnl-solution.fr/wp-content/uploads/2025/04/Logo-GNL3.png'));

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
/* Extrait le claim "organization" -> array('name'=>.., 'attributes'=>[..]). */
if (!function_exists('gnl_org_extract')) {
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
                    $list[] = array('name' => $name, 'attributes' => gnl_flatten_attrs($attrs));
                } else {
                    $list[] = array('name' => (string) $v, 'attributes' => array());
                }
            }
            return $list;
        }
        // Objet unique auto-descriptif : {"name":..,"attributes":{..}}
        if (isset($org['attributes']) || isset($org['name']) || isset($org['id']) || isset($org['alias'])) {
            $name  = isset($org['name']) ? (string) $org['name'] : (isset($org['alias']) ? (string) $org['alias'] : '');
            $attrs = (isset($org['attributes']) && is_array($org['attributes'])) ? $org['attributes'] : $org;
            $list[] = array('name' => $name, 'attributes' => gnl_flatten_attrs($attrs));
            return $list;
        }
        // Map indexée par nom d'organisation : {"orgA":{..}, "orgB":{..}}
        foreach ($org as $name => $data) {
            $attrs = array();
            if (is_array($data)) {
                $attrs = (isset($data['attributes']) && is_array($data['attributes'])) ? $data['attributes'] : $data;
            }
            $list[] = array('name' => (string) $name, 'attributes' => gnl_flatten_attrs($attrs));
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
            'organization'   => isset($org['name']) ? (string) $org['name'] : '',
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
            /* Récupération du code HTTP sans utiliser $http_response_header,
               déprécié en PHP 8.5. On privilégie http_get_last_response_headers()
               (PHP 8.5+) et on ne touche l'ancienne variable que sur PHP < 8.5,
               où elle n'est pas dépréciée. */
            $respHeaders = array();
            if (function_exists('http_get_last_response_headers')) {
                $h = http_get_last_response_headers();
                if (is_array($h)) $respHeaders = $h;
            } elseif (isset($http_response_header) && is_array($http_response_header)) {
                $respHeaders = $http_response_header;
            }
            foreach ($respHeaders as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) $status = (int) $m[1];
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
        $fields = array(
            'grant_type' => 'password',
            'client_id'  => KEYCLOAK_CLIENT_ID,
            'username'   => $username,
            'password'   => $password,
            'scope'      => $scope !== null ? $scope : KEYCLOAK_SCOPES,
        );
        if (KEYCLOAK_CLIENT_SECRET !== '') $fields['client_secret'] = KEYCLOAK_CLIENT_SECRET;
        return gnl_http('POST', KC_OIDC . '/token', http_build_query($fields), array(
            'Content-Type: application/x-www-form-urlencoded', 'Accept: application/json',
        ));
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
        $payload = array(
            'username'      => $email,
            'email'         => $email,
            'firstName'     => $firstName,
            'lastName'      => $lastName,
            'enabled'       => true,
            'emailVerified' => (bool) KEYCLOAK_REG_EMAIL_VERIFIED,
            'attributes'    => (object) $attributes,
            'credentials'   => array(array(
                'type'      => 'password',
                'value'     => $password,
                'temporary' => false,
            )),
        );
        if ($required) $payload['requiredActions'] = $required;

        $r = gnl_http('POST', gnl_kc_admin()['base'] . '/users',
            json_encode($payload),
            array('Authorization: Bearer ' . gnl_kc_admin_token(),
                  'Content-Type: application/json', 'Accept: application/json'));

        $status = isset($r['_status']) ? (int) $r['_status'] : 0;
        if ($status === 201) {
            // Retrouve l'id créé (l'en-tête Location n'est pas exposé simplement -> relecture)
            $u = gnl_kc_find_user('email', $email);
            return array('id' => $u ? (isset($u['id']) ? $u['id'] : '') : '');
        }
        if ($status === 409) return array('error' => 'exists');
        return array('error' => gnl_kc_detail($r) !== '' ? gnl_kc_detail($r) : ('HTTP ' . $status));
    }
}

/* Déclenche un e-mail d'actions requises (ex. mot de passe oublié). */
if (!function_exists('gnl_kc_execute_actions_email')) {
    function gnl_kc_execute_actions_email($userId, $actions) {
        $q = http_build_query(array('client_id' => KEYCLOAK_CLIENT_ID));
        return gnl_kc_admin_request('PUT', '/users/' . rawurlencode($userId) . '/execute-actions-email?' . $q, $actions);
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
        $_SESSION['gnl_user'] = array_merge(
            $base,
            gnl_org_fields(is_array($org) ? $org : array('name' => '', 'attributes' => array())),
            array('auth_at' => time())
        );
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

/* ==================== Gabarit visuel (charte GNL) ===================
   Carte centrée, police Manrope, vert #6c9400 / teal #009494 / ink #353535,
   dans l'esprit des pages /cart et /commande. */
if (!function_exists('gnl_auth_head')) {
    function gnl_auth_head($title, $active = 'connexion') {
        header('Content-Type: text/html; charset=UTF-8');
        $logo = gnl_e(KEYCLOAK_LOGO_URL);
        ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo gnl_e($title); ?> — GNL Solution</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="icon" href="https://gnl-solution.fr/wp-content/uploads/2025/12/cropped-Sans-titre37-32x32.png" sizes="32x32">
<style>
:root{
  --gnl-green:#6c9400; --gnl-green-d:#5c7f00; --gnl-teal:#009494; --gnl-ink:#353535;
  --gnl-line:#e4e6e2; --gnl-soft:#f4f6f1; --gnl-danger:#c0392b; --gnl-ok:#2e7d32;
}
*{box-sizing:border-box}
html,body{margin:0;padding:0}
body{
  font-family:'Manrope',system-ui,-apple-system,Segoe UI,Roboto,sans-serif;
  color:var(--gnl-ink); line-height:1.45;
  background:
    radial-gradient(1200px 500px at 15% -10%, color-mix(in srgb,var(--gnl-green) 10%, transparent), transparent 60%),
    radial-gradient(1000px 480px at 110% 10%, color-mix(in srgb,var(--gnl-teal) 12%, transparent), transparent 55%),
    #f3f4f1;
  min-height:100vh; display:flex; flex-direction:column; align-items:center; justify-content:center;
  padding:2.2rem 1rem;
}
.gnl-auth{width:100%; max-width:440px}
.gnl-auth-logo{display:block; text-align:center; margin:0 auto 1.1rem}
.gnl-auth-logo img{height:56px; width:auto}
.gnl-card{
  background:#fff; border:1px solid var(--gnl-line); border-radius:16px;
  padding:1.9rem 1.9rem 1.7rem; box-shadow:0 18px 50px rgba(20,30,15,.08);
}
.gnl-card h1{font-size:1.4rem; font-weight:700; margin:.1rem 0 .25rem; letter-spacing:-.2px}
.gnl-card .sub{margin:0 0 1.35rem; font-size:.92rem; color:#6a6f66}
.gnl-field{margin-bottom:.95rem}
.gnl-field.row{display:flex; gap:.75rem}
.gnl-field.row>div{flex:1}
label{display:block; font-size:.82rem; font-weight:600; margin:0 0 .35rem; color:#4a4f46}
.gnl-in{
  width:100%; border:1px solid var(--gnl-line); border-radius:10px;
  padding:.72rem .85rem; font:inherit; font-size:.96rem; background:#fff; color:inherit;
  transition:border-color .15s, box-shadow .15s;
}
.gnl-in:focus{outline:none; border-color:var(--gnl-green); box-shadow:0 0 0 3px color-mix(in srgb,var(--gnl-green) 22%, transparent)}
.gnl-in::placeholder{color:#a7aca1}
select.gnl-in{appearance:none; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath d='M1.5 4 6 8l4.5-4' stroke='%23777' stroke-width='1.5' fill='none'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right .8rem center; padding-right:2rem}
.gnl-pass{position:relative}
.gnl-pass .gnl-in{padding-right:3.2rem}
.gnl-eye{position:absolute; right:.55rem; top:50%; transform:translateY(-50%); border:none; background:none; cursor:pointer; color:#8a8f85; padding:.3rem; line-height:0; border-radius:7px}
.gnl-eye:hover{color:var(--gnl-ink)}
.gnl-row-between{display:flex; align-items:center; justify-content:space-between; gap:.75rem; margin:-.15rem 0 1.1rem}
.gnl-check{display:flex; align-items:flex-start; gap:.55rem; font-size:.85rem; color:#4a4f46; cursor:pointer; user-select:none}
.gnl-check input{margin:.15rem 0 0; accent-color:var(--gnl-green); flex:none}
.gnl-link{color:var(--gnl-teal); text-decoration:none; font-size:.85rem; font-weight:600}
.gnl-link:hover{text-decoration:underline}
.gnl-btn{
  display:block; width:100%; border:none; cursor:pointer; margin-top:.35rem;
  background:var(--gnl-green); color:#fff; border-radius:11px;
  padding:.85rem 1rem; font:inherit; font-weight:700; font-size:1rem;
  transition:filter .15s, transform .02s;
}
.gnl-btn:hover{filter:brightness(1.05)}
.gnl-btn:active{transform:translateY(1px)}
.gnl-btn[disabled]{opacity:.6; cursor:progress}
.gnl-alt{margin-top:1.25rem; text-align:center; font-size:.9rem; color:#5c6157}
.gnl-alt a{color:var(--gnl-teal); font-weight:700; text-decoration:none}
.gnl-alt a:hover{text-decoration:underline}
.gnl-msg{border-radius:11px; padding:.75rem .9rem; font-size:.88rem; margin:0 0 1.15rem; display:flex; gap:.55rem; align-items:flex-start}
.gnl-msg svg{flex:none; margin-top:1px}
.gnl-msg.err{background:color-mix(in srgb,var(--gnl-danger) 8%, #fff); border:1px solid color-mix(in srgb,var(--gnl-danger) 35%, transparent); color:#8e2a1e}
.gnl-msg.ok{background:color-mix(in srgb,var(--gnl-ok) 8%, #fff); border:1px solid color-mix(in srgb,var(--gnl-ok) 35%, transparent); color:#1f6323}
.gnl-hint{font-size:.78rem; color:#8a8f85; margin:.3rem 0 0}
.gnl-sep{display:flex; align-items:center; gap:.8rem; color:#a7aca1; font-size:.78rem; margin:1.15rem 0 .3rem}
.gnl-sep::before,.gnl-sep::after{content:""; height:1px; background:var(--gnl-line); flex:1}
.gnl-foot{margin-top:1.4rem; text-align:center; font-size:.76rem; color:#9aa093}
.gnl-foot a{color:inherit}
@media(max-width:480px){ .gnl-card{padding:1.5rem 1.25rem} .gnl-field.row{flex-direction:column; gap:0} }
</style>
</head>
<body>
<main class="gnl-auth">
  <a class="gnl-auth-logo" href="/" aria-label="GNL Solution"><img src="<?php echo $logo; ?>" alt="GNL Solution"></a>
  <div class="gnl-card">
<?php
    }
}

if (!function_exists('gnl_auth_foot')) {
    function gnl_auth_foot() {
        ?>
  </div>
  <p class="gnl-foot">Espace sécurisé GNL Solution &middot; <a href="/">Retour au site</a></p>
</main>
<script>
document.addEventListener('click', function(e){
  var b = e.target.closest('.gnl-eye'); if(!b) return;
  var inp = b.parentNode.querySelector('input');
  if(!inp) return;
  var show = inp.type === 'password';
  inp.type = show ? 'text' : 'password';
  b.setAttribute('aria-label', show ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
  b.innerHTML = show
    ? '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19M1 1l22 22"/><path d="M9.5 9.5a3 3 0 0 0 4.24 4.24"/></svg>'
    : '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
});
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
