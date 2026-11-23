<?php
declare(strict_types=1);

function installer_database_config(string $password): string {
    return "<?php\nreturn " . var_export([
        'FORUM_DB_DSN' => 'mysql:host=127.0.0.1;port=3306;dbname=enumeration;charset=utf8mb4',
        'FORUM_DB_USER' => 'forum',
        'FORUM_DB_PASSWORD' => $password,
    ], true) . ";\n";
}

function installer_password_literal(string $password): string {
    // Used only with NO_BACKSLASH_ESCAPES enabled on the setup connection.
    return "'" . str_replace("'", "''", $password) . "'";
}

// Allow offline tests to load the serialization helpers without opening MySQL.
if (PHP_SAPI !== 'cli') exit(1);
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;

try {
    $password = stream_get_contents(STDIN);
    if ($password === false || $password === '' || str_contains($password, "\0")) {
        throw new RuntimeException('A non-empty database password is required.');
    }
    $path = dirname(__DIR__) . '/config.php';
    if (!is_file($path) || filesize($path) !== 0 || !is_writable($path)) {
        throw new RuntimeException('Prepare an empty, writable config.php with mode 640 first.');
    }
    // Ubuntu's local root account uses socket authentication; no root password changes.
    $admin = new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
    ]);
    if ((int)$admin->query("SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='enumeration'")->fetchColumn()
        || (int)$admin->query("SELECT COUNT(*) FROM mysql.user WHERE User='forum' AND Host='127.0.0.1'")->fetchColumn()) {
        throw new RuntimeException('The enumeration database or forum account already exists; neither has been changed.');
    }
    $admin->exec("SET SESSION sql_mode = 'NO_BACKSLASH_ESCAPES'");
    $admin->exec('CREATE DATABASE enumeration CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $admin->exec("CREATE USER 'forum'@'127.0.0.1' IDENTIFIED BY " . installer_password_literal($password));
    $admin->exec("GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, REFERENCES ON enumeration.* TO 'forum'@'127.0.0.1'");
    if (file_put_contents($path, installer_database_config($password), LOCK_EX) === false) {
        throw new RuntimeException('Could not write database configuration.');
    }
    // Verify through the application's actual configuration loader and PDO connection.
    foreach (['FORUM_DB_DSN', 'FORUM_DB_USER', 'FORUM_DB_PASSWORD', 'FORUM_CONFIG'] as $key) putenv($key);
    require_once dirname(__DIR__) . '/app/bootstrap.php';
    if (config('FORUM_DB_PASSWORD') !== $password || (int)db()->query('SELECT 1')->fetchColumn() !== 1) {
        throw new RuntimeException('Application database verification failed.');
    }
    echo "Database account configured; application connection verified.\n";
} catch (PDOException $error) {
    // Avoid printing SQL statements or a password in an exception trace.
    fwrite(STDERR, 'Database setup failed (SQLSTATE ' . $error->getCode() . "). Check local MySQL access and password policy.\n");
    exit(1);
} catch (RuntimeException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
