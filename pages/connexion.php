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
$prefEmail = gnl_e(isset($_POST['username']) ? (string) $_POST['username'] : '');
$toPay    = (strpos($return, '/commande') === 0);   // depuis le panier : « Se connecter et payer »
$forgotOpen = (isset($_POST['action']) && $_POST['action'] === 'forgot');

gnl_auth_head('Connexion', 'connexion', true);
gnl_auth_dlg_open(gnl_auth_close_url($return));
?>
  <div class="gnl-dlg-body">
    <h1 class="gnl-sr">Connexion</h1>
    <p class="gnl-dlg-intro">Un seul compte pour commander, payer et gérer tous vos services depuis l'Espace Client.</p>

    <?php gnl_auth_tabs('connexion', $return); ?>

    <?php if ($error !== ''): ?>
      <div class="gnl-msg err" role="alert"><?php echo gnl_icon('err'); ?><span><?php echo gnl_e($error); ?></span></div>
    <?php endif; ?>
    <?php if ($notice !== ''): ?>
      <div class="gnl-msg ok" role="status"><?php echo gnl_icon('ok'); ?><span><?php echo gnl_e($notice); ?></span></div>
    <?php endif; ?>

    <form class="gnl-form login" method="post" action="/connexion" data-gnl-auth autocomplete="on">
      <input type="hidden" name="csrf" value="<?php echo gnl_e($csrf); ?>">
      <input type="hidden" name="action" value="login">
      <input type="hidden" name="return" value="<?php echo $retAttr; ?>">

      <label class="gnl-lbl" for="username">E-mail ou identifiant
        <input class="gnl-in" type="text" id="username" name="username" value="<?php echo $prefEmail; ?>"
               autocomplete="username" autocapitalize="none" spellcheck="false"
               placeholder="vous@exemple.fr" required<?php echo $forgotOpen ? '' : ' autofocus'; ?>>
      </label>

      <label class="gnl-lbl" for="password">Mot de passe
        <input class="gnl-in" type="password" id="password" name="password" autocomplete="current-password" required>
      </label>

      <div class="gnl-row-between">
        <label class="gnl-check"><input type="checkbox" name="remember" value="1">Rester connecté</label>
        <a class="gnl-link" href="#gnl-forgot-form" id="gnl-forgot-toggle" aria-controls="gnl-forgot-form" aria-expanded="<?php echo $forgotOpen ? 'true' : 'false'; ?>">Mot de passe oublié&nbsp;?</a>
      </div>

      <button class="gnl-btn" type="submit"><?php echo $toPay ? 'Se connecter et payer' : 'Se connecter'; ?></button>
    </form>

    <!-- Mot de passe oublié (affiché à la demande) -->
    <form class="gnl-form" method="post" action="/connexion" data-gnl-auth id="gnl-forgot-form"<?php echo $forgotOpen ? '' : ' hidden'; ?>>
      <input type="hidden" name="csrf" value="<?php echo gnl_e($csrf); ?>">
      <input type="hidden" name="action" value="forgot">
      <input type="hidden" name="return" value="<?php echo $retAttr; ?>">
      <div class="gnl-sep" style="margin-bottom:0">Réinitialiser le mot de passe</div>
      <label class="gnl-lbl" for="forgot-email">Votre adresse e-mail
        <input class="gnl-in" type="email" id="forgot-email" name="email" autocomplete="email" placeholder="vous@exemple.fr" required>
      </label>
      <p class="gnl-hint gnl-under">Un lien de réinitialisation vous sera envoyé par e-mail.</p>
      <button class="gnl-btn alt" type="submit">Envoyer le lien</button>
    </form>

    <script>
      (function(){
        var t = document.getElementById('gnl-forgot-toggle');
        var f = document.getElementById('gnl-forgot-form');
        if(t && f){ t.addEventListener('click', function(e){
          e.preventDefault();
          var open = !f.hidden;
          f.hidden = open;
          t.setAttribute('aria-expanded', open ? 'false' : 'true');
          if(!open){ var i=f.querySelector('input[type=email]'); var u=document.getElementById('username');
            if(i){ if(u && u.value && u.value.indexOf('@')>0 && !i.value) i.value=u.value; i.focus(); } }
        }); }
      })();
    </script>
  </div>
<?php
gnl_auth_dlg_close();
gnl_auth_foot();
