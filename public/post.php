<?php
require_once __DIR__ . '/../app/view.php';
require_once __DIR__ . '/../app/actions.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); fail(405, 'Use the posting form', 'Choose a board to start a conversation.'); }
$slug = input('board', $_POST); board($slug);
$threadId = input('thread', $_POST); $thread = null; $image = null; $replyTo = null;
if ($threadId !== '') {
    if (!ctype_digit($threadId)) fail(400, 'Invalid thread', 'Choose a conversation from the board.');
    $thread = thread((int)$threadId, $slug);
}
$account = current_user();
if ($thread && !$account) {
    http_response_code(403);
    page_header('Log in to reply', $slug);
    post_form($slug, $thread);
    page_footer();
    exit;
}
try {
    check_csrf();
    $replyId = input('reply_to', $_POST);
    if ($replyId !== '') {
        if (!$thread) throw new RuntimeException('Start a thread without a reply reference.');
        $replyTo = reply_target($replyId, (int)$thread['id']);
    }
    $subject = input('subject', $_POST); $body = input('body', $_POST);
    if (!$thread && ($subject === '' || mb_strlen($subject) > 100)) throw new RuntimeException('Add a subject of up to 100 characters.');
    if (mb_strlen($body) > 5000) throw new RuntimeException('Keep your message under 5,000 characters.');
    if ($thread && $thread['locked']) throw new RuntimeException('This thread is closed to replies.');
    rate_limit('post', 12, 60);
    $image = save_image();
    if ($body === '' && !$image) throw new RuntimeException('Add a message or an image before posting.');
    $userId = anonymous_user_id();
    db()->exec('BEGIN IMMEDIATE');
    // Recheck under the write lock so moderation and replies cannot race.
    if ($thread) {
        $fresh = query('SELECT locked FROM threads WHERE id=?', [$thread['id']])->fetch();
        if (!$fresh || $fresh['locked']) throw new RuntimeException('This conversation is no longer open to replies.');
        if ($replyTo) $replyTo = reply_target($replyId, (int)$thread['id']);
    } else {
        query('INSERT INTO threads (board_slug, subject) VALUES (?, ?)', [$slug, $subject]);
        $threadId = (int)db()->lastInsertId();
    }
    query('INSERT INTO posts (thread_id, user_id, account_id, reply_to, body, image, image_name, image_width, image_height) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [(int)$threadId, $userId, $account['id'] ?? null, $replyTo['id'] ?? null, $body, ...($image ?? [null, null, null, null])]);
    $postId = (int)db()->lastInsertId();
    query("UPDATE threads SET bumped_at=strftime('%Y-%m-%d %H:%M:%S', 'now') WHERE id=?", [(int)$threadId]);
    db()->exec('COMMIT');
    redirect('/' . $slug . '/' . $threadId . '#p' . $postId);
} catch (Throwable $error) {
    // PDO::inTransaction does not detect transactions begun with SQLite BEGIN IMMEDIATE.
    try { db()->exec('ROLLBACK'); } catch (PDOException) {}
    if ($image) remove_images([$image[0]]);
    $expected = $error instanceof RuntimeException && !$error instanceof PDOException;
    if (!$expected) error_log((string)$error);
    http_response_code($expected ? 422 : 500);
    page_header('Post not saved', $slug);
    post_form($slug, $thread, $expected ? $error->getMessage() : 'Your post could not be saved. Please try again.', ['subject' => $subject ?? '', 'body' => $body ?? ''], $replyTo);
    page_footer();
}
