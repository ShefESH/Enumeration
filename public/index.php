<?php
require_once __DIR__ . '/../app/view.php';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/') {
    page_header('Home');
    $counts = query('SELECT b.slug, COUNT(DISTINCT t.id) threads, COUNT(p.id) posts FROM boards b LEFT JOIN threads t ON t.board_slug=b.slug LEFT JOIN posts p ON p.thread_id=t.id GROUP BY b.slug')->fetchAll(PDO::FETCH_UNIQUE);
    $latest = query('SELECT t.*, COUNT(p.id) post_count FROM threads t JOIN posts p ON p.thread_id=t.id GROUP BY t.id ORDER BY t.bumped_at DESC, t.id DESC LIMIT 6')->fetchAll(); ?>
    <section class="welcome"><div><p class="eyebrow">YOU FOUND US. WELCOME IN.</p><h1>Good company.<br>No introductions required.</h1><p>A place for passing thoughts, late-night projects, and conversations<br class="desktop-break"> that go somewhere. Pick a board and make yourself at home.</p><a class="button" href="/gen/#compose">Say hello in /gen/</a></div><div class="welcome-stamp" aria-hidden="true"><span>THE INTERNET<br>IS STILL A</span><strong>nice<br>place.</strong><small>PLEASE HELP KEEP IT THAT WAY</small></div></section>
    <div class="home-columns"><section class="panel directory"><h2>The boards <span>Find your people.</span></h2><table><thead><tr><th scope="col">Board</th><th scope="col">Threads</th><th scope="col">Posts</th></tr></thead><tbody><?php foreach (BOARDS as $slug => [$title, $description]): ?><tr><td><a class="board-name" href="/<?= $slug ?>/"><span>/<?= $slug ?>/</span> <?= e($title) ?></a><p><?= e($description) ?></p></td><td><?= (int)$counts[$slug]['threads'] ?></td><td><?= (int)$counts[$slug]['posts'] ?></td></tr><?php endforeach; ?></tbody></table></section>
    <aside><section class="panel note"><h2>A note on the door</h2><p>You don't need a username to have something worth saying.</p><p>Be curious. Be helpful. Keep it suitable for everyone. There's a person on the other side of the screen.</p><a href="/rules.php">Read the house rules »</a></section><div class="little-note"><strong>100% human-sized internet.</strong><br>No profiles. No follower counts.<br>Just the conversation.</div></aside></div>
    <section class="panel"><h2>Recently on the boards <span>The kettle's still on.</span></h2><?php if (!$latest): ?><p class="empty">It's quiet here. <a href="/gen/#compose">Start the first conversation.</a></p><?php else: ?><ul class="recent-list"><?php foreach ($latest as $t): ?><li><a class="board-chip" href="/<?= e($t['board_slug']) ?>/">/<?= e($t['board_slug']) ?>/</a><a class="recent-subject" href="/<?= e($t['board_slug']) ?>/<?= (int)$t['id'] ?>"><?= e($t['subject']) ?></a><span><?= (int)$t['post_count'] - 1 ?> replies</span></li><?php endforeach; ?></ul><?php endif; ?></section><?php page_footer(); exit;
}
if (!preg_match('~^/([a-z]+)(?:/([1-9][0-9]*))?/?$~D', $path, $matches)) fail(404, 'Page not found', 'That address is not in the directory.');
$board = board($matches[1]); $slug = $board['slug'];
if (isset($matches[2])) {
    $thread = thread((int)$matches[2], $slug); page_header($thread['subject'], $slug); ?>
    <div class="breadcrumb"><a href="/">Home</a> / <a href="/<?= e($slug) ?>/">/<?= e($slug) ?>/</a> / Thread <?= (int)$thread['id'] ?></div>
    <div class="page-heading"><div><p class="eyebrow">/<?= e($slug) ?>/ · <?= e($board['title']) ?></p><h1><?= e($thread['subject']) ?></h1></div><a href="/<?= e($slug) ?>/">« Back to board</a></div>
    <section class="thread full-thread"><div class="thread-heading"><strong><?= $thread['pinned'] ? 'Pinned · ' : '' ?>Conversation #<?= (int)$thread['id'] ?></strong><span><?= $thread['locked'] ? 'Closed to replies' : 'Everyone is welcome' ?></span></div><?php $posts = query('SELECT * FROM posts WHERE thread_id = ? ORDER BY id', [$thread['id']])->fetchAll(); foreach ($posts as $i => $post) render_post($post, $thread, $i === 0); ?></section>
    <?php if ($thread['locked']): ?><p class="notice">This conversation has been closed by a moderator.</p><?php else: post_form($slug, $thread); endif;
    page_footer(); exit;
}
$page = max(1, (int)input('page', $_GET)); $total = (int)query('SELECT COUNT(*) FROM threads WHERE board_slug=?', [$slug])->fetchColumn();
$pages = max(1, (int)ceil($total / 10)); $page = min($page, $pages);
$threads = query('SELECT t.*, (SELECT COUNT(*) FROM posts WHERE thread_id=t.id) post_count FROM threads t WHERE board_slug=? ORDER BY pinned DESC, bumped_at DESC, id DESC LIMIT 10 OFFSET ' . (($page - 1) * 10), [$slug])->fetchAll();
page_header('/' . $slug . '/ — ' . $board['title'], $slug); ?>
<div class="breadcrumb"><a href="/">Home</a> / /<?= e($slug) ?>/</div><div class="page-heading"><div><p class="eyebrow">THE BOARDS</p><h1>/<?= e($slug) ?>/ <span><?= e($board['title']) ?></span></h1><p><?= e($board['description']) ?></p></div><a class="button" href="#compose">Start a thread</a></div>
<div class="board-tools"><span><?= $total ?> threads · First 5 posts shown</span><form action="/search.php" method="get"><input type="hidden" name="board" value="<?= e($slug) ?>"><label class="sr-only" for="search">Search this board</label><input id="search" name="q" placeholder="Search this board…" maxlength="120" required><button>Search</button></form></div>
<?php if (!$threads): ?><div class="panel empty"><h2>A fresh sheet of paper.</h2><p>No threads yet. Yours could be the first.</p></div><?php endif; foreach ($threads as $thread): ?>
<section class="thread"><div class="thread-heading"><h2><a href="/<?= e($slug) ?>/<?= (int)$thread['id'] ?>"><?= e($thread['subject']) ?></a><?php if ($thread['pinned']): ?> <span class="tag">Pinned</span><?php endif; ?><?php if ($thread['locked']): ?> <span class="tag">Closed</span><?php endif; ?></h2><span><?= (int)$thread['post_count'] - 1 ?> replies</span></div>
<?php $posts = query('SELECT * FROM posts WHERE thread_id=? ORDER BY id LIMIT 5', [$thread['id']])->fetchAll(); foreach ($posts as $i => $post) render_post($post, $thread, $i === 0); ?>
<div class="thread-footer"><span><?= (int)$thread['post_count'] > 5 ? ((int)$thread['post_count'] - 5) . ' more posts inside.' : 'You’re all caught up.' ?></span><a href="/<?= e($slug) ?>/<?= (int)$thread['id'] ?>">View thread / reply »</a></div></section><?php endforeach; ?>
<nav class="pagination" aria-label="Pages"><?php if ($page > 1): ?><a href="?page=<?= $page - 1 ?>">« Previous</a><?php endif; ?><span>Page <?= $page ?> of <?= $pages ?></span><?php if ($page < $pages): ?><a href="?page=<?= $page + 1 ?>">Next »</a><?php endif; ?></nav>
<?php post_form($slug); page_footer();
