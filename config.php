<?php
// Central DB configuration. Update these values to match your environment.
$db_host = getenv('DB_HOST') ?: '127.0.0.1';
$db_user = getenv('DB_USER') ?: 'root';
$db_pass = getenv('DB_PASS') ?: '';
$db_name = getenv('DB_NAME') ?: 'traffic_db';
$db_charset = getenv('DB_CHARSET') ?: 'utf8mb4';

// Example: override by creating a local config_private.php and setting values there.
if (file_exists(__DIR__ . '/config_private.php')) {
    include __DIR__ . '/config_private.php';
}

?>
