<?php
/* =====================================================================
   GNL Solution — Inscription  (/inscription  ->  inscription.php)
   ---------------------------------------------------------------------
   Création de compte « maison » via l'API REST Keycloak. Le client peut,
   au passage, CRÉER SON ORGANISATION et renseigner ses attributs
   (raison sociale, SIRET, TVA, adresse…) :

     1) jeton d'administration (grant "client_credentials") ;
     2) vérification d'unicité de l'e-mail ;
     3) si demandé : création de l'organisation + ses attributs ;
     4) création de l'utilisateur (POST /users) ;
     5) rattachement de l'utilisateur à l'organisation (member) ;
     6) connexion immédiate (grant "password") -> $_SESSION['gnl_user'].

   Prérequis Keycloak (compte de service) : rôles realm-management
   "manage-users" ET "manage-organizations" (+ "view-organizations").
   Client "siteweb" avec Direct access grants pour l'étape 6.
   ===================================================================== */

ob_start();
require __DIR__ . '/keycloak_rest.php';

$return = gnl_safe_return(isset($_REQUEST['return']) ? $_REQUEST['return'] : '/commande');
if (!empty($_SESSION['gnl_user']) && is_array($_SESSION['gnl_user'])) {
    header('Location: ' . gnl_site_base() . $return);
    exit;
}

/* Attributs d'organisation (clés = attributs Keycloak, cf. capture). */
$ORG_KEYS = array('nom_commercial','raison','entite_legal','siren','siret','tva',
                  'ent_email','telephone','voie_nbr','voie_name','cp','commune','pays','namespace');

$error  = '';
$vals   = array('civilite' => '', 'prenom' => '', 'nom' => '', 'email' => '', 'phone' => '');
foreach ($ORG_KEYS as $k) { $vals['org_' . $k] = ''; }
$wantOrg = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array('civilite','prenom','nom','email','phone') as $k) {
        $vals[$k] = trim((string) (isset($_POST[$k]) ? $_POST[$k] : ''));
    }
    foreach ($ORG_KEYS as $k) {
        $vals['org_' . $k] = trim((string) (isset($_POST['org_' . $k]) ? $_POST['org_' . $k] : ''));
    }
    $wantOrg = !empty($_POST['create_org']);
    $pass    = (string) (isset($_POST['password'])  ? $_POST['password']  : '');
    $pass2   = (string) (isset($_POST['password2']) ? $_POST['password2'] : '');
    $cgv     = !empty($_POST['cgv']);

    if (!gnl_csrf_check()) {
        $error = "Session expirée. Merci de renvoyer le formulaire.";
    } elseif ($vals['prenom'] === '' || $vals['nom'] === '') {
        $error = "Indiquez votre prénom et votre nom.";
    } elseif ($vals['email'] === '' || !filter_var($vals['email'], FILTER_VALIDATE_EMAIL)) {
        $error = "Indiquez une adresse e-mail valide.";
    } elseif (strlen($pass) < 8) {
        $error = "Le mot de passe doit contenir au moins 8 caractères.";
    } elseif ($pass !== $pass2) {
        $error = "Les deux mots de passe ne correspondent pas.";
    } elseif ($wantOrg && $vals['org_nom_commercial'] === '' && $vals['org_raison'] === '') {
        $error = "Pour créer une organisation, indiquez au moins le nom commercial ou la raison sociale.";
    } elseif (!$cgv) {
        $error = "Vous devez accepter les conditions générales.";
    } elseif (gnl_kc_admin_token() === null) {
        $error = "Le service d'inscription est momentanément indisponible. Réessayez plus tard.";
    } else {
        /* Compte déjà existant ? */
        $exists = gnl_kc_find_user('email', $vals['email']);
        if (!$exists) $exists = gnl_kc_find_user('username', $vals['email']);
        if ($exists) {
            $error = "Un compte existe déjà avec cette adresse. Essayez de vous connecter.";
        } else {
            /* --- 1) Organisation (avant l'utilisateur : échec = pas de compte créé) --- */
            $orgId = ''; $orgOk = true;
            if ($wantOrg) {
                $orgAttrs = array();
                foreach ($ORG_KEYS as $k) {
                    if ($k === 'namespace') continue; // fixé plus bas à l'alias
                    if ($vals['org_' . $k] !== '') $orgAttrs[$k] = array($vals['org_' . $k]);
                }
                $orgName = $vals['org_nom_commercial'] !== '' ? $vals['org_nom_commercial'] : $vals['org_raison'];
                $alias   = gnl_slug($vals['org_namespace'] !== '' ? $vals['org_namespace'] : $orgName);
                $orgAttrs['namespace'] = array($alias);

                $orgRes = gnl_kc_create_organization($orgName, $alias, $orgAttrs);
                if (isset($orgRes['error'])) {
                    error_log('[GNL REST] create org failed: ' . $orgRes['error']);
                    $error  = "Création de l'organisation impossible. " . $orgRes['error'];
                    $orgOk  = false;
                } else {
                    $orgId = (string) $orgRes['id'];
                }
            }

            /* --- 2) Utilisateur --- */
            if ($orgOk) {
                $attributes = array();
                if ($vals['civilite'] !== '') $attributes['civilite'] = array($vals['civilite']);
                if ($vals['phone'] !== '')    { $attributes['phone'] = array($vals['phone']); $attributes['phone_number'] = array($vals['phone']); }

                $res = gnl_kc_create_user($vals['email'], $pass, $vals['prenom'], $vals['nom'], $attributes);

                if (isset($res['error']) && $res['error'] === 'exists') {
                    if ($orgId !== '') gnl_kc_delete_organization($orgId);
                    $error = "Un compte existe déjà avec cette adresse. Essayez de vous connecter.";
                } elseif (isset($res['error'])) {
                    if ($orgId !== '') gnl_kc_delete_organization($orgId); // pas d'organisation orpheline
                    error_log('[GNL REST] create user failed: ' . $res['error']);
                    $msg = $res['error'];
                    if (stripos($msg, 'password') !== false && stripos($msg, 'policy') !== false) {
                        $msg = "Le mot de passe ne respecte pas la politique de sécurité (longueur, complexité…).";
                    }
                    $error = "Création du compte impossible. " . $msg;
                } else {
                    /* --- 3) Rattachement à l'organisation --- */
                    $userId = (string) (isset($res['id']) ? $res['id'] : '');
                    if ($orgId !== '' && $userId !== '') {
                        if (!gnl_kc_org_add_member($orgId, $userId)) {
                            error_log('[GNL REST] add member failed org=' . $orgId . ' user=' . $userId);
                        }
                    }
                    /* --- 4) Connexion immédiate --- */
                    $tok = gnl_kc_password_grant($vals['email'], $pass);
                    if (is_array($tok) && !empty($tok['access_token'])) {
                        $r = gnl_kc_populate_session($tok);
                        if ($r === 'choose') {
                            $_SESSION['gnl_pending_auth']['return'] = $return;
                            header('Location: ' . gnl_site_base() . '/organisation');
                            exit;
                        }
                        if ($r) {
                            header('Location: ' . gnl_site_base() . $return);
                            exit;
                        }
                    }
                    header('Location: ' . gnl_site_base() . '/connexion?registered=1&return=' . rawurlencode($return));
                    exit;
                }
            }
        }
    }
}

$csrf    = gnl_csrf_token();
$retAttr = gnl_e($return);
$logUrl  = '/connexion?return=' . rawurlencode($return);
$v = function ($k) use ($vals) { return gnl_e(isset($vals[$k]) ? $vals[$k] : ''); };
$paysDefault = $vals['org_pays'] !== '' ? $vals['org_pays'] : 'France';

gnl_auth_head('Créer un compte', 'inscription');
?>
    <style>
      .gnl-orgbox{border:1px solid var(--gnl-line); border-radius:12px; padding:.2rem 1rem 1rem; margin:.2rem 0 1.1rem; background:color-mix(in srgb,var(--gnl-teal) 3%, #fff)}
      .gnl-orgbox .gnl-sep{margin-top:1rem}
    </style>

    <h1>Créer un compte</h1>
    <p class="sub">Quelques informations et vous pourrez commander en quelques clics.</p>

    <?php if ($error !== ''): ?>
      <div class="gnl-msg err"><?php echo gnl_icon('err'); ?><span><?php echo gnl_e($error); ?></span></div>
    <?php endif; ?>

    <form method="post" action="/inscription" data-gnl-auth autocomplete="on">
      <input type="hidden" name="csrf" value="<?php echo gnl_e($csrf); ?>">
      <input type="hidden" name="return" value="<?php echo $retAttr; ?>">

      <div class="gnl-field row">
        <div style="flex:0 0 34%">
          <label for="civilite">Civilité</label>
          <select class="gnl-in" id="civilite" name="civilite">
            <?php $civ = $vals['civilite']; ?>
            <option value=""  <?php echo $civ === '' ? 'selected' : ''; ?>>—</option>
            <option value="Madame"    <?php echo $civ === 'Madame' ? 'selected' : ''; ?>>Madame</option>
            <option value="Monsieur"  <?php echo $civ === 'Monsieur' ? 'selected' : ''; ?>>Monsieur</option>
          </select>
        </div>
        <div>
          <label for="prenom">Prénom</label>
          <input class="gnl-in" type="text" id="prenom" name="prenom" value="<?php echo $v('prenom'); ?>"
                 autocomplete="given-name" placeholder="Prénom" required>
        </div>
      </div>

      <div class="gnl-field">
        <label for="nom">Nom</label>
        <input class="gnl-in" type="text" id="nom" name="nom" value="<?php echo $v('nom'); ?>"
               autocomplete="family-name" placeholder="Nom" required>
      </div>

      <div class="gnl-field">
        <label for="email">E-mail</label>
        <input class="gnl-in" type="email" id="email" name="email" value="<?php echo $v('email'); ?>"
               autocomplete="email" autocapitalize="none" spellcheck="false"
               placeholder="vous@exemple.fr" required>
      </div>

      <div class="gnl-field">
        <label for="phone">Téléphone <span style="font-weight:400;color:#9aa093">(facultatif)</span></label>
        <input class="gnl-in" type="tel" id="phone" name="phone" value="<?php echo $v('phone'); ?>"
               autocomplete="tel" placeholder="06 12 34 56 78">
      </div>

      <div class="gnl-field">
        <label for="password">Mot de passe</label>
        <div class="gnl-pass">
          <input class="gnl-in" type="password" id="password" name="password"
                 autocomplete="new-password" placeholder="8 caractères minimum" minlength="8" required>
          <button type="button" class="gnl-eye" aria-label="Afficher le mot de passe" tabindex="-1">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>
        <p class="gnl-hint">Au moins 8 caractères. Utilisez idéalement lettres, chiffres et symboles.</p>
      </div>

      <div class="gnl-field">
        <label for="password2">Confirmer le mot de passe</label>
        <div class="gnl-pass">
          <input class="gnl-in" type="password" id="password2" name="password2"
                 autocomplete="new-password" placeholder="Retapez le mot de passe" minlength="8" required>
          <button type="button" class="gnl-eye" aria-label="Afficher le mot de passe" tabindex="-1">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>
      </div>

      <!-- ===================== Organisation (optionnel) ===================== -->
      <label class="gnl-check" style="margin:.2rem 0 .5rem">
        <input type="checkbox" name="create_org" id="create_org" value="1" <?php echo $wantOrg ? 'checked' : ''; ?>>
        <span>Je représente une entreprise et souhaite créer mon organisation</span>
      </label>

      <div class="gnl-orgbox" id="org-fields" style="display:<?php echo $wantOrg ? 'block' : 'none'; ?>">
        <div class="gnl-sep">Informations de l'organisation</div>

        <div class="gnl-field row">
          <div>
            <label for="org_nom_commercial">Nom commercial</label>
            <input class="gnl-in" type="text" id="org_nom_commercial" name="org_nom_commercial" value="<?php echo $v('org_nom_commercial'); ?>" placeholder="COUTUREMANIA">
          </div>
          <div>
            <label for="org_raison">Raison sociale</label>
            <input class="gnl-in" type="text" id="org_raison" name="org_raison" value="<?php echo $v('org_raison'); ?>" placeholder="Nom légal / gérant">
          </div>
        </div>

        <div class="gnl-field">
          <label for="org_entite_legal">Forme juridique</label>
          <input class="gnl-in" type="text" id="org_entite_legal" name="org_entite_legal" value="<?php echo $v('org_entite_legal'); ?>" list="formes" placeholder="Entrepreneur individuel, SARL, SAS…">
          <datalist id="formes">
            <option value="Entrepreneur individuel"></option><option value="EI"></option><option value="EIRL"></option>
            <option value="EURL"></option><option value="SARL"></option><option value="SAS"></option>
            <option value="SASU"></option><option value="SA"></option><option value="SCI"></option>
            <option value="SNC"></option><option value="Association"></option><option value="Micro-entreprise"></option>
          </datalist>
        </div>

        <div class="gnl-field row">
          <div>
            <label for="org_siren">SIREN</label>
            <input class="gnl-in" type="text" id="org_siren" name="org_siren" value="<?php echo $v('org_siren'); ?>" inputmode="numeric" placeholder="947628517">
          </div>
          <div>
            <label for="org_siret">SIRET</label>
            <input class="gnl-in" type="text" id="org_siret" name="org_siret" value="<?php echo $v('org_siret'); ?>" inputmode="numeric" placeholder="94762851700023">
          </div>
        </div>

        <div class="gnl-field">
          <label for="org_tva">N° TVA intracommunautaire</label>
          <input class="gnl-in" type="text" id="org_tva" name="org_tva" value="<?php echo $v('org_tva'); ?>" placeholder="FR57947628517">
        </div>

        <div class="gnl-field row">
          <div>
            <label for="org_ent_email">E-mail de l'entreprise</label>
            <input class="gnl-in" type="email" id="org_ent_email" name="org_ent_email" value="<?php echo $v('org_ent_email'); ?>" autocapitalize="none" placeholder="contact@entreprise.fr">
          </div>
          <div>
            <label for="org_telephone">Téléphone</label>
            <input class="gnl-in" type="tel" id="org_telephone" name="org_telephone" value="<?php echo $v('org_telephone'); ?>" placeholder="03 00 00 00 00">
          </div>
        </div>

        <div class="gnl-field row">
          <div style="flex:0 0 28%">
            <label for="org_voie_nbr">N°</label>
            <input class="gnl-in" type="text" id="org_voie_nbr" name="org_voie_nbr" value="<?php echo $v('org_voie_nbr'); ?>" placeholder="30">
          </div>
          <div>
            <label for="org_voie_name">Voie / rue</label>
            <input class="gnl-in" type="text" id="org_voie_name" name="org_voie_name" value="<?php echo $v('org_voie_name'); ?>" placeholder="RUE Ronchaux">
          </div>
        </div>

        <div class="gnl-field row">
          <div style="flex:0 0 34%">
            <label for="org_cp">Code postal</label>
            <input class="gnl-in" type="text" id="org_cp" name="org_cp" value="<?php echo $v('org_cp'); ?>" inputmode="numeric" placeholder="25000">
          </div>
          <div>
            <label for="org_commune">Ville</label>
            <input class="gnl-in" type="text" id="org_commune" name="org_commune" value="<?php echo $v('org_commune'); ?>" placeholder="Besançon">
          </div>
        </div>

        <div class="gnl-field row">
          <div>
            <label for="org_pays">Pays</label>
            <input class="gnl-in" type="text" id="org_pays" name="org_pays" value="<?php echo gnl_e($paysDefault); ?>" placeholder="France">
          </div>
          <div>
            <label for="org_namespace">Identifiant <span style="font-weight:400;color:#9aa093">(auto si vide)</span></label>
            <input class="gnl-in" type="text" id="org_namespace" name="org_namespace" value="<?php echo $v('org_namespace'); ?>" autocapitalize="none" spellcheck="false" placeholder="couturemania">
          </div>
        </div>
      </div>
      <!-- =================================================================== -->

      <label class="gnl-check" style="margin:.2rem 0 1.1rem">
        <input type="checkbox" name="cgv" value="1" required>
        <span>J'accepte les <a class="gnl-link" href="/cgv" target="_blank" rel="noopener">conditions générales</a> et la politique de confidentialité.</span>
      </label>

      <button class="gnl-btn" type="submit">Créer mon compte</button>
    </form>

    <p class="gnl-alt">Vous avez déjà un compte&nbsp;? <a href="<?php echo gnl_e($logUrl); ?>">Se connecter</a></p>

    <script>
      (function(){
        var t = document.getElementById('create_org');
        var box = document.getElementById('org-fields');
        if(!t || !box) return;
        function sync(){ box.style.display = t.checked ? 'block' : 'none'; }
        t.addEventListener('change', sync); sync();
      })();
    </script>
<?php
gnl_auth_foot();
