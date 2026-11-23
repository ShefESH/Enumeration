<?php
require_once __DIR__ . '/../app/view.php';
$slug = input('board', $_GET) ?: 'gen'; board($slug);
$q = mb_substr(input('q', $_GET), 0, 120); $results = []; $error = '';
if ($q !== '') {
    try {
        if (lab_mode()) {
            // INTENTIONAL TRAINING VULNERABILITY: q is concatenated into SQL.
            // Enabled only by FORUM_LAB_MODE=1. Never expose this mode to the internet.
            // The board remains constrained to the fixed allowlist above.
            $results = db()->query("SELECT id, subject, board_slug, created_at FROM threads WHERE board_slug = '$slug' AND subject LIKE '%$q%' ORDER BY id DESC LIMIT 50")->fetchAll();
        } else {
            $results = query('SELECT id, subject, board_slug, created_at FROM threads WHERE board_slug = ? AND subject LIKE ? ORDER BY id DESC LIMIT 50', [$slug, '%' . $q . '%'])->fetchAll();
        }
    } catch (PDOException) { http_response_code(400); $error = 'That search could not be processed. Try another phrase.'; }
}
page_header('Search', $slug); ?>
<div class="breadcrumb"><a href="/<?= e($slug) ?>/">/<?= e($slug) ?>/</a> / Search</div><div class="page-heading"><div><h1>Search /<?= e($slug) ?>/</h1></div></div>
<section class="panel"><h2>Search</h2><form class="search-form" method="get"><input type="hidden" name="board" value="<?= e($slug) ?>"><label for="q">Thread subject</label><input id="q" name="q" maxlength="120" value="<?= e($q) ?>" required><button>Search</button></form>
<?php if ($error): ?><p class="notice error" role="alert"><?= e($error) ?></p><?php elseif ($q !== ''): ?><p class="search-summary"><?= count($results) ?> results for “<?= e($q) ?>” (up to 50)</p><ul class="recent-list"><?php foreach ($results as $result): ?><li><a href="/<?= e(rawurlencode((string)$result['board_slug'])) ?>/<?= (int)$result['id'] ?>"><?= e($result['subject']) ?></a></li><?php endforeach; ?></ul><?php if (!$results): ?><p class="empty">No results.</p><?php endif; endif; ?></section>
<?php page_footer();
