<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
function page_header(string $title, string $active = ''): void {
    ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?> - Enumeration</title><link rel="stylesheet" href="/assets/style.css"><link rel="icon" href="/assets/favicon.svg" type="image/svg+xml"></head>
<body id="top"><a class="skip-link" href="#content">Skip to content</a><div class="site">
<header class="site-header"><a class="brand" href="/" aria-label="Enumeration board index"><span class="brand-icon" aria-hidden="true">E</span><span><span class="wordmark">Enumeration</span><span class="tagline">An anonymous bulletin board.</span></span></a><div class="header-note">A place for every conversation.<br><span>Read. Post. Repeat.</span></div></header>
<nav aria-label="Main navigation" class="utility-nav"><a href="/" <?= $title === 'Home' ? 'aria-current="page"' : '' ?>>Board index</a><a href="/search.php<?= $active ? '?board=' . e($active) : '' ?>">Search</a><a href="/rules.php" <?= $title === 'Rules' ? 'aria-current="page"' : '' ?>>Board rules</a><a href="/admin.php" <?= $title === 'Admin' ? 'aria-current="page"' : '' ?>>Moderator panel</a></nav>
<nav aria-label="Boards" class="boardnav"><span>Jump to board:</span><?php foreach (BOARDS as $slug => $info): ?><a <?= $active === $slug ? 'aria-current="page"' : '' ?> href="/<?= e($slug) ?>/">/<?= e($slug) ?>/ <span><?= e($info[0]) ?></span></a><?php endforeach; ?></nav>
<main id="content"><?php
}
function page_footer(): void { ?></main><footer class="site-footer"><div><a href="/">Board index</a><span> / </span><a href="/rules.php">Board rules</a><a class="back-top" href="#top">Back to top</a></div><p><strong>Enumeration</strong> &mdash; Anonymous bulletin board<br><span>All post times are UTC.</span></p></footer></div></body></html><?php }
function render_body(string $body): string {
    $lines = explode("\n", $body);
    return implode('<br>', array_map(fn($line) => str_starts_with($line, '>') ? '<span class="quote">' . e($line) . '</span>' : e($line), $lines));
}
function render_post(array $post, array $thread, bool $op = false): void { ?>
<article class="post <?= $op ? 'op' : 'reply' ?>" id="p<?= (int)$post['id'] ?>">
<div class="post-meta"><time datetime="<?= e(str_replace(' ', 'T', $post['created_at'])) ?>Z"><?= e(gmdate('m/d/y(D)H:i:s', strtotime($post['created_at'] . ' UTC'))) ?></time><a class="post-number" href="/<?= e($thread['board_slug']) ?>/<?= (int)$thread['id'] ?>#p<?= (int)$post['id'] ?>">No.<?= padded_id((int)$post['id']) ?></a></div>
<div class="post-layout"><aside class="post-author"><strong class="poster">Anonymous</strong><span class="author-emblem" aria-hidden="true"></span><span class="user-id">ID:<?= padded_id((int)$post['user_id']) ?></span><span class="author-role"><?= $op ? 'Thread starter' : 'Participant' ?></span></aside><div class="post-content">
<?php if ($op): ?><div class="post-heading"><strong class="subject"><a href="/<?= e($thread['board_slug']) ?>/<?= (int)$thread['id'] ?>"><?= e($thread['subject']) ?></a></strong><span class="post-actions"><?php if ($thread['pinned']): ?><span class="badge">Pinned</span><?php endif; ?><?php if ($thread['locked']): ?><span class="badge">Closed</span><?php else: ?><a class="mini-button" href="/<?= e($thread['board_slug']) ?>/<?= (int)$thread['id'] ?>#compose">Reply</a><?php endif; ?></span></div><?php endif; ?>
<?php if ($post['image']): ?><div class="file-info">Attachment: <a href="/image.php?file=<?= e($post['image']) ?>" target="_blank" rel="noopener"><?= e($post['image_name']) ?></a> (<?= (int)$post['image_width'] ?>×<?= (int)$post['image_height'] ?>)</div><figure class="attachment"><a href="/image.php?file=<?= e($post['image']) ?>" target="_blank" rel="noopener"><img src="/image.php?file=<?= e($post['image']) ?>" alt="<?= e($post['image_name']) ?>" width="<?= (int)$post['image_width'] ?>" height="<?= (int)$post['image_height'] ?>" loading="lazy"></a></figure><?php endif; ?>
<div class="post-body"><?= render_body($post['body']) ?></div></div></div></article><?php }
function post_form(string $slug, ?array $thread = null, string $error = '', array $values = []): void { ?>
<section class="panel composer" id="compose"><h2><?= $thread ? 'Post a reply' : 'Start a new thread' ?></h2>
<?php if ($error): ?><p role="alert" class="notice error"><?= e($error) ?></p><?php endif; ?>
<p class="composer-note">Posting as <strong>Anonymous</strong>. No account required.</p>
<form action="/post.php" method="post" enctype="multipart/form-data"><?php csrf_field(); ?><input type="hidden" name="board" value="<?= e($slug) ?>"><?php if ($thread): ?><input type="hidden" name="thread" value="<?= (int)$thread['id'] ?>"><?php else: ?><div class="form-row"><label for="subject">Subject</label><input id="subject" name="subject" maxlength="100" required value="<?= e($values['subject'] ?? '') ?>"></div><?php endif; ?>
<div class="form-row"><label for="body">Message</label><textarea id="body" name="body" rows="4" maxlength="5000"><?= e($values['body'] ?? '') ?></textarea></div>
<div class="form-row"><label for="image">Image</label><div><input id="image" type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp"><p class="upload-help">JPG / PNG / GIF / WebP. Max 4 MB. GIFs saved as stills.</p></div></div>
<div class="form-actions"><button type="submit"><?= $thread ? 'Post reply' : 'Create thread' ?></button></div></form></section><?php }
