<?php
// Only public entry points and known assets are routable by the development server.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$endpoints = ['/post.php', '/admin.php', '/search.php', '/rules.php', '/image.php'];
if (in_array($path, $endpoints, true)) { require __DIR__ . $path; return; }
if (in_array($path, ['/assets/style.css', '/assets/favicon.svg'], true)) return false;
require __DIR__ . '/index.php';
