<?php
/* =====================================================================
   GNL Solution — État de nos systèmes  (pages/stats.php)
   ---------------------------------------------------------------------
   Page publique « type UptimeRobot » : bandeau d'état global + une carte
   par service/hôte, avec pourcentage de disponibilité et frise 90 jours.
   Elle réutilise l'en-tête et le pied de page du site
   (../include/header.php et ../include/footer.php), comme les autres
   pages du dossier pages/.

   Les données proviennent de l'API Zabbix (JSON-RPC). La configuration
   est lue dans les variables d'environnement :

       ZABBIX_API_URL        ex. https://zabbix.gnl-solution.fr
                              (on ajoute /api_jsonrpc.php si absent)
       ZABBIX_API_TOKEN       jeton d'API (recommandé, Zabbix 5.4+)
       ZABBIX_API_USERNAME    repli : identifiant si pas de jeton
       ZABBIX_API_PASSWORD    repli : mot de passe associé

   Variables OPTIONNELLES (facultatives) :
       ZABBIX_STATS_MODE       auto | services | hosts   (défaut : auto)
                               - "services" : utilise les IT Services Zabbix
                               - "hosts"    : hôtes + problèmes actifs
                               - "auto"     : services si présents, sinon hosts
       ZABBIX_STATS_HOSTGROUP  nom d'un groupe d'hôtes pour filtrer (mode hosts)
       ZABBIX_STATS_TTL        durée de cache en secondes (défaut : 45)
       ZABBIX_STATS_CACHE_DIR  dossier de cache/historique
                               (défaut : <tmp>/gnl_status)
       ZABBIX_API_INSECURE     "1" pour ne PAS vérifier le certificat TLS
                               (à éviter ; utile en labo / cert auto-signé)

   Endpoints internes :
       ?ajax=1     renvoie l'état en JSON (utilisé pour l'auto-refresh)
       ?refresh=1  force un rafraîchissement en ignorant le cache

   La frise « 90 jours » se construit dans le temps : à chaque
   rafraîchissement réel, le pire état de chaque composant pour la journée
   est mémorisé dans ZABBIX_STATS_CACHE_DIR/history.json (90 jours max).
   ===================================================================== */

/* -------- Lecture robuste des variables d'environnement (PHP-FPM) ----
   getenv() ne voit pas toujours les variables sous PHP-FPM (clear_env),
   d'où le repli sur $_SERVER puis $_ENV — identique au reste du site. */
if (!function_exists('gnl_env')) {
    function gnl_env($key, $default = '') {
        $v = getenv($key);
        if ($v !== false && $v !== '') return $v;
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return $_SERVER[$key];
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') return $_ENV[$key];
        return $default;
    }
}

/* ------------------------------ Configuration ----------------------- */
$GNLST = array(
    'url'        => gnl_env('ZABBIX_API_URL'),
    'token'      => gnl_env('ZABBIX_API_TOKEN'),
    'user'       => gnl_env('ZABBIX_API_USERNAME'),
    'pass'       => gnl_env('ZABBIX_API_PASSWORD'),
    'mode'       => strtolower(gnl_env('ZABBIX_STATS_MODE', 'auto')),
    'group'      => gnl_env('ZABBIX_STATS_HOSTGROUP', ''),
    'ttl'        => max(10, (int) gnl_env('ZABBIX_STATS_TTL', '45')),
    'cache_dir'  => gnl_env('ZABBIX_STATS_CACHE_DIR', rtrim(sys_get_temp_dir(), '/\\') . '/gnl_status'),
    'linktag'    => gnl_env('ZABBIX_STATS_LINK_TAG', 'clustersla'),
    'grouptag'   => gnl_env('ZABBIX_STATS_GROUP_TAG', 'type'),
    'insecure'   => gnl_env('ZABBIX_API_INSECURE', '') === '1',
);

$SITE_NAME = 'GNL Solution';

/* =====================================================================
   Utilitaires
   ===================================================================== */
function gnlst_h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

function gnlst_endpoint($url) {
    $url = trim((string) $url);
    if ($url === '') return '';
    if (stripos($url, 'api_jsonrpc.php') !== false) return $url;
    return rtrim($url, '/') . '/api_jsonrpc.php';
}

function gnlst_dir($dir) {
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    return is_dir($dir) && is_writable($dir);
}
function gnlst_read_json($file) {
    if (!is_file($file)) return null;
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') return null;
    $d = json_decode($raw, true);
    return is_array($d) ? $d : null;
}
function gnlst_write_json($file, $data) {
    $tmp = $file . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, json_encode($data), LOCK_EX) === false) return false;
    return @rename($tmp, $file);
}

/* Transport HTTP (curl, repli sur les flux) — renvoie le corps ou null. */
function gnlst_http($url, $payload, $headers, $insecure = false) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => $insecure ? false : true,
            CURLOPT_SSL_VERIFYHOST => $insecure ? 0 : 2,
        ));
        $body = curl_exec($ch);
        // Note : curl_close() est inutile depuis PHP 8.0 (déprécié en 8.5) — le
        // handle est libéré automatiquement quand $ch sort de portée.
        return $body === false ? null : $body;
    }
    $opts = array('http' => array(
        'method'        => 'POST',
        'header'        => implode("\r\n", $headers) . "\r\n",
        'content'       => $payload,
        'timeout'       => 8,
        'ignore_errors' => true,
    ));
    if ($insecure) $opts['ssl'] = array('verify_peer' => false, 'verify_peer_name' => false);
    $ctx  = stream_context_create($opts);
    $body = @file_get_contents($url, false, $ctx);
    return $body === false ? null : $body;
}

/* =====================================================================
   Client Zabbix JSON-RPC
   ---------------------------------------------------------------------
   Gère les deux façons de s'authentifier selon la version :
     • Zabbix >= 6.4 : en-tête HTTP  Authorization: Bearer <jeton|session>
     • Zabbix <  6.4 : champ "auth" dans le corps JSON-RPC
   et bascule automatiquement si l'un échoue.
   ===================================================================== */
class GnlstZabbix {
    private $endpoint, $token, $user, $pass, $insecure;
    private $auth = null;
    private $useHeader = true;
    public  $version = '';
    public  $error = '';
    private $id = 1;

    public function __construct($endpoint, $token, $user, $pass, $insecure = false) {
        $this->endpoint = $endpoint;
        $this->token    = (string) $token;
        $this->user     = (string) $user;
        $this->pass     = (string) $pass;
        $this->insecure = (bool) $insecure;
    }

    /* $mode : 'none' | 'header' | 'field' */
    private function request($method, $params, $mode) {
        $body = array(
            'jsonrpc' => '2.0',
            'method'  => $method,
            'params'  => ($params === array() || $params === null) ? new stdClass() : $params,
            'id'      => $this->id++,
        );
        $headers = array('Content-Type: application/json-rpc', 'Accept: application/json');
        if ($mode === 'field'  && $this->auth !== null) $body['auth'] = $this->auth;
        if ($mode === 'header' && $this->auth !== null) $headers[] = 'Authorization: Bearer ' . $this->auth;

        $resp = gnlst_http($this->endpoint, json_encode($body), $headers, $this->insecure);
        if ($resp === null) { if ($this->error === '') $this->error = 'Serveur Zabbix injoignable.'; return null; }

        $data = json_decode($resp, true);
        if (!is_array($data)) { $this->error = 'Réponse Zabbix illisible.'; return null; }
        if (isset($data['error'])) {
            $msg = isset($data['error']['message']) ? $data['error']['message'] : 'Erreur Zabbix';
            $dat = isset($data['error']['data']) ? $data['error']['data'] : '';
            $this->error = trim($msg . ($dat !== '' ? ' — ' . $dat : ''));
            return null;
        }
        return array_key_exists('result', $data) ? $data['result'] : null;
    }

    public function connect() {
        // Détection de version (sans authentification).
        $this->version  = (string) $this->request('apiinfo.version', array(), 'none');
        $this->useHeader = ($this->version === '') ? true : version_compare($this->version, '6.4', '>=');

        if ($this->token !== '') { $this->auth = $this->token; return true; }

        if ($this->user !== '') {
            $this->error = '';
            $s = $this->request('user.login', array('username' => $this->user, 'password' => $this->pass), 'none');
            if (!is_string($s) || $s === '') { // repli Zabbix < 6.0 : paramètre "user"
                $this->error = '';
                $s = $this->request('user.login', array('user' => $this->user, 'password' => $this->pass), 'none');
            }
            if (is_string($s) && $s !== '') { $this->auth = $s; return true; }
            if ($this->error === '') $this->error = 'Échec de la connexion Zabbix (identifiants).';
            return false;
        }

        if ($this->error === '') $this->error = 'Aucun jeton ni identifiant Zabbix fourni.';
        return false;
    }

    public function call($method, $params = array()) {
        $r = $this->request($method, $params, $this->useHeader ? 'header' : 'field');
        // Une seule tentative de bascule si l'erreur ressemble à un souci d'auth.
        if ($r === null && $this->auth !== null && stripos($this->error, 'auth') !== false) {
            $this->error = '';
            $this->useHeader = !$this->useHeader;
            $r = $this->request($method, $params, $this->useHeader ? 'header' : 'field');
        }
        return $r;
    }
}

/* =====================================================================
   Construction de la liste des composants
   Chaque composant : id, name, group, status (up|degraded|down), detail
   ===================================================================== */
function gnlst_severity_to_status($sev) {          // 0..5 (sévérité problème)
    if ($sev < 0)  return 'up';
    if ($sev <= 2) return 'degraded';               // non classé / info / avertissement
    return 'down';                                  // moyen / haut / désastre
}

/* Valeurs de lien (problem tags) d'un service et de ses descendants. */
function gnlst_service_link($svc, $byId, $tagName) {
    $out = array(); $seen = array(); $stack = array($svc['serviceid']);
    while ($stack) {
        $id = array_pop($stack);
        if (isset($seen[$id])) continue; $seen[$id] = true;
        $node = isset($byId[$id]) ? $byId[$id] : null;
        if (!$node) continue;
        if (!empty($node['problem_tags'])) foreach ($node['problem_tags'] as $pt) {
            if (isset($pt['tag']) && $pt['tag'] === $tagName) {
                $out[] = array('v' => isset($pt['value']) ? (string) $pt['value'] : '',
                               'op' => isset($pt['operator']) ? (int) $pt['operator'] : 0);
            }
        }
        if (!empty($node['children'])) foreach ($node['children'] as $ch)
            if (!empty($ch['serviceid'])) $stack[] = $ch['serviceid'];
    }
    return $out;
}

/* Valeurs de lien (tags d'hôte) -> règles "equals". */
function gnlst_tag_link($tags, $tagName) {
    $out = array();
    if (is_array($tags)) foreach ($tags as $t)
        if (isset($t['tag']) && $t['tag'] === $tagName)
            $out[] = array('v' => isset($t['value']) ? (string) $t['value'] : '', 'op' => 0);
    return $out;
}

/* Un service (règles de problem tags) matche-t-il une valeur de tag d'hôte ? */
function gnlst_link_match($serviceLink, $hostValues) {
    foreach ($serviceLink as $sl) {
        foreach ($hostValues as $hv) {
            $hv = (string) $hv;
            if ((int) $sl['op'] === 2) {                 // "contient"
                if ($sl['v'] !== '' && strpos($hv, $sl['v']) !== false) return true;
            } else {                                     // "égal" (ou tag présent si valeur vide)
                if ($sl['v'] === '' || $sl['v'] === $hv) return true;
            }
        }
    }
    return false;
}

/* Groupe d'affichage d'un service = valeur de son tag $groupTag (ex. "type").
   -> tous les services partageant la même valeur seront réunis dans une carte.
   Renvoie "Autres" si le service n'a pas ce tag, "Services" si le regroupement
   est désactivé ($groupTag vide). */
function gnlst_service_group($tags, $groupTag) {
    if ($groupTag === '') return 'Services';
    if (is_array($tags)) {
        foreach ($tags as $t) {
            if (isset($t['tag']) && strcasecmp((string) $t['tag'], $groupTag) === 0) {
                $v = isset($t['value']) ? trim((string) $t['value']) : '';
                if ($v !== '') return $v;
            }
        }
    }
    return 'Autres';
}

function gnlst_build_components($zbx, $mode, $groupName, $linkTag = '', $groupTag = '') {
    /* --- Mode Services (IT Services Zabbix) --------------------------- */
    if ($mode === 'auto' || $mode === 'services') {
        $svc = $zbx->call('service.get', array(
            'output'            => array('serviceid', 'name', 'description', 'status'),
            'selectParents'     => array('serviceid'),
            'selectChildren'    => array('serviceid'),
            'selectProblemTags' => 'extend',
            'selectTags'        => 'extend',
            'sortfield'         => array('name'),
        ));
        // Repli si selectProblemTags/selectChildren indisponibles.
        if (!is_array($svc)) { $zbx->error = ''; $svc = $zbx->call('service.get', array(
            'output' => array('serviceid', 'name', 'description', 'status'),
            'selectParents' => array('serviceid'), 'selectTags' => 'extend', 'sortfield' => array('name'),
        )); }
        if (is_array($svc) && count($svc) > 0) {
            // Index de TOUS les services (pour agréger les tags des enfants).
            $byId = array();
            foreach ($svc as $s) $byId[$s['serviceid']] = $s;

            // On garde les services racine (sans parent) ; sinon tous.
            $roots = array();
            foreach ($svc as $s) if (empty($s['parents'])) $roots[] = $s;
            $list = $roots ? $roots : $svc;

            $components = array();
            foreach ($list as $s) {
                $st = (int) $s['status'];                       // -1 = OK, sinon sévérité
                $status = $st < 0 ? 'up' : ($st <= 2 ? 'degraded' : 'down');
                $components[] = array(
                    'id'     => 'svc' . $s['serviceid'],
                    'kind'   => 'service',
                    'zid'    => (string) $s['serviceid'],
                    'name'   => $s['name'],
                    'group'  => gnlst_service_group(isset($s['tags']) ? $s['tags'] : array(), $groupTag),
                    'status' => $status,
                    'detail' => isset($s['description']) ? $s['description'] : '',
                    'link'   => ($linkTag !== '') ? gnlst_service_link($s, $byId, $linkTag) : array(),
                );
            }
            // Ordre des cartes : groupes triés alphabétiquement ("Autres" en
            // dernier), puis services triés par nom au sein de chaque groupe.
            usort($components, function ($a, $b) {
                $ga = $a['group'] === 'Autres' ? 1 : 0;
                $gb = $b['group'] === 'Autres' ? 1 : 0;
                if ($ga !== $gb) return $ga - $gb;
                $c = strcasecmp($a['group'], $b['group']);
                return $c !== 0 ? $c : strcasecmp($a['name'], $b['name']);
            });
            return array('components' => $components, 'mode' => 'services');
        }
        if ($mode === 'services') return array('components' => array(), 'mode' => 'services');
    }

    /* --- Mode Hôtes + problèmes actifs -------------------------------- */
    $groupids = null;
    if ($groupName !== '') {
        $g = $zbx->call('hostgroup.get', array('output' => array('groupid'), 'filter' => array('name' => array($groupName))));
        if (is_array($g) && $g) { $groupids = array(); foreach ($g as $x) $groupids[] = $x['groupid']; }
    }

    // host.get — on tente selectHostGroups (6.2+), repli selectGroups, puis sans groupe.
    $base = array('output' => array('hostid', 'name', 'status'), 'sortfield' => 'name', 'filter' => array('status' => 0));
    if ($linkTag !== '') $base['selectTags'] = 'extend';
    if ($groupids) $base['groupids'] = $groupids;

    $hosts = $zbx->call('host.get', $base + array('selectHostGroups' => array('name')));
    if (!is_array($hosts)) { $zbx->error = ''; $hosts = $zbx->call('host.get', $base + array('selectGroups' => array('name'))); }
    if (!is_array($hosts)) { $zbx->error = ''; $hosts = $zbx->call('host.get', $base); }
    if (!is_array($hosts)) $hosts = array();

    // Problèmes actifs (non résolus, non supprimés) -> pire sévérité par hôte.
    $probs = $zbx->call('problem.get', array(
        'output'     => array('eventid', 'objectid', 'severity', 'name', 'clock'),
        'source'     => 0, 'object' => 0,
        'recent'     => false,
        'suppressed' => false,
    ));

    $worstByHost = array();   // hostid => sévérité max
    $probByHost  = array();   // hostid => libellé du pire problème
    if (is_array($probs) && $probs) {
        $trigIds = array();
        foreach ($probs as $p) $trigIds[$p['objectid']] = true;
        $trigIds = array_keys($trigIds);

        $trigHost = array();  // triggerid => hostid
        if ($trigIds) {
            $trig = $zbx->call('trigger.get', array(
                'triggerids'  => $trigIds,
                'output'      => array('triggerid'),
                'selectHosts' => array('hostid'),
                'preservekeys'=> true,
            ));
            if (is_array($trig)) {
                foreach ($trig as $tid => $t) {
                    if (!empty($t['hosts'][0]['hostid'])) $trigHost[$tid] = $t['hosts'][0]['hostid'];
                }
            }
        }
        foreach ($probs as $p) {
            $hid = isset($trigHost[$p['objectid']]) ? $trigHost[$p['objectid']] : null;
            if (!$hid) continue;
            $sev = (int) $p['severity'];
            if (!isset($worstByHost[$hid]) || $sev > $worstByHost[$hid]) {
                $worstByHost[$hid] = $sev;
                $probByHost[$hid]  = $p['name'];
            }
        }
    }

    $components = array();
    foreach ($hosts as $h) {
        $hid = $h['hostid'];
        $sev = isset($worstByHost[$hid]) ? $worstByHost[$hid] : -1;
        $grp = 'Systèmes';
        if (!empty($h['hostgroups'][0]['name']))   $grp = $h['hostgroups'][0]['name'];
        elseif (!empty($h['groups'][0]['name']))    $grp = $h['groups'][0]['name'];
        $components[] = array(
            'id'     => 'host' . $hid,
            'kind'   => 'host',
            'zid'    => (string) $hid,
            'name'   => $h['name'],
            'group'  => $grp,
            'status' => gnlst_severity_to_status($sev),
            'detail' => $sev < 0 ? '' : (isset($probByHost[$hid]) ? $probByHost[$hid] : ''),
            'link'   => ($linkTag !== '' && !empty($h['tags'])) ? gnlst_tag_link($h['tags'], $linkTag) : array(),
        );
    }
    return array('components' => $components, 'mode' => 'hosts');
}

/* =====================================================================
   Historique (frise 90 jours) & disponibilité
   ===================================================================== */
function gnlst_update_history($dir, $components) {
    $file = $dir . '/history.json';
    $hist = gnlst_read_json($file);
    if (!is_array($hist)) $hist = array();

    $today = date('Y-m-d');
    if (!isset($hist[$today])) $hist[$today] = array();
    $rank = array('up' => 0, 'degraded' => 1, 'down' => 2);

    foreach ($components as $c) {
        $code = $rank[$c['status']];
        $prev = isset($hist[$today][$c['id']]) ? (int) $hist[$today][$c['id']] : -1;
        if ($code > $prev) $hist[$today][$c['id']] = $code;   // on conserve le pire état du jour
    }

    krsort($hist);
    $hist = array_slice($hist, 0, 90, true);   // 90 jours max
    gnlst_write_json($file, $hist);
    return $hist;
}

function gnlst_component_history($hist, $id) {
    ksort($hist);
    $days = array(); $up = 0; $tot = 0;
    foreach ($hist as $date => $m) {
        if (!array_key_exists($id, $m)) { $days[] = array('date' => $date, 'code' => -1); continue; }
        $code = (int) $m[$id];
        $days[] = array('date' => $date, 'code' => $code);
        $tot++; if ($code === 0) $up++;
    }
    $days = array_slice($days, -90);
    return array(
        'days'   => $days,
        'uptime' => $tot > 0 ? round($up / $tot * 100, 2) : null,
    );
}

/* =====================================================================
   État global (cache + calcul)
   ===================================================================== */
function gnlst_overall($components) {
    $down = 0; $deg = 0; $tot = count($components);
    foreach ($components as $c) {
        if ($c['status'] === 'down') $down++;
        elseif ($c['status'] === 'degraded') $deg++;
    }
    if ($tot === 0)          return 'unknown';
    if ($down === $tot)      return 'down';       // panne totale
    if ($down > 0)           return 'partial';    // panne partielle
    if ($deg  > 0)           return 'degraded';
    return 'up';
}

/* =====================================================================
   Maintenances planifiées (Zabbix maintenance.get)
   ---------------------------------------------------------------------
   On renvoie les maintenances EN COURS ou À VENIR (celles déjà terminées
   sont ignorées). L'état ("active" / "scheduled") est déduit de la fenêtre
   active_since / active_till renseignée dans Zabbix.
   ===================================================================== */
/* Jetons alphanumériques d'un libellé (minuscules), pour la correspondance. */
function gnlst_maint_tokens($s) {
    $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    $parts = preg_split('/[^a-z0-9]+/', $s, -1, PREG_SPLIT_NO_EMPTY);
    return $parts ? $parts : array();
}

/* Détermine les composants (services/hôtes affichés) impactés par une
   maintenance, à partir des hôtes/groupes qu'elle cible.
   - correspondance exacte/sous-chaîne sur le nom,
   - ou jeton significatif partagé (>= 4 caractères, hors mots génériques).
   En mode "hosts" la correspondance est exacte (hôte = composant). */
function gnlst_maint_impacted($targets, $components) {
    if (empty($components) || empty($targets)) return array();
    $stop = array('linux','windows','server','servers','serveur','serveurs','prod','production',
                  'test','hosts','host','hote','hotes','zabbix','cluster','node','nodes','group',
                  'groupe','kubelet','kubernetes','vm','vms','app','apps','service','services');
    $tgt = array();
    foreach ($targets as $t) {
        $tl = function_exists('mb_strtolower') ? mb_strtolower($t, 'UTF-8') : strtolower($t);
        $tgt[] = array('l' => $tl, 'tok' => gnlst_maint_tokens($t));
    }
    $impacted = array();
    foreach ($components as $c) {
        $name = isset($c['name']) ? $c['name'] : '';
        if ($name === '') continue;
        $cl   = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        $ctok = gnlst_maint_tokens($name);
        $hit  = false;
        foreach ($tgt as $t) {
            if ($t['l'] === '') continue;
            if (strpos($cl, $t['l']) !== false || strpos($t['l'], $cl) !== false) { $hit = true; break; }
            foreach ($t['tok'] as $tok) {
                if (strlen($tok) >= 4 && !in_array($tok, $stop, true) && in_array($tok, $ctok, true)) { $hit = true; break; }
            }
            if ($hit) break;
        }
        if ($hit) $impacted[] = $name;
    }
    return array_values(array_unique($impacted));
}

/* Services/hôtes impactés via le lien  maintenance -> hôtes -> tag -> service.
   - mode "hosts" : composant impacté si son hôte fait partie de la maintenance.
   - mode "services" : on lit les valeurs du tag de liaison (ex. clustersla) sur
     les hôtes de la maintenance, puis on garde les services dont un problem tag
     de même nom matche l'une de ces valeurs. */
function gnlst_maint_link_impacted($zbx, $components, $hostids, $groupids, $linkTag) {
    if (empty($components)) return array();

    // 1) Hôtes effectifs = hôtes explicites + membres des groupes ciblés.
    $eff = array();
    foreach ($hostids as $h) if ($h !== '') $eff[$h] = true;
    if (!empty($groupids)) {
        $gh = $zbx->call('host.get', array('output' => array('hostid'), 'groupids' => $groupids));
        if (is_array($gh)) foreach ($gh as $h) $eff[$h['hostid']] = true;
    }
    $eff = array_keys($eff);
    if (empty($eff)) return array();

    // 2) A-t-on des services à relier par tag ?
    $hasService = false;
    foreach ($components as $c) if (isset($c['kind']) && $c['kind'] === 'service') { $hasService = true; break; }

    // 3) Valeurs du tag de liaison portées par les hôtes de la maintenance.
    $vals = array();
    if ($hasService && $linkTag !== '') {
        $ht = $zbx->call('host.get', array('output' => array('hostid'), 'hostids' => $eff, 'selectTags' => 'extend'));
        if (is_array($ht)) foreach ($ht as $h) if (!empty($h['tags'])) foreach ($h['tags'] as $t)
            if (isset($t['tag']) && $t['tag'] === $linkTag) $vals[] = isset($t['value']) ? (string) $t['value'] : '';
        $vals = array_values(array_unique($vals));
    }

    // 4) Correspondance composant par composant.
    $effSet = array_flip($eff);
    $impacted = array();
    foreach ($components as $c) {
        $kind = isset($c['kind']) ? $c['kind'] : '';
        if ($kind === 'host') {
            if (isset($c['zid']) && isset($effSet[$c['zid']])) $impacted[] = $c['name'];
        } elseif ($kind === 'service') {
            if (!empty($c['link']) && $vals && gnlst_link_match($c['link'], $vals)) $impacted[] = $c['name'];
        }
    }
    return array_values(array_unique($impacted));
}

function gnlst_build_maintenances($zbx, $components = array(), $linkTag = '', $mode = '') {
    $now = time();
    $fields = array('maintenanceid', 'name', 'description', 'maintenance_type', 'active_since', 'active_till');

    $m = $zbx->call('maintenance.get', array(
        'output'           => $fields,
        'selectHostGroups' => array('groupid', 'name'),
        'selectHosts'      => array('hostid', 'name'),
        'sortfield'        => array('active_since'),
    ));
    // Replis : selectGroups (Zabbix < 6.2), puis sans cibles.
    if (!is_array($m)) { $zbx->error = ''; $m = $zbx->call('maintenance.get', array(
        'output' => $fields, 'selectGroups' => array('groupid', 'name'), 'selectHosts' => array('hostid', 'name'), 'sortfield' => array('active_since'),
    )); }
    if (!is_array($m)) { $zbx->error = ''; $m = $zbx->call('maintenance.get', array(
        'output' => $fields, 'sortfield' => array('active_since'),
    )); }
    if (!is_array($m)) return array();

    $out = array();
    foreach ($m as $mt) {
        $since = (int) $mt['active_since'];
        $till  = (int) $mt['active_till'];
        if ($till > 0 && $till < $now) continue;   // déjà terminée -> ignorée

        $targets = array();
        $grp = !empty($mt['hostgroups']) ? $mt['hostgroups'] : (!empty($mt['groups']) ? $mt['groups'] : array());
        foreach ($grp as $g) if (!empty($g['name'])) $targets[] = $g['name'];
        if (!empty($mt['hosts'])) foreach ($mt['hosts'] as $h) if (!empty($h['name'])) $targets[] = $h['name'];

        $out[] = array(
            'id'      => 'mnt' . $mt['maintenanceid'],
            'name'    => $mt['name'],
            'desc'    => isset($mt['description']) ? $mt['description'] : '',
            'state'   => ($since <= $now && ($till === 0 || $now <= $till)) ? 'active' : 'scheduled',
            'from'    => $since,
            'till'    => $till,
            'type'    => ((int) $mt['maintenance_type'] === 1) ? 'nodata' : 'data',
            'targets'  => $targets,
            'impacted' => gnlst_maint_impacted($targets, $components),
        );
    }
    return $out;
}

function gnlst_compute($cfg) {
    $writable = gnlst_dir($cfg['cache_dir']);
    $state = array('ok' => false, 'error' => '', 'mode' => '', 'overall' => 'unknown',
                   'components' => array(), 'maintenances' => array(), 'updated' => time(), 'stale' => false);

    $endpoint = gnlst_endpoint($cfg['url']);
    if ($endpoint === '') { $state['error'] = 'ZABBIX_API_URL n’est pas configurée.'; return $state; }

    $zbx = new GnlstZabbix($endpoint, $cfg['token'], $cfg['user'], $cfg['pass'], $cfg['insecure']);
    if (!$zbx->connect()) { $state['error'] = $zbx->error; return $state; }

    $res = gnlst_build_components($zbx, $cfg['mode'], $cfg['group'], $cfg['linktag'], $cfg['grouptag']);
    if (!$res['components'] && $zbx->error !== '') { $state['error'] = $zbx->error; return $state; }

    $state['ok']         = true;
    $state['mode']       = $res['mode'];
    $state['components'] = $res['components'];
    $state['overall']    = gnlst_overall($res['components']);
    $state['version']    = $zbx->version;

    // Maintenances planifiées (en cours ou à venir).
    $state['maintenances'] = gnlst_build_maintenances($zbx, $res['components']);

    // Historique + disponibilité (best effort ; n'empêche jamais l'affichage).
    if ($writable) {
        $hist = gnlst_update_history($cfg['cache_dir'], $res['components']);
        foreach ($state['components'] as &$c) {
            $h = gnlst_component_history($hist, $c['id']);
            $c['uptime']  = $h['uptime'];
            $c['history'] = $h['days'];
        }
        unset($c);
        gnlst_write_json($cfg['cache_dir'] . '/state.json', $state);
    } else {
        foreach ($state['components'] as &$c) { $c['uptime'] = null; $c['history'] = array(); }
        unset($c);
    }
    return $state;
}

function gnlst_get_state($cfg) {
    $force = isset($_GET['refresh']);
    $stateFile = $cfg['cache_dir'] . '/state.json';

    // Cache court : évite de solliciter Zabbix à chaque visite.
    if (!$force && is_file($stateFile) && (time() - filemtime($stateFile)) <= $cfg['ttl']) {
        $cached = gnlst_read_json($stateFile);
        if (is_array($cached) && !empty($cached['ok'])) { $cached['stale'] = false; return $cached; }
    }

    $fresh = gnlst_compute($cfg);
    if (!$fresh['ok']) {
        // Repli : on montre le dernier état connu s'il existe.
        $last = gnlst_read_json($stateFile);
        if (is_array($last) && !empty($last['ok'])) {
            $last['stale'] = true;
            $last['error'] = $fresh['error'];
            return $last;
        }
    }
    return $fresh;
}

/* =====================================================================
   Diagnostic (TEMPORAIRE) — pour comprendre pourquoi une maintenance
   (ou un composant) ne remonte pas.
   ---------------------------------------------------------------------
   Accès :  stats.php?debug=1   → renvoie un JSON de diagnostic.
   Le jeton n'est JAMAIS affiché. ⚠️ Pensez à SUPPRIMER ce bloc (ou à le
   re-protéger) une fois le diagnostic terminé, car il expose des noms
   d'hôtes / de maintenances et les messages d'erreur de l'API.
   ===================================================================== */
if (isset($_GET['debug'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');

    $dbg = array('now' => date('c'), 'url_configured' => $GNLST['url'] !== '');
    $endpoint = gnlst_endpoint($GNLST['url']);
    $dbg['endpoint'] = $endpoint;
    $dbg['auth_method'] = $GNLST['token'] !== '' ? 'token' : ($GNLST['user'] !== '' ? 'user/password' : 'aucun');

    $zbx = new GnlstZabbix($endpoint, $GNLST['token'], $GNLST['user'], $GNLST['pass'], $GNLST['insecure']);
    $dbg['connect_ok'] = $zbx->connect();
    $dbg['version']    = $zbx->version;
    $dbg['connect_error'] = $zbx->error;

    if ($dbg['connect_ok']) {
        // 1) maintenance.get AVEC cibles
        $zbx->error = '';
        $m1 = $zbx->call('maintenance.get', array(
            'output'           => array('maintenanceid','name','maintenance_type','active_since','active_till'),
            'selectHostGroups' => array('name'),
            'selectHosts'      => array('name'),
        ));
        $dbg['maintenance_get_with_selects'] = array(
            'error' => $zbx->error,
            'count' => is_array($m1) ? count($m1) : null,
            'items' => is_array($m1) ? array_map(function ($x) {
                return array(
                    'name'        => isset($x['name']) ? $x['name'] : '',
                    'active_since'=> isset($x['active_since']) ? date('c', (int)$x['active_since']) : null,
                    'active_till' => isset($x['active_till']) ? date('c', (int)$x['active_till']) : null,
                    'hosts'       => isset($x['hosts']) ? count($x['hosts']) : 0,
                    'hostgroups'  => isset($x['hostgroups']) ? count($x['hostgroups']) : 0,
                );
            }, $m1) : null,
        );

        // 2) maintenance.get SANS cibles (au cas où selectHostGroups/selectHosts pose souci)
        $zbx->error = '';
        $m2 = $zbx->call('maintenance.get', array('output' => 'extend'));
        $dbg['maintenance_get_plain'] = array(
            'error' => $zbx->error,
            'count' => is_array($m2) ? count($m2) : null,
        );

        // 3) Contexte : hôtes / services visibles par ce jeton
        $zbx->error = '';
        $h = $zbx->call('host.get', array('countOutput' => true));
        $dbg['host_get'] = array('error' => $zbx->error, 'count' => $h);
        $zbx->error = '';
        $s = $zbx->call('service.get', array('countOutput' => true));
        $dbg['service_get'] = array('error' => $zbx->error, 'count' => $s);

        // 4) Ce que la page en déduit
        $dbg['page_maintenances'] = count(gnlst_build_maintenances($zbx));
    }

    echo json_encode($dbg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

/* --------------------------- Calcul de l'état ----------------------- */
$STATE = gnlst_get_state($GNLST);

/* ------------------------- Endpoint JSON (AJAX) --------------------- */
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode($STATE);
    exit;
}

/* ----------------------- Rendu serveur (fallback) ------------------- */
$STATUS_META = array(
    'up'       => array('Opérationnel',              'up'),
    'degraded' => array('Dégradé',                   'degraded'),
    'down'     => array('Panne',                     'down'),
    'unknown'  => array('Indéterminé',               'nd'),
);
$OVERALL_META = array(
    'up'       => array('Tous les systèmes sont opérationnels',        'up'),
    'degraded' => array('Certains systèmes sont en mode dégradé',      'degraded'),
    'partial'  => array('Incident en cours sur certains systèmes',     'down'),
    'down'     => array('Panne majeure en cours',                      'down'),
    'unknown'  => array('État des systèmes indisponible',              'nd'),
);

function gnlst_strip_cells($days) {
    $days = array_slice($days, -90);
    $pad  = 90 - count($days);
    $lbl  = array(-1 => 'Aucune donnée', 0 => 'Opérationnel', 1 => 'Dégradé', 2 => 'Panne');
    $cls  = array(-1 => 'nd', 0 => 'up', 1 => 'degraded', 2 => 'down');
    $out  = '';
    for ($i = 0; $i < $pad; $i++) $out .= '<span class="gnlst-cell nd" title="Aucune donnée"></span>';
    foreach ($days as $d) {
        $c = isset($cls[$d['code']]) ? $cls[$d['code']] : 'nd';
        $t = $d['date'] . ' — ' . (isset($lbl[$d['code']]) ? $lbl[$d['code']] : 'Aucune donnée');
        $out .= '<span class="gnlst-cell ' . $c . '" title="' . gnlst_h($t) . '"></span>';
    }
    return $out;
}

function gnlst_fmt_dt($ts) {
    if (!$ts) return '';
    $mois = array(1=>'janv.',2=>'févr.',3=>'mars',4=>'avr.',5=>'mai',6=>'juin',
                  7=>'juil.',8=>'août',9=>'sept.',10=>'oct.',11=>'nov.',12=>'déc.');
    return (int) date('j', $ts) . ' ' . $mois[(int) date('n', $ts)] . ' ' . date('Y', $ts) . ' à ' . date('H:i', $ts);
}

function gnlst_render_maintenance($list) {
    $rows = '';
    foreach ($list as $m) {
        $cls = $m['state'] === 'active' ? 'active' : 'scheduled';
        $lbl = $m['state'] === 'active' ? 'En cours' : 'Planifiée';
        $period = gnlst_fmt_dt($m['from']);
        if (!empty($m['till'])) $period .= ' → ' . gnlst_fmt_dt($m['till']);

        $rows .= '<div class="gnlst-maint-row">';
        $rows .=   '<div class="gnlst-maint-head">';
        $rows .=     '<svg class="gnlst-maint-ic" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M22.7 19.3l-6.4-6.4a5.5 5.5 0 0 0-6.9-7L12.6 3 11 1.4 7.7 4.6a5.5 5.5 0 0 0 7 6.9l6.4 6.4a1 1 0 0 0 1.4 0l.2-.2a1 1 0 0 0 0-1.4z"/></svg>';
        $rows .=     '<span class="gnlst-maint-name">' . gnlst_h($m['name']) . '</span>';
        $rows .=     '<span class="gnlst-maint-badge ' . $cls . '">' . $lbl . '</span>';
        $rows .=   '</div>';
        if ($period !== '')          $rows .= '<div class="gnlst-maint-period">' . gnlst_h($period) . '</div>';
        if (!empty($m['desc']))      $rows .= '<div class="gnlst-maint-desc">' . gnlst_h($m['desc']) . '</div>';
        if (!empty($m['impacted']))  $rows .= '<div class="gnlst-maint-impacted"><span class="gnlst-maint-lbl">Service(s) impacté(s)</span>' . gnlst_h(implode(' · ', $m['impacted'])) . '</div>';
        if (!empty($m['targets']))   $rows .= '<div class="gnlst-maint-targets"><span class="gnlst-maint-lbl">Hôtes concernés</span>' . gnlst_h(implode(' · ', $m['targets'])) . '</div>';
        $rows .= '</div>';
    }
    return '<section class="gnlst-card gnlst-maint"><h2 class="gnlst-card-title">Maintenances planifiées</h2>' . $rows . '</section>';
}

function gnlst_render($state, $meta, $overallMeta) {
    $om = isset($overallMeta[$state['overall']]) ? $overallMeta[$state['overall']] : $overallMeta['unknown'];

    // Bandeau global
    echo '<div class="gnlst-banner ' . $om[1] . '">';
    echo   '<span class="gnlst-dot"></span>';
    echo   '<div class="gnlst-banner-txt"><strong>' . gnlst_h($om[0]) . '</strong>';
    if (!empty($state['stale'])) {
        echo '<span class="gnlst-note">Données de secours — connexion à Zabbix momentanément indisponible.</span>';
    } elseif (!$state['ok'] && $state['error'] !== '') {
        echo '<span class="gnlst-note">' . gnlst_h($state['error']) . '</span>';
    }
    echo   '</div>';
    echo '</div>';

    // Carte « Maintenances planifiées » (entre le bandeau et les composants)
    if (!empty($state['maintenances'])) {
        echo gnlst_render_maintenance($state['maintenances']);
    }

    if (empty($state['components'])) {
        echo '<div class="gnlst-empty">';
        echo   '<p>Aucun composant à afficher pour le moment.</p>';
        if ($state['error'] !== '') echo '<p class="gnlst-note">' . gnlst_h($state['error']) . '</p>';
        echo '</div>';
        return;
    }

    // Regroupement par "group"
    $groups = array();
    foreach ($state['components'] as $c) $groups[$c['group']][] = $c;

    foreach ($groups as $gname => $items) {
        echo '<section class="gnlst-card">';
        echo   '<h2 class="gnlst-card-title">' . gnlst_h($gname) . '</h2>';
        foreach ($items as $c) {
            $sm = isset($meta[$c['status']]) ? $meta[$c['status']] : $meta['unknown'];
            echo '<div class="gnlst-row">';
            echo   '<div class="gnlst-row-head">';
            echo     '<span class="gnlst-dot ' . $sm[1] . '"></span>';
            echo     '<span class="gnlst-name">' . gnlst_h($c['name']) . '</span>';
            echo     '<span class="gnlst-badge ' . $sm[1] . '">' . gnlst_h($sm[0]) . '</span>';
            if (isset($c['uptime']) && $c['uptime'] !== null) {
                echo   '<span class="gnlst-uptime">' . gnlst_h(number_format($c['uptime'], 2, ',', ' ')) . ' %</span>';
            }
            echo   '</div>';
            if (!empty($c['detail']) && $c['status'] !== 'up') {
                echo '<div class="gnlst-detail">' . gnlst_h($c['detail']) . '</div>';
            }
            echo   '<div class="gnlst-strip">' . gnlst_strip_cells(isset($c['history']) ? $c['history'] : array()) . '</div>';
            echo   '<div class="gnlst-strip-legend"><span>il y a 90 jours</span><span>aujourd’hui</span></div>';
            echo '</div>';
        }
        echo '</section>';
    }
}

$PAGE_TITLE = 'État de nos systèmes';
?>
<?php
/* ---- Affichage : nouvelle charte (include/layout.php) ---- */
require_once __DIR__ . '/../include/layout.php';
gnl_ui_open('État des services', 'statut', array('main' => false));
?>
<main id="contenu">

    <div class="gnlst-wrap">

        <div class="gnlst-top">
        <header class="gnlst-head">
            <p class="gnlst-kicker">TRANSPARENCE</p>
            <h1>État des services</h1>
            <p class="gnlst-sub">Disponibilité en temps réel de nos serveurs à Besançon, mesurée en continu par notre supervision.</p>
        </header>
        <a href="/incident" class="gnl-b gnl-b-line"><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>Signaler un incident</a>
        </div>

        <div id="gnlst-live">
            <?php gnlst_render($STATE, $STATUS_META, $OVERALL_META); ?>
        </div>

        <p class="gnlst-updated" id="gnlst-updated"></p>

        <div class="gnlst-legend">
            <span><i style="background:#1f7a4a"></i> Opérationnel</span>
            <span><i style="background:#e0a100"></i> Dégradé</span>
            <span><i style="background:#7a1610"></i> Panne</span>
            <span><i style="background:#c5cbd8"></i> Aucune donnée</span>
        </div>

    </div>

</main>
<?php ob_start(); ?>
<script>
/* =====================================================================
   Rafraîchissement automatique de l'état (toutes les 60 s) via ?ajax=1
   ===================================================================== */
(function () {
    var initial = <?php echo json_encode($STATE); ?>;

    var STATUS = {
        up:       ['Opérationnel', 'up'],
        degraded: ['Dégradé', 'degraded'],
        down:     ['Panne', 'down'],
        unknown:  ['Indéterminé', 'nd']
    };
    var OVERALL = {
        up:       ['Tous les systèmes sont opérationnels', 'up'],
        degraded: ['Certains systèmes sont en mode dégradé', 'degraded'],
        partial:  ['Incident en cours sur certains systèmes', 'down'],
        down:     ['Panne majeure en cours', 'down'],
        unknown:  ['État des systèmes indisponible', 'nd']
    };
    var CELL_CLS = {'-1':'nd','0':'up','1':'degraded','2':'down'};
    var CELL_LBL = {'-1':'Aucune donnée','0':'Opérationnel','1':'Dégradé','2':'Panne'};

    function esc(s){ var d=document.createElement('div'); d.textContent=(s==null?'':String(s)); return d.innerHTML; }
    function nf(n){ return Number(n).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, ' '); }

    var MOIS = ['janv.','févr.','mars','avr.','mai','juin','juil.','août','sept.','oct.','nov.','déc.'];
    function fmtDt(ts){
        if(!ts) return '';
        var d = new Date(ts*1000);
        function p(n){ return (n<10?'0':'')+n; }
        return d.getDate()+' '+MOIS[d.getMonth()]+' '+d.getFullYear()+' à '+p(d.getHours())+':'+p(d.getMinutes());
    }
    function maintHTML(list){
        if(!list || !list.length) return '';
        var html = '<section class="gnlst-card gnlst-maint"><h2 class="gnlst-card-title">Maintenances planifiées</h2>';
        list.forEach(function(m){
            var cls = m.state==='active' ? 'active' : 'scheduled';
            var lbl = m.state==='active' ? 'En cours' : 'Planifiée';
            var period = fmtDt(m.from) + (m.till ? ' → '+fmtDt(m.till) : '');
            html += '<div class="gnlst-maint-row"><div class="gnlst-maint-head">'
                 +  '<svg class="gnlst-maint-ic" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M22.7 19.3l-6.4-6.4a5.5 5.5 0 0 0-6.9-7L12.6 3 11 1.4 7.7 4.6a5.5 5.5 0 0 0 7 6.9l6.4 6.4a1 1 0 0 0 1.4 0l.2-.2a1 1 0 0 0 0-1.4z"/></svg>'
                 +  '<span class="gnlst-maint-name">'+esc(m.name)+'</span>'
                 +  '<span class="gnlst-maint-badge '+cls+'">'+lbl+'</span></div>';
            if (period)      html += '<div class="gnlst-maint-period">'+esc(period)+'</div>';
            if (m.desc)      html += '<div class="gnlst-maint-desc">'+esc(m.desc)+'</div>';
            if (m.impacted && m.impacted.length) html += '<div class="gnlst-maint-impacted"><span class="gnlst-maint-lbl">Service(s) impacté(s)</span>'+esc(m.impacted.join(' · '))+'</div>';
            if (m.targets && m.targets.length) html += '<div class="gnlst-maint-targets"><span class="gnlst-maint-lbl">Hôtes concernés</span>'+esc(m.targets.join(' · '))+'</div>';
            html += '</div>';
        });
        return html + '</section>';
    }

    function stripHTML(days){
        days = (days||[]).slice(-90);
        var html = '', pad = 90 - days.length, i;
        for(i=0;i<pad;i++) html += '<span class="gnlst-cell nd" title="Aucune donnée"></span>';
        days.forEach(function(d){
            var cls = CELL_CLS[String(d.code)] || 'nd';
            var lbl = CELL_LBL[String(d.code)] || 'Aucune donnée';
            html += '<span class="gnlst-cell '+cls+'" title="'+esc(d.date+' — '+lbl)+'"></span>';
        });
        return html;
    }

    function render(state){
        var om = OVERALL[state.overall] || OVERALL.unknown;
        var html = '';

        html += '<div class="gnlst-banner '+om[1]+'"><span class="gnlst-dot"></span>'
             +  '<div class="gnlst-banner-txt"><strong>'+esc(om[0])+'</strong>';
        if (state.stale) html += '<span class="gnlst-note">Données de secours — connexion à Zabbix momentanément indisponible.</span>';
        else if (!state.ok && state.error) html += '<span class="gnlst-note">'+esc(state.error)+'</span>';
        html += '</div></div>';

        html += maintHTML(state.maintenances);

        var comps = state.components || [];
        if (!comps.length){
            html += '<div class="gnlst-empty"><p>Aucun composant à afficher pour le moment.</p>'
                 +  (state.error ? '<p class="gnlst-note">'+esc(state.error)+'</p>' : '') + '</div>';
            document.getElementById('gnlst-live').innerHTML = html;
            return;
        }

        var groups = {}, order = [];
        comps.forEach(function(c){ if(!groups[c.group]){groups[c.group]=[];order.push(c.group);} groups[c.group].push(c); });

        order.forEach(function(g){
            html += '<section class="gnlst-card"><h2 class="gnlst-card-title">'+esc(g)+'</h2>';
            groups[g].forEach(function(c){
                var sm = STATUS[c.status] || STATUS.unknown;
                html += '<div class="gnlst-row"><div class="gnlst-row-head">'
                     +  '<span class="gnlst-dot '+sm[1]+'"></span>'
                     +  '<span class="gnlst-name">'+esc(c.name)+'</span>'
                     +  '<span class="gnlst-badge '+sm[1]+'">'+esc(sm[0])+'</span>';
                if (c.uptime !== null && c.uptime !== undefined)
                    html += '<span class="gnlst-uptime">'+nf(c.uptime)+' %</span>';
                html += '</div>';
                if (c.detail && c.status !== 'up')
                    html += '<div class="gnlst-detail">'+esc(c.detail)+'</div>';
                html += '<div class="gnlst-strip">'+stripHTML(c.history)+'</div>'
                     +  '<div class="gnlst-strip-legend"><span>il y a 90 jours</span><span>aujourd’hui</span></div>';
                html += '</div>';
            });
            html += '</section>';
        });

        document.getElementById('gnlst-live').innerHTML = html;
    }

    var lastUpdated = initial.updated ? initial.updated * 1000 : Date.now();
    function relative(ms){
        var s = Math.max(0, Math.round((Date.now()-ms)/1000));
        if (s < 5)  return "à l’instant";
        if (s < 60) return 'il y a ' + s + ' s';
        var m = Math.round(s/60);
        if (m < 60) return 'il y a ' + m + ' min';
        var h = Math.round(m/60);
        return 'il y a ' + h + ' h';
    }
    function tick(){
        var el = document.getElementById('gnlst-updated');
        if (el) el.textContent = 'Mis à jour ' + relative(lastUpdated);
    }

    function refresh(){
        fetch('?ajax=1', {headers:{'Accept':'application/json'}, cache:'no-store'})
            .then(function(r){ return r.json(); })
            .then(function(state){
                render(state);
                lastUpdated = state.updated ? state.updated * 1000 : Date.now();
                tick();
            })
            .catch(function(){ /* on garde l'affichage courant */ });
    }

    // Rendu initial identique + horloge + auto-refresh
    render(initial);
    tick();
    setInterval(tick, 1000);
    setInterval(refresh, 60000);
})();
</script>
<?php gnl_ui_close(ob_get_clean()); ?>
