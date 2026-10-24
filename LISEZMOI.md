# GNL Solution — site web (nouvelle charte « bas carbone »)

Intégration PHP de la maquette validée sur le canvas Design, branchée sur l'existant :
catalogue n8n, panier de session, paiement Mollie, comptes Keycloak, supervision Zabbix, domaines OVH.

## Pages

Toutes les URL sont préfixées par la langue : `https://eu.gnl-solution.com/de/contact`, `https://ch.gnl-solution.com/it/solutions`…
(voir « Régions, domaines et langues » plus bas). Le tableau donne le chemin sans ce préfixe.

| URL | Fichier | Rôle |
|---|---|---|
| `/` | `index.php` | Accueil (partenaires et prix « dès » chargés depuis n8n) |
| `/solutions` | `pages/solutions.php` | Catalogue, filtres, fenêtre de commande avec options et domaine, ajout au panier sans quitter la page |
| `/product-configuration?slug=…` | `pages/product-configuration.php` | Page détaillée d'une offre (inchangée côté logique) |
| `/cart` | `pages/cart.php` | Panier (session PHP, logique inchangée) |
| `/commande` | `pages/commande.php` | Coordonnées, périodicité, CGV, paiement Mollie (logique inchangée) |
| `/connexion`, `/inscription`, `/organisation` | `pages/*.php` | Comptes Keycloak (logique inchangée, nouveau gabarit) |
| `/stats` | `pages/stats.php` | État des services (Zabbix, logique inchangée) |
| `/impact`, `/qui-sommes-nous` | `pages/*.php` | Pages éditoriales |
| `/contact` | `pages/contact.php` | Formulaire → n8n `contact.create` |
| `/incident` et `/incident?type=abuse` | `pages/incident.php` | Incident technique → n8n `incident.create` ; contenu illicite (LCEN/DSA) → n8n `abuse.create` |
| `/cgu`, `/cgv`, `/politique-de-confidentialite` | `pages/*.php` | Pages légales |

## Installation

1. Déposer le contenu du dossier à la racine du site (PHP 7.4+ ; 8.x recommandé ; extensions cURL et **intl** pour les dates traduites).
   Le même dossier sert tous les domaines (`gnl-solution.com`, `eu.`, `ch.`, `ca.`, `uk.gnl-solution.com`, `in.gnl-solution.fr`).
2. **Apache** : le fichier `.htaccess` envoie tout ce qui n'est pas un fichier réel vers `router.php` (`mod_rewrite` requis)
   et bloque `include/`, `lang/`, `geoip/` et `pages/`.
   **Debian l'ignore par défaut** (`AllowOverride None` sur `/var/www`) : les adresses `/fr/`, `/it/…` répondent alors
   « Not Found ». À faire une fois sur le serveur :
   ```bash
   sudo a2enmod rewrite headers expires env
   # dans le vhost du site (/etc/apache2/sites-available/…conf), à l'intérieur de <VirtualHost> :
   #   <Directory /var/www/gnl-solution>        # ← dossier du site
   #       AllowOverride All
   #       Require all granted
   #   </Directory>
   sudo apachectl configtest && sudo systemctl reload apache2
   ```
   Un vhost prêt à l'emploi est fourni : `deploy/apache-gnl-solution.conf` (tous les sous-domaines sur le même dossier,
   `AllowOverride All`, `FallbackResource /router.php`, dossiers internes bloqués même sans `.htaccess`).
   **Vérification** : `curl -I https://ch.gnl-solution.com/it/` doit répondre `200` (ou `302` vers `/it/`) ; une page
   « Not Found » blanche d'Apache signifie que `router.php` n'est pas atteint (vhost / `AllowOverride`), alors qu'une
   page 404 aux couleurs GNL signifie que le routage fonctionne. `sudo apache2ctl -S` liste les vhosts et leur dossier.
   **Mode « un seul domaine »** (dépannage uniquement) : `SetEnv GNL_SINGLE_HOST 1` garde tout sur `gnl-solution.com/<langue>/…`,
   la région étant choisie par géolocalisation, cookie ou `?gnl_region=ch`. Par défaut chaque région a son sous-domaine.
   **Nginx** : équivalent à ajouter dans le bloc `server` (un seul bloc avec tous les `server_name`) :
   ```nginx
   server_name gnl-solution.com eu.gnl-solution.com ch.gnl-solution.com ca.gnl-solution.com uk.gnl-solution.com in.gnl-solution.fr;
   location ~ ^/(include|lang|geoip|pages)/ { deny all; }
   location ~ ^/(dev-router|index)\.php$ { deny all; }
   location / { try_files $uri /router.php$is_args$args; }
   location = /router.php { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root/router.php; fastcgi_pass php; }
   ```
3. Les réglages Keycloak, Mollie, OVH et Zabbix restent ceux de vos fichiers existants (variables d'environnement lues par `keycloak_rest.php`, `commande.php`, `product-configuration.php`, `stats.php`).
4. Télécharger la base de géolocalisation (voir `geoip/LISEZMOI.md`).

### Nouveaux réglages (`include/config.php`, surchargeables par variable d'environnement)

| Variable | Défaut | Rôle |
|---|---|---|
| `GNL_ESPACE_CLIENT_URL` | `https://espace-client.gnl-solution.fr/` | Bouton « Espace Client » |
| `GNL_FORMS_WEBHOOK_URL` | `https://api.gnl-solution.fr/webhook/boutique` | Webhook n8n des formulaires |
| `GNL_FORMS_MAIL_TO` | `contact@gnl-solution.com` | Repli e-mail si le webhook ne répond pas |
| `GNL_MATOMO_URL` | *(vide = pas de traceur)* | URL de votre Matomo auto-hébergé |
| `GNL_MATOMO_SITE_ID` | `1` | Identifiant du site dans Matomo |
| `GNL_STATS_TOOL` | `Matomo` | Nom affiché dans la fenêtre RGPD |
| `GNL_DELAI_REPONSE` | `48 h ouvrées` | Délai affiché sur la page Contact |


## Sobriété (GreenIT)

- **Pas de CSS ni de JavaScript en ligne** : styles propres à une page dans `assets/gnl/page/<page>.css`, scripts dans
  `assets/gnl/page/<page>.js`. Les scripts sont servis par le routeur à l'adresse `/<langue>/js/<page>.js` : leurs
  textes sont traduits comme le reste du site. Le dictionnaire JS est `/<langue>/js/i18n.js`.
- **Cache navigateur** : CSS et JS sont appelés avec `?v=<date de modification>` (fonctions `gnl_asset()`, `gnl_css()`,
  `gnl_js()` dans `include/i18n.php`) et mis en cache un an ; images 30 jours. En-têtes posés par le `.htaccess` et par
  `deploy/apache-gnl-solution.conf` (modules `headers` et `expires`).
- **Impression** : `assets/gnl/print.css` (chargée avec `media="print"`) masque en-tête, pied de page, menus, boutons
  et photos, et imprime en noir sur blanc.

## Régions, domaines et langues

### Aiguillage

| Visiteur | Domaine | Langues (préfixe d'URL) |
|---|---|---|
| Union européenne (dont DOM, Åland) | `eu.gnl-solution.com` | les 24 langues de l'UE ; langue par défaut selon le pays (France → `/fr`, Lituanie → `/lt`, Allemagne/Autriche → `/de`…) |
| Belgique | `eu.` | Flandre → `/nl`, Wallonie → `/fr`, Bruxelles → `/fr` ou `/nl` selon le navigateur |
| Suisse | `ch.gnl-solution.com` | `/fr` (GE, VD, NE, JU), `/it` (TI), `/de` (autres cantons) ; cantons bilingues FR, VS, BE, GR selon la langue du navigateur |
| Canada | `ca.gnl-solution.com` | Québec → `/fr`, autres provinces → `/en`, Nouveau-Brunswick selon le navigateur |
| Royaume-Uni (+ Jersey, Guernesey, île de Man) | `uk.gnl-solution.com` | `/en` |
| Reste du monde | `in.gnl-solution.fr` | page « Ce site n'est pas disponible dans votre pays » (HTTP 451), dans la langue du navigateur |

- **Première visite** (`gnl-solution.com` ou une URL sans langue) : redirection automatique d'après le pays et la région de l'adresse IP.
- **Choix mémorisé** : le bouton « EU · FR » de l'en-tête (et le lien du pied de page) ouvre la fenêtre « Pays et langue ».
  Le choix est gardé 13 mois dans le cookie `gnl_loc` (domaine `.gnl-solution.com`) et prime ensuite sur la géolocalisation.
- Les robots d'indexation ne sont jamais redirigés : la racine leur affiche la liste des pays, et chaque page déclare ses
  variantes (`<link rel="alternate" hreflang>`), avec `x-default` vers `https://gnl-solution.com/`.
- Les formulaires et appels AJAX ne sont pas redirigés ; les redirections internes (`Location: /cart`) reçoivent automatiquement le préfixe de langue.

### DNS et serveur

- Créer les enregistrements DNS `eu`, `ch`, `ca`, `uk` (sur `gnl-solution.com`) et `in` (sur `gnl-solution.fr`) vers le serveur, plus la racine `gnl-solution.com`.
- Un certificat HTTPS couvrant tous ces noms (Let's Encrypt : `-d gnl-solution.com -d eu.gnl-solution.com -d ch.… -d ca.… -d uk.… -d in.gnl-solution.fr`).
- Derrière un proxy ou un CDN, mettre `GNL_TRUST_PROXY=1` pour lire l'IP réelle dans `X-Forwarded-For` / `CF-Connecting-IP`.
- **Keycloak** : déclarer dans le client les URI de redirection de chaque hôte (`https://eu.gnl-solution.com/*`, `https://ch.…/*`, etc.),
  et définir `KEYCLOAK_REDIRECT_URI` par vhost (sinon le retour de connexion se fait sur `beta.gnl-solution.fr`).
- **Mollie** : l'URL de retour est construite sur l'hôte et la langue courants ; vérifier que les domaines sont autorisés dans le profil Mollie.

### Réglages (variables d'environnement)

| Variable | Défaut | Rôle |
|---|---|---|
| `GNL_SINGLE_HOST` | `0` | `0` = un sous-domaine par région (eu./ch./ca./uk.) ; `1` = tout sur un seul domaine (dépannage) |
| `GNL_ROOT_HOST` | `gnl-solution.com` | Domaine racine qui aiguille les visiteurs |
| `GNL_HOST_EU` / `_CH` / `_CA` / `_UK` | `eu.` / `ch.` / `ca.` / `uk.gnl-solution.com` | Hôte de chaque région |
| `GNL_HOST_IN` | `in.gnl-solution.fr` | Hôte de la page « non disponible » (attention : en `.fr`, comme demandé ; le cookie de choix n'y est pas partagé) |
| `GNL_COOKIE_DOMAIN` | `.gnl-solution.com` | Domaine du cookie `gnl_loc` |
| `GNL_GEOIP_DB` | `geoip/GeoLite2-City.mmdb` | Base MaxMind (City recommandée : la version Country ne donne ni cantons ni provinces) |
| `GNL_GEO_FALLBACK` | `eu` | Région utilisée si l'IP est inconnue (réseau local, base absente) |
| `GNL_TRUST_PROXY` | `0` | `1` = lire l'IP dans les en-têtes du proxy |
| `GNL_DEV_REGION` | `eu` | Région utilisée sur un hôte inconnu (localhost, préproduction) |
| `GNL_GEO_DEBUG` | `0` | `1` = autorise `?geo=CH-VD`, `?geo=CA-QC`, `?geo=US` pour tester l'aiguillage. **Ne pas activer en production.** |
| `GNL_I18N_MISSING_LOG` | *(vide)* | Chemin d'un fichier où sont notés les textes affichés sans traduction |

Si l'extension PHP `maxminddb` ou le module Apache/Nginx GeoIP2 est installé, il est utilisé (variables `GEOIP2_COUNTRY_CODE`,
`GEOIP2_SUBDIVISION_CODE` / `MM_*`) ; sinon le lecteur PHP intégré (`include/geoip.php`) lit directement le fichier `.mmdb`.

### Traductions

- Le français reste la langue source, écrite directement dans les pages. Les autres langues sont dans `lang/<code>.php`
  (tableau « texte français → traduction »), appliquées à l'affichage ; les textes JavaScript passent par `window.gnlT()`.
- Toutes les pages, y compris CGU, CGV et politique de confidentialité, sont traduites dans les 23 autres langues de l'UE.
  **Les traductions juridiques doivent être relues par un juriste** ; les textes entre crochets restent en français tant qu'ils ne sont pas remplis.
- Si vous modifiez un texte français, sa traduction ne correspond plus : la page affiche alors le français.
  Activez `GNL_I18N_MISSING_LOG`, parcourez le site dans une langue, puis ajoutez les lignes manquantes dans `lang/<code>.php`.
- Un bloc HTML marqué `translate="no"` n'est jamais traduit (noms de produits, adresses…).
- **Catalogue n8n** : les produits et options s'affichent en français. Pour les traduire, ajoutez dans la datatable des colonnes
  `name_de`, `stitre_de`, `description_de`, etc. (une par langue) ; elles sont utilisées automatiquement si elles sont remplies.
- Les prix restent en euros et les conditions de vente relèvent du droit français pour toutes les régions (Suisse, Canada et Royaume-Uni compris).
  Adaptez CGV et fiscalité avant d'ouvrir réellement ces marchés.

## Workflow n8n à prévoir

Les formulaires envoient un `POST` JSON sur le webhook, avec le champ `action` :

```json
{ "source": "site", "action": "contact.create", "reference": "CT-2026-1A2B3C", "lang": "de", "region": "eu",
  "submitted_at": "2026-10-23T10:00:00+02:00", "ip_hash": "…",
  "data": { "nom": "…", "email": "…", "message": "…" },
  "files": [ { "name": "capture.png", "type": "image/png", "size": 12345, "content_base64": "…" } ] }
```

- `contact.create` : nom, structure, email, tel, profil, sujet, message.
- `incident.create` : service, constat, cible, depuis, description, email, num_client, priorite.
- `abuse.create` : categorie, urls, motif, nom, email (facultatifs pour les contenus pédocriminels), qualite, bonne_foi, priorite.

Le workflow doit répondre en 2xx et envoyer l'accusé de réception au demandeur (la page affiche la référence).
Les champs `lang` et `region` permettent de répondre dans la langue du visiteur.
Sinon le site bascule sur un e-mail texte à `GNL_FORMS_MAIL_TO` (sans pièces jointes).

## Statistiques sans bandeau cookies

Le lien « RGPD et statistiques » du pied de page ouvre une fenêtre d'information avec la case « Compter ma visite ».
Décocher crée le cookie `gnl_stats_optout=1` (13 mois) : le traceur n'est alors plus chargé.
Pour rester exempté de consentement (CNIL), configurez Matomo ainsi :

- anonymisation de l'IP (au moins 2 octets) ;
- pas de User-ID ni de recoupement avec d'autres données ;
- aucun partage avec des tiers ;
- conservation des données de 25 mois maximum.

## Test en local

```bash
GNL_GEO_DEBUG=1 php -S localhost:8000 dev-router.php
```

Puis ouvrir `http://localhost:8000/fr/`, `http://localhost:8000/de/`… Sur `localhost`, le site reste dans la région `GNL_DEV_REGION`, sans redirection.
Pour tester l'aiguillage, simuler l'hôte racine et un pays :

```bash
curl -sI -A Mozilla -H "Host: gnl-solution.com" "http://localhost:8000/?geo=CH-TI"   # → https://ch.gnl-solution.com/it/
curl -sI -A Mozilla -H "Host: gnl-solution.com" "http://localhost:8000/?geo=US"      # → https://in.gnl-solution.fr/en/
```

Sans accès au webhook n8n, le catalogue se rabat sur `product.csv` / `pages/product_option.csv` / `partner.csv` s'ils existent (mêmes colonnes que la datatable n8n).
`dev-router.php` ne sert qu'au test local et n'est pas utilisé en production.

## Fichiers retirés par rapport à l'ancienne archive

`wp-content/`, `wp-includes/`, `wp-json/`, `xmlrpc0db0.php`, `assets/css/`, `assets/js/` et `include/wiget-acces.php` : restes de l'export WordPress, plus utilisés.
Les images de `assets/img/` (dont `partener_brand/`) sont conservées.

## Contenus à compléter

Les mentions entre crochets (`[DURÉE]`, `[NOM DU MÉDIATEUR]`, `[ANNÉE]`, etc.) sont visibles sur le site. Remplacez-les avant la mise en ligne :

```bash
grep -rn "\[[A-ZÀ-Ü][^]]*\]" index.php pages/*.php
```
