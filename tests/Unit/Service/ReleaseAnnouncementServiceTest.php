<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Infrastructure\DiscordWebhook;
use App\Repository\Contract\ReleaseAnnouncementRepositoryInterface;
use App\Service\ReleaseAnnouncementService;
use App\Service\ReleaseNotesManifest;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ReleaseAnnouncementServiceTest extends TestCase
{
    private const WEBHOOK = 'https://discord.com/api/webhooks/123/secret-token';
    private string $directory;
    private MemoryReleaseReceipts $repository;
    private RecordingReleaseWebhook $client;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/energy-announcement-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->repository = new MemoryReleaseReceipts();
        $this->client = new RecordingReleaseWebhook();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory);
    }

    public function testStatusIsReadOnlyAndDoesNotTakeALock(): void
    {
        $this->manifest('1.0.1');
        $status = $this->service()->run($this->directory, '1.0.1');
        self::assertSame(['status' => 'pending', 'versions' => ['1.0.1']], $status['languages']['en']);
        self::assertSame([], $this->repository->sent);
        self::assertSame(0, $this->repository->locks);
        self::assertSame([], $this->client->payloads);
    }

    public function testPublicationAggregatesPendingVersionsAndDoesNotResendThem(): void
    {
        $this->manifest('1.0.0', false);
        $this->manifest('1.0.1');
        $this->manifest('1.0.2');
        $this->manifest('1.0.3');
        $result = $this->service()->run($this->directory, '1.0.2', true);
        self::assertTrue($result['ok']);
        self::assertSame(['status' => 'sent', 'versions' => ['1.0.1', '1.0.2']], $result['languages']['en']);
        self::assertSame(['1.0.1', '1.0.2'], $this->repository->sent);
        self::assertCount(1, $this->client->payloads);
        $embed = $this->client->payloads[0]['embeds'][0];
        self::assertSame('Updates from version 1.0.1 to 1.0.2', $embed['title']);
        self::assertLessThan(strpos($embed['description'], '**Version 1.0.1**'), strpos($embed['description'], '**Version 1.0.2**'));
        self::assertStringNotContainsString('1.0.3', $embed['description']);
        self::assertSame(['parse' => []], $this->client->payloads[0]['allowed_mentions']);
        self::assertSame('up_to_date', $this->service()->run($this->directory, '1.0.2', true)['languages']['en']['status']);
        self::assertCount(1, $this->client->payloads);
    }

    public function testFailedDeliveryLeavesVersionsPendingForRetryAndRedactsSecrets(): void
    {
        $this->manifest('1.0.1');
        $this->client->fail = true;
        $result = $this->service()->run($this->directory, '1.0.1', true);
        self::assertFalse($result['ok']);
        self::assertSame([], $this->repository->sent);
        self::assertStringNotContainsString('secret-token', $result['languages']['en']['error']);
        $this->client->fail = false;
        self::assertTrue($this->service()->run($this->directory, '1.0.1', true)['ok']);
        self::assertSame(['1.0.1'], $this->repository->sent);
    }

    public function testPublisherRechecksReceiptsAfterAcquiringLock(): void
    {
        $this->manifest('1.0.1');
        $this->repository->concurrentReceipt = '1.0.1';
        $result = $this->service()->run($this->directory, '1.0.1', true);
        self::assertSame('up_to_date', $result['languages']['en']['status']);
        self::assertSame([], $this->client->payloads);
        self::assertSame(1, $this->repository->locks);
    }

    public function testDisabledWebhookPreservesPendingVersions(): void
    {
        $this->manifest('1.0.1');
        $result = $this->service('')->run($this->directory, '1.0.1', true);
        self::assertTrue($result['ok']);
        self::assertSame(['status' => 'disabled', 'versions' => ['1.0.1']], $result['languages']['en']);
        self::assertSame([], $this->repository->sent);
        self::assertSame([], $this->client->payloads);
    }

    public function testTechnicalReleaseIsPublishedRegardlessOfLegacyImpactFlag(): void
    {
        $this->manifest('1.0.1', false, "**Maintenance**\n- Update dependencies, CI and tests.");
        $result = $this->service()->run($this->directory, '1.0.1', true);
        self::assertSame(['status' => 'sent', 'versions' => ['1.0.1']], $result['languages']['en']);
        self::assertStringContainsString('Update dependencies, CI and tests.', $this->client->payloads[0]['embeds'][0]['description']);
    }

    public function testInvalidWebhookIsAnErrorAndDoesNotSend(): void
    {
        $this->manifest('1.0.1');
        self::assertFalse($this->service('https://example.com')->run($this->directory, '1.0.1', true)['ok']);
        self::assertSame([], $this->client->payloads);
    }

    public function testMissingInstalledManifestStillPublishesOlderPendingVersions(): void
    {
        $this->manifest('1.0.1');
        $result = $this->service()->run($this->directory, '1.0.2', true);
        self::assertTrue($result['ok']);
        self::assertSame(ReleaseNotesManifest::MISSING_WARNING, $result['warning']);
        self::assertSame(['1.0.1'], $this->repository->sent);
    }

    public function testMalformedDeliveredManifestBlocksAllPublicationButFutureOneDoesNot(): void
    {
        $this->manifest('1.0.1');
        file_put_contents($this->directory . '/1.0.2.json', '{bad');
        self::assertTrue($this->service()->run($this->directory, '1.0.1')['ok']);
        $this->expectException(\JsonException::class);
        try {
            $this->service()->run($this->directory, '1.0.2', true);
        } finally {
            self::assertSame([], $this->repository->sent);
            self::assertSame([], $this->client->payloads);
        }
    }

    public function testLongNotesArePublishedCompletelyIncludingOlderVersions(): void
    {
        $content = str_repeat("- Dependency and CI updates 🌍\n", 400) . '- Final change.';
        $payloads = ReleaseAnnouncementService::payloads([
            ['version' => '1.0.2', 'title' => 'Version 1.0.2', 'content' => $content],
            ['version' => '1.0.1', 'title' => 'Version 1.0.1', 'content' => 'Older changes.'],
        ]);
        self::assertGreaterThan(1, count($payloads));
        $complete = '';
        foreach ($payloads as $index => $payload) {
            $embed = $payload['embeds'][0];
            self::assertSame('Part ' . ($index + 1) . '/' . count($payloads), $embed['footer']['text']);
            self::assertLessThanOrEqual(4096, ReleaseNotesManifest::length($embed['description']));
            self::assertLessThanOrEqual(6000, ReleaseNotesManifest::length($embed['title'] . $embed['description'] . $embed['footer']['text']));
            self::assertStringEndsWith('/tree/main/releases)', $embed['description']);
            self::assertTrue(mb_check_encoding($embed['description'], 'UTF-8'));
            self::assertSame(['parse' => []], $payload['allowed_mentions']);
            $complete .= explode("\n\n[Full release notes]", $embed['description'])[0];
        }
        self::assertSame("**Version 1.0.2**\n" . $content . "\n\n**Version 1.0.1**\nOlder changes.", $complete);
    }

    public function testSplitCodeFencesPreserveEveryEmoji(): void
    {
        $payloads = ReleaseAnnouncementService::payloads([
            ['version' => '1.0.1', 'title' => 'Version 1.0.1', 'content' => "```text\n" . str_repeat('🌍', 10_000) . "\n```\nLast change."],
        ]);
        $emojiCount = 0;
        foreach ($payloads as $payload) {
            $description = $payload['embeds'][0]['description'];
            self::assertLessThanOrEqual(4096, ReleaseNotesManifest::length($description));
            self::assertSame(0, substr_count($description, '```') % 2);
            self::assertTrue(mb_check_encoding($description, 'UTF-8'));
            $emojiCount += substr_count($description, '🌍');
        }
        self::assertSame(10_000, $emojiCount);
        self::assertStringContainsString('Last change.', $description);
    }

    public function testPartialDeliveryDoesNotMarkIncompleteNotesAsSent(): void
    {
        $this->manifest('1.0.1', true, str_repeat("- Technical change.\n", 500));
        $this->client->failOnPost = 2;
        $result = $this->service()->run($this->directory, '1.0.1', true);
        self::assertFalse($result['ok']);
        self::assertCount(1, $this->client->payloads);
        self::assertSame([], $this->repository->sent);
        self::assertSame('pending', $this->service()->run($this->directory, '1.0.1')['languages']['en']['status']);

        $this->client->failOnPost = null;
        self::assertSame('sent', $this->service()->run($this->directory, '1.0.1', true)['languages']['en']['status']);
        self::assertSame(['1.0.1'], $this->repository->sent);
        $posts = count($this->client->payloads);
        self::assertSame('up_to_date', $this->service()->run($this->directory, '1.0.1', true)['languages']['en']['status']);
        self::assertCount($posts, $this->client->payloads);
    }

    private function service(string $webhook = self::WEBHOOK): ReleaseAnnouncementService
    {
        return new ReleaseAnnouncementService($this->repository, $this->client, $webhook);
    }

    private function manifest(string $version, bool $impact = true, string $content = 'Useful changes.'): void
    {
        file_put_contents($this->directory . '/' . $version . '.json', json_encode([
            'version' => $version, 'user_impact' => $impact,
            'announcements' => $version === '1.0.0' && !$impact ? [] : ['en' => ['title' => 'Version ' . $version, 'content' => $content]],
        ], JSON_THROW_ON_ERROR));
    }
}

final class MemoryReleaseReceipts implements ReleaseAnnouncementRepositoryInterface
{
    public array $sent = [];
    public int $locks = 0;
    public ?string $concurrentReceipt = null;

    public function sentVersions(): array
    {
        return $this->sent;
    }

    public function markSent(array $versions): void
    {
        $this->sent = array_merge($this->sent, $versions);
    }

    public function withPublishLock(callable $publish): array
    {
        ++$this->locks;
        if ($this->concurrentReceipt !== null) {
            $this->sent[] = $this->concurrentReceipt;
        }

        return $publish();
    }
}

final class RecordingReleaseWebhook extends DiscordWebhook
{
    public array $payloads = [];
    public bool $fail = false;
    public ?int $failOnPost = null;

    public function post(string $url, array $payload): void
    {
        if ($this->fail || $this->failOnPost === count($this->payloads) + 1) {
            throw new RuntimeException('Request failed: ' . $url);
        }
        $this->payloads[] = $payload;
    }
}
