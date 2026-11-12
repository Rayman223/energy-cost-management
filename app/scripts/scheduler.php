<?php

declare(strict_types=1);

use App\Service\SchedulerSchedule;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = dirname(__DIR__, 2);
$timezone = getenv('EC_SCHEDULER_TZ');
if ($timezone === false || !in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
    fwrite(STDERR, "EC_SCHEDULER_TZ must be an explicit IANA timezone (the former Unraid cron timezone).\n");
    exit(1);
}
$schedule = new SchedulerSchedule($root . '/var/scheduler', new DateTimeZone($timezone));
/** @var list<resource> $children */
$children = [];
// Supervisor stops the whole process group, including active PHP tasks.
while (true) {
    // This process lives across deployments; do not cache maintenance markers.
    clearstatcache();
    foreach ($children as $key => $child) {
        $status = proc_get_status($child);
        if (!$status['running']) {
            $code = $status['exitcode'];
            proc_close($child);
            unset($children[$key]);
            if ($code !== 0 && $code !== 2 && $code !== 75) {
                fwrite(STDERR, "Scheduled task failed; inspect private task logs.\n");
            }
        }
    }
    touch($root . '/var/scheduler/heartbeat');
    if (!is_file($root . '/var/maintenance') && !is_file($root . '/var/cron-paused')) {
        try {
            foreach ($schedule->claim(new DateTimeImmutable('now')) as $job) {
                $child = proc_open([PHP_BINARY, $root . '/app/scripts/run_scheduled_task.php', $job], [
                    0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR,
                ], $pipes, $root);
                if (!is_resource($child)) {
                    throw new RuntimeException('Cannot launch task runner');
                }
                $children[] = $child;
            }
        } catch (Throwable) {
            // An unreadable state must make health fail, rather than replay slots.
            fwrite(STDERR, "Scheduler state unavailable.\n");
            exit(1);
        }
    }
    sleep(5);
}
