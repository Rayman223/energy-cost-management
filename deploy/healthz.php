<?php

declare(strict_types=1);

$root = dirname(__DIR__);
try {
    $config = require $root . '/app/bootstrap.php';
    (new App\Infrastructure\Database($config['database']))->pdo()->query('SELECT 1');
    $heartbeat = @filemtime($root . '/var/scheduler/heartbeat');
    if ($heartbeat === false || time() - $heartbeat > 90) {
        throw new RuntimeException('Scheduler unavailable');
    }
    header('Content-Type: text/plain');
    echo "ok\n";
} catch (Throwable) {
    http_response_code(503);
    echo "unavailable\n";
}
