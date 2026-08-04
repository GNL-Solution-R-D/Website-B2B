<?php
/* =====================================================================
   À COLLER dans le functions.php du thème (ou un mini-plugin).
   Charge le badge panier universel sur TOUTES les pages WordPress.
   Les pages personnalisées (/cart, configurateur, /commande) affichent
   déjà l'icône côté serveur ; ce snippet couvre le reste du site.
   ===================================================================== */
add_action('wp_footer', function () {
    // Chemin vers le fichier JS déposé dans le thème. Adaptez si besoin.
    $src = get_stylesheet_directory_uri() . '/js/gnl-cart-badge.js';
    echo '<script src="' . esc_url($src) . '" defer></script>' . "\n";
}, 99);
