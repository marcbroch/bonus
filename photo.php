<?php
// Liefert Fotos nur an angemeldete Nutzer aus (Kinder sehen nur ihre eigenen)
declare(strict_types=1);
require __DIR__ . '/lib.php';

$u = current_user();
$f = (string)($_GET['f'] ?? '');
if (!$u || !preg_match('#^\d{4}/\d{2}/[a-f0-9]{24}\.(jpg|png|webp|gif)$#', $f)) { http_response_code(404); exit; }

if ($u['role'] !== 'parent') {
    $st = db()->prepare('SELECT 1 FROM submissions WHERE photo=? AND user_id=?');
    $st->execute([$f, $u['id']]);
    if (!$st->fetchColumn()) { http_response_code(404); exit; }
}
$path = UPLOAD_DIR . '/' . $f;
if (!is_file($path)) { http_response_code(404); exit; }

$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
header('Content-Type: ' . $types[pathinfo($path, PATHINFO_EXTENSION)]);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=2592000');
header('X-Content-Type-Options: nosniff');
readfile($path);
