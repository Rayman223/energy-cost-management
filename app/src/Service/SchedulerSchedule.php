<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/** Persistent claim per local calendar slot; no replay of missed slots. */
final class SchedulerSchedule
{
    public function __construct(private readonly string $directory, private readonly DateTimeZone $timezone)
    {
    }

    /** @return list<string> */
    public function claim(DateTimeImmutable $now): array
    {
        $local = $now->setTimezone($this->timezone);
        $job = match ($local->format('H:i')) {
            '13:30', '18:30' => 'dynamic-prices',
            '09:00' => 'load-profiles',
            default => null,
        };
        if ($job === null) {
            return [];
        }
        $lock = fopen($this->directory . '/schedule.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Cannot lock scheduler state');
        }
        try {
            $path = $this->directory . '/slots.json';
            $state = is_file($path) ? json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [];
            if (!is_array($state)) {
                throw new RuntimeException('Invalid scheduler state');
            }
            $slot = $local->format('Y-m-d H:i');
            if (($state[$job] ?? null) === $slot) {
                return [];
            }
            $state[$job] = $slot;
            self::writeJson($path, $state);
            return [$job];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<mixed> $state */
    public static function writeJson(string $path, array $state): void
    {
        $temp = tempnam(dirname($path), '.state-');
        if ($temp === false) {
            throw new RuntimeException('Cannot create state');
        }
        try {
            if (file_put_contents($temp, json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) === false
                || !chmod($temp, 0640) || !rename($temp, $path)) {
                throw new RuntimeException('Cannot persist state');
            }
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }
}
