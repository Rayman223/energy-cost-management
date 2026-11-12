<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Service\NotificationQueue;
use PHPUnit\Framework\TestCase;

final class NotificationQueueTest extends TestCase
{
    private string $directory;
    private NotificationQueue $queue;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/ec-notify-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->directory .= '/notifications';
        mkdir($this->directory);
        $this->queue = new NotificationQueue($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        foreach (glob(dirname($this->directory) . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir(dirname($this->directory));
    }

    public function testFailedRelayRemainsPendingAndAckPreventsDuplicates(): void
    {
        $this->queue->update('load-profiles', 2, ['RLP0N/BE 2026-09'], 'https://energy-cost.eu');
        $item = $this->queue->pending()[0];
        $this->queue->update('load-profiles', 2, ['RLP0N/BE 2026-09'], 'https://energy-cost.eu');
        self::assertSame($item['id'], $this->queue->pending()[0]['id']);
        $this->queue->acknowledge('load-profiles', (string) $item['id']);
        $this->queue->update('load-profiles', 2, ['RLP0N/BE 2026-09'], 'https://energy-cost.eu');
        self::assertSame([], $this->queue->pending());
        $this->queue->update('load-profiles', 0, [], '');
        $this->queue->update('load-profiles', 2, ['RLP0N/BE 2026-09'], '');
        self::assertCount(1, $this->queue->pending());
    }

    public function testStaleAckCannotClearANewerAlert(): void
    {
        $this->queue->update('load-profiles', 2, ['RLP0N/BE 2026-09'], '');
        $old = $this->queue->pending()[0];
        $this->queue->update('load-profiles', 2, ['RLP0N/BE 2026-09', 'RLP0N/BE 2026-10'], '');
        $this->queue->acknowledge('load-profiles', (string) $old['id']);
        self::assertCount(1, $this->queue->pending());
    }

    public function testLegacyAndTransientErrorsPreserveMonthlyDeduplication(): void
    {
        file_put_contents(dirname($this->directory) . '/energy-load-profile.notified', "RLP0N BE 2026-09\n");
        $this->queue->update('load-profiles', 2, ['RLP0N/BE 2026-09'], '');
        self::assertSame([], $this->queue->pending());
        $this->queue->update('load-profiles', 1, [], '');
        self::assertSame('error', $this->queue->pending()[0]['kind']);
        $this->queue->update('load-profiles', 2, ['RLP0N/BE 2026-09'], '');
        self::assertSame([], $this->queue->pending());
        $this->queue->update('load-profiles', 2, ['RLP0N/BE 2026-09', 'RLP0N/BE 2026-10'], '');
        self::assertSame(['RLP0N/BE 2026-10'], $this->queue->pending()[0]['labels']);
    }

    public function testErrorNotifiedOnceUntilRecovery(): void
    {
        $this->queue->update('dynamic-prices', 1, [], '');
        $item = $this->queue->pending()[0];
        $this->queue->acknowledge('dynamic-prices', (string) $item['id']);
        $this->queue->update('dynamic-prices', 1, [], '');
        self::assertSame([], $this->queue->pending());
        $this->queue->update('dynamic-prices', 0, [], '');
        $this->queue->update('dynamic-prices', 1, [], '');
        self::assertCount(1, $this->queue->pending());
    }
}
