<?php
/* =====================================================================
   GNL Solution — Inscription  (/inscription  ->  inscription.php)
   ---------------------------------------------------------------------
   Création de compte via l'API REST Keycloak. Le client choisit son
   profil ; s'il représente une entreprise/association, une carte
   « Informations de l'organisation » apparaît à droite et permet de
   créer l'organisation avec ses attributs (SIRET, TVA, adresse…).

     1) jeton d'administration (client_credentials) ;
     2) unicité de l'e-mail ;
     3) si entreprise : création de l'organisation + attributs ;
     4) création de l'utilisateur ;
     5) rattachement de l'utilisateur à l'organisation ;
     6) connexion immédiate -> $_SESSION['gnl_user'].

   SIREN déduit du SIRET (9 premiers chiffres). TVA renseignée seulement
   si « Assujetti à la TVA » est coché, sinon attribut tva vide.
   ===================================================================== */

ob_start();
require __DIR__ . '/keycloak_rest.php';

$return = gnl_safe_return(isset($_REQUEST['return']) ? $_REQUEST['return'] : '/commande');
if (!empty($_SESSION['gnl_user']) && is_array($_SESSION['gnl_user'])) {
    header('Location: ' . gnl_site_base() . $return);
    exit;
}

/* Champs du formulaire "organisation" (siren est DÉDUIT, pas saisi). */
$FORM_ORG = array('nom_commercial','raison','entite_legal','siret','tva',
                  'ent_email','telephone','voie_nbr','voie_name','cp','commune','pays','namespace');
/* Champs organisation obligatoires (hors SIRET, validé à part). */
$REQ_ORG = array(
    'nom_commercial' => 'le nom commercial',
    'raison'         => 'la raison sociale',
    'entite_legal'   => 'la forme juridique',
    'voie_name'      => 'la voie (rue)',
    'cp'             => 'le code postal',
    'commune'        => 'la ville',
    'pays'           => 'le pays',
);

$error = '';
$vals  = array('civilite' => '', 'prenom' => '', 'nom' => '', 'email' => '', 'phone' => '', 'profil_type' => 'particulier');
foreach ($FORM_ORG as $k) { $vals['org_' . $k] = ''; }
$wantOrg = false;
$tvaAssujetti = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array('civilite','prenom','nom','email','phone','profil_type') as $k) {
        $vals[$k] = trim((string) (isset($_POST[$k]) ? $_POST[$k] : ''));
    }
    foreach ($FORM_ORG as $k) {
        $vals['org_' . $k] = trim((string) (isset($_POST['org_' . $k]) ? $_POST['org_' . $k] : ''));
    }
    if (!in_array($vals['profil_type'], array('particulier','etudiant','entreprise'), true)) $vals['profil_type'] = 'particulier';
    $wantOrg      = ($vals['profil_type'] === 'entreprise');
    $tvaAssujetti = !empty($_POST['tva_assujetti']);
    $pass  = (string) (isset($_POST['password'])  ? $_POST['password']  : '');
    $pass2 = (string) (isset($_POST['password2']) ? $_POST['password2'] : '');
    $cgv   = !empty($_POST['cgv']);

    /* SIRET -> chiffres seuls ; SIREN = 9 premiers chiffres. */
    $siretRaw = preg_replace('/\D/', '', $vals['org_siret']);
    $sirenRaw = substr($siretRaw, 0, 9);

    /* Premier champ organisation obligatoire manquant. */
    $missingOrg = '';
    if ($wantOrg) {
        foreach ($REQ_ORG as $k => $lbl) { if ($vals['org_' . $k] === '') { $missingOrg = $lbl; break; } }
    }

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
    } elseif ($wantOrg && $missingOrg !== '') {
        $error = "Pour l'organisation, veuillez renseigner " . $missingOrg . ".";
    } elseif ($wantOrg && strlen($siretRaw) !== 14) {
        $error = "Le SIRET doit comporter 14 chiffres.";
    } elseif ($wantOrg && $tvaAssujetti && $vals['org_tva'] === '') {
        $error = "Indiquez le numéro de TVA, ou décochez « Assujetti à la TVA ».";
    } elseif (!$cgv) {
        $error = "Vous devez accepter les conditions générales.";
    } elseif (gnl_kc_admin_token() === null) {
        $error = "Le service d'inscription est momentanément indisponible. Réessayez plus tard.";
    } else {
        $exists = gnl_kc_find_user('email', $vals['email']);
        if (!$exists) $exists = gnl_kc_find_user('username', $vals['email']);
        if ($exists) {
            $error = "Un compte existe déjà avec cette adresse. Essayez de vous connecter.";
        } else {
            /* --- 1) Organisation (avant l'utilisateur) --- */
            $orgId = ''; $orgOk = true;
            if ($wantOrg) {
                $orgAttrs = array();
                foreach (array('nom_commercial','raison','entite_legal','ent_email','telephone','voie_nbr','voie_name','cp','commune','pays') as $k) {
                    if ($vals['org_' . $k] !== '') $orgAttrs[$k] = array($vals['org_' . $k]);
                }
                if ($siretRaw !== '') $orgAttrs['siret'] = array($siretRaw);
                if ($sirenRaw !== '') $orgAttrs['siren'] = array($sirenRaw);
                $orgAttrs['tva'] = array($tvaAssujetti ? $vals['org_tva'] : ''); // vide si non assujetti

                $orgName = $vals['org_nom_commercial'] !== '' ? $vals['org_nom_commercial'] : $vals['org_raison'];
                $alias   = gnl_slug($vals['org_namespace'] !== '' ? $vals['org_namespace'] : $orgName);
                $orgAttrs['namespace'] = array($alias);

                $orgRes = gnl_kc_create_organization($orgName, $alias, $orgAttrs);
                if (isset($orgRes['error'])) {
                    error_log('[GNL REST] create org failed: ' . $orgRes['error']);
                    $error = "Création de l'organisation impossible. " . $orgRes['error'];
                    $orgOk = false;
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
                    if ($orgId !== '') gnl_kc_delete_organization($orgId);
                    error_log('[GNL REST] create user failed: ' . $res['error']);
                    $msg = $res['error'];
                    if (stripos($msg, 'password') !== false && stripos($msg, 'policy') !== false) {
                        $msg = "Le mot de passe ne respecte pas la politique de sécurité (longueur, complexité…).";
                    }
                    $error = "Création du compte impossible. " . $msg;
                } else {
                    $userId = (string) (isset($res['id']) ? $res['id'] : '');
                    if (!empty($res['attr_dropped'])) {
                        error_log('[GNL REST] attributs custom (civilite/phone) ignorés : déclarez-les dans le User Profile ou activez "Unmanaged Attributes".');
                    }
                    if ($orgId !== '' && $userId !== '') {
                        if (!gnl_kc_org_add_member($orgId, $userId)) {
                            error_log('[GNL REST] add member failed org=' . $orgId . ' user=' . $userId);
                        }
                    }
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
$v = function ($k) use ($vals) { return gnl_e(isset($vals[$k]) ? $vals[$k] : ''); };
$paysDefault = $vals['org_pays'] !== '' ? $vals['org_pays'] : 'France';
$pt = $vals['profil_type'];

gnl_auth_head('Créer un compte', 'inscription', true, $wantOrg ? 'has-org' : '');
?>
<form method="post" action="/inscription" data-gnl-auth autocomplete="on" style="display:contents">
  <input type="hidden" name="csrf" value="<?php echo gnl_e($csrf); ?>">
  <input type="hidden" name="return" value="<?php echo $retAttr; ?>">
  <input type="hidden" name="cgv" value="1"><?php /* acceptation des CGU par la création du compte (mention sous le bouton) */ ?>

  <!-- ============ Panneau organisation (à gauche, profil Entreprise) ============ -->
  <section class="gnl-orgpanel" id="org-panel" aria-labelledby="titre-org">
    <div>
      <p class="kicker">ENTREPRISE</p>
      <h2 id="titre-org">Informations de l'organisation</h2>
      <p class="sub">Nécessaire pour vos factures et devis.</p>
    </div>

    <label class="gnl-lbl" for="org_siret">SIRET <span aria-hidden="true">*</span>
      <input class="gnl-in" type="text" id="org_siret" name="org_siret" value="<?php echo $v('org_siret'); ?>"
             inputmode="numeric" maxlength="17" autocomplete="off" placeholder="942 358 805 00011" aria-describedby="aide-siret siret-status" data-req="1">
    </label>
    <p class="gnl-hint gnl-under" id="aide-siret">Nous complétons automatiquement les informations publiques de l'entreprise.</p>
    <p class="gnl-hint gnl-under" id="siret-status" aria-live="polite"></p>

    <div class="gnl-g2">
      <label class="gnl-lbl" for="siren_display">SIREN <span class="opt">(déduit du SIRET)</span>
        <input class="gnl-in gnl-derived" type="text" id="siren_display" value="" placeholder="—" readonly tabindex="-1">
      </label>
      <label class="gnl-lbl" for="org_entite_legal">Forme juridique <span aria-hidden="true">*</span>
        <input class="gnl-in" type="text" id="org_entite_legal" name="org_entite_legal" value="<?php echo $v('org_entite_legal'); ?>" list="formes" placeholder="SAS, EI, association…" data-req="1">
        <datalist id="formes">
          <option value="Association loi 1901"></option><option value="Entrepreneur individuel"></option><option value="EI"></option>
          <option value="Micro-entreprise"></option><option value="EIRL"></option><option value="EURL"></option><option value="SARL"></option>
          <option value="SAS"></option><option value="SASU"></option><option value="SA"></option><option value="SCI"></option><option value="SNC"></option>
        </datalist>
      </label>
    </div>

    <label class="gnl-lbl" for="org_nom_commercial">Nom commercial <span aria-hidden="true">*</span>
      <input class="gnl-in" type="text" id="org_nom_commercial" name="org_nom_commercial" value="<?php echo $v('org_nom_commercial'); ?>" data-req="1">
    </label>
    <label class="gnl-lbl" for="org_raison">Raison sociale <span aria-hidden="true">*</span>
      <input class="gnl-in" type="text" id="org_raison" name="org_raison" value="<?php echo $v('org_raison'); ?>" autocomplete="organization" data-req="1">
    </label>

    <label class="gnl-check" for="tva_assujetti">
      <input type="checkbox" name="tva_assujetti" id="tva_assujetti" value="1" <?php echo $tvaAssujetti ? 'checked' : ''; ?>><span>Assujetti à la TVA</span>
    </label>
    <label class="gnl-lbl" for="org_tva" id="tva-field" hidden>N° TVA intracommunautaire <span aria-hidden="true">*</span>
      <input class="gnl-in" type="text" id="org_tva" name="org_tva" value="<?php echo $v('org_tva'); ?>" placeholder="FR57947628517">
    </label>

    <div class="gnl-g2">
      <label class="gnl-lbl" for="org_ent_email">E-mail de l'entreprise
        <input class="gnl-in" type="email" id="org_ent_email" name="org_ent_email" value="<?php echo $v('org_ent_email'); ?>" autocapitalize="none" placeholder="contact@entreprise.fr">
      </label>
      <label class="gnl-lbl" for="org_telephone">Téléphone
        <input class="gnl-in" type="tel" id="org_telephone" name="org_telephone" value="<?php echo $v('org_telephone'); ?>">
      </label>
    </div>

    <div class="gnl-g-num">
      <label class="gnl-lbl" for="org_voie_nbr">N°
        <input class="gnl-in" type="text" id="org_voie_nbr" name="org_voie_nbr" value="<?php echo $v('org_voie_nbr'); ?>" autocomplete="address-line2">
      </label>
      <label class="gnl-lbl" for="org_voie_name">Voie / rue <span aria-hidden="true">*</span>
        <input class="gnl-in" type="text" id="org_voie_name" name="org_voie_name" value="<?php echo $v('org_voie_name'); ?>" autocomplete="address-line1" data-req="1">
      </label>
    </div>

    <div class="gnl-g-cp">
      <label class="gnl-lbl" for="org_cp">Code postal <span aria-hidden="true">*</span>
        <input class="gnl-in" type="text" id="org_cp" name="org_cp" value="<?php echo $v('org_cp'); ?>" inputmode="numeric" autocomplete="postal-code" data-req="1">
      </label>
      <label class="gnl-lbl" for="org_commune">Ville <span aria-hidden="true">*</span>
        <input class="gnl-in" type="text" id="org_commune" name="org_commune" value="<?php echo $v('org_commune'); ?>" autocomplete="address-level2" data-req="1">
      </label>
    </div>

    <div class="gnl-g2">
      <label class="gnl-lbl" for="org_pays">Pays <span aria-hidden="true">*</span>
        <input class="gnl-in" type="text" id="org_pays" name="org_pays" value="<?php echo gnl_e($paysDefault); ?>" autocomplete="country-name" list="pays" data-req="1">
        <datalist id="pays"><option value="France"></option><option value="Belgique"></option><option value="Suisse"></option><option value="Luxembourg"></option></datalist>
      </label>
      <label class="gnl-lbl" for="org_namespace">Identifiant <span class="opt">(auto)</span>
        <input class="gnl-in" type="text" id="org_namespace" name="org_namespace" value="<?php echo $v('org_namespace'); ?>" autocapitalize="none" spellcheck="false" placeholder="—">
      </label>
    </div>
  </section>

  <!-- ===================== Fenêtre principale ===================== -->
<?php gnl_auth_dlg_open(gnl_auth_close_url($return)); ?>
  <div class="gnl-dlg-body">
    <p class="gnl-dlg-intro">Un seul compte pour commander, payer et gérer tous vos services depuis l'Espace Client.</p>

    <?php gnl_auth_tabs('inscription', $return); ?>

    <div class="gnl-form">
      <div>
        <h1 style="margin:0 0 4px;font-size:18px;line-height:24px;font-weight:800">Créer un compte</h1>
        <p class="sub">Quelques informations et vous pourrez commander en quelques clics.</p>
      </div>

      <?php if ($error !== ''): ?>
        <div class="gnl-msg err" role="alert" style="margin:0"><?php echo gnl_icon('err'); ?><span><?php echo gnl_e($error); ?></span></div>
      <?php endif; ?>

      <fieldset class="gnl-fs">
        <legend>Vous êtes</legend>
        <div class="gnl-kinds">
          <?php foreach (array('particulier' => 'Particulier', 'etudiant' => 'Étudiant', 'entreprise' => 'Entreprise') as $val => $lbl): ?>
          <label class="gnl-kind"><input type="radio" name="profil_type" value="<?php echo $val; ?>"<?php echo $pt === $val ? ' checked' : ''; ?>><span><?php echo gnl_e($lbl); ?></span></label>
          <?php endforeach; ?>
        </div>
      </fieldset>

      <div class="gnl-g-civ">
        <label class="gnl-lbl" for="civilite">Civilité
          <select class="gnl-in" id="civilite" name="civilite" autocomplete="honorific-prefix">
            <?php
            $civ = $vals['civilite'];
            echo '<option value=""' . ($civ === '' ? ' selected' : '') . '>—</option>';
            foreach (array('Monsieur', 'Madame', 'Maitre', 'Docteur', 'Professeur', 'Non communiqué') as $opt) {
                echo '<option value="' . gnl_e($opt) . '"' . ($civ === $opt ? ' selected' : '') . '>' . gnl_e($opt) . '</option>';
            }
            ?>
          </select>
        </label>
        <label class="gnl-lbl" for="prenom">Prénom <span aria-hidden="true">*</span>
          <input class="gnl-in" type="text" id="prenom" name="prenom" value="<?php echo $v('prenom'); ?>" autocomplete="given-name" required>
        </label>
        <label class="gnl-lbl" for="nom">Nom <span aria-hidden="true">*</span>
          <input class="gnl-in" type="text" id="nom" name="nom" value="<?php echo $v('nom'); ?>" autocomplete="family-name" required>
        </label>
      </div>

      <label class="gnl-lbl" for="email">E-mail *
        <input class="gnl-in" type="email" id="email" name="email" value="<?php echo $v('email'); ?>" autocomplete="email" autocapitalize="none" spellcheck="false" placeholder="vous@exemple.fr" required>
      </label>

      <label class="gnl-lbl" for="phone">Téléphone <span class="opt">(facultatif)</span>
        <input class="gnl-in" type="tel" id="phone" name="phone" value="<?php echo $v('phone'); ?>" autocomplete="tel" placeholder="06 12 34 56 78">
      </label>

      <label class="gnl-lbl" for="password">Mot de passe <span aria-hidden="true">*</span>
        <input class="gnl-in" type="password" id="password" name="password" autocomplete="new-password" minlength="8" aria-describedby="aide-mdp" required>
      </label>
      <p class="gnl-hint gnl-under" id="aide-mdp">Au moins 8 caractères. Utilisez idéalement lettres, chiffres et symboles.</p>

      <label class="gnl-lbl" for="password2">Confirmer le mot de passe <span aria-hidden="true">*</span>
        <input class="gnl-in" type="password" id="password2" name="password2" autocomplete="new-password" minlength="8" required>
      </label>

      <div class="gnl-note" id="note-etu" role="note"<?php echo $pt === 'etudiant' ? '' : ' hidden'; ?>>
        <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#b54708" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6"/><path d="M9 14h6M9 17h4"/></svg>
        <p><strong>Certificat de scolarité obligatoire.</strong> Après votre commande, vous devrez nous transmettre un certificat de scolarité de l'année en cours depuis votre Espace Client. Il est indispensable pour activer le service : sans certificat valide, la commande sera annulée et remboursée.</p>
      </div>

      <div id="note-org" style="display:flex;flex-direction:column;gap:16px"<?php echo $pt === 'entreprise' ? '' : ' hidden'; ?>>
        <p class="gnl-tip">Complétez aussi les <a href="#org-panel"><strong>informations de l'organisation</strong></a> dans le panneau dédié.</p>
        <div class="gnl-note" role="note">
          <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#b54708" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 4 6v6c0 4.5 3.4 8.3 8 9 4.6-.7 8-4.5 8-9V6z"/><path d="m9 12 2 2 4-4"/></svg>
          <p><strong>Vérification du compte entreprise.</strong> Les informations de votre organisation (complétez le panneau dédié) feront l'objet d'une vérification ultérieure par nos équipes : SIRET, raison sociale, adresse. En cas d'informations inexactes, nous pourrons demander des justificatifs, suspendre le compte ou annuler les commandes en cours.</p>
        </div>
      </div>

      <button class="gnl-btn" type="submit">Créer mon compte</button>
      <p class="gnl-legal">En créant un compte, vous acceptez nos <a href="/cgu" target="_blank" rel="noopener">CGU</a>. GNL Solution (Gabin Grobost, EI) utilise ces informations pour gérer votre compte, vos commandes et vos factures (exécution du contrat et obligations comptables). Elles restent à Besançon, ne sont jamais revendues et sont conservées pendant notre relation puis 3 ans (10 ans pour les factures). Vous pouvez y accéder, les rectifier, les exporter ou demander leur effacement depuis votre Espace Client ou à <a href="mailto:contact@gnl-solution.com">contact@gnl-solution.com</a>. <a href="/politique-de-confidentialite" target="_blank" rel="noopener">Politique de confidentialité</a></p>
    </div>
  </div>
<?php gnl_auth_dlg_close(); ?>
</form>

<?php echo gnl_js('inscription'); ?>
<?php
gnl_auth_foot();
