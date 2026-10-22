<?php
/* =====================================================================
   GNL Solution — Signaler un incident / un contenu illicite
   - type=tech  : incident technique  -> webhook action "incident.create"
   - type=abuse : notification de contenu illicite (LCEN art. 6, DSA)
                  -> webhook action "abuse.create"
   Repli e-mail si le webhook est indisponible.
   ===================================================================== */
require_once __DIR__ . '/../include/forms.php';
require_once __DIR__ . '/../include/layout.php';

$type = (isset($_REQUEST['type']) && $_REQUEST['type'] === 'abuse') ? 'abuse' : 'tech';
$SERVICES = array('web' => array('Hébergement web', 'Site web, base de données'), 'client' => array("L'Espace Client", 'Connexion, factures'),
                  'labo' => array('Le Laboratoire', 'Machines virtuelles'), 'autre' => array('Je ne sais pas', 'Nous identifierons'));
$IMPACTS  = array('down' => 'Inaccessible', 'slow' => 'Lent ou dégradé', 'err' => 'Erreur ponctuelle', 'sec' => 'Problème de sécurité');
$CATS     = array('haine' => 'Haine, harcèlement, menaces', 'terro' => 'Apologie du terrorisme', 'csam' => 'Exploitation sexuelle de mineurs',
                  'arnaque' => 'Escroquerie, hameçonnage (phishing), malware', 'pi' => "Contrefaçon, atteinte au droit d'auteur ou aux marques",
                  'diff' => 'Diffamation, injure, atteinte à la vie privée', 'donnees' => 'Données personnelles publiées sans consentement', 'autre' => 'Autre contenu illicite');
$QUALITES = array('En mon nom', 'Pour une organisation', 'En tant que représentant légal ou avocat');
$v = array('service' => 'web', 'impact' => 'down', 'cible' => '', 'depuis' => '', 'description' => '', 'email' => '', 'num_client' => '',
           'categorie' => 'haine', 'urls' => '', 'motif' => '', 'nom' => '', 'qualite' => $QUALITES[0]);
$error = ''; $sent = false; $ref = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($v as $k => $_) $v[$k] = gnl_form_val($k, in_array($k, array('description', 'motif', 'urls'), true) ? 5000 : 200);
    if (!isset($SERVICES[$v['service']])) $v['service'] = 'autre';
    if (!isset($IMPACTS[$v['impact']]))   $v['impact'] = 'err';
    if (!isset($CATS[$v['categorie']]))   $v['categorie'] = 'autre';
    if (!in_array($v['qualite'], $QUALITES, true)) $v['qualite'] = $QUALITES[0];
    $anon = ($type === 'abuse' && $v['categorie'] === 'csam');
    $emailOk = filter_var($v['email'], FILTER_VALIDATE_EMAIL) !== false;

    if (gnl_form_is_bot()) {
        $sent = true; $ref = gnl_form_ref($type === 'abuse' ? 'ABUS' : 'INC');
    } elseif (!gnl_form_token_ok()) {
        $error = 'Votre session a expiré. Merci de renvoyer le formulaire.';
    } elseif ($type === 'tech' && ($v['cible'] === '' || $v['depuis'] === '' || $v['description'] === '' || !$emailOk)) {
        $error = 'Merci de compléter les champs obligatoires (service concerné, date, description et e-mail valide).';
    } elseif ($type === 'abuse' && ($v['urls'] === '' || $v['motif'] === '' || empty($_POST['bonne_foi']) || (!$anon && ($v['nom'] === '' || !$emailOk)) || ($v['email'] !== '' && !$emailOk))) {
        $error = 'Merci de compléter les champs obligatoires : adresse du contenu, motif, vos coordonnées et la déclaration de bonne foi.';
    } elseif (!gnl_form_rate_ok()) {
        $error = 'Trop d\'envois en peu de temps. Réessayez dans quelques minutes, ou appelez-nous au 03 65 67 01 69.';
    } else {
        $files = gnl_form_files('pj');
        if ($type === 'tech') {
            $ref = gnl_form_ref('INC');
            $data = array('service' => $SERVICES[$v['service']][0], 'constat' => $IMPACTS[$v['impact']], 'cible' => $v['cible'],
                          'depuis' => $v['depuis'], 'description' => $v['description'], 'email' => $v['email'], 'num_client' => $v['num_client'],
                          'priorite' => in_array($v['impact'], array('down', 'sec'), true) ? 'haute' : 'normale');
            $ok = gnl_form_dispatch('incident.create', $ref, $data, $files);
        } else {
            $ref = gnl_form_ref('ABUS');
            $data = array('categorie' => $CATS[$v['categorie']], 'urls' => $v['urls'], 'motif' => $v['motif'], 'nom' => $v['nom'],
                          'email' => $v['email'], 'qualite' => $v['qualite'], 'bonne_foi' => 'oui',
                          'priorite' => in_array($v['categorie'], array('csam', 'terro'), true) ? 'haute' : 'normale');
            $ok = gnl_form_dispatch('abuse.create', $ref, $data, $files);
        }
        if ($ok) $sent = true;
        else $error = 'L\'envoi n\'a pas abouti. Écrivez-nous à contact@gnl-solution.com ou appelez le 03 65 67 01 69.';
    }
}

$gnl_head = <<<'GNLHEAD'
<style>
a{color:#1f7a4a}a:hover{color:#141a33}
input:focus-visible,select:focus-visible,textarea:focus-visible,button:focus-visible{outline:2px solid #1f7a4a;outline-offset:2px}
.gnl-typecard{display:block;text-align:left;padding:20px 22px;border-radius:3px;border:1px solid #c5cbd8;background:#fff;color:#141a33;text-decoration:none;font:400 14px/21px var(--font-display)}
.gnl-typecard strong{display:block;font-size:17px;font-weight:800;margin-bottom:4px}
.gnl-typecard span{color:#5e6684}
.gnl-typecard:hover{border-color:#141a33;color:#141a33}
.gnl-typecard[aria-current="page"]{border:2px solid #1f7a4a;background:#eef8f2}
.gnl-radio{position:relative}
.gnl-radio input,.gnl-card-radio input{position:absolute;opacity:0;width:1px;height:1px}
.gnl-radio span{display:inline-flex;align-items:center;min-height:44px;padding:0 16px;border-radius:3px;border:1px solid #c5cbd8;background:#fff;color:#141a33;font:600 14px var(--font-display);cursor:pointer}
.gnl-radio input:checked+span{background:#141a33;border-color:#141a33;color:#fff}
.gnl-card-radio{position:relative;display:block}
.gnl-card-radio span{display:block;min-height:72px;padding:14px 16px;border-radius:3px;border:1px solid #c5cbd8;background:#fff;color:#5e6684;font:400 14px/20px var(--font-display);cursor:pointer}
.gnl-card-radio span strong{display:block;font-size:15px;font-weight:700;margin-bottom:2px;color:#141a33}
.gnl-card-radio input:checked+span{border:2px solid #1f7a4a;background:#eef8f2;padding:13px 15px}
.gnl-radio input:focus-visible+span,.gnl-card-radio input:focus-visible+span{outline:2px solid #1f7a4a;outline-offset:2px}
</style>
GNLHEAD;
$gnl_js = <<<'GNLJS'
<script>
(function(){
  var sel = document.getElementById('gnl-abuse-cat'); if (!sel) return;
  function sync(){
    var anon = sel.value === 'csam';
    document.querySelectorAll('.gnl-idreq').forEach(function(i){ if (anon) i.removeAttribute('required'); else i.setAttribute('required',''); });
    document.querySelectorAll('.gnl-reqmark').forEach(function(s){ s.hidden = anon; });
    var n = document.getElementById('gnl-anon'); if (n) n.hidden = !anon;
  }
  sel.addEventListener('change', sync); sync();
})();
</script>
GNLJS;
gnl_ui_open($type === 'abuse' ? 'Signaler un contenu illicite' : 'Signaler un incident', '', array('desc' => 'Signalez un incident technique ou un contenu illicite hébergé par GNL Solution.', 'head' => $gnl_head, 'main' => false));
?>
<div class="gnl-page" style="color: #141a33; background: #f4f6fa;">
<main id="contenu" style="max-width: 1040px; margin: 0 auto; padding: 48px 24px 96px;">

<p style="margin: 0 0 24px; font-size: 14px;"><a href="/stats" style="font-weight: 700; text-decoration: none;">← État des services</a></p>

<?php if ($sent): $isA = ($type === 'abuse'); ?>
<!-- Confirmation -->
<section role="status" style="background: #ffffff; border: 1px solid #c5cbd8; border-radius: 3px; padding: 40px;">
<div style="display: flex; gap: 16px; align-items: center; margin-bottom: 20px;">
<svg aria-hidden="true" width="18" height="34" viewBox="0 0 22 40"><path d="M13 0h9L9 40H0z" fill="#1f7a4a"/></svg>
<p style="margin: 0; font-size: 12px; font-weight: 700; letter-spacing: 0.2em; color: #1f7a4a;"><?php echo $isA ? 'NOTIFICATION REÇUE' : 'SIGNALEMENT REÇU'; ?></p>
</div>
<h1 style="margin: 0 0 12px; font-size: 36px; line-height: 42px; font-weight: 800;"><?php echo $isA ? 'Merci, votre notification est enregistrée.' : 'Merci, nous prenons le relais.'; ?></h1>
<p style="margin: 0 0 28px; max-width: 640px; font-size: 17px; line-height: 27px; color: #5e6684;">Votre signalement porte la référence <strong style="color: #141a33;"><?php echo gnl_ui_h($ref); ?></strong>. <?php echo $isA ? 'Nous l\'examinons avec diligence ; conservez cette référence pour tout échange.' : 'Un technicien le prend en charge ; rappelez cette référence si vous nous contactez.'; ?></p>
<ol style="margin: 0 0 32px; padding: 0; list-style: none; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1px; background: #c5cbd8; border: 1px solid #c5cbd8;">
<li style="background: #ffffff; padding: 20px 22px;"><p style="margin: 0 0 6px; font-size: 11px; font-weight: 700; letter-spacing: 0.2em; color: #1f7a4a;">1 · FAIT</p><p style="margin: 0; font-size: 15px; font-weight: 700;"><?php echo $isA ? 'Notification enregistrée' : 'Signalement reçu'; ?></p></li>
<li style="background: #ffffff; padding: 20px 22px;"><p style="margin: 0 0 6px; font-size: 11px; font-weight: 700; letter-spacing: 0.2em; color: #b54708;">2 · EN COURS</p><p style="margin: 0; font-size: 15px; font-weight: 700;"><?php echo $isA ? 'Examen du contenu signalé' : 'Prise en charge par un technicien'; ?></p></li>
<li style="background: #ffffff; padding: 20px 22px;"><p style="margin: 0 0 6px; font-size: 11px; font-weight: 700; letter-spacing: 0.2em; color: #5e6684;">3 · À VENIR</p><p style="margin: 0; font-size: 15px; font-weight: 700;"><?php echo $isA ? 'Décision motivée communiquée' : 'Résolution et compte rendu'; ?></p></li>
</ol>
<div style="display: flex; flex-wrap: wrap; gap: 14px;">
<a href="/stats" class="gnl-b gnl-b-green">Suivre l'état des services</a>
<a href="/incident<?php echo $isA ? '?type=abuse' : ''; ?>" class="gnl-b gnl-b-line">Faire un autre signalement</a>
</div>
</section>

<?php else: ?>
<div style="margin-bottom: 32px;">
<p style="margin: 0 0 12px; font-size: 12px; font-weight: 700; letter-spacing: 0.2em; color: #1f7a4a;">SUPPORT</p>
<h1 style="margin: 0 0 12px; font-size: clamp(32px, 4vw, 44px); line-height: 1.14; font-weight: 800;">Signaler un incident</h1>
<p style="margin: 0; max-width: 680px; font-size: 17px; line-height: 27px; color: #5e6684;">Un problème technique sur nos services, ou un contenu illégal hébergé chez nous ? Chaque signalement est lu par une personne, à Besançon.</p>
</div>

<!-- Type de signalement -->
<nav aria-label="Type de signalement" style="margin: 0 0 32px;">
<p style="margin: 0 0 12px; font-size: 16px; font-weight: 800;">Que souhaitez-vous signaler ?</p>
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px;">
<a href="/incident" class="gnl-typecard"<?php echo $type === 'tech' ? ' aria-current="page"' : ''; ?>><strong>Un incident technique</strong><span>Site inaccessible, lenteur, erreur, faille de sécurité sur nos services.</span></a>
<a href="/incident?type=abuse" class="gnl-typecard"<?php echo $type === 'abuse' ? ' aria-current="page"' : ''; ?>><strong>Un contenu illicite hébergé chez nous</strong><span>Contenu haineux, arnaque, contrefaçon, données personnelles… sur un site que nous hébergeons.</span></a>
</div>
</nav>

<?php if ($error !== ''): ?><p class="gnl-alert-err" role="alert" style="margin: 0 0 24px;"><?php echo gnl_ui_h($error); ?></p><?php endif; ?>

<?php if ($type === 'tech'): ?>
<!-- État actuel -->
<section style="background: #ffffff; border: 1px solid #c5cbd8; border-radius: 3px; padding: 20px 24px; display: flex; flex-wrap: wrap; gap: 12px 24px; align-items: center; justify-content: space-between; margin-bottom: 24px;">
<p style="margin: 0; font-size: 15px; line-height: 22px;"><strong>Un incident est peut-être déjà connu.</strong> <span style="color: #5e6684;">Vérifiez l'état de nos services : un incident en cours y est affiché avec son suivi.</span></p>
<a href="/stats" style="font-size: 14px; font-weight: 700;">Voir l'état des services →</a>
</section>

<!-- Urgence sécurité -->
<section style="background: #141a33; color: #ffffff; border-radius: 3px; padding: 20px 24px; display: flex; flex-wrap: wrap; gap: 12px 24px; align-items: center; justify-content: space-between; margin-bottom: 32px;">
<p style="margin: 0; font-size: 15px; line-height: 22px;"><strong>Suspicion de piratage ou de fuite de données ?</strong> <span style="color: #c5cbd8;">Appelez-nous directement, sans attendre.</span></p>
<a href="tel:+33365670169" style="display: inline-flex; align-items: center; min-height: 44px; padding: 0 18px; border-radius: 3px; background: #5cd98b; color: #141a33; font-weight: 700; font-size: 14px; text-decoration: none;">03 65 67 01 69</a>
</section>

<!-- Formulaire -->
<form method="post" action="/incident" enctype="multipart/form-data" style="background: #ffffff; border: 1px solid #c5cbd8; border-radius: 3px; padding: 36px; display: flex; flex-direction: column; gap: 28px;">
<input type="hidden" name="type" value="tech">
<input type="hidden" name="csrf" value="<?php echo gnl_ui_h(gnl_form_token()); ?>">
<div class="gnl-honey" aria-hidden="true"><label>Ne pas remplir <input type="text" name="site_web" tabindex="-1" autocomplete="off"></label></div>

<fieldset style="border: 0; margin: 0; padding: 0;">
<legend style="margin: 0 0 12px; font-size: 16px; font-weight: 800;">1. Quel service est concerné ? *</legend>
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px;">
<?php foreach ($SERVICES as $k => $s): ?>
<label class="gnl-card-radio"><input type="radio" name="service" value="<?php echo $k; ?>"<?php echo $v['service'] === $k ? ' checked' : ''; ?>><span><strong><?php echo gnl_ui_h($s[0]); ?></strong><?php echo gnl_ui_h($s[1]); ?></span></label>
<?php endforeach; ?>
</div>
</fieldset>

<fieldset style="border: 0; margin: 0; padding: 0;">
<legend style="margin: 0 0 12px; font-size: 16px; font-weight: 800;">2. Que constatez-vous ? *</legend>
<div style="display: flex; flex-wrap: wrap; gap: 10px;">
<?php foreach ($IMPACTS as $k => $lab): ?>
<label class="gnl-radio"><input type="radio" name="impact" value="<?php echo $k; ?>"<?php echo $v['impact'] === $k ? ' checked' : ''; ?>><span><?php echo gnl_ui_h($lab); ?></span></label>
<?php endforeach; ?>
</div>
</fieldset>

<div style="display: flex; flex-direction: column; gap: 20px;">
<p style="margin: 0; font-size: 16px; font-weight: 800;">3. Détails</p>
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px;">
<label style="display: flex; flex-direction: column; gap: 8px; font-size: 14px; font-weight: 700;">Site, domaine ou machine concerné *
<input type="text" name="cible" required maxlength="200" placeholder="mon-asso.fr, VM labo-12…" value="<?php echo gnl_ui_h($v['cible']); ?>" style="min-height: 46px; padding: 0 14px; border: 1px solid #5e6684; border-radius: 3px; font: 400 15px var(--font-display); color: #141a33;">
</label>
<label style="display: flex; flex-direction: column; gap: 8px; font-size: 14px; font-weight: 700;">Depuis quand ? *
<input type="datetime-local" name="depuis" required value="<?php echo gnl_ui_h($v['depuis']); ?>" style="min-height: 46px; padding: 0 14px; border: 1px solid #5e6684; border-radius: 3px; font: 400 15px var(--font-display); color: #141a33;">
</label>
</div>
<label style="display: flex; flex-direction: column; gap: 8px; font-size: 14px; font-weight: 700;">Description *
<textarea name="description" required rows="5" maxlength="5000" placeholder="Ce que vous faisiez, ce qui s'est passé, le message d'erreur exact s'il y en a un…" style="padding: 12px 14px; border: 1px solid #5e6684; border-radius: 3px; font: 400 15px/24px var(--font-display); color: #141a33; resize: vertical;"><?php echo gnl_ui_h($v['description']); ?></textarea>
</label>
<label style="display: flex; flex-direction: column; gap: 8px; font-size: 14px; font-weight: 700;">Capture d'écran <span style="font-weight: 400; color: #5e6684;">(facultatif, 5 Mo max.)</span>
<input type="file" name="pj[]" accept="image/*,.txt,.log" style="font: 400 14px var(--font-display); color: #141a33;">
</label>
</div>

<div style="display: flex; flex-direction: column; gap: 20px;">
<p style="margin: 0; font-size: 16px; font-weight: 800;">4. Vos coordonnées</p>
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px;">
<label style="display: flex; flex-direction: column; gap: 8px; font-size: 14px; font-weight: 700;">E-mail *
<input type="email" name="email" required maxlength="160" autocomplete="email" placeholder="vous@exemple.fr" value="<?php echo gnl_ui_h($v['email']); ?>" style="min-height: 46px; padding: 0 14px; border: 1px solid #5e6684; border-radius: 3px; font: 400 15px var(--font-display); color: #141a33;">
</label>
<label style="display: flex; flex-direction: column; gap: 8px; font-size: 14px; font-weight: 700;">N° client <span style="font-weight: 400; color: #5e6684;">(si vous l'avez)</span>
<input type="text" name="num_client" maxlength="60" placeholder="Visible dans votre Espace Client" value="<?php echo gnl_ui_h($v['num_client']); ?>" style="min-height: 46px; padding: 0 14px; border: 1px solid #5e6684; border-radius: 3px; font: 400 15px var(--font-display); color: #141a33;">
</label>
</div>
</div>

<p style="margin: 0; padding: 14px 16px; background: #f4f6fa; border-radius: 3px; font-size: 12px; line-height: 19px; color: #5e6684;"><strong style="color: #141a33;">Vos données.</strong> GNL Solution (Gabin Grobost, EI) traite ces informations pour diagnostiquer et résoudre l'incident, dans le cadre de votre contrat ou de son intérêt légitime, et les conserve [DURÉE]. Droits d'accès, de rectification, d'effacement et d'opposition : <a href="mailto:contact@gnl-solution.com">contact@gnl-solution.com</a>. Réclamation possible auprès de la CNIL. <a href="/politique-de-confidentialite">Politique de confidentialité</a></p>
<div style="display: flex; flex-wrap: wrap; gap: 16px; align-items: center; border-top: 1px solid #c5cbd8; padding-top: 24px;">
<button type="submit" class="gnl-b gnl-b-green" style="padding: 0 24px;">Envoyer le signalement</button>
<span style="font-size: 13px; color: #5e6684;">* champs obligatoires</span>
</div>
</form>

<?php else: ?>
<!-- Urgence -->
<section style="background: #141a33; color: #ffffff; border-radius: 3px; padding: 24px; margin-bottom: 24px; display: flex; flex-direction: column; gap: 10px;">
<p style="margin: 0; font-size: 16px; line-height: 24px; font-weight: 800;">Danger immédiat pour une personne ? Appelez le 17 ou le 112.</p>
<p style="margin: 0; font-size: 14px; line-height: 22px; color: #c5cbd8;">Pour les contenus pédocriminels, terroristes ou d'incitation à la haine, signalez-les aussi sur la plateforme officielle <a href="https://www.internet-signalement.gouv.fr" style="color: #5cd98b; font-weight: 700;">PHAROS</a>. Nous traitons ces signalements en priorité.</p>
</section>

<!-- Formulaire de notification -->
<form method="post" action="/incident?type=abuse" enctype="multipart/form-data" style="background: #ffffff; border: 1px solid #c5cbd8; border-radius: 3px; padding: 36px; display: flex; flex-direction: column; gap: 28px;">
<input type="hidden" name="type" value="abuse">
<input type="hidden" name="csrf" value="<?php echo gnl_ui_h(gnl_form_token()); ?>">
<div class="gnl-honey" aria-hidden="true"><label>Ne pas remplir <input type="text" name="site_web" tabindex="-1" autocomplete="off"></label></div>
<p style="margin: 0; font-size: 14px; line-height: 22px; color: #5e6684;">En tant qu'hébergeur, nous examinons chaque notification avec diligence. Pour qu'elle soit recevable, indiquez précisément le contenu, son adresse et la raison pour laquelle vous le jugez illicite.</p>

<div style="display: flex; flex-direction: column; gap: 20px;">
<p style="margin: 0; font-size: 16px; font-weight: 800;">1. Le contenu</p>
<label style="display: flex; flex-direction: column; gap: 8px; font-size: 14px; font-weight: 700;">Nature du contenu *
<select name="categorie" id="gnl-abuse-cat" required style="min-height: 46px; padding: 0 14px; border: 1px solid #5e6684; border-radius: 3px; font: 400 15px var(--font-display); color: #141a33; background: #ffffff;">
<?php foreach ($CATS as $k => $lab): ?><option value="<?php echo $k; ?>"<?php echo $v['categorie'] === $k ? ' selected' : ''; ?>><?php echo gnl_ui_h($lab); ?></option><?php endforeach; ?>
</select>
</label>
<label style="display: flex; flex-direction: column; gap: 8px; font-size: 14px; font-weight: 700;">Adresse(s) exacte(s) du contenu (URL) *
<textarea name="urls" required rows="3" maxlength="3000" placeholder="https://… (une adresse par ligne)" style="padding: 12px 14px; border: 1px solid #5e6684; border-radius: 3px; font: 400 15px/24px var(--font-display); color: #141a33; resize: vertical;"><?php echo gnl_ui_h($v['urls']); ?></textarea>
</label>
<label style="display: flex; flex-direction: column; gap: 8px; font-size: 14px; font-weight: 700;">Pourquoi ce contenu est-il illicite ? *
<textarea name="motif" required rows="5" maxlength="5000" placeholder="Décrivez le contenu et expliquez en quoi il enfreint la loi (texte de loi visé, droit qui vous est atteint…)" style="padding: 12px 14px; border: 1px solid #5e6684; border-radius: 3px; font: 400 15px/24px var(--font-display); color: #141a33; resize: vertical;"><?php echo gnl_ui_h($v['motif']); ?></textarea>
</label>
<label style="display: flex; flex-direction: column; gap: 8px; font-size: 14px; font-weight: 700;">Pièces justificatives <span style="font-weight: 400; color: #5e6684;">(facultatif : captures, titre de propriété, décision de justice…)</span>
<input type="file" name="pj[]" multiple accept="image/*,.pdf" style="font: 400 14px var(--font-display); color: #141a33;">
</label>
</div>

<div style="display: flex; flex-direction: column; gap: 20px;">
<p style="margin: 0; font-size: 16px; font-weight: 800;">2. Vos coordonnées</p>
<p id="gnl-anon" style="margin: 0; padding: 14px 16px; background: #f4f6fa; border-radius: 3px; font-size: 14px; line-height: 22px;"<?php echo $v['categorie'] === 'csam' ? '' : ' hidden'; ?>>Pour ce type de contenu, vous pouvez rester anonyme : vos nom et e-mail sont facultatifs.</p>
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px;">
<label style="display: flex; flex-direction: column; gap: 8px; font-size: 14px; font-weight: 700;">Nom et prénom <span class="gnl-reqmark">*</span>
<input type="text" name="nom" class="gnl-idreq" maxlength="120" autocomplete="name" placeholder="Camille Martin" value="<?php echo gnl_ui_h($v['nom']); ?>"<?php echo $v['categorie'] === 'csam' ? '' : ' required'; ?> style="min-height: 46px; padding: 0 14px; border: 1px solid #5e6684; border-radius: 3px; font: 400 15px var(--font-display); color: #141a33;">
</label>
<label style="display: flex; flex-direction: column; gap: 8px; font-size: 14px; font-weight: 700;">E-mail <span class="gnl-reqmark">*</span>
<input type="email" name="email" class="gnl-idreq" maxlength="160" autocomplete="email" placeholder="vous@exemple.fr" value="<?php echo gnl_ui_h($v['email']); ?>"<?php echo $v['categorie'] === 'csam' ? '' : ' required'; ?> style="min-height: 46px; padding: 0 14px; border: 1px solid #5e6684; border-radius: 3px; font: 400 15px var(--font-display); color: #141a33;">
</label>
<label style="display: flex; flex-direction: column; gap: 8px; font-size: 14px; font-weight: 700;">Vous agissez <span style="font-weight: 400; color: #5e6684;">(facultatif)</span>
<select name="qualite" style="min-height: 46px; padding: 0 14px; border: 1px solid #5e6684; border-radius: 3px; font: 400 15px var(--font-display); color: #141a33; background: #ffffff;">
<?php foreach ($QUALITES as $q): ?><option<?php echo $v['qualite'] === $q ? ' selected' : ''; ?>><?php echo gnl_ui_h($q); ?></option><?php endforeach; ?>
</select>
</label>
</div>
</div>

<label style="display: flex; gap: 12px; align-items: flex-start; font-size: 14px; line-height: 22px;">
<input type="checkbox" name="bonne_foi" value="1" required style="width: 20px; height: 20px; margin: 2px 0 0; flex: none; accent-color: #1f7a4a;">
<span>Je déclare de bonne foi que les informations de cette notification sont exactes et complètes. *</span>
</label>

<p style="margin: 0; padding: 14px 16px; border: 1px solid #b54708; border-radius: 3px; font-size: 13px; line-height: 20px; color: #141a33;">Présenter sciemment un contenu comme illicite pour en obtenir le retrait, en sachant cette information inexacte, est puni par la loi (LCEN, art. 6) d'un an d'emprisonnement et de 15 000 € d'amende.</p>

<p style="margin: 0; padding: 14px 16px; background: #f4f6fa; border-radius: 3px; font-size: 12px; line-height: 19px; color: #5e6684;"><strong style="color: #141a33;">Vos données.</strong> GNL Solution (Gabin Grobost, EI) traite cette notification pour respecter ses obligations d'hébergeur (LCEN, règlement européen sur les services numériques) et la conserve [DURÉE]. Votre identité n'est communiquée à l'éditeur du contenu signalé que si la loi l'impose. Droits d'accès, de rectification et d'effacement : <a href="mailto:contact@gnl-solution.com">contact@gnl-solution.com</a>. Réclamation possible auprès de la CNIL. <a href="/politique-de-confidentialite">Politique de confidentialité</a></p>
<div style="display: flex; flex-wrap: wrap; gap: 16px; align-items: center; border-top: 1px solid #c5cbd8; padding-top: 24px;">
<button type="submit" class="gnl-b gnl-b-green" style="padding: 0 24px;">Envoyer la notification</button>
<span style="font-size: 13px; color: #5e6684;">Accusé de réception immédiat · * champs obligatoires</span>
</div>
</form>
<?php endif; ?>
<?php endif; ?>

</main>
</div>
<?php gnl_ui_close($gnl_js); ?>
