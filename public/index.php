<?php
require_once __DIR__ . '/../app/view.php';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/') {
    page_header('Home');
    $counts = query('SELECT b.slug, COUNT(DISTINCT t.id) threads, COUNT(p.id) posts FROM boards b LEFT JOIN threads t ON t.board_slug=b.slug LEFT JOIN posts p ON p.thread_id=t.id GROUP BY b.slug')->fetchAll(PDO::FETCH_UNIQUE);
    ?><section class="panel directory"><h1>Boards</h1><table><thead><tr><th scope="col">Board</th><th scope="col">Threads</th><th scope="col">Posts</th></tr></thead><tbody><?php foreach (BOARDS as $slug => [$title]): ?><tr><td><a href="/<?= $slug ?>/">/<?= $slug ?>/ - <?= e($title) ?></a></td><td><?= (int)$counts[$slug]['threads'] ?></td><td><?= (int)$counts[$slug]['posts'] ?></td></tr><?php endforeach; ?></tbody></table></section><?php page_footer(); exit;
}
if (!preg_match('~^/([a-z]+)(?:/([1-9][0-9]*))?/?$~D', $path, $matches)) fail(404, 'Page not found', 'That address is not in the directory.');
$board = board($matches[1]); $slug = $board['slug'];
if (isset($matches[2])) {
    $thread = thread((int)$matches[2], $slug); page_header($thread['subject'], $slug); ?>
    <div class="breadcrumb"><a href="/">Home</a> / <a href="/<?= e($slug) ?>/">/<?= e($slug) ?>/</a> / Thread <?= (int)$thread['id'] ?></div>
    <div class="page-heading"><div><h1><?= e($thread['subject']) ?></h1></div><a href="/<?= e($slug) ?>/">« Back to board</a></div>
    <section class="thread full-thread"><?php $posts = query('SELECT * FROM posts WHERE thread_id = ? ORDER BY id', [$thread['id']])->fetchAll(); foreach ($posts as $i => $post) render_post($post, $thread, $i === 0); ?></section>
    <?php if ($thread['locked']): ?><p class="notice">This conversation has been closed by a moderator.</p><?php else: post_form($slug, $thread); endif;
    page_footer(); exit;
}
$page = max(1, (int)input('page', $_GET)); $total = (int)query('SELECT COUNT(*) FROM threads WHERE board_slug=?', [$slug])->fetchColumn();
$pages = max(1, (int)ceil($total / 10)); $page = min($page, $pages);
$threads = query('SELECT t.*, (SELECT COUNT(*) FROM posts WHERE thread_id=t.id) post_count FROM threads t WHERE board_slug=? ORDER BY pinned DESC, bumped_at DESC, id DESC LIMIT 10 OFFSET ' . (($page - 1) * 10), [$slug])->fetchAll();
page_header('/' . $slug . '/ — ' . $board['title'], $slug); ?>
<div class="page-heading"><div><h1>/<?= e($slug) ?>/ <span><?= e($board['title']) ?></span></h1></div><a href="#compose">[Start a new thread]</a></div>
<div class="board-layout"><div class="board-content"><div class="board-tools"><span><?= $total ?> threads | First 5 posts shown</span><form action="/search.php" method="get"><input type="hidden" name="board" value="<?= e($slug) ?>"><label class="sr-only" for="search">Search this board</label><input id="search" name="q" placeholder="Search this board…" maxlength="120" required><button>Search</button></form></div>
<?php if (!$threads): ?><div class="panel empty"><p>No threads.</p></div><?php endif; foreach ($threads as $thread): ?>
<section class="thread">
<?php $posts = query('SELECT * FROM posts WHERE thread_id=? ORDER BY id LIMIT 5', [$thread['id']])->fetchAll(); foreach ($posts as $i => $post) render_post($post, $thread, $i === 0); ?>
<div class="thread-footer"><span><?= (int)$thread['post_count'] > 5 ? ((int)$thread['post_count'] - 5) . ' posts omitted.' : '' ?></span><a href="/<?= e($slug) ?>/<?= (int)$thread['id'] ?>">[View thread]</a></div></section><?php endforeach; ?>
<nav class="pagination" aria-label="Pages"><?php if ($page > 1): ?><a href="?page=<?= $page - 1 ?>">« Previous</a><?php endif; ?><span>Page <?= $page ?> of <?= $pages ?></span><?php if ($page < $pages): ?><a href="?page=<?= $page + 1 ?>">Next »</a><?php endif; ?></nav>
</div><aside class="posting-sidebar"><?php post_form($slug); ?></aside></div><?php page_footer();
