# Base de géolocalisation (GeoLite2)

Ce dossier doit contenir **`GeoLite2-City.mmdb`**. Il n'est pas fourni : la licence MaxMind interdit de le redistribuer.
Le dossier est bloqué par `.htaccess` (jamais servi au public).

## Obtenir la base

1. Créer un compte gratuit sur https://www.maxmind.com/en/geolite2/signup puis générer une clé de licence.
2. Télécharger « GeoLite2 City » (format `.mmdb`) et le copier ici sous le nom `GeoLite2-City.mmdb`.

La version **City** est nécessaire : elle donne le canton suisse, la province canadienne et la région belge.
Avec la version Country, ces visiteurs reçoivent la langue de leur navigateur.

## Mise à jour automatique (obligatoire : MaxMind publie chaque semaine, la licence impose de supprimer les bases de plus de 30 jours)

Avec l'outil officiel `geoipupdate` (paquet Debian/Ubuntu du même nom), fichier `/etc/GeoIP.conf` :

```
AccountID 123456
LicenseKey VOTRE_CLE
EditionIDs GeoLite2-City
DatabaseDirectory /var/www/gnl-solution/geoip
```

Puis une tâche planifiée hebdomadaire :

```
17 4 * * 3  /usr/bin/geoipupdate
```

## Sans base

Si le fichier est absent, tous les visiteurs sont dirigés vers la région `GNL_GEO_FALLBACK` (par défaut `eu`)
avec la langue de leur navigateur ; personne n'est envoyé sur la page « non disponible ».
Un autre emplacement peut être indiqué avec la variable `GNL_GEOIP_DB`.
