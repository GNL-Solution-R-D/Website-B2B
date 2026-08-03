<?php
/**
 * =====================================================================
 *  CONFIGURATION DES PAGES LÉGALES
 * =====================================================================
 *  ► Remplace UNIQUEMENT les valeurs ci-dessous par tes informations.
 *  ► Elles sont réutilisées automatiquement dans :
 *      - cgu.php
 *      - cgv.php
 *      - politique-de-confidentialite.php
 *
 *  Tout ce qui est marqué « À COMPLÉTER » doit être renseigné.
 *  Les lignes qui ne te concernent pas peuvent rester ou être vidées.
 * =====================================================================
 */

$LEGAL = [

    /* ---------- LE SITE ---------- */
    'site_nom'            => 'GNL Solution',          // ex. « Ma Boutique »
    'site_url'           => 'https://www.gnl-solution.fr',             // URL complète du site

    /* ---------- L’ÉDITEUR / LE VENDEUR ---------- */
    'raison_sociale'     => 'GABIN GROBOST',       // ex. « MA SOCIÉTÉ »
    'forme_juridique'    => 'ENTREPRENEUR INDIVIDUEL (EI)',                        // ex. « SAS », « SARL », « EURL », « entrepreneur individuel (EI) », « micro-entreprise »
    'capital_social'     => '',                        // ex. « 10 000 € » — laisser vide si entreprise individuelle / micro
    'siren'              => '942358805',                        // 9 chiffres
    'siret'              => '94235880500011',                        // 14 chiffres (siège)
    'rcs_ville'          => 'BESANCON',                        // ex. « RCS Nantes » — ou « RM » pour un artisan
    'tva_intra'          => '',                        // ex. « FR 00 123456789 » — laisser vide si non assujetti (mention ci-dessous)
    'tva_non_applicable' => true,                               // true si « TVA non applicable, art. 293 B du CGI » (micro-entreprise)

    'adresse_siege'      => '20 rue Gustave Courbet',
    'email'              => 'contact@gnl-solution.fr',
    'telephone'          => '0365670169',                        // ex. « 01 23 45 67 89 »

    'directeur_publication' => 'Gabin Grobost',       // responsable de la publication

    /* ---------- L’HÉBERGEUR ---------- */
    'hebergeur_nom'      => 'GNL Solution',   // ex. « OVH SAS », « o2switch », « Hostinger »…
    'hebergeur_adresse'  => '20 rue Gustave Courbet',
    'hebergeur_tel'      => '0365670169',                        // téléphone ou site de l’hébergeur

    /* ---------- PROTECTION DES DONNÉES (RGPD) ---------- */
    'dpo_present'        => false,                               // true si tu as désigné un Délégué à la Protection des Données
    'dpo_contact'       => 'dpo@gnl-solution.fr',                     // e-mail du DPO (si dpo_present = true)
    'contact_rgpd'      => 'contact@gnl-solution.fr',                 // e-mail pour exercer ses droits (souvent = email général)

    /* ---------- MÉDIATION DE LA CONSOMMATION (obligatoire si vente aux consommateurs) ---------- */
    'mediateur_nom'      => 'À COMPLÉTER — Nom du médiateur',     // ex. « Médiateur de la consommation … »
    'mediateur_adresse'  => 'À COMPLÉTER — Adresse du médiateur',
    'mediateur_url'      => 'https://www.exemple-mediateur.fr',

    /* ---------- DIVERS ---------- */
    'date_maj'           => '03/08/2026',                        // date de dernière mise à jour (JJ/MM/AAAA)
    'annee'              => date('Y'),
];

/**
 * Petit utilitaire d’affichage sécurisé (échappe le HTML).
 * Utilise-le partout : e($LEGAL['email'])
 */
if (!function_exists('e')) {
    function e($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
}

/**
 * Affiche la ligne « identité » complète du vendeur/éditeur,
 * en adaptant selon la forme juridique (capital / RCS / TVA).
 */
if (!function_exists('legal_identite')) {
    function legal_identite(array $L): string {
        $parts = [];
        $parts[] = '<strong>' . e($L['raison_sociale']) . '</strong>';
        $forme = trim($L['forme_juridique']);
        if ($forme !== '' && stripos($forme, 'À COMPLÉTER') === false) {
            $ligne = $forme;
            if (!empty($L['capital_social']) && stripos($L['capital_social'], 'À COMPLÉTER') === false) {
                $ligne .= ' au capital de ' . e($L['capital_social']);
            }
            $parts[] = $ligne;
        }
        if (!empty($L['adresse_siege']))  $parts[] = 'Siège social : ' . e($L['adresse_siege']);
        if (!empty($L['siren']))          $parts[] = 'SIREN : ' . e($L['siren']);
        if (!empty($L['siret']))          $parts[] = 'SIRET : ' . e($L['siret']);
        if (!empty($L['rcs_ville']))      $parts[] = e($L['rcs_ville']);
        if (!empty($L['tva_non_applicable'])) {
            $parts[] = 'TVA non applicable, article 293 B du CGI';
        } elseif (!empty($L['tva_intra'])) {
            $parts[] = 'N° TVA intracommunautaire : ' . e($L['tva_intra']);
        }
        if (!empty($L['email']))          $parts[] = 'E-mail : ' . e($L['email']);
        if (!empty($L['telephone']))      $parts[] = 'Téléphone : ' . e($L['telephone']);
        return implode('<br>', $parts);
    }
}
