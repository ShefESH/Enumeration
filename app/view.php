<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/accounts.php';
function page_header(string $title, string $active = ''): void {
    ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?> - Enumeration</title><link rel="stylesheet" href="/assets/style.css"><link rel="icon" href="/assets/favicon.svg" type="image/svg+xml"></head>
<body id="top"><a class="skip-link" href="#content">Skip to content</a><div class="site">
<div class="userbar"><div class="account-nav"><?php if ($user = current_user()): ?><span>Logged in as <a href="/account.php"><?= e($user['username']) ?></a></span><form method="post" action="/account.php"><?php csrf_field(); ?><button class="text-button" name="action" value="logout">Log out</button></form><?php else: ?><span>Welcome, Guest.</span><a class="auth-link" href="/login.php">Log in</a><a class="auth-link" href="/register.php">Register</a><?php endif; ?></div><nav class="utility-nav" aria-label="Main navigation"><a href="/search.php<?= $active ? '?board=' . e($active) : '' ?>">Search</a><a href="/rules.php">Board rules</a></nav></div>
<header class="site-header"><a class="brand" href="/" aria-label="Enumeration board index"><span class="wordmark">Enumeration</span><span class="site-name">Community Forums</span></a></header>
<div class="navigation-strip"><a class="index-link" href="/">Board index</a><nav aria-label="Boards" class="boardnav"><?php foreach (BOARDS as $slug => $info): ?><a <?= $active === $slug ? 'aria-current="page"' : '' ?> href="/<?= e($slug) ?>/">/<?= e($slug) ?>/</a><?php endforeach; ?></nav></div>

<main id="content"><?php
}
function page_footer(): void {
    try { $databaseVersion = (string)query('SELECT sqlite_version()')->fetchColumn(); }
    catch (Throwable) { $databaseVersion = 'unavailable'; }
    ?></main><footer class="site-footer"><p><span class="server-info"><span>Server time: <time datetime="<?= e(date('c')) ?>"><?= e(date('Y-m-d H:i:s T')) ?></time></span><span>PHP <?= e(PHP_VERSION) ?></span><span>SQLite <?= e($databaseVersion) ?></span></span><br><a class="admin-link" href="/admin.php">Admin panel</a></p></footer></div></body></html><?php }
function render_body(string $body): string {
    $lines = explode("\n", $body);
    return implode('<br>', array_map(fn($line) => str_starts_with($line, '>') ? '<span class="quote">' . e($line) . '</span>' : e($line), $lines));
}
function render_reply_quote(array $post, array $thread): void { ?>
<blockquote class="notice" cite="/<?= e($thread['board_slug']) ?>/<?= (int)$thread['id'] ?>#p<?= (int)$post['id'] ?>"><p><a href="/<?= e($thread['board_slug']) ?>/<?= (int)$thread['id'] ?>#p<?= (int)$post['id'] ?>"><?= e($post['username'] ?? 'Anonymous') ?> wrote:</a></p><div class="quote"><?= render_body($post['body']) ?></div><?php if ($post['image']): ?><p>Attachment: <a href="/image.php?file=<?= e($post['image']) ?>"><?= e($post['image_name']) ?></a></p><?php endif; ?></blockquote>
<?php }
function render_post(array $post, array $thread, bool $op = false, bool $showSubject = true): void { ?>
<article class="post <?= $op ? 'op' : 'reply' ?>" id="p<?= (int)$post['id'] ?>">
<div class="post-meta"><time datetime="<?= e(str_replace(' ', 'T', $post['created_at'])) ?>Z"><?= e(gmdate('d M Y (D) H:i:s \U\T\C', strtotime($post['created_at'] . ' UTC'))) ?></time><a class="post-number" href="/<?= e($thread['board_slug']) ?>/<?= (int)$thread['id'] ?>#p<?= (int)$post['id'] ?>">No.<?= padded_id((int)$post['id']) ?></a></div>
<div class="post-layout"><aside class="post-author"><strong class="poster"><?= e($post['username'] ?? 'Anonymous') ?></strong><?php if (!empty($post['account_id'])): ?><img class="avatar" src="/avatar.php?id=<?= (int)$post['account_id'] ?>" alt="" width="80" height="80" loading="lazy"><span class="user-id">Member #<?= padded_id((int)$post['account_id']) ?></span><?php else: ?><span class="user-id">ID:<?= padded_id((int)$post['user_id']) ?></span><?php endif; ?></aside><div class="post-content">
<?php if ($op && $showSubject): ?><div class="post-heading"><strong class="subject"><a href="/<?= e($thread['board_slug']) ?>/<?= (int)$thread['id'] ?>"><?= e($thread['subject']) ?></a></strong><span class="post-actions"><?php if ($thread['pinned']): ?><span class="badge">Pinned</span><?php endif; ?><?php if ($thread['locked']): ?><span class="badge">Closed</span><?php elseif (current_user()): ?><a class="mini-button" href="/<?= e($thread['board_slug']) ?>/<?= (int)$thread['id'] ?>#compose">Reply</a><?php endif; ?></span></div><?php endif; ?>
<?php if (!empty($post['quoted_id'])) render_reply_quote(['id' => $post['quoted_id'], 'username' => $post['quoted_username'], 'body' => $post['quoted_body'], 'image' => $post['quoted_image'], 'image_name' => $post['quoted_image_name']], $thread); ?>
<?php if ($post['image']): ?><div class="file-info">Attachment: <a href="/image.php?file=<?= e($post['image']) ?>" target="_blank" rel="noopener"><?= e($post['image_name']) ?></a> (<?= (int)$post['image_width'] ?>×<?= (int)$post['image_height'] ?>)</div><figure class="attachment"><a href="/image.php?file=<?= e($post['image']) ?>" target="_blank" rel="noopener"><img src="/image.php?file=<?= e($post['image']) ?>" alt="<?= e($post['image_name']) ?>" width="<?= (int)$post['image_width'] ?>" height="<?= (int)$post['image_height'] ?>" loading="lazy"></a></figure><?php endif; ?>
<div class="post-body"><?= render_body($post['body']) ?></div><?php if (!$thread['locked'] && current_user()): ?><div class="post-actions"><a class="mini-button" href="/<?= e($thread['board_slug']) ?>/<?= (int)$thread['id'] ?>?reply=<?= (int)$post['id'] ?>#compose" aria-label="Reply to post <?= (int)$post['id'] ?>">Reply</a></div><?php endif; ?></div></div></article><?php }
function post_form(string $slug, ?array $thread = null, string $error = '', array $values = [], ?array $replyTo = null): void {
    if ($thread && !current_user()) {
        $next = rawurlencode('/' . $slug . '/' . (int)$thread['id'] . '#compose');
        ?><p class="reply-login" id="compose"><a href="/login.php?next=<?= e($next) ?>">Log in</a> or <a href="/register.php?next=<?= e($next) ?>">register</a> to reply.</p><?php
        return;
    }
    ?>
<section class="panel composer" id="compose"><h2><?= $thread ? 'Post reply' : 'New thread' ?></h2>
<?php if ($replyTo): render_reply_quote($replyTo, $thread); ?><p><a href="/<?= e($slug) ?>/<?= (int)$thread['id'] ?>#compose">Reply without quoting</a></p><?php endif; ?>
<?php if ($error): ?><p role="alert" class="notice error"><?= e($error) ?></p><?php endif; ?>
<form action="/post.php" method="post" enctype="multipart/form-data"><?php csrf_field(); ?><input type="hidden" name="board" value="<?= e($slug) ?>"><?php if ($thread): ?><input type="hidden" name="thread" value="<?= (int)$thread['id'] ?>"><?php if ($replyTo): ?><input type="hidden" name="reply_to" value="<?= (int)$replyTo['id'] ?>"><?php endif; ?><?php else: ?><div class="form-row"><label for="subject">Subject</label><input id="subject" name="subject" maxlength="100" required value="<?= e($values['subject'] ?? '') ?>"></div><?php endif; ?>
<div class="form-row"><label for="body">Message</label><textarea id="body" name="body" rows="4" maxlength="5000"><?= e($values['body'] ?? '') ?></textarea></div>
<div class="form-row"><label for="image">Image</label><div><input id="image" type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp"><p class="upload-help">JPG / PNG / GIF / WebP. Max 4 MB. GIFs saved as stills.</p></div></div>
<div class="form-actions"><button type="submit"><?= $thread ? 'Post reply' : 'Post thread' ?></button></div></form></section><?php }

function forum_time(string $stamp): string {
    return gmdate('d M Y, H:i', strtotime($stamp . ' UTC'));
}
function last_post_cell(?array $post, bool $subject = true): void {
    if (!$post) { ?><span class="no-posts">No posts</span><?php return; }
    ?><div class="last-post"><?php if ($subject): ?><a class="last-subject" href="/<?= e($post['board_slug']) ?>/<?= (int)$post['thread_id'] ?>#p<?= (int)$post['post_id'] ?>" title="<?= e($post['subject']) ?>"><?= e($post['subject']) ?></a><?php endif; ?><span>by <strong><?= e($post['username'] ?? 'Anonymous') ?></strong></span><a class="last-time" href="/<?= e($post['board_slug']) ?>/<?= (int)$post['thread_id'] ?>#p<?= (int)$post['post_id'] ?>"><time datetime="<?= e(str_replace(' ', 'T', $post['created_at'])) ?>Z"><?= e(forum_time($post['created_at'])) ?></time></a></div><?php
}
