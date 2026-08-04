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
        curl_close($ch);
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

function gnlst_build_components($zbx, $mode, $groupName) {
    /* --- Mode Services (IT Services Zabbix) --------------------------- */
    if ($mode === 'auto' || $mode === 'services') {
        $svc = $zbx->call('service.get', array(
            'output'        => array('serviceid', 'name', 'description', 'status'),
            'selectParents' => array('serviceid'),
            'sortfield'     => array('name'),
        ));
        if (is_array($svc) && count($svc) > 0) {
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
                    'name'   => $s['name'],
                    'group'  => 'Services',
                    'status' => $status,
                    'detail' => isset($s['description']) ? $s['description'] : '',
                );
            }
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
            'name'   => $h['name'],
            'group'  => $grp,
            'status' => gnlst_severity_to_status($sev),
            'detail' => $sev < 0 ? '' : (isset($probByHost[$hid]) ? $probByHost[$hid] : ''),
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

function gnlst_compute($cfg) {
    $writable = gnlst_dir($cfg['cache_dir']);
    $state = array('ok' => false, 'error' => '', 'mode' => '', 'overall' => 'unknown',
                   'components' => array(), 'updated' => time(), 'stale' => false);

    $endpoint = gnlst_endpoint($cfg['url']);
    if ($endpoint === '') { $state['error'] = 'ZABBIX_API_URL n’est pas configurée.'; return $state; }

    $zbx = new GnlstZabbix($endpoint, $cfg['token'], $cfg['user'], $cfg['pass'], $cfg['insecure']);
    if (!$zbx->connect()) { $state['error'] = $zbx->error; return $state; }

    $res = gnlst_build_components($zbx, $cfg['mode'], $cfg['group']);
    if (!$res['components'] && $zbx->error !== '') { $state['error'] = $zbx->error; return $state; }

    $state['ok']         = true;
    $state['mode']       = $res['mode'];
    $state['components'] = $res['components'];
    $state['overall']    = gnlst_overall($res['components']);
    $state['version']    = $zbx->version;

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
<!DOCTYPE html>
<html lang="fr-FR">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="robots" content="index,follow" />
    <title><?= gnlst_h($PAGE_TITLE) ?> — <?= gnlst_h($SITE_NAME) ?></title>
    <meta name="description" content="État en temps réel des services et de l’infrastructure <?= gnlst_h($SITE_NAME) ?>." />
    <link rel="canonical" href="https://gnl-solution.fr/pages/stats" />

    <!-- Police du thème (Manrope) -->
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet" />

    <!-- =====================================================================
         EN-TÊTE COMMUN DU THÈME
         Pour un rendu 100 % identique au reste du site, collez ICI le contenu
         du <head> d'une de vos pages (par ex. cart.php) : les <style> et
         <link rel="stylesheet"> du thème WordPress. Le bloc <style> ci-dessous
         est une base de secours autonome pour que l'en-tête, le pied de page
         et la page s'affichent proprement même sans ces styles.
    ====================================================================== -->
    <style>
    /* --- Base de secours (peut être retirée si vous collez le head du thème) --- */
    :root{
        --wp--style--global--content-size:645px;
        --wp--style--global--wide-size:1340px;
    }
    *{box-sizing:border-box}
    body{margin:0;font-family:"Manrope",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#353535;background:#fff;-webkit-font-smoothing:antialiased}
    a{color:inherit}
    header .wp-block-navigation__container,
    footer .wp-block-navigation__container{display:flex;flex-wrap:wrap;gap:1.25rem;align-items:center;list-style:none;margin:0;padding:0}
    .wp-block-navigation a{text-decoration:none}
    .wp-block-navigation a:hover{text-decoration:underline}
    .wp-block-navigation__submenu-container{position:absolute;background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:.35rem 0;min-width:220px;box-shadow:0 8px 24px rgba(0,0,0,.08);z-index:40}
    .wp-block-navigation__submenu-container li{list-style:none}
    .wp-block-navigation__submenu-container a{display:block;padding:.4rem 1rem}
    .wp-block-navigation-item.has-child{position:relative}
    .wp-block-navigation__responsive-container-open{display:none;background:none;border:0;cursor:pointer}
    @media (max-width:781px){
        .wp-block-navigation__responsive-container-open{display:inline-flex}
        header .wp-block-navigation__container{display:none}
    }

    /* ============================ Page « status » ============================ */
    .gnlst-wrap{max-width:900px;margin:0 auto;padding:2.5rem 1.25rem 3.5rem;font-family:"Manrope",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#353535}
    .gnlst-head{margin-bottom:1.5rem}
    .gnlst-head h1{font-size:1.9rem;font-weight:800;margin:0 0 .35rem;letter-spacing:-.01em}
    .gnlst-sub{margin:0;color:#6b7280;font-size:1rem}

    /* Couleurs d'état */
    .gnlst-wrap{--up:#16a34a;--up-bg:#dcfce7;--deg:#f59e0b;--deg-bg:#fef3c7;--down:#ef4444;--down-bg:#fee2e2;--nd:#d1d5db;--line:#e5e7eb}

    /* Bandeau global */
    .gnlst-banner{display:flex;align-items:center;gap:.9rem;padding:1.1rem 1.25rem;border-radius:14px;border:1px solid var(--line);background:#fff;box-shadow:0 1px 2px rgba(13,19,30,.05);margin-bottom:1.75rem;border-left-width:5px}
    .gnlst-banner.up{border-left-color:var(--up)}
    .gnlst-banner.degraded{border-left-color:var(--deg)}
    .gnlst-banner.down{border-left-color:var(--down)}
    .gnlst-banner.nd{border-left-color:var(--nd)}
    .gnlst-banner-txt{display:flex;flex-direction:column;gap:.15rem}
    .gnlst-banner-txt strong{font-size:1.15rem;font-weight:700}
    .gnlst-note{font-size:.82rem;color:#6b7280}

    /* Pastille d'état */
    .gnlst-dot{width:12px;height:12px;border-radius:50%;background:var(--nd);flex:0 0 auto}
    .gnlst-banner .gnlst-dot{width:14px;height:14px}
    .gnlst-dot.up{background:var(--up)}
    .gnlst-dot.degraded{background:var(--deg)}
    .gnlst-dot.down{background:var(--down)}
    .gnlst-banner.up .gnlst-dot{box-shadow:0 0 0 0 rgba(22,163,74,.5);animation:gnlst-pulse 2.4s infinite}
    @keyframes gnlst-pulse{0%{box-shadow:0 0 0 0 rgba(22,163,74,.45)}70%{box-shadow:0 0 0 8px rgba(22,163,74,0)}100%{box-shadow:0 0 0 0 rgba(22,163,74,0)}}

    /* Cartes de composants */
    .gnlst-card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:1.1rem 1.25rem;margin-bottom:1.1rem;box-shadow:0 1px 2px rgba(13,19,30,.05)}
    .gnlst-card-title{font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#9ca3af;margin:0 0 .9rem}
    .gnlst-row{padding:.85rem 0;border-top:1px solid var(--line)}
    .gnlst-row:first-of-type{border-top:0;padding-top:0}
    .gnlst-row-head{display:flex;align-items:center;gap:.6rem;flex-wrap:wrap}
    .gnlst-name{font-weight:600;font-size:1rem}
    .gnlst-badge{font-size:.72rem;font-weight:600;padding:.12rem .55rem;border-radius:999px;color:#374151;background:#f3f4f6}
    .gnlst-badge.up{color:var(--up);background:var(--up-bg)}
    .gnlst-badge.degraded{color:#b45309;background:var(--deg-bg)}
    .gnlst-badge.down{color:#b91c1c;background:var(--down-bg)}
    .gnlst-uptime{margin-left:auto;font-variant-numeric:tabular-nums;font-weight:700;font-size:.9rem;color:#374151}
    .gnlst-detail{margin:.35rem 0 0;font-size:.85rem;color:#6b7280}

    /* Frise 90 jours */
    .gnlst-strip{display:flex;gap:2px;margin-top:.7rem;height:30px;align-items:stretch}
    .gnlst-cell{flex:1 1 0;min-width:2px;border-radius:2px;background:var(--nd)}
    .gnlst-cell.up{background:var(--up)}
    .gnlst-cell.degraded{background:var(--deg)}
    .gnlst-cell.down{background:var(--down)}
    .gnlst-cell:hover{filter:brightness(.9)}
    .gnlst-strip-legend{display:flex;justify-content:space-between;font-size:.72rem;color:#9ca3af;margin-top:.35rem}

    /* Divers */
    .gnlst-empty{background:#fff;border:1px dashed var(--line);border-radius:14px;padding:2rem;text-align:center;color:#6b7280}
    .gnlst-updated{text-align:center;color:#9ca3af;font-size:.82rem;margin:1.5rem 0 .75rem}
    .gnlst-legend{display:flex;justify-content:center;gap:1.25rem;flex-wrap:wrap;font-size:.8rem;color:#6b7280}
    .gnlst-legend span{display:inline-flex;align-items:center;gap:.4rem}
    .gnlst-legend i{width:10px;height:10px;border-radius:2px;display:inline-block}

    @media (max-width:600px){
        .gnlst-wrap{padding:1.75rem 1rem 2.5rem}
        .gnlst-head h1{font-size:1.55rem}
        .gnlst-strip{height:26px}
    }
    @media (prefers-reduced-motion:reduce){
        .gnlst-banner.up .gnlst-dot{animation:none}
    }
    </style>
</head>
<body class="home wp-singular page-template-default page wp-custom-logo wp-embed-responsive wp-theme-twentytwentyfive surecart-theme-light">
    <script>
        const onSkipLinkClick = () => {
            const htmlElement = document.querySelector('html');
            htmlElement.style['scroll-behavior'] = 'smooth';
            setTimeout(() => htmlElement.style['scroll-behavior'] = null, 1000);
        }
        document.addEventListener("DOMContentLoaded", () => {
            if (!document.querySelector('#content')) {
                const l = document.querySelector('.ea11y-skip-to-content-link');
                if (l) l.remove();
            }
        });
    </script>
    <nav aria-label="Skip to content navigation">
        <a class="ea11y-skip-to-content-link" href="#content" tabindex="-1" onclick="onSkipLinkClick()">
            Aller au contenu principal
        </a>
        <div class="ea11y-skip-to-content-backdrop"></div>
    </nav>

<div class="wp-site-blocks">

<?php
// En-tête commun (identique aux autres pages)
if (is_readable('../include/header.php')) {
    include '../include/header.php';
}
?>

<main id="content" class="wp-block-group has-background has-global-padding is-layout-constrained wp-block-group-is-layout-constrained" style="background-color:#f3f3f3;margin-top:0">

    <div class="gnlst-wrap">

        <header class="gnlst-head">
            <h1>État de nos systèmes</h1>
            <p class="gnlst-sub">Suivi en temps réel de nos services et de notre infrastructure.</p>
        </header>

        <div id="gnlst-live">
            <?php gnlst_render($STATE, $STATUS_META, $OVERALL_META); ?>
        </div>

        <p class="gnlst-updated" id="gnlst-updated"></p>

        <div class="gnlst-legend">
            <span><i style="background:#16a34a"></i> Opérationnel</span>
            <span><i style="background:#f59e0b"></i> Dégradé</span>
            <span><i style="background:#ef4444"></i> Panne</span>
            <span><i style="background:#d1d5db"></i> Aucune donnée</span>
        </div>

    </div>

</main>

<?php
// Pied de page commun (identique aux autres pages)
if (is_readable('../include/footer.php')) {
    include '../include/footer.php';
}
?>

</div><!-- .wp-site-blocks -->

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

</body>
<!-- stats.php — GNL Solution (état des systèmes / Zabbix) -->
</html>
