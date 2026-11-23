<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
if (PHP_SAPI !== 'cli') exit(1);
initialize_database();
echo "MySQL schema and boards initialized. Existing rows preserved.\n";
