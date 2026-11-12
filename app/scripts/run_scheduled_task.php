<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

try {
    exit((new App\Service\ScheduledTaskRunner(dirname(__DIR__, 2)))->run($argv[1] ?? ''));
} catch (Throwable) {
    fwrite(STDERR, "Scheduled task failed; inspect private configuration and logs.\n");
    exit(1);
}
