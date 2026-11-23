<?php
require_once __DIR__ . '/view.php';
require_once __DIR__ . '/actions.php';
$next = input('next', $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET);
// Only allow forum thread destinations, never external or protocol-relative URLs.
if (!preg_match('~^/([a-z]+)/[1-9][0-9]*#compose$~D', $next, $match) || !isset(BOARDS[$match[1]])) $next = '/account.php';
$nextQuery = '?next=' . rawurlencode($next);
if (current_user()) redirect($next);
$error = ''; $username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        check_csrf();
        rate_limit($register ? 'register' : 'user-login', $register ? 5 : 10, 300);
        $username = input('username', $_POST);
        $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        if ($register) {
            $user = register_user($username, $password);
        } else {
            $user = query('SELECT * FROM users WHERE username=?', [$username])->fetch();
            // Verify a fixed dummy hash for unknown users as well.
            $valid = strlen($password) <= 72 && !str_contains($password, "\0") && password_verify($password, $user['password_hash'] ?? '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
            if (!$user || !$valid) throw new RuntimeException('Incorrect username or password.');
            if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                $user['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                query('UPDATE users SET password_hash=? WHERE id=?', [$user['password_hash'], $user['id']]);
            }
        }
        sign_in_user($user);
        redirect($next);
    } catch (Throwable $exception) {
        $expected = $exception instanceof RuntimeException && !$exception instanceof PDOException;
        if (!$expected) error_log((string)$exception);
        http_response_code($expected ? 422 : 500);
        $error = $expected ? $exception->getMessage() : 'Unable to sign in. Try again later.';
    }
}
$title = $register ? 'Register' : 'Log in';
page_header($title); ?>
<div class="breadcrumb"><a href="/">Board index</a> / <?= e($title) ?></div>
<section class="panel login"><h2><?= e($title) ?></h2>
<?php if ($error): ?><p class="notice error" role="alert"><?= e($error) ?></p><?php endif; ?>
<form method="post"><?php csrf_field(); ?><input type="hidden" name="next" value="<?= e($next) ?>">
<label for="username">Username</label><input id="username" name="username" required minlength="3" maxlength="24" autocomplete="username" value="<?= e($username) ?>" <?php if ($register): ?>pattern="[a-zA-Z0-9_]{3,24}" aria-describedby="username-help"<?php endif; ?>>
<?php if ($register): ?><p class="field-help" id="username-help">3–24 letters, numbers or underscores.</p><?php endif; ?>
<label for="password">Password</label><input id="password" name="password" type="password" required autocomplete="<?= $register ? 'new-password' : 'current-password' ?>" <?php if ($register): ?>minlength="10" aria-describedby="password-help"<?php endif; ?>>
<?php if ($register): ?><p class="field-help" id="password-help">10–72 bytes. Spaces are allowed. No email or password recovery.</p><?php endif; ?>
<button type="submit"><?= e($title) ?></button></form>
<p><?php if ($register): ?><a href="/login.php<?= e($nextQuery) ?>">Log in</a><?php else: ?><a href="/register.php<?= e($nextQuery) ?>">Register an account</a><?php endif; ?></p></section>
<?php page_footer();
