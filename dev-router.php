<?php
/* Serveur de test local (n'est pas utilisé en production) :
     php -S localhost:8000 dev-router.php
   Les fichiers réels (assets) sont servis tels quels, tout le reste passe
   par router.php, comme avec le .htaccess. */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && preg_match('#^/assets/#', $path) && is_file(__DIR__ . $path)) return false;
require __DIR__ . '/router.php';
