<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$path = dirname(__DIR__) . '/config.local.php';
if (!is_file($path)) { fwrite(STDERR, "Copy config.example.php to config.local.php first.\n"); exit(1); }
$config = require $path;
try {
    $pdo = new PDO($config['db_dsn'], $config['db_user'], $config['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec(file_get_contents(dirname(__DIR__) . '/schema.sql'));
    $pdo->exec(file_get_contents(dirname(__DIR__) . '/migrations/001-chat.sql'));
    echo "Database tables installed. Accounts are created only after an allowlisted Google sign-in.\n";
} catch (Throwable $error) { fwrite(STDERR, "Install failed: " . $error->getMessage() . "\n"); exit(1); }
