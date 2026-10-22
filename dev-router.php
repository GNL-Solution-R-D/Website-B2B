<?php
/* Serveur de test local (n'est pas utilisé en production) :
     php -S localhost:8000 dev-router.php
   Reproduit le routage du fichier .htaccess. */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file(__DIR__ . $path) && strpos($path, '/include/') !== 0) return false;
if ($path === '/' || $path === '/index.php') { require __DIR__ . '/index.php'; return true; }
if ($path === '/entreprise-lookup') { chdir(__DIR__ . '/pages'); require __DIR__ . '/pages/entreprise_lookup.php'; return true; }
if (preg_match('#^/([a-z0-9_-]+)(?:\.php)?/?$#', $path, $m) && is_file(__DIR__ . '/pages/' . $m[1] . '.php')
    && !in_array($m[1], array('config-legal', 'keycloak_rest'), true)) {
    chdir(__DIR__ . '/pages');
    require __DIR__ . '/pages/' . $m[1] . '.php';
    return true;
}
http_response_code(404);
echo 'Page introuvable';
