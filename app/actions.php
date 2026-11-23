<?php
require_once __DIR__ . '/bootstrap.php';
function anonymous_user_id(): int {
    $token = input('forum_visitor', $_COOKIE);
    if (preg_match('/^[a-f0-9]{64}$/D', $token)) {
        $id = query('SELECT id FROM anonymous_users WHERE token_hash=?', [hash('sha256', $token)])->fetchColumn();
        if ($id !== false) return (int)$id;
    }
    $token = bin2hex(random_bytes(32));
    query('INSERT INTO anonymous_users (token_hash) VALUES (?)', [hash('sha256', $token)]);
    $id = (int)db()->lastInsertId();
    setcookie('forum_visitor', $token, ['expires' => time() + 31536000, 'path' => '/', 'httponly' => true, 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'samesite' => 'Lax']);
    return $id;
}
function rate_limit(string $scope, int $max, int $window): void {
    $key = hash('sha256', $scope . ':' . ($_SERVER['REMOTE_ADDR'] ?? 'local'));
    query('DELETE FROM rate_limits WHERE expires < ?', [time()]);
    query('INSERT INTO rate_limits (key, hits, expires) VALUES (?, 1, ?) ON CONFLICT(key) DO UPDATE SET hits=hits+1', [$key, time() + $window]);
    if ((int)query('SELECT hits FROM rate_limits WHERE key=?', [$key])->fetchColumn() > $max) {
        throw new RuntimeException('A little too fast. Please wait a few minutes before trying again.');
    }
}
function save_image(): ?array {
    if (!isset($_FILES['image']) || $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) return null;
    $file = $_FILES['image'];
    if (!is_int($file['error']) || $file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('The image could not be uploaded. Please choose a file under 4 MB.');
    if ($file['size'] > 4 * 1024 * 1024) throw new RuntimeException('Images must be no larger than 4 MB.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) throw new RuntimeException('Please use a JPG, PNG, GIF or WebP image.');
    $dimensions = @getimagesize($file['tmp_name']);
    if (!$dimensions || $dimensions[0] * $dimensions[1] > 16000000 || max($dimensions[0], $dimensions[1]) > 8000) throw new RuntimeException('Please choose an image under 16 megapixels and 8,000 pixels per side.');
    $image = @imagecreatefromstring(file_get_contents($file['tmp_name']));
    if (!$image) throw new RuntimeException('That image could not be read. Please try another.');
    if (!is_dir(data_dir() . '/uploads') && !mkdir(data_dir() . '/uploads', 0700, true)) throw new RuntimeException('Image storage is unavailable.');
    $name = bin2hex(random_bytes(16)) . '.png';
    imagesavealpha($image, true);
    if (!imagepng($image, data_dir() . '/uploads/' . $name, 6)) throw new RuntimeException('The image could not be saved.');
    return [$name, mb_substr(basename($file['name']), 0, 120), $dimensions[0], $dimensions[1]];
}
function remove_images(array $names): void {
    foreach ($names as $name) if ($name && preg_match('/^[a-f0-9]{32}\.png$/D', $name)) {
        $path = data_dir() . '/uploads/' . $name;
        if (is_file($path)) unlink($path);
    }
}
