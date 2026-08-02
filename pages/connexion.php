<?php
/* =====================================================================
   GNL Solution — Connexion  (/connexion  ->  connexion.php)
   ---------------------------------------------------------------------
   Formulaire de connexion « maison » qui parle à Keycloak par l'API REST
   (grant "password" / Direct Access Grant), sans redirection vers les
   pages hébergées par Keycloak.

   • GET  /connexion?return=/commande   -> affiche le formulaire
   • POST (action=login)                -> échange identifiants -> jeton
   • POST (action=forgot)               -> e-mail de réinitialisation
   Après succès : $_SESSION['gnl_user'] rempli, redirection vers "return".

   Prérequis Keycloak : client "siteweb" confidentiel avec
   « Direct access grants » activé. Voir keycloak_rest.php pour la config.
   ===================================================================== */

ob_start(); // un avis PHP ne doit jamais casser une redirection/session
require __DIR__ . '/keycloak_rest.php';

/* Déjà connecté ? -> on repart directement vers la cible. */
$return = gnl_safe_return(isset($_REQUEST['return']) ? $_REQUEST['return'] : '/commande');
if (!empty($_SESSION['gnl_user']) && is_array($_SESSION['gnl_user'])) {
    header('Location: ' . gnl_site_base() . $return);
    exit;
}
/* Identifiants déjà vérifiés mais choix d'organisation en attente : on reprend. */
if (gnl_pending_auth()) {
    header('Location: ' . gnl_site_base() . '/organisation');
    exit;
}

$error  = '';
$notice = '';

/* Message d'accueil après une inscription réussie nécessitant vérification. */
if (isset($_GET['registered'])) {
    $notice = "Votre compte a été créé. Vérifiez votre boîte mail si une confirmation est requise, puis connectez-vous.";
}
if (isset($_GET['loggedout'])) {
    $notice = "Vous êtes déconnecté.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!gnl_csrf_check()) {
        $error = "Session expirée. Merci de renvoyer le formulaire.";
    } else {
        $action = isset($_POST['action']) ? $_POST['action'] : 'login';

        /* -------- Mot de passe oublié (Admin REST : execute-actions-email) */
        if ($action === 'forgot') {
            $email = trim((string) (isset($_POST['email']) ? $_POST['email'] : ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = "Indiquez une adresse e-mail valide.";
            } else {
                $u = gnl_kc_find_user('email', $email);
                if ($u && !empty($u['id'])) {
                    gnl_kc_execute_actions_email($u['id'], array('UPDATE_PASSWORD'));
                }
                // Message générique : on ne révèle pas si l'adresse existe.
                $notice = "Si un compte est associé à cette adresse, un e-mail de réinitialisation vient d'être envoyé.";
            }
        }

        /* --------------------------- Connexion ------------------------ */
        else {
            $username = trim((string) (isset($_POST['username']) ? $_POST['username'] : ''));
            $password = (string) (isset($_POST['password']) ? $_POST['password'] : '');

            if ($username === '' || $password === '') {
                $error = "Renseignez votre identifiant et votre mot de passe.";
            } else {
                $tok = gnl_kc_password_grant($username, $password);
                if (is_array($tok) && !empty($tok['access_token'])) {
                    $r = gnl_kc_populate_session($tok);
                    if ($r === 'choose') {                       // ≥ 2 organisations
                        $_SESSION['gnl_pending_auth']['return'] = $return;
                        header('Location: ' . gnl_site_base() . '/organisation');
                        exit;
                    }
                    if ($r) {                                     // 'done'
                        header('Location: ' . gnl_site_base() . $return);
                        exit;
                    }
                }
                error_log('[GNL REST] login failed for "' . $username . '": ' . gnl_kc_detail($tok));
                $error = gnl_login_error_fr($tok);
            }
        }
    }
}

$csrf     = gnl_csrf_token();
$retAttr  = gnl_e($return);
$regUrl   = '/inscription?return=' . rawurlencode($return);
$prefEmail = gnl_e(isset($_POST['username']) ? (string) $_POST['username'] : '');

gnl_auth_head('Connexion', 'connexion');
?>
    <h1>Connexion</h1>
    <p class="sub">Accédez à votre espace pour finaliser votre commande.</p>

    <?php if ($error !== ''): ?>
      <div class="gnl-msg err"><?php echo gnl_icon('err'); ?><span><?php echo gnl_e($error); ?></span></div>
    <?php endif; ?>
    <?php if ($notice !== ''): ?>
      <div class="gnl-msg ok"><?php echo gnl_icon('ok'); ?><span><?php echo gnl_e($notice); ?></span></div>
    <?php endif; ?>

    <form method="post" action="/connexion" data-gnl-auth autocomplete="on">
      <input type="hidden" name="csrf" value="<?php echo gnl_e($csrf); ?>">
      <input type="hidden" name="action" value="login">
      <input type="hidden" name="return" value="<?php echo $retAttr; ?>">

      <div class="gnl-field">
        <label for="username">E-mail ou identifiant</label>
        <input class="gnl-in" type="text" id="username" name="username" value="<?php echo $prefEmail; ?>"
               autocomplete="username" autocapitalize="none" spellcheck="false"
               placeholder="vous@exemple.fr" required autofocus>
      </div>

      <div class="gnl-field">
        <label for="password">Mot de passe</label>
        <div class="gnl-pass">
          <input class="gnl-in" type="password" id="password" name="password"
                 autocomplete="current-password" placeholder="••••••••" required>
          <button type="button" class="gnl-eye" aria-label="Afficher le mot de passe" tabindex="-1">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>
      </div>

      <div class="gnl-row-between">
        <label class="gnl-check"><input type="checkbox" name="remember" value="1"> Se souvenir de moi</label>
        <a class="gnl-link" href="#" id="gnl-forgot-toggle">Mot de passe oublié&nbsp;?</a>
      </div>

      <button class="gnl-btn" type="submit">Se connecter</button>
    </form>

    <!-- Bloc "mot de passe oublié" (affiché à la demande) -->
    <form method="post" action="/connexion" data-gnl-auth id="gnl-forgot-form" style="display:none; margin-top:1.1rem">
      <input type="hidden" name="csrf" value="<?php echo gnl_e($csrf); ?>">
      <input type="hidden" name="action" value="forgot">
      <input type="hidden" name="return" value="<?php echo $retAttr; ?>">
      <div class="gnl-sep">Réinitialiser le mot de passe</div>
      <div class="gnl-field">
        <label for="forgot-email">Votre adresse e-mail</label>
        <input class="gnl-in" type="email" id="forgot-email" name="email"
               autocomplete="email" placeholder="vous@exemple.fr" required>
        <p class="gnl-hint">Un lien de réinitialisation vous sera envoyé par e-mail.</p>
      </div>
      <button class="gnl-btn" type="submit" style="background:var(--gnl-teal)">Envoyer le lien</button>
    </form>

    <p class="gnl-alt">Pas encore de compte&nbsp;? <a href="<?php echo gnl_e($regUrl); ?>">Créer un compte</a></p>

    <script>
      (function(){
        var t = document.getElementById('gnl-forgot-toggle');
        var f = document.getElementById('gnl-forgot-form');
        if(t && f){ t.addEventListener('click', function(e){
          e.preventDefault();
          var open = f.style.display !== 'none';
          f.style.display = open ? 'none' : 'block';
          if(!open){ var i=f.querySelector('input[type=email]'); if(i) i.focus(); }
        }); }
      })();
    </script>
<?php
gnl_auth_foot();
