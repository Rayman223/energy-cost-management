<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

try {
    (new App\Service\ScheduledTaskRunner(dirname(__DIR__, 2)))->waitUntilIdle();
} catch (Throwable) {
    fwrite(STDERR, "Scheduled tasks did not drain; maintenance retained.\n");
    exit(1);
}
