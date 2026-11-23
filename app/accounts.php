<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

function current_user(): ?array {
    if (empty($_SESSION['account_id']) || (int)($_SESSION['account_until'] ?? 0) < time()) return null;
    $user = query('SELECT * FROM users WHERE id=?', [(int)$_SESSION['account_id']])->fetch();
    if (!$user || !hash_equals(hash('sha256', $user['password_hash']), (string)($_SESSION['account_version'] ?? ''))) return null;
    return $user;
}
function sign_in_user(array $user): void {
    session_regenerate_id(true);
    $_SESSION['account_id'] = (int)$user['id'];
    $_SESSION['account_until'] = time() + 86400;
    $_SESSION['account_version'] = hash('sha256', $user['password_hash']);
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
function register_user(string $username, string $password): array {
    if (!preg_match('/^[a-zA-Z0-9_]{3,24}$/D', $username)) throw new RuntimeException('Use 3–24 letters, numbers or underscores for your username.');
    if (in_array(strtolower($username), ['anonymous', 'admin', 'administrator', 'moderator'], true)) throw new RuntimeException('That username is reserved.');
    if (strlen($password) < 10 || strlen($password) > 72 || str_contains($password, "\0")) throw new RuntimeException('Use a password between 10 and 72 bytes.');
    $hash = password_hash($password, PASSWORD_DEFAULT);
    try {
        query('INSERT INTO users (username, password_hash) VALUES (?, ?)', [$username, $hash]);
    } catch (PDOException $error) {
        if (query('SELECT id FROM users WHERE username=?', [$username])->fetchColumn() !== false) throw new RuntimeException('That username is already in use.');
        throw $error;
    }
    return ['id' => (int)db()->lastInsertId(), 'username' => $username, 'password_hash' => $hash];
}
function thread_posts(int $id, ?int $limit = null): array {
    $sql = 'SELECT p.*, u.username, original.id quoted_id, original.body quoted_body, original.image quoted_image, original.image_name quoted_image_name, author.username quoted_username FROM posts p LEFT JOIN users u ON u.id=p.account_id LEFT JOIN posts original ON original.id=p.reply_to AND original.thread_id=p.thread_id LEFT JOIN users author ON author.id=original.account_id WHERE p.thread_id=? ORDER BY p.id';
    if ($limit !== null) $sql .= ' LIMIT ' . max(1, $limit);
    return query($sql, [$id])->fetchAll();
}
function reply_target(string $id, int $threadId): array {
    if (!ctype_digit($id)) throw new RuntimeException('Choose a post from this thread to reply to.');
    $post = query('SELECT p.*, u.username FROM posts p LEFT JOIN users u ON u.id=p.account_id WHERE p.id=? AND p.thread_id=?', [$id, $threadId])->fetch();
    if (!$post) throw new RuntimeException('That post is no longer available in this thread.');
    return $post;
}
// Reuse the attachment decoder and limits, then store only a 96px PNG thumbnail.
function save_avatar(): string {
    $image = save_image();
    if (!$image) throw new RuntimeException('Choose an avatar image.');
    $path = data_dir() . '/uploads/' . $image[0];
    try {
        $source = imagecreatefrompng($path);
        $size = min($image[2], $image[3]);
        $thumb = imagecreatetruecolor(96, 96);
        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);
        imagecopyresampled($thumb, $source, 0, 0, (int)(($image[2] - $size) / 2), (int)(($image[3] - $size) / 2), 96, 96, $size, $size);
        if (!imagepng($thumb, $path, 6)) throw new RuntimeException('The avatar could not be saved.');
        return $image[0];
    } catch (Throwable $error) {
        remove_images([$image[0]]);
        throw $error;
    }
}
