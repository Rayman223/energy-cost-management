<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Service\SchedulerSchedule;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class SchedulerScheduleTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/ec-schedule-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testRestartDoesNotReplayClaimedSlotButNextPassStillRuns(): void
    {
        $schedule = new SchedulerSchedule($this->directory, new DateTimeZone('Europe/Brussels'));
        self::assertSame(['dynamic-prices'], $schedule->claim(new DateTimeImmutable('2026-11-11 12:30:00Z')));
        $restart = new SchedulerSchedule($this->directory, new DateTimeZone('Europe/Brussels'));
        self::assertSame([], $restart->claim(new DateTimeImmutable('2026-11-11 12:30:30Z')));
        self::assertSame([], $restart->claim(new DateTimeImmutable('2026-11-11 12:31:00Z')));
        self::assertSame(['dynamic-prices'], $restart->claim(new DateTimeImmutable('2026-11-11 17:30:00Z')));
    }

    public function testLocalSchedulesSurviveDaylightSavingChanges(): void
    {
        $schedule = new SchedulerSchedule($this->directory, new DateTimeZone('Europe/Brussels'));
        foreach (['2026-03-28 08:00:00Z', '2026-03-29 07:00:00Z', '2026-10-24 07:00:00Z', '2026-10-25 08:00:00Z'] as $date) {
            self::assertSame(['load-profiles'], $schedule->claim(new DateTimeImmutable($date)));
        }
    }

    public function testMissedSlotsAreNotReplayed(): void
    {
        $schedule = new SchedulerSchedule($this->directory, new DateTimeZone('UTC'));
        self::assertSame([], $schedule->claim(new DateTimeImmutable('2026-11-11 09:01:00Z')));
    }

    public function testCorruptStateStopsScheduling(): void
    {
        file_put_contents($this->directory . '/slots.json', '{broken');
        $schedule = new SchedulerSchedule($this->directory, new DateTimeZone('UTC'));
        $this->expectException(\JsonException::class);
        $schedule->claim(new DateTimeImmutable('2026-11-11 09:00:00Z'));
    }
}
