<?php
/* =====================================================================
   GNL Solution — envoi des formulaires (contact, incident, contenu illicite)
   ---------------------------------------------------------------------
   1. Contrôles : jeton CSRF de session, champ piège anti-robot, limite
      de 6 envois / 10 min par session.
   2. Envoi JSON au webhook n8n (GNL_FORMS_WEBHOOK_URL) :
        { source:"site", action:"contact.create" | "incident.create" | "abuse.create",
          reference, submitted_at, ip_hash, data:{…}, files:[{name,type,size,content_base64}] }
      -> le workflow n8n se charge de l'accusé de réception par e-mail.
   3. Repli : si le webhook ne répond pas en 2xx, e-mail texte à
      GNL_FORMS_MAIL_TO via mail() (sans pièces jointes).
   ===================================================================== */
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE && !headers_sent()) session_start();

function gnl_form_token() {
    if (empty($_SESSION['gnl_form_csrf'])) $_SESSION['gnl_form_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['gnl_form_csrf'];
}
function gnl_form_token_ok() {
    $t = isset($_POST['csrf']) ? (string) $_POST['csrf'] : '';
    return $t !== '' && !empty($_SESSION['gnl_form_csrf']) && hash_equals($_SESSION['gnl_form_csrf'], $t);
}
function gnl_form_is_bot() {
    return !empty($_POST['site_web']); // champ piège invisible
}
function gnl_form_rate_ok() {
    $now = time();
    $hits = isset($_SESSION['gnl_form_hits']) && is_array($_SESSION['gnl_form_hits']) ? $_SESSION['gnl_form_hits'] : array();
    $hits = array_values(array_filter($hits, function ($t) use ($now) { return $t > $now - 600; }));
    if (count($hits) >= 6) { $_SESSION['gnl_form_hits'] = $hits; return false; }
    $hits[] = $now;
    $_SESSION['gnl_form_hits'] = $hits;
    return true;
}
function gnl_form_val($k, $max = 2000) {
    $v = isset($_POST[$k]) ? (string) $_POST[$k] : '';
    $v = trim(str_replace("\0", '', $v));
    if (function_exists('mb_substr')) $v = mb_substr($v, 0, $max, 'UTF-8'); else $v = substr($v, 0, $max);
    return $v;
}
function gnl_form_ref($prefix) {
    return $prefix . '-' . date('Y') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
}

/* Pièces jointes : images, PDF, texte/log ; 5 Mo par fichier, 3 fichiers max. */
function gnl_form_files($field) {
    $out = array();
    if (empty($_FILES[$field])) return $out;
    $f = $_FILES[$field];
    $names = is_array($f['name']) ? $f['name'] : array($f['name']);
    $tmps  = is_array($f['tmp_name']) ? $f['tmp_name'] : array($f['tmp_name']);
    $errs  = is_array($f['error']) ? $f['error'] : array($f['error']);
    $sizes = is_array($f['size']) ? $f['size'] : array($f['size']);
    $allowed = array('image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf', 'text/plain');
    foreach ($names as $i => $name) {
        if (count($out) >= 3) break;
        if ($errs[$i] !== UPLOAD_ERR_OK || !is_uploaded_file($tmps[$i])) continue;
        if ($sizes[$i] > 5 * 1024 * 1024) continue;
        $type = function_exists('mime_content_type') ? (string) @mime_content_type($tmps[$i]) : 'application/octet-stream';
        if (!in_array($type, $allowed, true)) continue;
        $out[] = array(
            'name' => preg_replace('/[^A-Za-z0-9._ -]/', '_', basename((string) $name)),
            'type' => $type,
            'size' => (int) $sizes[$i],
            'content_base64' => base64_encode((string) file_get_contents($tmps[$i])),
        );
    }
    return $out;
}

function gnl_form_dispatch($action, $reference, $data, $files = array()) {
    $payload = array(
        'source' => 'site', 'action' => $action, 'reference' => $reference,
        'submitted_at' => date('c'),
        'ip_hash' => hash('sha256', (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '') . date('Y-m-d')),
        'data' => $data, 'files' => $files,
    );
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ok = false;
    if (GNL_FORMS_WEBHOOK_URL !== '' && function_exists('curl_init')) {
        $ch = curl_init(GNL_FORMS_WEBHOOK_URL);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'Accept: application/json'),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 4,
        ));
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ok = ($code >= 200 && $code < 300);
    }
    if (!$ok && GNL_FORMS_MAIL_TO !== '') {
        $lines = array('Référence : ' . $reference, 'Type : ' . $action, 'Date : ' . date('d/m/Y H:i'), '');
        foreach ($data as $k => $v) $lines[] = $k . ' : ' . (is_array($v) ? implode(', ', $v) : $v);
        if ($files) $lines[] = "\n(" . count($files) . " pièce(s) jointe(s) non transmise(s) par e-mail : webhook indisponible.)";
        $subject = '=?UTF-8?B?' . base64_encode('[Site] ' . $reference . ' — ' . $action) . '?=';
        $headers = "Content-Type: text/plain; charset=UTF-8\r\nFrom: GNL Solution <no-reply@gnl-solution.com>";
        if (!empty($data['email']) && filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $headers .= "\r\nReply-To: " . $data['email'];
        $ok = @mail(GNL_FORMS_MAIL_TO, $subject, implode("\n", $lines), $headers);
    }
    if (!$ok) error_log('[GNL forms] envoi impossible pour ' . $reference);
    return $ok;
}
