<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
function page_header(string $title, string $active = ''): void {
    ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?> - Enumeration</title><link rel="stylesheet" href="/assets/style.css"><link rel="icon" href="/assets/favicon.svg" type="image/svg+xml"></head>
<body><div class="site"><header><a class="wordmark" href="/">Enumeration</a></header>
<nav aria-label="Boards" class="boardnav"><a href="/">Home</a><?php foreach (BOARDS as $slug => $info): ?><a <?= $active === $slug ? 'aria-current="page"' : '' ?> href="/<?= e($slug) ?>/">/<?= e($slug) ?>/</a><?php endforeach; ?><a href="/rules.php">Rules</a><a href="/admin.php">Admin</a></nav>
<main><?php
}
function page_footer(): void { ?></main></div></body></html><?php }
function render_body(string $body): string {
    $lines = explode("\n", $body);
    return implode('<br>', array_map(fn($line) => str_starts_with($line, '>') ? '<span class="quote">' . e($line) . '</span>' : e($line), $lines));
}
function render_post(array $post, array $thread, bool $op = false): void { ?>
<article class="post <?= $op ? 'op' : 'reply' ?>" id="p<?= (int)$post['id'] ?>">
<?php if ($post['image']): ?><div class="file-info">File: <a href="/image.php?file=<?= e($post['image']) ?>" target="_blank" rel="noopener"><?= e($post['image_name']) ?></a> (<?= (int)$post['image_width'] ?>×<?= (int)$post['image_height'] ?>)</div><figure class="attachment"><a href="/image.php?file=<?= e($post['image']) ?>" target="_blank" rel="noopener"><img src="/image.php?file=<?= e($post['image']) ?>" alt="<?= e($post['image_name']) ?>" width="<?= (int)$post['image_width'] ?>" height="<?= (int)$post['image_height'] ?>" loading="lazy"></a></figure><?php endif; ?>
<div class="post-meta"><?php if ($op): ?><strong class="subject"><?= e($thread['subject']) ?></strong> <?php endif; ?><strong class="poster">Anonymous</strong> <span class="user-id">ID:<?= padded_id((int)$post['user_id']) ?></span> <time datetime="<?= e(str_replace(' ', 'T', $post['created_at'])) ?>Z"><?= e(gmdate('m/d/y(D)H:i:s', strtotime($post['created_at'] . ' UTC'))) ?></time> <a class="post-number" href="/<?= e($thread['board_slug']) ?>/<?= (int)$thread['id'] ?>#p<?= (int)$post['id'] ?>">No.<?= padded_id((int)$post['id']) ?></a><?php if ($op): ?> <a href="/<?= e($thread['board_slug']) ?>/<?= (int)$thread['id'] ?>#compose">[Reply]</a><?php if ($thread['pinned']): ?> <span>[Pinned]</span><?php endif; ?><?php if ($thread['locked']): ?> <span>[Closed]</span><?php endif; ?><?php endif; ?></div>
<div class="post-body"><?= render_body($post['body']) ?></div></article><?php }
function post_form(string $slug, ?array $thread = null, string $error = '', array $values = []): void { ?>
<section class="panel composer" id="compose"><h2><?= $thread ? 'Reply' : 'New thread' ?></h2>
<?php if ($error): ?><p role="alert" class="notice error"><?= e($error) ?></p><?php endif; ?>
<form action="/post.php" method="post" enctype="multipart/form-data"><?php csrf_field(); ?><input type="hidden" name="board" value="<?= e($slug) ?>"><?php if ($thread): ?><input type="hidden" name="thread" value="<?= (int)$thread['id'] ?>"><?php else: ?><div class="form-row"><label for="subject">Subject</label><input id="subject" name="subject" maxlength="100" required value="<?= e($values['subject'] ?? '') ?>"></div><?php endif; ?>
<div class="form-row"><label for="body">Message</label><textarea id="body" name="body" rows="4" maxlength="5000"><?= e($values['body'] ?? '') ?></textarea></div>
<div class="form-row"><label for="image">Image</label><div><input id="image" type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp"><p class="upload-help">JPG / PNG / GIF / WebP. Max 4 MB. GIFs saved as stills.</p></div></div>
<div class="form-actions"><button type="submit"><?= $thread ? 'Post reply' : 'Create thread' ?></button></div></form></section><?php }
