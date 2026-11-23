<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/accounts.php';
if (PHP_SAPI !== 'cli') exit(1);
$count = $argv[1] ?? '10';
if ($argc > 2 || !ctype_digit($count) || (int)$count < 1 || (int)$count > 100) {
    fwrite(STDERR, "Usage: php bin/seed-users.php [count: 1–100, default: 10]\n"); exit(1);
}
// Unique random credentials; only password hashes are stored. No posts are seeded.
$rows = [];
db()->beginTransaction();
try {
    for ($i = 0; $i < (int)$count; $i++) {
        do { $username = 'member_' . bin2hex(random_bytes(5)); }
        while (query('SELECT id FROM users WHERE username=?', [$username])->fetchColumn() !== false);
        $password = bin2hex(random_bytes(16));
        register_user($username, $password);
        $rows[] = [$username, $password];
    }
    db()->commit();
} catch (Throwable $error) {
    db()->rollBack();
    fwrite(STDERR, "User seeding failed; no users were added.\n"); exit(1);
}
fwrite(STDERR, "Created $count users. Credentials below are shown once; keep them private.\n");
fputcsv(STDOUT, ['username', 'password'], ',', '"', '');
foreach ($rows as $row) fputcsv(STDOUT, $row, ',', '"', '');
