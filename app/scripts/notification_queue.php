<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

try {
    $directory = dirname(__DIR__, 2) . '/var/notifications';
    $lock = fopen($directory . '/queue.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Cannot lock notification queue');
    }
    try {
        $queue = new App\Service\NotificationQueue($directory);
        if (($argv[1] ?? '') === '--list') {
            echo json_encode($queue->pending(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        } elseif (($argv[1] ?? '') === '--ack') {
            $queue->acknowledge($argv[2] ?? '', $argv[3] ?? '');
        } else {
            throw new RuntimeException('Invalid command');
        }
    } finally {
        fclose($lock);
    }
} catch (Throwable) {
    fwrite(STDERR, "Notification queue unavailable.\n");
    exit(1);
}
