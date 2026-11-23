<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
if (PHP_SAPI !== 'cli') exit(1);
if ($argc !== 2 || !is_file($argv[1])) {
    fwrite(STDERR, "Usage: php bin/migrate-sqlite.php /absolute/path/forum.sqlite\n"); exit(1);
}
// Stop writes to the old application before running. Never modify the source.
$source = new PDO('sqlite:' . realpath($argv[1]), null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$source->exec('PRAGMA query_only = ON');
$source->beginTransaction();
$tables = ['boards', 'anonymous_users', 'users', 'threads', 'posts'];
$available = $source->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
foreach (['boards', 'threads', 'posts'] as $required) {
    if (!in_array($required, $available, true)) throw new RuntimeException('Not a forum database: missing ' . $required);
}
initialize_database();
foreach (array_merge(array_slice($tables, 1), ['rate_limits']) as $table) {
    if ((int)query("SELECT COUNT(*) FROM `$table`")->fetchColumn()) {
        fwrite(STDERR, "Migration requires an empty MySQL forum. No rows imported.\n"); exit(1);
    }
}
db()->beginTransaction();
try {
    $replies = [];
    foreach ($tables as $table) {
        if (!in_array($table, $available, true)) continue;
        $allowed = query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($source->query("SELECT * FROM `$table` ORDER BY " . ($table === 'boards' ? 'slug' : 'id')) as $row) {
            $row = array_intersect_key($row, array_flip($allowed));
            if ($table === 'posts' && !empty($row['reply_to'])) {
                $replies[$row['id']] = $row['reply_to'];
                $row['reply_to'] = null; // Restore after all referenced posts exist.
            }
            $columns = '`' . implode('`,`', array_keys($row)) . '`';
            $sql = "INSERT INTO `$table` ($columns) VALUES (" . implode(',', array_fill(0, count($row), '?')) . ')';
            if ($table === 'boards') $sql .= ' ON DUPLICATE KEY UPDATE title=boards.title';
            query($sql, array_values($row));
        }
    }
    // Very old SQLite versions did not record anonymous browser IDs.
    foreach (query('SELECT id FROM posts WHERE user_id IS NULL')->fetchAll(PDO::FETCH_COLUMN) as $id) {
        query('INSERT INTO anonymous_users (token_hash) VALUES (?) ON DUPLICATE KEY UPDATE token_hash=anonymous_users.token_hash', ['legacy:' . $id]);
        query('UPDATE posts SET user_id=(SELECT id FROM anonymous_users WHERE token_hash=?) WHERE id=?', ['legacy:' . $id, $id]);
    }
    foreach ($replies as $id => $reply) query('UPDATE posts SET reply_to=? WHERE id=?', [$reply, $id]);
    db()->commit();
    $source->rollBack();
    echo "Imported forum into MySQL. SQLite source unchanged. Keep the same data directory for uploads and admin.hash.\n";
} catch (Throwable $error) {
    if (db()->inTransaction()) db()->rollBack();
    fwrite(STDERR, "Import failed; imported rows rolled back: " . $error->getMessage() . "\n"); exit(1);
}
