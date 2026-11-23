<?php
declare(strict_types=1);

const ROOT = __DIR__ . '/..';
const BOARDS = [
    'gen' => ['General', 'A little bit of everything. Pull up a chair.'],
    'tech' => ['Technology', 'Computers, code, and things with blinking lights.'],
    'art' => ['Arts & crafts', 'Things you made. Things that inspire you.'],
    'music' => ['Music', 'Good records and very important mixtapes.'],
    'games' => ['Games', 'One more turn. One more level.'],
    'books' => ['Books', 'Books, authors and reading.'],
    'film' => ['Film & TV', 'Films, television and documentaries.'],
];
function data_dir(): string { return getenv('FORUM_DATA_DIR') ?: ROOT . '/data'; }
function lab_mode(): bool { return getenv('FORUM_LAB_MODE') === '1'; }
function db(): PDO {
    static $db;
    if ($db) return $db;
    if (!is_dir(data_dir()) && !mkdir(data_dir(), 0700, true)) throw new RuntimeException('Storage unavailable.');
    $db = new PDO('sqlite:' . data_dir() . '/forum.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000; PRAGMA journal_mode = WAL;');
    $db->exec(file_get_contents(ROOT . '/app/schema.sql'));
    // Upgrade existing databases without dropping posts or attachments.
    $columns = $db->query('PRAGMA table_info(posts)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('user_id', $columns, true)) {
        $db->exec('ALTER TABLE posts ADD COLUMN user_id INTEGER REFERENCES anonymous_users(id)');
    }
    if (!in_array('account_id', $columns, true)) {
        $db->exec('ALTER TABLE posts ADD COLUMN account_id INTEGER REFERENCES users(id)');
    }
    $db->exec("INSERT OR IGNORE INTO anonymous_users (token_hash) SELECT 'legacy:' || id FROM posts WHERE user_id IS NULL");
    $db->exec("UPDATE posts SET user_id=(SELECT id FROM anonymous_users WHERE token_hash='legacy:' || posts.id) WHERE user_id IS NULL");
    $insert = $db->prepare('INSERT OR IGNORE INTO boards (slug, title, description) VALUES (?, ?, ?)');
    foreach (BOARDS as $slug => [$title, $description]) $insert->execute([$slug, $title, $description]);
    return $db;
}
function query(string $sql, array $params = []): PDOStatement {
    $statement = db()->prepare($sql); $statement->execute($params); return $statement;
}
function padded_id(int $id): string { return str_pad((string)$id, 8, '0', STR_PAD_LEFT); }
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function input(string $key, array $source): string { return isset($source[$key]) && is_string($source[$key]) ? trim($source[$key]) : ''; }
function redirect(string $path): never { header('Location: ' . $path, true, 303); exit; }
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function csrf_field(): void { echo '<input type="hidden" name="csrf" value="' . e(csrf()) . '">'; }
function check_csrf(): void {
    if (!hash_equals(csrf(), input('csrf', $_POST))) throw new RuntimeException('Your form expired. Refresh the page and try again.');
}
function board(string $slug): array {
    if (!isset(BOARDS[$slug])) fail(404, 'Board not found', 'That board is not on the directory.');
    return ['slug' => $slug, 'title' => BOARDS[$slug][0], 'description' => BOARDS[$slug][1]];
}
function thread(int $id, string $slug): array {
    $row = query('SELECT * FROM threads WHERE id = ? AND board_slug = ?', [$id, $slug])->fetch();
    if (!$row) fail(404, 'Thread not found', 'This conversation may have been removed.');
    return $row;
}
function fail(int $status, string $title, string $message): never {
    http_response_code($status); require_once ROOT . '/app/view.php'; page_header($title);
    echo '<section class="panel"><h2>' . e($title) . '</h2><p>' . e($message) . '</p><p><a href="/">Back to the directory</a></p></section>';
    page_footer(); exit;
}
function admin_hash(): string {
    return getenv('FORUM_ADMIN_HASH') ?: (is_file(data_dir() . '/admin.hash') ? trim(file_get_contents(data_dir() . '/admin.hash')) : '');
}
function is_admin(): bool {
    return !empty($_SESSION['admin_until']) && $_SESSION['admin_until'] > time()
        && hash_equals(hash('sha256', admin_hash()), $_SESSION['admin_version'] ?? '');
}
if (PHP_SAPI !== 'cli') {
    ini_set('session.use_strict_mode', '1');
    session_name('smallhours');
    session_set_cookie_params(['httponly' => true, 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'samesite' => 'Lax']);
    session_start();
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; img-src 'self'; style-src 'self'; script-src 'none'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
    header('Cache-Control: no-store');
    set_exception_handler(function (Throwable $error): void {
        error_log((string)$error);
        fail(500, 'A small interruption', 'Something went wrong. Please try again in a moment.');
    });
}
