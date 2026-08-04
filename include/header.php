<?php
/* ---------------------------------------------------------------------
   Icone panier de l'en-tete — pilotee par le panier « maison »
   (session $_SESSION['gnl_cart'], voir cart.php). On additionne les
   quantites pour afficher l'icone des qu'il y a un article et la garder
   visible tant que le panier n'est pas vide. Aucune dependance a l'etat
   JavaScript de SureCart, qui ne reflete pas ce panier. */
if (session_status() === PHP_SESSION_NONE && !headers_sent()) { session_start(); }
$gnl_cart_count = 0;
if (!empty($_SESSION['gnl_cart']) && is_array($_SESSION['gnl_cart'])) {
    foreach ($_SESSION['gnl_cart'] as $gnl_ci) {
        $gnl_cart_count += isset($gnl_ci['qty']) ? max(0, (int) $gnl_ci['qty']) : 1;
    }
}
?>
<header class="wp-block-template-part">
<div class="wp-block-group alignfull is-layout-flow wp-block-group-is-layout-flow">
<div class="wp-block-group has-global-padding is-layout-constrained wp-block-group-is-layout-constrained">
<div class="wp-block-group alignfull is-content-justification-space-between is-nowrap is-layout-flex wp-container-core-group-is-layout-55cd4bd1 wp-block-group-is-layout-flex wp-container-3 is-position-sticky" style="padding-top:0;padding-right:var(--wp--preset--spacing--40);padding-bottom:0;padding-left:0">
<div class="wp-block-group is-nowrap is-layout-flex wp-container-core-group-is-layout-c0d5ccf6 wp-block-group-is-layout-flex"><div class="wp-block-site-logo"><a href="./" class="custom-logo-link" rel="home" aria-current="page"><img width="135" height="75" src="wp-content/uploads/2025/04/Logo-GNL3.png" class="custom-logo" alt="GNL Solution" decoding="async" srcset="https://gnl-solution.fr/wp-content/uploads/2025/04/Logo-GNL3.png 1920w, https://gnl-solution.fr/wp-content/uploads/2025/04/Logo-GNL3-600x338.png 600w, https://gnl-solution.fr/wp-content/uploads/2025/04/Logo-GNL3-300x169.png 300w, https://gnl-solution.fr/wp-content/uploads/2025/04/Logo-GNL3-1024x576.png 1024w, https://gnl-solution.fr/wp-content/uploads/2025/04/Logo-GNL3-768x432.png 768w, https://gnl-solution.fr/wp-content/uploads/2025/04/Logo-GNL3-1536x864.png 1536w" sizes="(max-width: 135px) 100vw, 135px" /></a></div></div>



<div class="wp-block-group is-content-justification-right is-nowrap is-layout-flex wp-container-core-group-is-layout-82baacbd wp-block-group-is-layout-flex"><nav style="color: #353535;" class="has-text-color is-responsive items-justified-right wp-block-navigation is-content-justification-right is-layout-flex wp-container-core-navigation-is-layout-fc306653 wp-block-navigation-is-layout-flex" aria-label="Navigation" 
		 data-wp-interactive="core/navigation" data-wp-context='{"overlayOpenedBy":{"click":false,"hover":false,"focus":false},"type":"overlay","roleAttribute":"","ariaLabel":"Menu"}'><button aria-haspopup="dialog" aria-label="Ouvrir le menu" class="wp-block-navigation__responsive-container-open" 
				data-wp-on--click="actions.openMenuOnClick"
				data-wp-on--keydown="actions.handleMenuKeydown"
			><svg width="24" height="24" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 7.5h16v1.5H4z"></path><path d="M4 15h16v1.5H4z"></path></svg></button>
				<div class="wp-block-navigation__responsive-container  has-text-color has-contrast-color has-background has-base-background-color"  id="modal-2" 
				data-wp-class--has-modal-open="state.isMenuOpen"
				data-wp-class--is-menu-open="state.isMenuOpen"
				data-wp-watch="callbacks.initMenu"
				data-wp-on--keydown="actions.handleMenuKeydown"
				data-wp-on--focusout="actions.handleMenuFocusout"
				tabindex="-1"
			>
					<div class="wp-block-navigation__responsive-close" tabindex="-1">
						<div class="wp-block-navigation__responsive-dialog" 
				data-wp-bind--aria-modal="state.ariaModal"
				data-wp-bind--aria-label="state.ariaLabel"
				data-wp-bind--role="state.roleAttribute"
			>
							<button aria-label="Fermer le menu" class="wp-block-navigation__responsive-container-close" 
				data-wp-on--click="actions.closeMenuOnClick"
			><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path d="m13.06 12 6.47-6.47-1.06-1.06L12 10.94 5.53 4.47 4.47 5.53 10.94 12l-6.47 6.47 1.06 1.06L12 13.06l6.47 6.47 1.06-1.06L13.06 12Z"></path></svg></button>
							<div class="wp-block-navigation__responsive-container-content" 
				data-wp-watch="callbacks.focusFirstElement"
			 id="modal-2-content">
								<ul style="color: #353535;" class="wp-block-navigation__container has-text-color is-responsive items-justified-right wp-block-navigation"><li class=" wp-block-navigation-item wp-block-navigation-link"><a class="wp-block-navigation-item__content"  href="#"><span class="wp-block-navigation-item__label">Qui somme nous ?</span></a></li><li data-wp-context="{ &quot;submenuOpenedBy&quot;: { &quot;click&quot;: false, &quot;hover&quot;: false, &quot;focus&quot;: false }, &quot;type&quot;: &quot;submenu&quot;, &quot;modal&quot;: null, &quot;previousFocus&quot;: null }" data-wp-interactive="core/navigation" data-wp-on--focusout="actions.handleMenuFocusout" data-wp-on--keydown="actions.handleMenuKeydown" data-wp-on--mouseenter="actions.openMenuOnHover" data-wp-on--mouseleave="actions.closeMenuOnHover" data-wp-watch="callbacks.initMenu" tabindex="-1" class="wp-block-navigation-item has-child open-on-hover-click wp-block-navigation-submenu"><a class="wp-block-navigation-item__content"><span class="wp-block-navigation-item__label">Un Incident ?</span></a><button data-wp-bind--aria-expanded="state.isMenuOpen" data-wp-on--click="actions.toggleMenuOnClick" aria-label="Sous-menu Un Incident ?" class="wp-block-navigation__submenu-icon wp-block-navigation-submenu__toggle" ><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true" focusable="false"><path d="M1.50002 4L6.00002 8L10.5 4" stroke-width="1.5"></path></svg></button><ul data-wp-on--focus="actions.openMenuOnFocus" class="wp-block-navigation__submenu-container has-text-color has-contrast-color has-background has-base-background-color wp-block-navigation-submenu"><li class=" wp-block-navigation-item wp-block-navigation-link"><a class="wp-block-navigation-item__content"  href="./stats"><span class="wp-block-navigation-item__label">États de nos systèmes</span></a></li><li class=" wp-block-navigation-item wp-block-navigation-link"><a class="wp-block-navigation-item__content"  href="https://incident.gnl-solution.fr/"><span class="wp-block-navigation-item__label">Signaler un Incident</span></a></li></ul></li></ul>
							</div>
						</div>
					</div>
				</div></nav>

<a
	class="menu-link wp-block-surecart-cart-menu-icon-button"
	href="/cart"
	aria-label="Voir le panier (<?php echo (int) $gnl_cart_count; ?> article<?php echo $gnl_cart_count > 1 ? 's' : ''; ?>)"<?php echo $gnl_cart_count > 0 ? '' : ' hidden'; ?>
>
	<div class="sc-cart-icon">
		<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
  <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z" />
  <line x1="3" y1="6" x2="21" y2="6" />
  <path d="M16 10a4 4 0 0 1-8 0" />
</svg>
		<span
			class="sc-cart-count"<?php echo $gnl_cart_count > 0 ? '' : ' hidden'; ?>
		><?php echo (int) $gnl_cart_count; ?></span>
	</div>
</a></div>
</div>
</div>
</div>
</header>