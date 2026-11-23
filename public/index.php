<?php
require_once __DIR__ . '/../app/view.php';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/') {
    page_header('Home');
    $counts = query('SELECT b.slug, COUNT(DISTINCT t.id) threads, COUNT(p.id) posts FROM boards b LEFT JOIN threads t ON t.board_slug=b.slug LEFT JOIN posts p ON p.thread_id=t.id GROUP BY b.slug')->fetchAll(PDO::FETCH_UNIQUE);
    $lastPosts = query('SELECT t.board_slug, p.id post_id, p.thread_id, p.created_at, t.subject, u.username FROM posts p JOIN threads t ON t.id=p.thread_id LEFT JOIN users u ON u.id=p.account_id WHERE p.id IN (SELECT MAX(p2.id) FROM posts p2 JOIN threads t2 ON t2.id=p2.thread_id GROUP BY t2.board_slug)')->fetchAll(PDO::FETCH_UNIQUE);
    $recentPosts = query('SELECT p.id post_id, p.thread_id, p.created_at, t.board_slug, t.subject, u.username FROM posts p JOIN threads t ON t.id=p.thread_id LEFT JOIN users u ON u.id=p.account_id WHERE p.id IN (SELECT MAX(id) FROM posts GROUP BY thread_id) ORDER BY p.id DESC LIMIT 5')->fetchAll();
    $memberCount = (int)query('SELECT COUNT(*) FROM users')->fetchColumn();
    $newest = query('SELECT username FROM users ORDER BY id DESC LIMIT 1')->fetchColumn();
    $descriptions = ['gen' => 'General discussion.', 'tech' => 'Computers, software and hardware.', 'art' => 'Art, design, photography and crafts.', 'music' => 'Artists, albums and music discussion.', 'games' => 'Video games, tabletop games and gaming discussion.', 'books' => 'Books, authors and reading.', 'film' => 'Films, television and documentaries.'];
    ?>
    <div class="page-heading index-heading"><h1 class="sr-only">Board index</h1><span class="board-clock"><?= e(gmdate('D d M Y, H:i')) ?> UTC</span></div>
    <div class="index-layout"><div class="forum-groups">
    <?php foreach (BOARD_CATEGORIES as $category => $slugs): ?>
    <section class="forum-category" aria-label="<?= e($category) ?>"><table class="forum-table"><thead><tr><th scope="col"><?= e($category) ?></th><th scope="col" class="count">Threads</th><th scope="col" class="count">Posts</th><th scope="col" class="last-column">Last post</th></tr></thead><tbody>
    <?php foreach ($slugs as $slug): ?><tr><td><div class="forum-cell"><span class="forum-icon" aria-hidden="true"></span><div><a class="forum-title" href="/<?= e($slug) ?>/"><?= e(BOARDS[$slug][0]) ?></a><p class="forum-description"><?= e($descriptions[$slug] ?? BOARDS[$slug][1]) ?></p><span class="mobile-counts"><?= (int)$counts[$slug]['threads'] ?> threads / <?= (int)$counts[$slug]['posts'] ?> posts</span></div></div></td><td class="count"><?= number_format((int)$counts[$slug]['threads']) ?></td><td class="count"><?= number_format((int)$counts[$slug]['posts']) ?></td><td class="last-column"><?php $last = $lastPosts[$slug] ?? null; if ($last) $last['board_slug'] = $slug; last_post_cell($last); ?></td></tr><?php endforeach; ?>
    </tbody></table></section><?php endforeach; ?>
    <section class="panel board-statistics"><h2>Board statistics</h2><p>Total posts: <strong><?= number_format(array_sum(array_column($counts, 'posts'))) ?></strong> &nbsp; | &nbsp; Total threads: <strong><?= number_format(array_sum(array_column($counts, 'threads'))) ?></strong> &nbsp; | &nbsp; Members: <strong><?= number_format($memberCount) ?></strong><?php if ($newest !== false): ?><br>Newest member: <strong><?= e($newest) ?></strong><?php endif; ?></p></section>
    </div><aside class="latest-panel panel"><h2>Latest posts</h2><?php if (!$recentPosts): ?><p>No posts.</p><?php else: ?><ul class="latest-posts"><?php foreach ($recentPosts as $recent): ?><li><?php last_post_cell($recent); ?></li><?php endforeach; ?></ul><?php endif; ?></aside></div>
    <?php page_footer(); exit;
}
if (!preg_match('~^/([a-z]+)(?:/([1-9][0-9]*))?/?$~D', $path, $matches)) fail(404, 'Page not found', 'That address is not in the directory.');
$board = board($matches[1]); $slug = $board['slug'];
if (isset($matches[2])) {
    $thread = thread((int)$matches[2], $slug);
    $replyTo = null;
    if (input('reply', $_GET) !== '' && current_user() && !$thread['locked']) {
        try { $replyTo = reply_target(input('reply', $_GET), (int)$thread['id']); }
        catch (RuntimeException $error) { fail(404, 'Post not found', $error->getMessage()); }
    }
    page_header($thread['subject'], $slug); ?>
    <div class="breadcrumb"><a href="/">Board index</a> / <a href="/<?= e($slug) ?>/"><?= e($slug) ?></a> / <?= e($thread['subject']) ?></div>
    <div class="page-heading"><div><h1><?= e($thread['subject']) ?></h1></div></div>
    <section class="thread full-thread"><?php $posts = thread_posts((int)$thread['id']); foreach ($posts as $i => $post) render_post($post, $thread, $i === 0, false); ?></section>
    <?php if ($thread['locked']): ?><p class="notice">This thread is closed.</p><?php else: post_form($slug, $thread, '', [], $replyTo); endif;
    page_footer(); exit;
}
$page = max(1, (int)input('page', $_GET)); $total = (int)query('SELECT COUNT(*) FROM threads WHERE board_slug=?', [$slug])->fetchColumn();
$pages = max(1, (int)ceil($total / 10)); $page = min($page, $pages);
$threads = query('SELECT t.*, (SELECT COUNT(*) FROM posts WHERE thread_id=t.id) post_count, author.username author_name, lp.id last_post_id, lp.created_at last_post_at, last_author.username last_author_name FROM threads t LEFT JOIN posts op ON op.id=(SELECT MIN(id) FROM posts WHERE thread_id=t.id) LEFT JOIN users author ON author.id=op.account_id LEFT JOIN posts lp ON lp.id=(SELECT MAX(id) FROM posts WHERE thread_id=t.id) LEFT JOIN users last_author ON last_author.id=lp.account_id WHERE t.board_slug=? ORDER BY t.pinned DESC, t.bumped_at DESC, t.id DESC LIMIT 10 OFFSET ' . (($page - 1) * 10), [$slug])->fetchAll();
page_header('/' . $slug . '/ — ' . $board['title'], $slug); ?>
<div class="breadcrumb"><a href="/">Board index</a> / <?= e($slug) ?></div>
<div class="page-heading"><div><h1><?= e($board['title']) ?> <span class="heading-code">/<?= e($slug) ?>/</span></h1></div><a class="button primary" href="#compose">New thread</a></div>
<div class="board-tools"><span><?= $total ?> threads</span><form action="/search.php" method="get"><input type="hidden" name="board" value="<?= e($slug) ?>"><label class="sr-only" for="search">Search this board</label><input id="search" name="q" placeholder="Search this board…" maxlength="120" required><button>Search</button></form></div>
<section class="forum-category" aria-label="Threads"><table class="topic-table"><thead><tr><th scope="col">Thread / Author</th><th scope="col" class="count">Replies</th><th scope="col" class="last-column">Last post</th></tr></thead><tbody>
<?php if (!$threads): ?><tr><td colspan="3" class="empty">No threads.</td></tr><?php endif; foreach ($threads as $thread): ?>
<tr class="topic-row"><td><div class="forum-cell"><span class="forum-icon <?= $thread['locked'] ? 'icon-closed' : '' ?>" aria-hidden="true"></span><div class="topic-details"><?php if ($thread['pinned']): ?><span class="topic-status">Pinned:</span> <?php endif; ?><?php if ($thread['locked']): ?><span class="topic-status">Closed:</span> <?php endif; ?><a class="forum-title" href="/<?= e($slug) ?>/<?= (int)$thread['id'] ?>"><?= e($thread['subject']) ?></a><p class="topic-author">by <?= e($thread['author_name'] ?? 'Anonymous') ?> <span class="topic-started">/ <?= e(forum_time($thread['created_at'])) ?></span></p></div></div></td><td class="count"><?= max(0, (int)$thread['post_count'] - 1) ?></td><td class="last-column"><?php last_post_cell($thread['last_post_id'] ? ['post_id' => $thread['last_post_id'], 'thread_id' => $thread['id'], 'board_slug' => $slug, 'created_at' => $thread['last_post_at'], 'username' => $thread['last_author_name']] : null, false); ?></td></tr>
<?php endforeach; ?></tbody></table></section>
<nav class="pagination" aria-label="Pages"><?php if ($page > 1): ?><a href="?page=<?= $page - 1 ?>">« Previous</a><?php endif; ?><span>Page <?= $page ?> of <?= $pages ?></span><?php if ($page < $pages): ?><a href="?page=<?= $page + 1 ?>">Next »</a><?php endif; ?></nav>
<?php post_form($slug); page_footer();
