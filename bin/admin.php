<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
if (PHP_SAPI !== 'cli') exit(1);
fwrite(STDERR, "New admin password (at least 12 characters; input is visible): ");
$password = trim(fgets(STDIN) ?: '');
if (strlen($password) < 12) { fwrite(STDERR, "Use at least 12 characters.\n"); exit(1); }
db();
$path = data_dir() . '/admin.hash';
if (file_put_contents($path, password_hash($password, PASSWORD_DEFAULT), LOCK_EX) === false) { fwrite(STDERR, "Could not save admin password.\n"); exit(1); }
chmod($path, 0600);
fwrite(STDOUT, "Admin password saved. Sign in at /admin.php. Existing sessions are invalidated.\n");
