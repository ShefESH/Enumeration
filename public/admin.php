<?php
require_once __DIR__ . '/../app/view.php';
require_once __DIR__ . '/../app/actions.php';
$error = ''; $message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        check_csrf(); $action = input('action', $_POST);
        if ($action === 'login') {
            rate_limit('login', 5, 300);
            $hash = admin_hash();
            if ($hash === '' || !password_verify(input('password', $_POST), $hash)) throw new RuntimeException('Sign-in failed. Check your password and try again.');
            session_regenerate_id(true);
            $_SESSION['admin_until'] = time() + 7200;
            $_SESSION['admin_version'] = hash('sha256', $hash);
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            redirect('/admin.php');
        }
        if (!is_admin()) throw new RuntimeException('Please sign in to moderate the boards.');
        if ($action === 'logout') { $_SESSION = []; session_regenerate_id(true); redirect('/admin.php'); }
        $id = (int)input('id', $_POST);
        if ($id < 1) throw new RuntimeException('Choose a thread or post first.');
        $images = [];
        db()->exec('BEGIN IMMEDIATE');
        if ($action === 'lock' || $action === 'pin') {
            $column = $action === 'lock' ? 'locked' : 'pinned';
            query("UPDATE threads SET $column = 1 - $column WHERE id=?", [$id]);
        } elseif ($action === 'delete_thread') {
            if (input('confirm', $_POST) !== 'yes') throw new RuntimeException('Tick the confirmation box to delete this thread.');
            $images = query('SELECT image FROM posts WHERE thread_id=?', [$id])->fetchAll(PDO::FETCH_COLUMN);
            query('DELETE FROM threads WHERE id=?', [$id]);
        } elseif ($action === 'delete_post') {
            if (input('confirm', $_POST) !== 'yes') throw new RuntimeException('Tick the confirmation box to delete this post.');
            $post = query('SELECT * FROM posts WHERE id=?', [$id])->fetch();
            if (!$post) throw new RuntimeException('That post has already been removed.');
            $first = (int)query('SELECT MIN(id) FROM posts WHERE thread_id=?', [$post['thread_id']])->fetchColumn();
            if ($first === $id) throw new RuntimeException('The opening post belongs to the thread. Delete the whole thread to remove it.');
            $images = [$post['image']];
            query('DELETE FROM posts WHERE id=?', [$id]);
        } else { throw new RuntimeException('Unknown moderation action.'); }
        db()->exec('COMMIT');
        remove_images($images);
        $_SESSION['flash'] = 'The board has been updated.';
        $return = (int)input('return_thread', $_POST);
        redirect('/admin.php' . ($return && $action !== 'delete_thread' ? '?thread=' . $return : ''));
    } catch (Throwable $exception) {
        try { db()->exec('ROLLBACK'); } catch (PDOException) {}
        $expected = $exception instanceof RuntimeException && !$exception instanceof PDOException;
        if (!$expected) error_log((string)$exception);
        $error = $expected ? $exception->getMessage() : 'The change could not be saved. Please try again.';
        http_response_code($expected ? 422 : 500);
    }
}
page_header('Admin'); ?>
<div class="breadcrumb"><a href="/">Board index</a> / Moderator panel</div><div class="page-heading"><div><h1>Moderator panel</h1><p>Manage threads and posts across the boards.</p></div><?php if (is_admin()): ?><form method="post"><?php csrf_field(); ?><input type="hidden" name="action" value="logout"><button>Sign out</button></form><?php endif; ?></div>
<?php if ($error): ?><p class="notice error" role="alert"><?= e($error) ?></p><?php endif; ?>
<?php if (!is_admin()): ?><section class="panel login"><h2>Moderator sign-in</h2><?php if (admin_hash() === ''): ?><p>Moderator access has not been configured. Contact the site operator.</p><?php else: ?><form method="post"><?php csrf_field(); ?><input type="hidden" name="action" value="login"><label for="password">Password</label><input id="password" type="password" name="password" required autocomplete="current-password"><button>Sign in</button></form><?php endif; ?></section><?php page_footer(); exit; endif; ?>
<?php if (isset($_SESSION['flash'])): ?><p class="notice" role="status"><?= e($_SESSION['flash']) ?></p><?php unset($_SESSION['flash']); endif;
$selected = (int)input('thread', $_GET);
if ($selected) {
    $thread = query('SELECT * FROM threads WHERE id=?', [$selected])->fetch();
    if (!$thread) { echo '<p class="notice">That thread no longer exists.</p>'; }
    else { ?>
    <p><a href="/admin.php">« All threads</a> | <a href="/<?= e($thread['board_slug']) ?>/<?= $selected ?>">View public thread</a></p><section class="panel"><h2><?= e($thread['subject']) ?></h2>
    <?php $posts = query('SELECT * FROM posts WHERE thread_id=? ORDER BY id', [$selected])->fetchAll(); foreach ($posts as $i => $post): ?><div class="admin-post"><?php render_post($post, $thread, $i === 0); if ($i > 0): ?><form method="post"><?php csrf_field(); ?><input type="hidden" name="action" value="delete_post"><input type="hidden" name="id" value="<?= (int)$post['id'] ?>"><input type="hidden" name="return_thread" value="<?= $selected ?>"><label><input type="checkbox" name="confirm" value="yes" required> Remove this post permanently</label> <button class="danger">Delete post</button></form><?php endif; ?></div><?php endforeach; ?></section><?php }
} else {
    $page = max(1, (int)input('page', $_GET));
    $pages = max(1, (int)ceil((int)query('SELECT COUNT(*) FROM threads')->fetchColumn() / 30)); $page = min($page, $pages);
    $threads = query('SELECT t.*, (SELECT COUNT(*) FROM posts WHERE thread_id=t.id) post_count FROM threads t ORDER BY bumped_at DESC, id DESC LIMIT 30 OFFSET ' . (($page - 1) * 30))->fetchAll(); ?>
    <section class="panel"><h2>Threads</h2><?php if (!$threads): ?><p class="empty">No threads.</p><?php endif; foreach ($threads as $t): ?><div class="moderation-row"><div><a href="/admin.php?thread=<?= (int)$t['id'] ?>"><?= e($t['subject']) ?></a><br><small>/<?= e($t['board_slug']) ?>/ | <?= (int)$t['post_count'] ?> posts <?= $t['locked'] ? '| Closed' : '' ?> <?= $t['pinned'] ? '| Pinned' : '' ?></small></div><div><form method="post"><?php csrf_field(); ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button name="action" value="pin"><?= $t['pinned'] ? 'Unpin' : 'Pin' ?></button><button name="action" value="lock"><?= $t['locked'] ? 'Reopen' : 'Close' ?></button></form> <form method="post"><?php csrf_field(); ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="action" value="delete_thread"><label><input type="checkbox" name="confirm" value="yes" required> Confirm</label><button class="danger">Delete thread</button></form></div></div><?php endforeach; ?></section>
    <nav class="pagination" aria-label="Moderation pages"><?php if ($page > 1): ?><a href="?page=<?= $page - 1 ?>">« Previous</a><?php endif; ?><span>Page <?= $page ?> of <?= $pages ?></span><?php if ($page < $pages): ?><a href="?page=<?= $page + 1 ?>">Next »</a><?php endif; ?></nav>
<?php } page_footer();
