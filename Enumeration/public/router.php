<?php
// Only public entry points and known assets are routable by the development server.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$endpoints = ['/post.php', '/admin.php', '/search.php', '/rules.php', '/image.php', '/login.php', '/register.php', '/account.php', '/avatar.php'];
if (in_array($path, $endpoints, true)) { require __DIR__ . $path; return; }
if (in_array($path, ['/assets/style.css', '/assets/favicon.svg'], true)) return false;
$decoys = require __DIR__ . '/../app/decoys.php';
if (isset($decoys[$path])) {
    $file = $decoys[$path];
    header('Content-Type: ' . (str_ends_with($file, '.html') ? 'text/html; charset=utf-8' : 'text/plain; charset=utf-8'));
    header('X-Content-Type-Options: nosniff');
    readfile(__DIR__ . '/' . $file);
    return;
}
require __DIR__ . '/index.php';
