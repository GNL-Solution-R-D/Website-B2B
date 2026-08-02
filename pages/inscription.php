<?php
/* =====================================================================
   GNL Solution — Inscription  (/inscription  ->  inscription.php)
   ---------------------------------------------------------------------
   Création de compte « maison » qui parle à Keycloak par l'API REST :
     1) jeton d'administration (grant "client_credentials" du compte de
        service, rôle realm-management "manage-users") ;
     2) vérification d'unicité (GET /users?email=..&exact=true) ;
     3) création (POST /users) avec mot de passe et attributs ;
     4) connexion immédiate (grant "password") -> $_SESSION['gnl_user'],
        puis redirection vers "return".
   Si la connexion immédiate est bloquée (vérification e-mail requise),
   on renvoie vers /connexion avec un message d'information.

   Prérequis Keycloak : compte de service avec "manage-users" (voir
   keycloak_rest.php). Client "siteweb" avec Direct access grants pour
   l'étape 4.
   ===================================================================== */

ob_start();
require __DIR__ . '/keycloak_rest.php';

$return = gnl_safe_return(isset($_REQUEST['return']) ? $_REQUEST['return'] : '/commande');
if (!empty($_SESSION['gnl_user']) && is_array($_SESSION['gnl_user'])) {
    header('Location: ' . gnl_site_base() . $return);
    exit;
}

$error  = '';
$vals   = array('civilite' => '', 'prenom' => '', 'nom' => '', 'email' => '', 'phone' => '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($vals as $k => $_) { $vals[$k] = trim((string) (isset($_POST[$k]) ? $_POST[$k] : '')); }
    $pass    = (string) (isset($_POST['password']) ? $_POST['password'] : '');
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
    } elseif (!$cgv) {
        $error = "Vous devez accepter les conditions générales.";
    } else {
        /* Le jeton d'admin est-il disponible ? (config service account) */
        if (gnl_kc_admin_token() === null) {
            $error = "Le service d'inscription est momentanément indisponible. Réessayez plus tard.";
        } else {
            /* Compte déjà existant ? */
            $exists = gnl_kc_find_user('email', $vals['email']);
            if (!$exists) $exists = gnl_kc_find_user('username', $vals['email']);
            if ($exists) {
                $error = "Un compte existe déjà avec cette adresse. Essayez de vous connecter.";
            } else {
                /* Attributs Keycloak custom (civilité / téléphone). */
                $attributes = array();
                if ($vals['civilite'] !== '') $attributes['civilite'] = array($vals['civilite']);
                if ($vals['phone'] !== '')    { $attributes['phone'] = array($vals['phone']); $attributes['phone_number'] = array($vals['phone']); }

                $res = gnl_kc_create_user($vals['email'], $pass, $vals['prenom'], $vals['nom'], $attributes);

                if (isset($res['error']) && $res['error'] === 'exists') {
                    $error = "Un compte existe déjà avec cette adresse. Essayez de vous connecter.";
                } elseif (isset($res['error'])) {
                    error_log('[GNL REST] create user failed: ' . $res['error']);
                    $msg = $res['error'];
                    // Messages Keycloak fréquents -> FR
                    if (stripos($msg, 'password') !== false && stripos($msg, 'policy') !== false) {
                        $msg = "Le mot de passe ne respecte pas la politique de sécurité (longueur, complexité…).";
                    }
                    $error = "Création du compte impossible. " . $msg;
                } else {
                    /* Compte créé -> connexion immédiate par grant "password". */
                    $tok = gnl_kc_password_grant($vals['email'], $pass);
                    if (is_array($tok) && !empty($tok['access_token']) && gnl_kc_populate_session($tok)) {
                        header('Location: ' . gnl_site_base() . $return);
                        exit;
                    }
                    /* Connexion auto bloquée (ex. vérification e-mail) -> /connexion. */
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

gnl_auth_head('Créer un compte', 'inscription');
?>
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

      <label class="gnl-check" style="margin:.2rem 0 1.1rem">
        <input type="checkbox" name="cgv" value="1" required>
        <span>J'accepte les <a class="gnl-link" href="/cgv" target="_blank" rel="noopener">conditions générales</a> et la politique de confidentialité.</span>
      </label>

      <button class="gnl-btn" type="submit">Créer mon compte</button>
    </form>

    <p class="gnl-alt">Vous avez déjà un compte&nbsp;? <a href="<?php echo gnl_e($logUrl); ?>">Se connecter</a></p>
<?php
gnl_auth_foot();
