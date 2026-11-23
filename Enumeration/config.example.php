<?php
// Copy to config.php (gitignored, outside public/) and set your database password.
// Environment variables override these values for CLI and Apache alike.
return [
    'FORUM_DB_DSN' => 'mysql:host=127.0.0.1;port=3306;dbname=enumeration;charset=utf8mb4',
    'FORUM_DB_USER' => 'forum',
    'FORUM_DB_PASSWORD' => 'REPLACE_WITH_YOUR_DATABASE_PASSWORD',
];
