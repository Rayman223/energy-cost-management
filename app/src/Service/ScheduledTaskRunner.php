<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

final class ScheduledTaskRunner
{
    public function __construct(private readonly string $root)
    {
    }

    /** Wait for active tasks before Supervisor receives a stop signal. */
    public function waitUntilIdle(int $timeout = 300): void
    {
        $deadline = microtime(true) + $timeout;
        $locks = [];
        try {
            foreach (['dynamic-prices', 'load-profiles'] as $job) {
                $lock = fopen($this->root . '/var/scheduler/' . $job . '.lock', 'c');
                if ($lock === false) {
                    throw new RuntimeException('Cannot open task lock');
                }
                $locks[] = $lock;
                while (!flock($lock, LOCK_EX | LOCK_NB)) {
                    if (microtime(true) >= $deadline) {
                        throw new RuntimeException('Scheduled task drain timed out');
                    }
                    usleep(100000);
                }
            }
        } finally {
            foreach ($locks as $lock) {
                fclose($lock);
            }
        }
    }

    public function run(string $job): int
    {
        $script = match ($job) {
            'dynamic-prices' => 'cron_dynamic_prices.php',
            'load-profiles' => 'cron_load_profile_check.php',
            default => throw new RuntimeException('Unknown scheduled task'),
        };
        $lock = fopen($this->root . '/var/scheduler/' . $job . '.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot open task lock');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return 75;
        }
        try {
            if (is_file($this->root . '/var/cron-paused') || is_file($this->root . '/var/maintenance')) {
                return 75;
            }
            $log = $this->root . '/logs/' . $job . '.log';
            if (is_file($log) && filesize($log) > 1048576) {
                if (!@rename($log, $log . '.1')) {
                    fwrite(STDERR, "Task log rotation unavailable; continuing without the task log.\n");
                    $log = null;
                }
            }
            // Keep subprocess output out of User Scripts and notification payloads.
            $temp = tempnam($this->root . '/var/tmp', 'task-');
            if ($temp === false) {
                throw new RuntimeException('Cannot create task output');
            }
            try {
                $process = proc_open([PHP_BINARY, $this->root . '/app/scripts/' . $script], [
                    0 => ['file', '/dev/null', 'r'],
                    1 => ['file', $temp, 'a'],
                    2 => ['file', $temp, 'a'],
                ], $pipes, $this->root);
                if (!is_resource($process)) {
                    throw new RuntimeException('Cannot launch scheduled task');
                }
                $status = proc_close($process);
                $output = (string) file_get_contents($temp);
                if ($log !== null && @file_put_contents($log, gmdate('c') . ' exit=' . $status . "\n" . $output, FILE_APPEND) === false) {
                    fwrite(STDERR, "Task log unavailable; task result and notifications retained.\n");
                }
                $missing = [];
                if ($job === 'load-profiles' && $status === 2) {
                    preg_match_all('/^\[MISSING\]\s+([A-Za-z0-9_-]+)\s+([A-Z]{2})\s+(\d{4}-\d{2})\s/m', $output, $matches, PREG_SET_ORDER);
                    foreach ($matches as $match) {
                        $missing[] = $match[1] . '/' . $match[2] . ' ' . $match[3];
                    }
                }
                // Queue and relay both take this lock, preventing lost acknowledgements.
                $queueLock = fopen($this->root . '/var/notifications/queue.lock', 'c');
                if ($queueLock === false || !flock($queueLock, LOCK_EX)) {
                    throw new RuntimeException('Cannot lock notifications');
                }
                try {
                    try {
                        $config = require $this->root . '/app/bootstrap.php';
                    } catch (\Throwable) {
                        $config = [];
                    }
                    $url = \App\Support\ConfigUrl::httpUrl($config, 'seo', 'base_url') ?? '';
                    (new NotificationQueue($this->root . '/var/notifications'))->update($job, $status, $missing, $url);
                } finally {
                    fclose($queueLock);
                }
                return $status;
            } finally {
                unlink($temp);
            }
        } finally {
            fclose($lock);
        }
    }
}
