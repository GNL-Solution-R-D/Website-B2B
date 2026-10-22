<?php
/* =====================================================================
   GNL Solution — Votre solution (catalogue des offres)
   Offres chargées depuis le webhook n8n (product.list / option.list),
   avec repli CSV. « Commander » ouvre une fenêtre d'options puis ajoute
   l'offre au panier de session (POST /cart?action=add), sans quitter la page.
   La vérification de domaine réutilise le proxy OVH de product-configuration.
   ===================================================================== */
require_once __DIR__ . '/../include/catalogue.php';
require_once __DIR__ . '/../include/layout.php';

$GNL_PRODUCTS = gnl_load_products();
$GNL_OPTIONS  = gnl_cat_load_options();

$KICKERS = array(1 => 'HÉBERGEMENT PHP', 2 => 'HÉBERGEMENT WORDPRESS', 3 => 'SPÉCIAL DÉVELOPPEURS');
$offers = array(); $soon = array(); $jsProducts = array();
foreach ($GNL_PRODUCTS as $p) {
    if ($p['slug'] === '' || $p['name'] === '') continue;
    if ($p['type'] === 'soon') { $soon[] = $p; continue; }
    if ($p['type'] === 'web_edu')      { $cat = 'edu'; $kicker = 'ÉTUDIANTS · CHERCHEURS'; }
    elseif ($p['categorie'] === 3)     { $cat = 'dev'; $kicker = $KICKERS[3]; }
    else                               { $cat = 'web'; $kicker = isset($KICKERS[$p['categorie']]) ? $KICKERS[$p['categorie']] : 'HÉBERGEMENT WEB'; }
    $desc = trim(strip_tags((string) $p['description']));
    $lines = array_values(array_filter(array_map(function ($l) { return trim(preg_replace('/^[\s\-\*•·]+/u', '', $l)); }, preg_split('/\r\n|\r|\n/', $desc))));
    $features = count($lines) > 1 ? array_slice($lines, 0, 7) : array();
    $pitch = $p['stitre'] !== '' ? $p['stitre'] : (count($lines) === 1 ? $lines[0] : '');
    $priceNum = (float) str_replace(array(' ', ','), array('', '.'), (string) $p['prix']);
    $offers[] = array('p' => $p, 'cat' => $cat, 'kicker' => $kicker, 'pitch' => $pitch, 'features' => $features, 'price' => gnl_price($p['prix']));
    $jsProducts[$p['slug']] = array(
        'slug' => $p['slug'], 'name' => $p['name'], 'kicker' => $kicker, 'price' => $priceNum,
        'options' => gnl_cat_has_options($p),
        'stock' => ((string) $p['stock'] !== '' && (int) $p['stock'] <= 0 && strtolower((string) $p['stock_limite']) === 'true') ? 0 : null,
    );
}
$jsData = array('products' => $jsProducts, 'options' => $GNL_OPTIONS);

$gnl_head = <<<'GNLHEAD'
<style>
a{color:#1f7a4a}a:hover{color:#141a33}
button:focus-visible,a:focus-visible,summary:focus-visible{outline:2px solid #1f7a4a;outline-offset:2px}
.faq summary{cursor:pointer;list-style:none;display:flex;justify-content:space-between;gap:16px;align-items:center;padding:20px 0;font-size:17px;font-weight:700}
.faq summary::-webkit-details-marker{display:none}
.faq summary::after{content:"+";font-size:24px;font-weight:400;color:#1f7a4a}
.faq details[open] summary::after{content:"–"}
.faq p{margin:0 0 20px;font-size:15px;line-height:24px;color:#5e6684;max-width:760px}
.gnl-offer[hidden]{display:none}
.gnl-optrow{border:1px solid #c5cbd8;border-radius:3px;background:#fff}
.gnl-optrow.is-on{border:2px solid #1f7a4a;background:#f3fbf6}
.gnl-optrow label{display:flex;gap:12px;align-items:flex-start;padding:14px 16px;cursor:pointer}
.gnl-optrow input[type=checkbox]{width:20px;height:20px;margin:1px 0 0;flex:none;accent-color:#1f7a4a}
</style>
GNLHEAD;
gnl_ui_open('Votre solution', 'solutions', array('desc' => "Nos offres d'hébergement PHP, WordPress, développeur et Laboratoire, sur des serveurs sobres à Besançon.", 'head' => $gnl_head, 'main' => false));
?>
<div class="gnl-page" style="color: #141a33; background: #f4f6fa;">
<!-- Accroche -->
<section style="background: #141a33; color: #ffffff;">
<div style="max-width: 1240px; margin: 0 auto; padding: 72px 24px 64px;">
<p style="margin: 0 0 16px; font-size: 12px; font-weight: 700; letter-spacing: 0.2em; color: #5cd98b;">VOTRE SOLUTION</p>
<h1 style="margin: 0 0 20px; max-width: 820px; font-size: clamp(34px, 4.2vw, 54px); line-height: 1.06; font-weight: 800;">L'hébergement qui correspond à votre projet.</h1>
<p style="margin: 0 0 32px; max-width: 620px; font-size: 18px; line-height: 28px; color: #c5cbd8;">Site PHP, WordPress, projet de développeur ou laboratoire d'apprentissage : chaque offre tourne sur nos serveurs sobres à Besançon et se gère depuis votre Espace Client.</p>
<ul style="margin: 0; padding: 0; list-style: none; display: flex; flex-wrap: wrap; gap: 10px 28px; font-size: 15px; color: #ffffff;">
<li style="display: flex; gap: 10px; align-items: center;"><svg aria-hidden="true" width="9" height="16" viewBox="0 0 22 40"><path d="M13 0h9L9 40H0z" fill="#5cd98b"/></svg>Hébergé à Besançon, 100 % UE</li>
<li style="display: flex; gap: 10px; align-items: center;"><svg aria-hidden="true" width="9" height="16" viewBox="0 0 22 40"><path d="M13 0h9L9 40H0z" fill="#5cd98b"/></svg>0,17 W consommés par site</li>
<li style="display: flex; gap: 10px; align-items: center;"><svg aria-hidden="true" width="9" height="16" viewBox="0 0 22 40"><path d="M13 0h9L9 40H0z" fill="#5cd98b"/></svg>Résiliable à tout moment</li>
</ul>
</div>
</section>

<main id="contenu" style="max-width: 1240px; margin: 0 auto; padding: 56px 24px 96px;">

<!-- Filtres -->
<div role="group" aria-label="Filtrer les offres" id="gnl-filters" style="display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 32px;">
<button type="button" class="gnl-pill" data-filter="all" aria-pressed="true">Toutes les offres</button>
<button type="button" class="gnl-pill" data-filter="web" aria-pressed="false">Sites web</button>
<button type="button" class="gnl-pill" data-filter="dev" aria-pressed="false">Développeurs</button>
<button type="button" class="gnl-pill" data-filter="edu" aria-pressed="false">Étudiants</button>
</div>

<!-- Offres -->
<?php if (!$offers): ?>
<div class="gnl-empty" style="margin-bottom: 64px;"><h2 style="margin-top: 0;">Le catalogue est momentanément indisponible</h2><p>Réessayez dans quelques instants, ou <a href="/contact">contactez-nous</a> : nous vous répondons rapidement.</p></div>
<?php else: ?>
<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 24px; margin-bottom: 64px;">
<?php foreach ($offers as $o): $p = $o['p']; ?>
<article class="gnl-offer" data-cat="<?php echo $o['cat']; ?>" style="background: #ffffff; border: 1px solid #c5cbd8; border-radius: 3px; padding: 28px; display: flex; flex-direction: column; gap: 14px;">
<p style="margin: 0; display: flex; justify-content: space-between; gap: 12px; font-size: 11px; font-weight: 700; letter-spacing: 0.2em; color: #1f7a4a;"><span><?php echo gnl_e($o['kicker']); ?></span><?php if ($p['flag'] === 'FR'): ?><span style="letter-spacing: 0.1em; color: #5e6684;">FRANCE</span><?php endif; ?></p>
<h2 style="margin: 0; font-size: 24px; line-height: 30px; font-weight: 800;"><?php echo gnl_e($p['name']); ?></h2>
<?php if ($o['pitch'] !== ''): ?><p style="margin: 0; font-size: 15px; line-height: 23px; color: #5e6684;"><?php echo gnl_e($o['pitch']); ?></p><?php endif; ?>
<p style="margin: 4px 0; font-size: 15px; color: #5e6684;"><?php if ($o['price'] !== ''): ?><strong style="font-size: 30px; line-height: 36px; font-weight: 800; color: #141a33;"><?php echo $o['price']; ?></strong> / mois<?php else: ?><strong style="font-size: 22px; color: #141a33;">Sur devis</strong><?php endif; ?></p>
<?php if ((int) $p['stock'] > 0): ?><p style="margin: 0; font-size: 13px; font-weight: 700; color: #b54708;">Plus que <?php echo (int) $p['stock']; ?> place<?php echo (int) $p['stock'] > 1 ? 's' : ''; ?></p><?php endif; ?>
<ul style="margin: 0; padding: 16px 0 0; list-style: none; border-top: 1px solid #e3e6ee; display: flex; flex-direction: column; gap: 10px; font-size: 14px; line-height: 21px; flex: 1;">
<?php foreach ($o['features'] as $ft): ?>
<li style="display: flex; gap: 10px; align-items: baseline;"><svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#1f7a4a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex: none; transform: translateY(2px);"><path d="M20 6 9 17l-5-5"/></svg><span><?php echo gnl_e($ft); ?></span></li>
<?php endforeach; ?>
</ul>
<button type="button" class="gnl-b gnl-b-green" data-order="<?php echo gnl_e($p['slug']); ?>" style="margin-top: 8px; min-height: 46px; font-size: 14px;">Commander</button>
<a href="/product-configuration?slug=<?php echo rawurlencode($p['slug']); ?>" style="font-size: 13px; font-weight: 600; text-align: center;">Voir le détail de l'offre</a>
</article>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($soon): ?>
<section style="margin-bottom: 64px;">
<p style="margin: 0 0 12px; font-size: 12px; font-weight: 700; letter-spacing: 0.2em; color: #1f7a4a;">BIENTÔT DISPONIBLE</p>
<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 16px;">
<?php foreach ($soon as $p): ?>
<div style="border: 2px dashed #c5cbd8; border-radius: 3px; padding: 22px;"><h3 style="margin: 0 0 6px; font-size: 18px; font-weight: 800;"><?php echo gnl_e($p['name']); ?></h3><p style="margin: 0; font-size: 14px; line-height: 22px; color: #5e6684;"><?php echo gnl_e($p['stitre'] !== '' ? $p['stitre'] : strip_tags($p['description'])); ?></p></div>
<?php endforeach; ?>
</div>
</section>
<?php endif; ?>

<!-- Nom de domaine -->
<section style="background: #141a33; color: #ffffff; border-radius: 3px; padding: 32px; display: flex; flex-wrap: wrap; gap: 20px 40px; align-items: center; justify-content: space-between; margin-bottom: 64px;">
<div style="flex: 1 1 420px; min-width: 0;">
<p style="margin: 0 0 8px; font-size: 11px; font-weight: 700; letter-spacing: 0.2em; color: #5cd98b;">EN OPTION</p>
<h2 style="margin: 0 0 8px; font-size: 24px; line-height: 30px; font-weight: 800;">Votre nom de domaine</h2>
<p style="margin: 0; font-size: 15px; line-height: 24px; color: #c5cbd8;">Réservez ou rattachez votre domaine au moment de la commande. Le prix dépend de l'extension choisie (.fr, .com, .org…).</p>
</div>
<div style="flex: 1 1 360px; min-width: 0;"><form id="gnl-dom-form" style="display: flex; gap: 10px; flex-wrap: wrap;">
<label for="domaine" style="position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0);">Nom de domaine</label>
<input id="domaine" type="text" placeholder="mon-association.fr" style="flex: 1 1 200px; min-width: 0; min-height: 48px; padding: 0 14px; border: 1px solid #5e6684; border-radius: 3px; background: #ffffff; font: 400 15px var(--font-display); color: #141a33;">
<button type="submit" style="min-height: 48px; padding: 0 20px; border-radius: 3px; border: 0; background: #5cd98b; color: #141a33; font: 700 15px var(--font-display); cursor: pointer;">Vérifier</button>
</form>
<div id="gnl-dom-res" aria-live="polite" style="margin-top: 12px; display: flex; flex-direction: column; gap: 8px; font-size: 14px;"></div></div>
</section>

<!-- Inclus partout -->
<section style="margin-bottom: 64px;">
<p style="margin: 0 0 12px; font-size: 12px; font-weight: 700; letter-spacing: 0.2em; color: #1f7a4a;">DANS TOUTES LES OFFRES</p>
<h2 style="margin: 0 0 32px; font-size: 32px; line-height: 38px; font-weight: 800;">Ce qui est toujours inclus</h2>
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 32px 40px;">
<div style="border-top: 2px solid #141a33; padding-top: 16px;"><h3 style="margin: 0 0 6px; font-size: 17px; font-weight: 800;">Données à Besançon</h3><p style="margin: 0; font-size: 14px; line-height: 22px; color: #5e6684;">Aucun transfert hors UE. Vos données restent sur notre infrastructure, conforme au RGPD.</p></div>
<div style="border-top: 2px solid #141a33; padding-top: 16px;"><h3 style="margin: 0 0 6px; font-size: 17px; font-weight: 800;">Espace Client</h3><p style="margin: 0; font-size: 14px; line-height: 22px; color: #5e6684;">Commandes, factures, ressources et abonnements, au même endroit.</p></div>
<div style="border-top: 2px solid #141a33; padding-top: 16px;"><h3 style="margin: 0 0 6px; font-size: 17px; font-weight: 800;">Supervision continue</h3><p style="margin: 0; font-size: 14px; line-height: 22px; color: #5e6684;">Disponibilité publiée en temps réel sur la page <a href="/stats">État des services</a>.</p></div>
<div style="border-top: 2px solid #141a33; padding-top: 16px;"><h3 style="margin: 0 0 6px; font-size: 17px; font-weight: 800;">Certificat HTTPS</h3><p style="margin: 0; font-size: 14px; line-height: 22px; color: #5e6684;">[À CONFIRMER : certificat SSL fourni et renouvelé automatiquement.]</p></div>
<div style="border-top: 2px solid #141a33; padding-top: 16px;"><h3 style="margin: 0 0 6px; font-size: 17px; font-weight: 800;">Sauvegardes</h3><p style="margin: 0; font-size: 14px; line-height: 22px; color: #5e6684;">[FRÉQUENCE] et conservées [DURÉE].</p></div>
<div style="border-top: 2px solid #141a33; padding-top: 16px;"><h3 style="margin: 0 0 6px; font-size: 17px; font-weight: 800;">Support de proximité</h3><p style="margin: 0; font-size: 14px; line-height: 22px; color: #5e6684;">Un interlocuteur à Besançon, joignable au 03 65 67 01 69.</p></div>
</div>
</section>

<!-- Sur mesure -->
<section style="background: #ffffff; border: 2px dashed #5e6684; border-radius: 3px; padding: 32px; display: flex; flex-wrap: wrap; gap: 20px 40px; align-items: center; justify-content: space-between; margin-bottom: 64px;">
<div style="flex: 1 1 420px; min-width: 0;">
<h2 style="margin: 0 0 8px; font-size: 24px; line-height: 30px; font-weight: 800;">Un besoin particulier ?</h2>
<p style="margin: 0; font-size: 15px; line-height: 24px; color: #5e6684;">Projet associatif, ressources spécifiques, migration depuis un autre hébergeur : parlons-en, nous construisons une offre sur mesure.</p>
</div>
<a href="/contact" style="display: inline-flex; align-items: center; min-height: 48px; padding: 0 22px; border-radius: 3px; border: 1px solid #141a33; color: #141a33; font-weight: 700; font-size: 15px; text-decoration: none;">Nous contacter</a>
</section>

<!-- FAQ -->
<section class="faq">
<h2 style="margin: 0 0 8px; font-size: 32px; line-height: 38px; font-weight: 800;">Questions fréquentes</h2>
<div style="border-top: 1px solid #c5cbd8;">
<details style="border-bottom: 1px solid #c5cbd8;"><summary>Comment se passe la commande ?</summary><p>Tout se fait depuis votre Espace Client : vous choisissez l'offre, payez en ligne et suivez la mise en service. Le service est actif sous [DÉLAI DE MISE EN SERVICE].</p></details>
<details style="border-bottom: 1px solid #c5cbd8;"><summary>Puis-je changer d'offre ou résilier ?</summary><p>Oui, à tout moment depuis l'Espace Client. Une résiliation prend effet à la fin de la période déjà payée, voir nos <a href="/cgv">CGV</a>.</p></details>
<details style="border-bottom: 1px solid #c5cbd8;"><summary>Pouvez-vous migrer mon site existant ?</summary><p>[À COMPLÉTER : conditions de migration depuis un autre hébergeur.]</p></details>
<details style="border-bottom: 1px solid #c5cbd8;"><summary>Comment est mesurée la consommation de 0,17 W par site ?</summary><p>[À COMPLÉTER : méthode de mesure, matériel, période.] <a href="/impact">En savoir plus sur notre impact</a>.</p></details>
</div>
</section>


</main>
</div>

<!-- Fenêtre de commande -->
<div class="gnl-modal" id="gnl-order" hidden>
<div class="gnl-modal-box" role="dialog" aria-modal="true" aria-labelledby="titre-commande" style="max-width: 560px;">
<div class="gnl-modal-head" style="margin-bottom: 20px;">
<div><p id="gnl-o-kicker" style="margin: 0 0 6px; font-size: 11px; font-weight: 700; letter-spacing: 0.2em; color: #1f7a4a;"></p><h2 id="titre-commande" style="margin: 0; font-size: 28px; line-height: 34px; font-weight: 800;"></h2></div>
<button type="button" class="gnl-modal-x" data-gnl-close aria-label="Fermer"><svg aria-hidden="true" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button>
</div>
<div id="gnl-o-step1">
<div style="display: flex; justify-content: space-between; align-items: baseline; gap: 16px; padding: 16px 18px; background: #f4f6fa; border-radius: 3px; margin-bottom: 24px;"><span id="gnl-o-base" style="font-size: 15px; font-weight: 700;"></span><span style="font-size: 15px;"><strong id="gnl-o-baseprice"></strong> / mois</span></div>
<fieldset id="gnl-o-opts" style="border: 0; margin: 0 0 24px; padding: 0;"><legend style="margin: 0 0 12px; font-size: 16px; font-weight: 800;">Options</legend><div id="gnl-o-list" style="display: flex; flex-direction: column; gap: 10px;"></div></fieldset>
<p id="gnl-o-none" style="margin: 0 0 24px; font-size: 15px; line-height: 24px; color: #5e6684;">Cette offre ne comporte pas d'option supplémentaire.</p>
<div style="display: flex; justify-content: space-between; align-items: baseline; gap: 16px; padding-top: 16px; border-top: 1px solid #c5cbd8; margin-bottom: 6px;"><span id="gnl-o-count" style="font-size: 15px; color: #5e6684;"></span><span style="font-size: 22px; font-weight: 800;"><span id="gnl-o-total"></span> <small style="font-size: 13px; font-weight: 500; color: #5e6684;">/ mois</small></span></div>
<p id="gnl-o-once" style="margin: 0 0 14px; text-align: right; font-size: 13px; color: #5e6684;"></p>
<p id="gnl-o-err" class="gnl-alert-err" style="margin: 0 0 14px;" hidden></p>
<button type="button" id="gnl-o-add" class="gnl-b gnl-b-green" style="width: 100%; min-height: 50px; font-size: 16px;"><svg aria-hidden="true" width="23" height="20" viewBox="0 0 36 30" fill="none"><path d="M1.5 3.5H6.2L7.6 8" stroke="#ffffff" stroke-width="2.6" stroke-linecap="square"/><path d="M7.6 8H34L30.4 21H11.2Z" fill="#5cd98b"/><rect x="11.6" y="24.4" width="3.4" height="3.4" fill="#ffffff"/><rect x="25.6" y="24.4" width="3.4" height="3.4" fill="#ffffff"/></svg>Ajouter au panier</button>
</div>
<div id="gnl-o-step2" hidden>
<div role="status" style="display: flex; gap: 14px; align-items: center; padding: 18px; background: #eef8f2; border: 1px solid #1f7a4a; border-radius: 3px; margin-bottom: 24px;"><svg aria-hidden="true" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#1f7a4a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex: none;"><path d="M20 6 9 17l-5-5"/></svg><p id="gnl-o-done" style="margin: 0; font-size: 15px; line-height: 22px;"></p></div>
<div style="display: flex; flex-wrap: wrap; gap: 12px;"><button type="button" class="gnl-b gnl-b-line" data-gnl-close style="flex: 1 1 200px;">Continuer mes achats</button><a href="/cart" class="gnl-b gnl-b-green" style="flex: 1 1 200px;">Voir le panier</a></div>
</div>
</div>
</div>

<script type="application/json" id="gnl-sol-data"><?php echo json_encode($jsData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP); ?></script>
<?php gnl_ui_close('<script src="/assets/gnl/solutions.js" defer></script>'); ?>
