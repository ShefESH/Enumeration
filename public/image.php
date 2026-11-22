<?php
require_once __DIR__ . '/../app/bootstrap.php';
$name = input('file', $_GET);
if (!preg_match('/^[a-f0-9]{32}\.png$/D', $name)) fail(404, 'Image not found', 'That image is unavailable.');
$path = data_dir() . '/uploads/' . $name;
if (!is_file($path)) fail(404, 'Image not found', 'That image is unavailable.');
session_write_close();
header('Content-Type: image/png');
header('Content-Disposition: inline; filename="' . $name . '"');
header('Cache-Control: public, max-age=3600');
header('Content-Length: ' . filesize($path));
readfile($path);
