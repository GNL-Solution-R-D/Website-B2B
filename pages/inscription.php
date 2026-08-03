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
$logUrl  = '/connexion?return=' . rawurlencode($return);
$v = function ($k) use ($vals) { return gnl_e(isset($vals[$k]) ? $vals[$k] : ''); };
$paysDefault = $vals['org_pays'] !== '' ? $vals['org_pays'] : 'France';

gnl_auth_head('Créer un compte', 'inscription', true, $wantOrg ? 'has-org' : '');
?>
    <form method="post" action="/inscription" data-gnl-auth autocomplete="on">
      <input type="hidden" name="csrf" value="<?php echo gnl_e($csrf); ?>">
      <input type="hidden" name="return" value="<?php echo $retAttr; ?>">

      <div class="gnl-auth-cols">

        <!-- ===================== Carte compte (gauche) ===================== -->
        <section class="gnl-card">
          <h1>Créer un compte</h1>
          <p class="sub">Quelques informations et vous pourrez commander en quelques clics.</p>

          <?php if ($error !== ''): ?>
            <div class="gnl-msg err"><?php echo gnl_icon('err'); ?><span><?php echo gnl_e($error); ?></span></div>
          <?php endif; ?>

          <div class="gnl-field">
            <label for="profil_type">Vous êtes</label>
            <select class="gnl-in" id="profil_type" name="profil_type">
              <?php
              $pt = $vals['profil_type'];
              $ptOptions = array(
                  'particulier' => 'Je suis un particulier',
                  'etudiant'    => 'Je suis étudiant',
                  'entreprise'  => 'Je représente une entreprise ou une association',
              );
              foreach ($ptOptions as $val => $lbl) {
                  echo '<option value="' . gnl_e($val) . '"' . ($pt === $val ? ' selected' : '') . '>' . gnl_e($lbl) . '</option>';
              }
              ?>
            </select>
          </div>

          <div class="gnl-field row">
            <div style="flex:0 0 42%">
              <label for="civilite">Civilité</label>
              <select class="gnl-in" id="civilite" name="civilite">
                <?php
                $civ = $vals['civilite'];
                $civOptions = array('Monsieur', 'Madame', 'Maitre', 'Docteur', 'Professeur', 'Non communiqué');
                echo '<option value=""' . ($civ === '' ? ' selected' : '') . '>—</option>';
                foreach ($civOptions as $opt) {
                    echo '<option value="' . gnl_e($opt) . '"' . ($civ === $opt ? ' selected' : '') . '>' . gnl_e($opt) . '</option>';
                }
                ?>
              </select>
            </div>
            <div>
              <label for="prenom">Prénom <span class="req">*</span></label>
              <input class="gnl-in" type="text" id="prenom" name="prenom" value="<?php echo $v('prenom'); ?>"
                     autocomplete="given-name" placeholder="Prénom" required>
            </div>
          </div>

          <div class="gnl-field">
            <label for="nom">Nom <span class="req">*</span></label>
            <input class="gnl-in" type="text" id="nom" name="nom" value="<?php echo $v('nom'); ?>"
                   autocomplete="family-name" placeholder="Nom" required>
          </div>

          <div class="gnl-field">
            <label for="email">E-mail <span class="req">*</span></label>
            <input class="gnl-in" type="email" id="email" name="email" value="<?php echo $v('email'); ?>"
                   autocomplete="email" autocapitalize="none" spellcheck="false" placeholder="vous@exemple.fr" required>
          </div>

          <div class="gnl-field">
            <label for="phone">Téléphone <span style="font-weight:400;color:#9aa093">(facultatif)</span></label>
            <input class="gnl-in" type="tel" id="phone" name="phone" value="<?php echo $v('phone'); ?>"
                   autocomplete="tel" placeholder="06 12 34 56 78">
          </div>

          <div class="gnl-field">
            <label for="password">Mot de passe <span class="req">*</span></label>
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
            <label for="password2">Confirmer le mot de passe <span class="req">*</span></label>
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
            <span>J'accepte les <a class="gnl-link" href="/cgv" target="_blank" rel="noopener">conditions générales</a> et la politique de confidentialité. <span class="req">*</span></span>
          </label>

          <button class="gnl-btn" type="submit">Créer mon compte</button>
          <p class="gnl-alt">Vous avez déjà un compte&nbsp;? <a href="<?php echo gnl_e($logUrl); ?>">Se connecter</a></p>
        </section>

        <!-- ================= Carte organisation (droite) ================= -->
        <aside class="gnl-card gnl-orgcard" id="org-card">
          <h2>Informations de l'organisation</h2>
          <p class="sub">Nécessaire pour vos factures et devis.</p>

          <div class="gnl-field">
            <label for="org_siret">SIRET <span class="req">*</span></label>
            <input class="gnl-in" type="text" id="org_siret" name="org_siret" value="<?php echo $v('org_siret'); ?>"
                   inputmode="numeric" autocomplete="off" placeholder="942 358 805 00011" data-req="1">
            <p class="gnl-hint" id="siret-status" aria-live="polite" style="min-height:1.05em"></p>
          </div>

          <div class="gnl-field">
            <label for="siren_display">SIREN <span style="font-weight:400;color:#9aa093">(déduit du SIRET)</span></label>
            <input class="gnl-in gnl-derived" type="text" id="siren_display" value="" placeholder="942 358 805" readonly tabindex="-1">
          </div>

          <div class="gnl-field row">
            <div>
              <label for="org_nom_commercial">Nom commercial <span class="req">*</span></label>
              <input class="gnl-in" type="text" id="org_nom_commercial" name="org_nom_commercial" value="<?php echo $v('org_nom_commercial'); ?>" placeholder="COUTUREMANIA" data-req="1">
            </div>
            <div>
              <label for="org_raison">Raison sociale <span class="req">*</span></label>
              <input class="gnl-in" type="text" id="org_raison" name="org_raison" value="<?php echo $v('org_raison'); ?>" placeholder="Nom légal / gérant" data-req="1">
            </div>
          </div>

          <div class="gnl-field">
            <label for="org_entite_legal">Forme juridique <span class="req">*</span></label>
            <input class="gnl-in" type="text" id="org_entite_legal" name="org_entite_legal" value="<?php echo $v('org_entite_legal'); ?>" list="formes" placeholder="Entrepreneur individuel, SARL, SAS…" data-req="1">
            <datalist id="formes">
              <option value="Entrepreneur individuel"></option><option value="EI"></option><option value="EIRL"></option>
              <option value="EURL"></option><option value="SARL"></option><option value="SAS"></option>
              <option value="SASU"></option><option value="SA"></option><option value="SCI"></option>
              <option value="SNC"></option><option value="Association"></option><option value="Micro-entreprise"></option>
            </datalist>
          </div>

          <label class="gnl-check" style="margin:.2rem 0 .5rem">
            <input type="checkbox" name="tva_assujetti" id="tva_assujetti" value="1" <?php echo $tvaAssujetti ? 'checked' : ''; ?>>
            <span>Assujetti à la TVA</span>
          </label>
          <div class="gnl-field" id="tva-field" style="display:none">
            <label for="org_tva">N° TVA intracommunautaire <span class="req">*</span></label>
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
              <label for="org_voie_name">Voie / rue <span class="req">*</span></label>
              <input class="gnl-in" type="text" id="org_voie_name" name="org_voie_name" value="<?php echo $v('org_voie_name'); ?>" placeholder="RUE Ronchaux" data-req="1">
            </div>
          </div>

          <div class="gnl-field row">
            <div style="flex:0 0 34%">
              <label for="org_cp">Code postal <span class="req">*</span></label>
              <input class="gnl-in" type="text" id="org_cp" name="org_cp" value="<?php echo $v('org_cp'); ?>" inputmode="numeric" placeholder="25000" data-req="1">
            </div>
            <div>
              <label for="org_commune">Ville <span class="req">*</span></label>
              <input class="gnl-in" type="text" id="org_commune" name="org_commune" value="<?php echo $v('org_commune'); ?>" placeholder="Besançon" data-req="1">
            </div>
          </div>

          <div class="gnl-field row">
            <div>
              <label for="org_pays">Pays <span class="req">*</span></label>
              <input class="gnl-in" type="text" id="org_pays" name="org_pays" value="<?php echo gnl_e($paysDefault); ?>" placeholder="France" data-req="1">
            </div>
            <div>
              <label for="org_namespace">Identifiant <span style="font-weight:400;color:#9aa093">(auto)</span></label>
              <input class="gnl-in" type="text" id="org_namespace" name="org_namespace" value="<?php echo $v('org_namespace'); ?>" autocapitalize="none" spellcheck="false" placeholder="couturemania">
            </div>
          </div>
        </aside>

      </div>
    </form>

    <script>
      (function(){
        var pt   = document.getElementById('profil_type');
        var auth = document.querySelector('.gnl-auth');
        var card = document.getElementById('org-card');
        var reqs = card ? card.querySelectorAll('[data-req]') : [];
        var tvaChk = document.getElementById('tva_assujetti');
        var tvaBox = document.getElementById('tva-field');
        var tvaIn  = document.getElementById('org_tva');
        var siret  = document.getElementById('org_siret');
        var siren  = document.getElementById('siren_display');
        var statusEl = document.getElementById('siret-status');
        var lastLookup = ''; var lookupTimer = null;

        function group(d, sizes){
          var out = [], i = 0;
          for (var s = 0; s < sizes.length && i < d.length; s++){ out.push(d.substr(i, sizes[s])); i += sizes[s]; }
          return out.join(' ');
        }
        function isOrg(){ return pt && pt.value === 'entreprise'; }
        function setReq(el, on){ if(!el) return; if(on) el.setAttribute('required',''); else el.removeAttribute('required'); }
        function syncTva(){
          var on = isOrg() && tvaChk && tvaChk.checked;
          if (tvaBox) tvaBox.style.display = on ? '' : 'none';
          setReq(tvaIn, on);
        }
        function syncCard(){
          var on = isOrg();
          if (auth) auth.classList.toggle('has-org', on);
          for (var i = 0; i < reqs.length; i++) setReq(reqs[i], on);
          syncTva();
        }
        function setStatus(txt, cls){
          if (!statusEl) return;
          statusEl.textContent = txt || '';
          statusEl.style.color = cls === 'ok' ? '#1f6323' : (cls === 'err' ? '#8e2a1e' : '#8a8f85');
        }
        function fillField(id, val){ var el = document.getElementById(id); if (el && val != null && val !== '') el.value = val; }
        function lookup(siretDigits){
          if (siretDigits === lastLookup) return;
          lastLookup = siretDigits;
          setStatus('Recherche de l\'entreprise…', '');
          fetch('/entreprise-lookup?siret=' + encodeURIComponent(siretDigits), { headers: { 'Accept': 'application/json' } })
            .then(function(r){ return r.json(); })
            .then(function(j){
              if (!j || !j.ok || !j.data){ setStatus('Aucune entreprise trouvée pour ce SIRET.', 'err'); return; }
              var d = j.data;
              fillField('org_nom_commercial', d.nom_commercial);
              fillField('org_raison', d.raison);
              fillField('org_entite_legal', d.entite_legal);
              fillField('org_voie_nbr', d.voie_nbr);
              fillField('org_voie_name', d.voie_name);
              fillField('org_cp', d.cp);
              fillField('org_commune', d.commune);
              fillField('org_pays', d.pays || 'France');
              if (tvaIn && d.tva) tvaIn.value = d.tva;
              var name = d.nom_commercial || d.raison || 'Entreprise trouvée';
              if (d.etat === 'F') setStatus('⚠ ' + name + ' — établissement fermé, vérifiez le SIRET', 'err');
              else setStatus('✓ ' + name, 'ok');
            })
            .catch(function(){ setStatus('Recherche indisponible pour le moment.', 'err'); });
        }
        function fmtSiret(){
          if (!siret) return;
          var d = siret.value.replace(/\D/g, '').slice(0, 14);
          siret.value = group(d, [3,3,3,5]);
          if (siren) siren.value = group(d.slice(0, 9), [3,3,3]);
          if (d.length === 14){
            clearTimeout(lookupTimer);
            lookupTimer = setTimeout(function(){ lookup(d); }, 400);
          } else { lastLookup = ''; setStatus('', ''); }
        }
        if (pt)     pt.addEventListener('change', syncCard);
        if (tvaChk) tvaChk.addEventListener('change', syncTva);
        if (siret)  siret.addEventListener('input', fmtSiret);
        syncCard(); fmtSiret();
      })();
    </script>
<?php
gnl_auth_foot();
