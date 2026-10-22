<?php
/* =====================================================================
   GNL Solution — Réglages du site (nouvelle charte)
   ---------------------------------------------------------------------
   Toutes les valeurs peuvent être surchargées par variable d'environnement
   (SetEnv dans Apache, env[] dans PHP-FPM) sans toucher au code.
   ===================================================================== */

if (!function_exists('gnl_cfg')) {
    function gnl_cfg($key, $default = '') {
        $v = getenv($key);
        return ($v === false || $v === '') ? $default : $v;
    }
}

/* URL de l'Espace Client (bouton vert de l'en-tête). */
if (!defined('GNL_ESPACE_CLIENT_URL')) define('GNL_ESPACE_CLIENT_URL', gnl_cfg('GNL_ESPACE_CLIENT_URL', 'https://espace-client.gnl-solution.fr/'));

/* Webhook n8n qui reçoit les formulaires (contact, incident, contenu illicite).
   Même webhook que la boutique ; on distingue les cas par le champ "action". */
if (!defined('GNL_FORMS_WEBHOOK_URL')) define('GNL_FORMS_WEBHOOK_URL', gnl_cfg('GNL_FORMS_WEBHOOK_URL', 'https://api.gnl-solution.fr/webhook/boutique'));

/* Adresse de repli : si le webhook ne répond pas, le formulaire part par mail(). */
if (!defined('GNL_FORMS_MAIL_TO')) define('GNL_FORMS_MAIL_TO', gnl_cfg('GNL_FORMS_MAIL_TO', 'contact@gnl-solution.com'));

/* Statistiques de visite (Matomo auto-hébergé, configuré en mode exempté CNIL).
   Laisser vide pour désactiver complètement le traceur. */
if (!defined('GNL_MATOMO_URL'))     define('GNL_MATOMO_URL', gnl_cfg('GNL_MATOMO_URL', ''));      // ex. https://stats.gnl-solution.com/
if (!defined('GNL_MATOMO_SITE_ID')) define('GNL_MATOMO_SITE_ID', gnl_cfg('GNL_MATOMO_SITE_ID', '1'));
if (!defined('GNL_STATS_TOOL'))     define('GNL_STATS_TOOL', gnl_cfg('GNL_STATS_TOOL', 'Matomo'));

/* Délai de réponse affiché sur la page Contact. */
if (!defined('GNL_DELAI_REPONSE'))  define('GNL_DELAI_REPONSE', gnl_cfg('GNL_DELAI_REPONSE', '48 h ouvrées'));
