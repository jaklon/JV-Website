<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
try {
    db()->exec(file_get_contents(dirname(__DIR__) . '/migrations/001-chat.sql'));
    echo "Chat tables ready. Existing memories and credentials were preserved.\n";
} catch (Throwable $e) { fwrite(STDERR, "Chat migration failed. Check database permissions/configuration.\n"); exit(1); }
