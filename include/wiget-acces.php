<?php
/**
 * wiget-acces.php
 * Widget d'accessibilite (Elementor / pojo-accessibility, ea11y) de GNL Solution.
 * Extrait de index.php. Inclus via :
 *   if (is_readable('../include/wiget-acces.php')) { include '../include/wiget-acces.php'; }
 *
 * Contenu : styles (police + skip-link + charte GNL), lien "Aller au contenu principal",
 * configuration ea11yWidget (icone bleu de marque #1863DC) et chargement du widget.
 * Remarque : wp-a11y (coeur WordPress) et les regions a11y-speak restent dans index.php.
 */
?>

<!-- Feuilles de style du widget d'accessibilite (police + skip-link) -->
<link rel='stylesheet' id='ea11y-widget-fonts-css' href='wp-content/plugins/pojo-accessibility/assets/build/fonts0235.css?ver=4.1.1' media='all' />
<link rel='stylesheet' id='ea11y-skip-link-css' href='wp-content/plugins/pojo-accessibility/assets/build/skip-link0235.css?ver=4.1.1' media='all' />

<!-- Surcharge charte GNL (couleurs de marque, Manrope, coins arrondis) -->
<style id="gnl-a11y-brand-css">
/* GNL Solution — accessibilite alignee sur la charte du site
   (bleu de marque #1863DC, police Manrope, coins arrondis, ombre douce) */

/* Lien "Aller au contenu principal" (rendu dans la page, entierement stylable) */
.ea11y-skip-to-content-link{
	font-family: var(--wp--preset--font-family--manrope, "Manrope", sans-serif) !important;
	font-weight: 600 !important;
	letter-spacing: -0.1px !important;
	background-color: #1863DC !important;
	color: #ffffff !important;
	border: 0 !important;
	border-radius: 3px !important;
	box-shadow: var(--wp--preset--shadow--natural, 6px 6px 9px rgba(0,0,0,.2)) !important;
	text-decoration: none !important;
}
.ea11y-skip-to-content-link:hover{
	background-color: color-mix(in srgb, #1863DC 88%, #000) !important;
}
.ea11y-skip-to-content-link:focus,
.ea11y-skip-to-content-link:focus-visible{
	outline: 2px solid #1863DC !important;
	outline-offset: 2px !important;
}
/* L'icone du lien herite de la couleur du texte (blanc sur fond bleu) */
.ea11y-skip-to-content-link svg [stroke]{ stroke: currentColor !important; }

/* Bouton flottant du widget : police de la marque sur l'element hote.
   La couleur et les coins arrondis sont deja definis via ea11yWidget.iconSettings. */
#ea11y-widget, .ea11y-widget-container, [id^="ea11y-widget"]{
	font-family: var(--wp--preset--font-family--manrope, "Manrope", sans-serif);
}
</style>

<!-- Lien "Aller au contenu principal" -->
		<script>
			const onSkipLinkClick = () => {
				const htmlElement = document.querySelector('html');

				htmlElement.style['scroll-behavior'] = 'smooth';

				setTimeout( () => htmlElement.style['scroll-behavior'] = null, 1000 );
			}
			document.addEventListener("DOMContentLoaded", () => {
				if (!document.querySelector('#content')) {
					document.querySelector('.ea11y-skip-to-content-link').remove();
				}
			});
		</script>
		<nav aria-label="Skip to content navigation">
			<a class="ea11y-skip-to-content-link"
				href="#content"
				tabindex="-1"
				onclick="onSkipLinkClick()"
			>
				Aller au contenu principal
				<svg width="24" height="24" viewBox="0 0 24 24" fill="none" role="presentation">
					<path d="M18 6V12C18 12.7956 17.6839 13.5587 17.1213 14.1213C16.5587 14.6839 15.7956 15 15 15H5M5 15L9 11M5 15L9 19"
								stroke="black"
								stroke-width="1.5"
								stroke-linecap="round"
								stroke-linejoin="round"
					/>
				</svg>
			</a>
			<div class="ea11y-skip-to-content-backdrop"></div>
		</nav>

<!-- Configuration + chargement du widget (config AVANT le loader) -->
<script id="ea11y-widget-js-extra">
var ea11yWidget = {"iconSettings":{"style":{"icon":"eye","size":"medium","color":"#1863DC","cornerRadius":{"radius":3,"unit":"px"}},"position":{"desktop":{"hidden":false,"enableExactPosition":false,"exactPosition":{"horizontal":{"direction":"right","value":10,"unit":"px"},"vertical":{"direction":"bottom","value":10,"unit":"px"}},"position":"bottom-right"},"mobile":{"hidden":false,"enableExactPosition":false,"exactPosition":{"horizontal":{"direction":"right","value":10,"unit":"px"},"vertical":{"direction":"bottom","value":10,"unit":"px"}},"position":"bottom-right"}}},"toolsSettings":{"bigger-text":{"enabled":true},"bigger-line-height":{"enabled":true},"text-align":{"enabled":true},"readable-font":{"enabled":true},"grayscale":{"enabled":true},"contrast":{"enabled":true},"page-structure":{"enabled":true},"sitemap":{"enabled":false,"url":"https://gnl-solution.fr/wp-sitemap.xml"},"reading-mask":{"enabled":true},"hide-images":{"enabled":true},"pause-animations":{"enabled":true},"highlight-links":{"enabled":true},"focus-outline":{"enabled":true},"screen-reader":{"enabled":false},"remove-elementor-label":{"enabled":false}},"accessibilityStatementURL":"","analytics":{"enabled":false,"url":null}};
//# sourceURL=ea11y-widget-js-extra
</script>
<script src="https://cdn.elementor.com/a11y/widget.js?api_key=ea11y-68734028-9c2f-4c2a-a091-10c4eea21500&amp;ver=4.1.1" id="ea11y-widget-js" referrerpolicy="origin"></script>
