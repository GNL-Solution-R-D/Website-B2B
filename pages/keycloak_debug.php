<?php
/* =====================================================================
   GNL Solution — Diagnostic organisation Keycloak  (keycloak_debug.php)
   ---------------------------------------------------------------------
   Outil TEMPORAIRE pour comprendre pourquoi les organisations d'un
   utilisateur ne remontent pas. Il effectue une connexion REST (grant
   "password") avec les identifiants saisis, puis affiche le claim
   "organization" tel qu'il apparaît dans CHAQUE source (access token,
   id token, userinfo) et ce que le code en déduit.

   SÉCURITÉ : la page est verrouillée par la variable d'environnement
   KEYCLOAK_DEBUG_KEY. Elle n'est accessible qu'avec ?key=<cette valeur>.
   >>> SUPPRIMEZ ce fichier une fois le diagnostic terminé. <<<
   ===================================================================== */

require __DIR__ . '/keycloak_rest.php';
header('X-Robots-Tag: noindex, nofollow');

$expected = gnl_env('KEYCLOAK_DEBUG_KEY');
$provided = isset($_REQUEST['key']) ? (string) $_REQUEST['key'] : '';
if ($expected === '' || !hash_equals($expected, $provided)) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><body style="font-family:system-ui;max-width:640px;margin:3rem auto;color:#353535">'
       . '<h1 style="font-size:1.2rem">Accès refusé</h1>'
       . '<p>Définissez la variable d\'environnement <code>KEYCLOAK_DEBUG_KEY</code> (une valeur secrète), '
       . 'puis appelez cette page avec <code>?key=VOTRE_VALEUR</code>.</p>'
       . '<p style="color:#b00">Supprimez ce fichier après usage.</p></body>';
    exit;
}

function jd($v) { return htmlspecialchars(json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); }

$ran = false; $err = ''; $report = array();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ran = true;
    $u = trim((string) (isset($_POST['username']) ? $_POST['username'] : ''));
    $p = (string) (isset($_POST['password']) ? $_POST['password'] : '');
    $scope = trim((string) (isset($_POST['scope']) ? $_POST['scope'] : '')) ?: KEYCLOAK_SCOPES;

    $tok = gnl_kc_password_grant($u, $p, $scope);
    if (!is_array($tok) || empty($tok['access_token'])) {
        $err = gnl_kc_detail($tok) . ' — ' . gnl_login_error_fr($tok);
    } else {
        $atc = gnl_jwt_payload($tok['access_token']);
        $itc = !empty($tok['id_token']) ? gnl_jwt_payload($tok['id_token']) : array();
        $uic = gnl_kc_userinfo($tok['access_token']) ?: array();

        $mk = function ($claims) {
            $org = isset($claims['organization']) ? $claims['organization'] : null;
            $list = gnl_org_extract_all($org);
            $labels = array();
            foreach ($list as $o) { $l = gnl_org_label($o); $labels[] = trim($l['title'] . ($l['sub'] !== '' ? ' — ' . $l['sub'] : '')); }
            return array(
                'present'   => array_key_exists('organization', $claims),
                'claim'     => $org,
                'count'     => count($list),
                'labels'    => $labels,
                'keys'      => array_keys($claims),
            );
        };
        $A = $mk($atc); $I = $mk($itc); $U = $mk($uic);

        $best = max($A['count'], $I['count'], $U['count']);
        $report = array(
            'scope_demande' => $scope,
            'scope_accorde' => isset($tok['scope']) ? $tok['scope'] : '(non renvoyé)',
            'access'   => $A,
            'id'       => $I,
            'userinfo' => $U,
            'best'     => $best,
            'verdict'  => $best >= 2 ? 'choose (page /organisation affichée)' : ($best === 1 ? 'done (1 organisation, pas de choix)' : 'done (aucune organisation détectée)'),
        );
    }
}
$keyAttr = htmlspecialchars($provided, ENT_QUOTES);
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Diagnostic organisation — Keycloak</title>
<style>
 body{font-family:system-ui,Segoe UI,Roboto,sans-serif;color:#353535;max-width:900px;margin:2rem auto;padding:0 1rem;line-height:1.5}
 h1{font-size:1.3rem} h2{font-size:1rem;margin:1.4rem 0 .4rem}
 form.q{display:flex;gap:.6rem;flex-wrap:wrap;align-items:end;background:#f4f6f1;border:1px solid #e4e6e2;border-radius:12px;padding:1rem}
 label{display:block;font-size:.8rem;font-weight:600;margin-bottom:.25rem}
 input{border:1px solid #cfd3ca;border-radius:8px;padding:.55rem .7rem;font:inherit}
 input[type=text],input[type=password]{min-width:220px}
 button{border:none;background:#6c9400;color:#fff;border-radius:9px;padding:.6rem 1.1rem;font:inherit;font-weight:700;cursor:pointer}
 pre{background:#0f1113;color:#e6e6e6;border-radius:10px;padding:.9rem;overflow:auto;font-size:.82rem}
 .box{border:1px solid #e4e6e2;border-radius:12px;padding:1rem;margin:.6rem 0}
 .ok{color:#1f6323;font-weight:700} .no{color:#8e2a1e;font-weight:700}
 .verdict{font-size:1.05rem;font-weight:700;background:#eef6e6;border:1px solid #cfe3b8;border-radius:10px;padding:.7rem .9rem}
 code{background:#eee;padding:.05rem .3rem;border-radius:4px}
 .warn{background:#fff6e5;border:1px solid #f0d8a0;border-radius:10px;padding:.7rem .9rem;font-size:.9rem}
</style></head><body>
<h1>Diagnostic — d'où viennent les organisations&nbsp;?</h1>
<p class="warn">Outil temporaire. Il se connecte réellement à Keycloak avec les identifiants saisis pour lire le jeton. <strong>Supprimez ce fichier après usage.</strong></p>

<form class="q" method="post" action="keycloak_debug.php?key=<?php echo $keyAttr; ?>">
  <div><label>E-mail / identifiant</label><input type="text" name="username" value="<?php echo htmlspecialchars(isset($_POST['username'])?$_POST['username']:'',ENT_QUOTES); ?>" required></div>
  <div><label>Mot de passe</label><input type="password" name="password" required></div>
  <div><label>Scope (modifiable)</label><input type="text" name="scope" value="<?php echo htmlspecialchars(isset($_POST['scope'])&&$_POST['scope']!==''?$_POST['scope']:KEYCLOAK_SCOPES,ENT_QUOTES); ?>"></div>
  <div><button type="submit">Analyser</button></div>
</form>

<?php if ($ran && $err !== ''): ?>
  <div class="box"><span class="no">Échec de connexion :</span> <?php echo htmlspecialchars($err, ENT_QUOTES); ?></div>
<?php elseif ($ran): ?>
  <div class="verdict">Verdict : <?php echo htmlspecialchars($report['verdict'], ENT_QUOTES); ?></div>
  <p>Scope demandé : <code><?php echo htmlspecialchars($report['scope_demande'],ENT_QUOTES); ?></code> &middot;
     scope accordé par Keycloak : <code><?php echo htmlspecialchars($report['scope_accorde'],ENT_QUOTES); ?></code></p>

  <?php foreach (array('access'=>'Access token','id'=>'ID token','userinfo'=>'UserInfo') as $k=>$titre): $S=$report[$k]; ?>
    <h2><?php echo $titre; ?> — claim <code>organization</code> :
        <?php echo $S['present'] ? '<span class="ok">présent</span>' : '<span class="no">absent</span>'; ?>
        (<?php echo (int)$S['count']; ?> organisation<?php echo $S['count']>1?'s':''; ?>)</h2>
    <?php if ($S['labels']): ?><p><?php echo htmlspecialchars(implode(' | ', $S['labels']),ENT_QUOTES); ?></p><?php endif; ?>
    <pre><?php echo jd($S['claim']); ?></pre>
    <details><summary style="cursor:pointer;font-size:.85rem">Voir toutes les clés de cette source</summary>
      <pre><?php echo htmlspecialchars(implode(', ', $S['keys']),ENT_QUOTES); ?></pre></details>
  <?php endforeach; ?>

  <?php if ($report['best'] < 2): ?>
    <div class="warn">
      <strong>Moins de 2 organisations détectées.</strong> Pistes, selon ce que tu vois ci-dessus :
      <ul>
        <li>Le claim est <em>absent partout</em> : le mapper d'organisation n'ajoute le claim à aucun jeton, ou le scope <code>organization</code> n'est pas accordé (vérifie « scope accordé »). Assure-toi que le scope <code>organization</code> est bien un <em>client scope</em> par défaut/optionnel du client <code>siteweb</code>.</li>
        <li>Le claim vaut <code>true</code> ou ne contient qu'un <em>nom</em> : active « Add organization attributes » (et « Add organization id ») sur le mapper d'appartenance du scope <code>organization</code>.</li>
        <li>Une seule organisation listée alors que l'utilisateur en a deux : demande toutes les appartenances en mettant le scope à <code>organization:*</code> (teste-le dans le champ ci-dessus). Si ça marche, reporte-le dans <code>KEYCLOAK_SCOPES</code>.</li>
      </ul>
    </div>
  <?php endif; ?>
<?php endif; ?>
</body></html>
