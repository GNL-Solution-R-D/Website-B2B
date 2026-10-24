<?php
/* =====================================================================
   GNL Solution — Contact
   Formulaire envoyé au webhook n8n (action "contact.create"), repli e-mail.
   ===================================================================== */
require_once __DIR__ . '/../include/forms.php';
require_once __DIR__ . '/../include/layout.php';

$PROFILS = array('asso' => 'Une association', 'tpe' => 'Une TPE / un entrepreneur', 'etu' => 'Étudiant ou chercheur', 'autre' => 'Autre');
$SUJETS  = array('Hébergement web', 'Le Laboratoire', 'Mon Espace Client / facturation', 'Partenariat', 'Autre demande');
$v = array('profil' => 'asso', 'nom' => '', 'structure' => '', 'email' => '', 'tel' => '', 'sujet' => $SUJETS[0], 'message' => '');
$error = ''; $sent = false; $ref = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($v as $k => $_) $v[$k] = gnl_form_val($k, $k === 'message' ? 5000 : 200);
    if (!isset($PROFILS[$v['profil']])) $v['profil'] = 'autre';
    if (!in_array($v['sujet'], $SUJETS, true)) $v['sujet'] = 'Autre demande';
    if (gnl_form_is_bot()) {
        $sent = true; $ref = gnl_form_ref('CT');               // on ne dit rien au robot
    } elseif (!gnl_form_token_ok()) {
        $error = 'Votre session a expiré. Merci de renvoyer le formulaire.';
    } elseif ($v['nom'] === '' || $v['message'] === '' || !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Merci de renseigner votre nom, une adresse e-mail valide et votre message.';
    } elseif (!gnl_form_rate_ok()) {
        $error = 'Trop d\'envois en peu de temps. Réessayez dans quelques minutes, ou appelez-nous au 03 65 67 01 69.';
    } else {
        $ref = gnl_form_ref('CT');
        $data = $v; $data['profil'] = $PROFILS[$v['profil']];
        if (gnl_form_dispatch('contact.create', $ref, $data)) { $sent = true; }
        else { $error = 'L\'envoi n\'a pas abouti. Écrivez-nous à contact@gnl-solution.com ou appelez le 03 65 67 01 69.'; }
    }
}

$gnl_head = gnl_css('contact');   // assets/gnl/page/contact.css
gnl_ui_open('Contact', 'contact', array('desc' => 'Contactez GNL Solution, hébergeur bas carbone à Besançon : formulaire, téléphone, adresse.', 'head' => $gnl_head, 'main' => false));
?>
<div class="gnl-page" style="color: #141a33; background: #f4f6fa;">
<main id="contenu" style="max-width: 1240px; margin: 0 auto; padding: 64px 24px 96px;">

<div style="max-width: 720px; margin-bottom: 48px;">
<p style="margin: 0 0 12px; font-size: 12px; font-weight: 700; letter-spacing: 0.2em; color: #1f7a4a;">CONTACT</p>
<h1 style="margin: 0 0 12px; font-size: 44px; line-height: 50px; font-weight: 800;">Parlons de votre projet.</h1>
<p style="margin: 0; font-size: 17px; line-height: 27px; color: #5e6684;">Une question sur nos offres, un projet d'hébergement, un partenariat ? Écrivez-nous : nous répondons sous <?php echo gnl_ui_h(GNL_DELAI_REPONSE); ?>, depuis Besançon.</p>
</div>

<div style="display: flex; flex-wrap: wrap; gap: 32px; align-items: flex-start;">

<!-- Formulaire -->
<section aria-labelledby="titre-form" style="flex: 999 1 560px; min-width: 0; background: #ffffff; border: 1px solid #c5cbd8; border-radius: 3px; padding: 36px;">
<?php if ($sent): ?>
<div role="status" style="display: flex; flex-direction: column; gap: 16px; align-items: flex-start; padding: 24px 0;">
<svg aria-hidden="true" width="18" height="34" viewBox="0 0 22 40"><path d="M13 0h9L9 40H0z" fill="#1f7a4a"/></svg>
<h2 id="titre-form" style="margin: 0; font-size: 28px; line-height: 34px; font-weight: 800;">Merci, votre message est envoyé.</h2>
<p style="margin: 0; font-size: 16px; line-height: 26px; color: #5e6684; max-width: 520px;"><?php echo str_replace(array('{ref}', '{delai}'), array('<strong style="color: #141a33;" translate="no">' . gnl_ui_h($ref) . '</strong>', gnl_ui_h(gnl_t(GNL_DELAI_REPONSE))), gnl_ui_h(gnl_t('Votre demande porte la référence {ref}. Nous revenons vers vous sous {delai}, à l\'adresse que vous avez indiquée.'))); ?></p>
<a href="/contact" class="gnl-b gnl-b-line" style="min-height: 44px; font-size: 14px;">Envoyer un autre message</a>
</div>
<?php else: ?>
<form method="post" action="/contact" novalidate style="display: flex; flex-direction: column; gap: 22px;">
<h2 id="titre-form" style="margin: 0; font-size: 22px; line-height: 28px; font-weight: 800;">Envoyer un message</h2>
<?php if ($error !== ''): ?><p class="gnl-alert-err" role="alert" style="margin: 0;"><?php echo gnl_ui_h($error); ?></p><?php endif; ?>
<input type="hidden" name="csrf" value="<?php echo gnl_ui_h(gnl_form_token()); ?>">
<div class="gnl-honey" aria-hidden="true"><label>Ne pas remplir <input type="text" name="site_web" tabindex="-1" autocomplete="off"></label></div>

<fieldset style="border: 0; margin: 0; padding: 0;">
<legend style="margin: 0 0 10px; font-size: 14px; font-weight: 700;">Vous êtes</legend>
<div style="display: flex; flex-wrap: wrap; gap: 10px;">
<?php foreach ($PROFILS as $k => $lab): ?>
<label class="gnl-radio"><input type="radio" name="profil" value="<?php echo $k; ?>"<?php echo $v['profil'] === $k ? ' checked' : ''; ?>><span><?php echo gnl_ui_h($lab); ?></span></label>
<?php endforeach; ?>
</div>
</fieldset>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px;">
<label style="display: flex; flex-direction: column; gap: 8px; font-size: 14px; font-weight: 700;">Nom et prénom *
<input type="text" name="nom" required maxlength="120" autocomplete="name" placeholder="Camille Martin" value="<?php echo gnl_ui_h($v['nom']); ?>" style="min-height: 46px; padding: 0 14px; border: 1px solid #5e6684; border-radius: 3px; font: 400 15px var(--font-display); color: #141a33;">
</label>
<label style="display: flex; flex-direction: column; gap: 8px; font-size: 14px; font-weight: 700;">Structure
<input type="text" name="structure" maxlength="160" autocomplete="organization" placeholder="Association, entreprise, école…" value="<?php echo gnl_ui_h($v['structure']); ?>" style="min-height: 46px; padding: 0 14px; border: 1px solid #5e6684; border-radius: 3px; font: 400 15px var(--font-display); color: #141a33;">
</label>
<label style="display: flex; flex-direction: column; gap: 8px; font-size: 14px; font-weight: 700;">E-mail *
<input type="email" name="email" required maxlength="160" autocomplete="email" placeholder="vous@exemple.fr" value="<?php echo gnl_ui_h($v['email']); ?>" style="min-height: 46px; padding: 0 14px; border: 1px solid #5e6684; border-radius: 3px; font: 400 15px var(--font-display); color: #141a33;">
</label>
<label style="display: flex; flex-direction: column; gap: 8px; font-size: 14px; font-weight: 700;">Téléphone
<input type="tel" name="tel" maxlength="40" autocomplete="tel" placeholder="06 12 34 56 78" value="<?php echo gnl_ui_h($v['tel']); ?>" style="min-height: 46px; padding: 0 14px; border: 1px solid #5e6684; border-radius: 3px; font: 400 15px var(--font-display); color: #141a33;">
</label>
</div>

<label style="display: flex; flex-direction: column; gap: 8px; font-size: 14px; font-weight: 700;">Sujet *
<select name="sujet" required style="min-height: 46px; padding: 0 14px; border: 1px solid #5e6684; border-radius: 3px; font: 400 15px var(--font-display); color: #141a33; background: #ffffff;">
<?php foreach ($SUJETS as $s): ?><option value="<?php echo gnl_ui_h($s); ?>"<?php echo $v['sujet'] === $s ? ' selected' : ''; ?>><?php echo gnl_ui_h($s); ?></option><?php endforeach; ?>
</select>
</label>

<label style="display: flex; flex-direction: column; gap: 8px; font-size: 14px; font-weight: 700;">Message *
<textarea name="message" required rows="6" maxlength="5000" placeholder="Décrivez votre besoin : type de site, trafic attendu, échéance…" style="padding: 12px 14px; border: 1px solid #5e6684; border-radius: 3px; font: 400 15px/24px var(--font-display); color: #141a33; resize: vertical;"><?php echo gnl_ui_h($v['message']); ?></textarea>
</label>

<p style="margin: 0; padding: 14px 16px; background: #f4f6fa; border-radius: 3px; font-size: 12px; line-height: 19px; color: #5e6684;"><strong style="color: #141a33;">Vos données.</strong> GNL Solution (Gabin Grobost, EI) traite ces informations pour répondre à votre demande, sur la base de son intérêt légitime. Seuls les champs marqués * sont nécessaires. Elles sont hébergées à Besançon, jamais revendues, et conservées 3 ans après notre dernier échange. Vous pouvez y accéder, les rectifier, les effacer ou vous opposer à leur traitement en écrivant à <a href="mailto:contact@gnl-solution.com">contact@gnl-solution.com</a>, et saisir la CNIL. <a href="/politique-de-confidentialite">Politique de confidentialité</a></p>

<div style="display: flex; flex-wrap: wrap; gap: 16px; align-items: center;">
<button type="submit" class="gnl-b gnl-b-green" style="padding: 0 24px;">Envoyer le message</button>
<span style="font-size: 13px; color: #5e6684;">* champs obligatoires</span>
</div>
</form>
<?php endif; ?>
</section>

<!-- Coordonnées -->
<aside style="flex: 1 1 320px; min-width: 0; display: flex; flex-direction: column; gap: 24px;">
<section style="background: #141a33; color: #ffffff; border-radius: 3px; padding: 32px;">
<h2 style="margin: 0 0 20px; font-size: 20px; line-height: 26px; font-weight: 800;">Nous joindre</h2>
<dl style="margin: 0; display: flex; flex-direction: column; gap: 18px; font-size: 15px; line-height: 22px;">
<div><dt style="font-size: 11px; font-weight: 700; letter-spacing: 0.2em; color: #c5cbd8; margin-bottom: 4px;">TÉLÉPHONE</dt><dd style="margin: 0;"><a href="tel:+33365670169" style="color: #ffffff; text-decoration: none; font-weight: 700;">03 65 67 01 69</a></dd></div>
<div><dt style="font-size: 11px; font-weight: 700; letter-spacing: 0.2em; color: #c5cbd8; margin-bottom: 4px;">E-MAIL</dt><dd style="margin: 0;"><a href="mailto:contact@gnl-solution.com" style="color: #5cd98b;">contact@gnl-solution.com</a></dd></div>
<div><dt style="font-size: 11px; font-weight: 700; letter-spacing: 0.2em; color: #c5cbd8; margin-bottom: 4px;">ADRESSE</dt><dd style="margin: 0;">20 rue Gustave Courbet<br>25000 Besançon, France</dd></div>
<div><dt style="font-size: 11px; font-weight: 700; letter-spacing: 0.2em; color: #c5cbd8; margin-bottom: 4px;">HORAIRES</dt><dd style="margin: 0;">[JOURS ET HORAIRES D'OUVERTURE]</dd></div>
</dl>
</section>

<section style="background: #ffffff; border: 1px solid #c5cbd8; border-radius: 3px; padding: 28px 32px;">
<h2 style="margin: 0 0 8px; font-size: 18px; line-height: 24px; font-weight: 800;">Un incident sur un service ?</h2>
<p style="margin: 0 0 16px; font-size: 14px; line-height: 22px; color: #5e6684;">Vérifiez d'abord l'état de nos services, puis signalez le problème pour un traitement prioritaire.</p>
<p style="margin: 0; display: flex; flex-direction: column; gap: 8px; font-size: 15px; font-weight: 700;"><a href="/stats">Voir l'état des services →</a><a href="/incident">Signaler un incident →</a></p>
</section>

<section style="background: #ffffff; border: 1px solid #c5cbd8; border-radius: 3px; padding: 28px 32px;">
<h2 style="margin: 0 0 8px; font-size: 18px; line-height: 24px; font-weight: 800;">Communauté</h2>
<p style="margin: 0 0 16px; font-size: 14px; line-height: 22px; color: #5e6684;">Échangez avec nous et suivez nos projets open source.</p>
<p style="margin: 0; display: flex; flex-wrap: wrap; gap: 8px 20px; font-size: 15px; font-weight: 700;"><a href="https://github.com/GNL-Solution-R-D">GitHub</a><a href="https://www.linkedin.com/company/109507423/">LinkedIn</a><a href="https://discord.gg/RKnRsNbQG5">Discord</a></p>
</section>
</aside>

</div>
</main>
</div>
<?php gnl_ui_close(); ?>
