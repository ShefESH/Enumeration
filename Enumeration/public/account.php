<?php
require_once __DIR__ . '/../app/view.php';
require_once __DIR__ . '/../app/actions.php';
$user = current_user();
if (!$user) redirect('/login.php');
$error = ''; $message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $avatar = null;
    try {
        check_csrf();
        $action = input('action', $_POST);
        if ($action === 'logout') {
            unset($_SESSION['account_id'], $_SESSION['account_until'], $_SESSION['account_version']);
            session_regenerate_id(true);
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            redirect('/');
        }
        if (!in_array($action, ['avatar', 'remove-avatar'], true)) throw new RuntimeException('Unknown account action.');
        rate_limit('avatar', 10, 300);
        if ($action === 'avatar') $avatar = save_avatar();
        // Read the old filename while locked so concurrent uploads do not leak files.
        db()->beginTransaction();
        $old = query('SELECT avatar FROM users WHERE id=? FOR UPDATE', [$user['id']])->fetchColumn();
        query('UPDATE users SET avatar=? WHERE id=?', [$avatar, $user['id']]);
        db()->commit();
        $avatar = null;
        remove_images([$old]);
        $_SESSION['account_flash'] = 'Avatar updated.';
        redirect('/account.php');
    } catch (Throwable $exception) {
        if (db()->inTransaction()) db()->rollBack();
        if ($avatar) remove_images([$avatar]);
        $expected = $exception instanceof RuntimeException && !$exception instanceof PDOException;
        if (!$expected) error_log((string)$exception);
        http_response_code($expected ? 422 : 500);
        $error = $expected ? $exception->getMessage() : 'Unable to update your account.';
    }
}
$message = $_SESSION['account_flash'] ?? ''; unset($_SESSION['account_flash']);
page_header('Account'); ?>
<div class="breadcrumb"><a href="/">Board index</a> / Account</div>
<div class="page-heading"><h1><?= e($user['username']) ?></h1></div>
<section class="panel account-panel"><h2>Avatar</h2>
<?php if ($error): ?><p class="notice error" role="alert"><?= e($error) ?></p><?php endif; ?>
<?php if ($message): ?><p class="notice" role="status"><?= e($message) ?></p><?php endif; ?>
<div class="avatar-preview"><img class="avatar" src="/avatar.php?id=<?= (int)$user['id'] ?>" alt="Current avatar" width="96" height="96"><p>Member #<?= padded_id((int)$user['id']) ?><br>Joined <?= e(gmdate('d M Y', strtotime($user['created_at'] . ' UTC'))) ?></p></div>
<form method="post" enctype="multipart/form-data"><?php csrf_field(); ?><input type="hidden" name="action" value="avatar"><label for="image">Upload avatar</label><input id="image" name="image" type="file" accept="image/jpeg,image/png,image/gif,image/webp" required aria-describedby="avatar-help"><p class="field-help" id="avatar-help">JPG, PNG, GIF or WebP. Maximum 4 MB. Cropped to 96 × 96 pixels. GIFs are saved as still images.</p><button>Save avatar</button></form>
<?php if ($user['avatar']): ?><form method="post"><?php csrf_field(); ?><button name="action" value="remove-avatar">Remove avatar</button></form><?php endif; ?>
</section><?php page_footer();
