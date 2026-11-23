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
    'intro' => ['Introductions', 'Introduce yourself and meet other members.'],
    'life' => ['Everyday life', 'Work, study and day-to-day life.'],
    'news' => ['Current affairs', 'News and events around the world.'],
    'food' => ['Food & cooking', 'Recipes, cooking and food discussion.'],
    'sport' => ['Sport & fitness', 'Matches, training and staying active.'],
    'travel' => ['Travel', 'Places, trips and local knowledge.'],
    'retro' => ['Retro computing', 'Old computers, consoles and preservation.'],
    'diy' => ['DIY & electronics', 'Builds, repairs, circuits and projects.'],
    'space' => ['Astronomy & space', 'Stargazing, telescopes and space exploration.'],
    'linux' => ['Linux & open source', 'Distributions, self-hosting and open-source software.'],
    'garden' => ['Gardening', 'Plants, growing food and outdoor spaces.'],
];
const BOARD_CATEGORIES = [
    'General' => ['gen', 'intro', 'life', 'news'],
    'Interests' => ['tech', 'art', 'music', 'games', 'books', 'film', 'food', 'sport', 'travel'],
    'Special interests' => ['retro', 'diy', 'space', 'linux', 'garden'],
];
function data_dir(): string { return getenv('FORUM_DATA_DIR') ?: ROOT . '/data'; }
function lab_mode(): bool { return getenv('FORUM_LAB_MODE') === '1'; }
function config(string $key, string $default = ''): string {
    $value = getenv($key);
    if ($value !== false) return $value;
    static $settings;
    if ($settings === null) {
        $path = getenv('FORUM_CONFIG') ?: ROOT . '/config.php';
        $settings = is_file($path) ? require $path : [];
        if (!is_array($settings)) throw new RuntimeException('Forum config must return an array.');
    }
    return (string)($settings[$key] ?? $default);
}
function db(): PDO {
    static $db;
    if ($db) return $db;
    if (!is_dir(data_dir()) && !mkdir(data_dir(), 0700, true)) throw new RuntimeException('Storage unavailable.');
    $dsn = config('FORUM_DB_DSN', 'mysql:host=127.0.0.1;port=3306;dbname=enumeration;charset=utf8mb4');
    if (!str_starts_with($dsn, 'mysql:')) throw new RuntimeException('FORUM_DB_DSN must use MySQL.');
    $db = new PDO($dsn, config('FORUM_DB_USER', 'forum'), config('FORUM_DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
    ]);
    $db->exec("SET time_zone = '+00:00'");
    return $db;
}
function initialize_database(): void {
    // DDL is a CLI installation step; web requests only need CRUD permissions.
    foreach (explode(';', file_get_contents(ROOT . '/app/schema.sql')) as $sql) {
        if (trim($sql) !== '') db()->exec($sql);
    }
    $insert = db()->prepare('INSERT INTO boards (slug, title, description) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE title=boards.title');
    foreach (BOARDS as $slug => [$title, $description]) $insert->execute([$slug, $title, $description]);
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
