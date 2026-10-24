<?php
/* =====================================================================
   Pied de page commun + fenêtre « RGPD et statistiques ».
   Inclus par include/layout.php (gnl_ui_close).
   ===================================================================== */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/i18n.php';
if (!function_exists('gnl_ui_h')) {
    function gnl_ui_h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
}
?>
<footer class="gnl-ft">
  <div class="gnl-ft-in">
    <div class="gnl-ft-brand">
      <img src="/assets/gnl/img/logo-gnl-marine.svg" alt="GNL Solution" width="194" height="62">
      <p>Hébergement bas carbone à Besançon (Doubs), France.<br>SIRET 942 358 805 00011</p>
    </div>
    <div class="gnl-ft-col">
      <p class="gnl-ft-h">CONTACT</p>
      <p><a class="gnl-ft-strong" href="tel:+33365670169">03 65 67 01 69</a><br><a href="mailto:contact@gnl-solution.com">contact@gnl-solution.com</a><br><a href="/contact">Formulaire de contact</a></p>
    </div>
    <div class="gnl-ft-col">
      <p class="gnl-ft-h">COMMUNAUTÉ</p>
      <p><a href="https://github.com/GNL-Solution-R-D" rel="noopener">GitHub</a><br><a href="https://www.linkedin.com/company/109507423/" rel="noopener">LinkedIn</a><br><a href="https://discord.gg/RKnRsNbQG5" rel="noopener">Discord</a></p>
    </div>
    <div class="gnl-ft-col">
      <p class="gnl-ft-h">INFORMATIONS</p>
      <p><a href="/cgu">CGU</a><br><a href="/cgv">CGV</a><br><a href="/politique-de-confidentialite">Politique de confidentialité</a><br><button type="button" class="gnl-linkbtn" data-gnl-open="gnl-rgpd">RGPD et statistiques</button><br><a href="/incident">Signaler un incident</a></p>
    </div>
  </div>
  <div class="gnl-ft-bottom"><span>© <?php echo date('Y'); ?> GNL Solution · gnl-solution.com</span><button type="button" class="gnl-linkbtn gnl-ft-loc" data-gnl-open="gnl-loc"><?php echo gnl_ui_h(gnl_t(gnl_i18n_regions()[gnl_region()]['name'])); ?> · <span translate="no"><?php echo gnl_ui_h(gnl_i18n_lang_names()[gnl_lang()]); ?></span></button></div>
</footer>

<!-- Fenêtre RGPD et statistiques -->
<!-- Gabarits : hors du DOM tant que la fenêtre n'est pas ouverte (sobriété, EcoIndex) -->
<template id="tpl-gnl-rgpd">
<div class="gnl-modal" id="gnl-rgpd" hidden>
  <div class="gnl-modal-box" role="dialog" aria-modal="true" aria-labelledby="titre-rgpd">
    <div class="gnl-modal-head">
      <div class="gnl-modal-title"><svg aria-hidden="true" width="12" height="22" viewBox="0 0 22 40"><path d="M13 0h9L9 40H0z" fill="#1f7a4a"/></svg><h2 id="titre-rgpd">Vos données sur ce site</h2></div>
      <button type="button" class="gnl-modal-x" data-gnl-close aria-label="Fermer"><svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
    </div>
    <p class="gnl-modal-lead">Pas de bandeau ici : nous ne déposons aucun cookie publicitaire ni de suivi d'un site à l'autre. Voici ce que nous utilisons, et comment vous y opposer.</p>
    <div class="gnl-rgpd-list">
      <div>
        <div class="gnl-rgpd-row"><h3>Cookies nécessaires</h3><span class="gnl-rgpd-on">Toujours actifs</span></div>
        <p>Session, panier, pays et langue choisis, connexion à l'Espace Client, sécurité. Sans eux, le site ne fonctionne pas.</p>
      </div>
      <div>
        <div class="gnl-rgpd-row"><h3>Statistiques de visite</h3><span class="gnl-rgpd-state" id="gnl-stats-state">Actives</span></div>
        <p>Nous comptons les visites avec <?php echo gnl_ui_h(GNL_STATS_TOOL); ?>, installé sur nos serveurs à Besançon : adresse IP anonymisée, aucune donnée partagée ni croisée avec d'autres sites, cookie de 13 mois maximum, statistiques conservées 25 mois maximum. Elles servent uniquement à savoir quelles pages sont utiles.</p>
        <label class="gnl-rgpd-check"><input type="checkbox" id="gnl-stats-toggle" checked> Compter ma visite</label>
      </div>
    </div>
    <p class="gnl-modal-small">Responsable : GNL Solution (Gabin Grobost, EI), 20 rue Gustave Courbet, 25000 Besançon. Vous pouvez accéder à vos données, les rectifier, les effacer ou vous opposer à leur traitement en écrivant à <a href="mailto:contact@gnl-solution.com">contact@gnl-solution.com</a>, et saisir la CNIL.</p>
    <div class="gnl-modal-actions">
      <button type="button" class="gnl-b gnl-b-green" data-gnl-close>Fermer</button>
      <a href="/politique-de-confidentialite">Politique de confidentialité complète</a>
    </div>
  </div>
</div>
</template>

<!-- Fenêtre pays et langue -->
<template id="tpl-gnl-loc">
<div class="gnl-modal" id="gnl-loc" hidden>
  <div class="gnl-modal-box" role="dialog" aria-modal="true" aria-labelledby="titre-loc" style="max-width: 760px;">
    <div class="gnl-modal-head">
      <div class="gnl-modal-title"><svg aria-hidden="true" width="12" height="22" viewBox="0 0 22 40"><path d="M13 0h9L9 40H0z" fill="#1f7a4a"/></svg><h2 id="titre-loc">Pays et langue</h2></div>
      <button type="button" class="gnl-modal-x" data-gnl-close aria-label="Fermer"><svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
    </div>
    <p class="gnl-modal-lead">Les offres, la facturation et le droit applicable dépendent du pays depuis lequel vous commandez. Votre choix est mémorisé sur cet appareil.</p>
    <?php $gnl_names = gnl_i18n_lang_names(); foreach (gnl_i18n_regions() as $gnl_rk => $gnl_r): if ($gnl_rk === 'in') continue; ?>
    <section class="gnl-loc-region">
      <h3><?php echo gnl_ui_h($gnl_r['name']); ?></h3>
      <ul>
        <?php foreach ($gnl_r['langs'] as $gnl_l): $gnl_cur = ($gnl_rk === gnl_region() && $gnl_l === gnl_lang()); ?>
        <li><a translate="no" lang="<?php echo $gnl_l; ?>" hreflang="<?php echo $gnl_l; ?>" href="<?php echo gnl_ui_h(gnl_i18n_url($gnl_rk, $gnl_l)); ?>"<?php echo $gnl_cur ? ' aria-current="true"' : ''; ?>><?php echo gnl_ui_h($gnl_names[$gnl_l]); ?></a></li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endforeach; ?>
  </div>
</div>
</template>
