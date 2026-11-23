<?php
require_once __DIR__ . '/../app/bootstrap.php';
$user = query('SELECT id, avatar FROM users WHERE id=?', [(int)input('id', $_GET)])->fetch();
if (!$user) fail(404, 'Avatar not found', 'No such user.');
session_write_close();
header('Cache-Control: no-store');
if ($user['avatar'] && preg_match('/^[a-f0-9]{32}\.png$/D', $user['avatar']) && is_file($path = data_dir() . '/uploads/' . $user['avatar'])) {
    header('Content-Type: image/png');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}
// Deterministic identicon: no external image service or user-controlled SVG.
$hash = hash('sha256', 'enumeration-avatar-' . $user['id']);
header('Content-Type: image/svg+xml');
header('Content-Security-Policy: default-src \'none\'; sandbox');
echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 70 70"><rect width="70" height="70" fill="#eaf0f5"/>';
$color = '#' . substr($hash, 0, 6);
for ($y = 0; $y < 5; $y++) for ($x = 0; $x < 3; $x++) {
    if (hexdec($hash[6 + $y * 3 + $x]) % 2) foreach (array_unique([$x, 4 - $x]) as $column) {
        echo '<rect x="' . (10 + $column * 10) . '" y="' . (10 + $y * 10) . '" width="10" height="10" fill="' . $color . '"/>';
    }
}
echo '</svg>';
