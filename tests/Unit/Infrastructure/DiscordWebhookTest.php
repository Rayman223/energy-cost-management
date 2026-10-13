<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Infrastructure\DiscordWebhook;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DiscordWebhookTest extends TestCase
{
    private const URL = 'https://discord.com/api/webhooks/123/secret-token';

    #[DataProvider('urls')]
    public function testUrlValidation(string $url, bool $valid): void
    {
        self::assertSame($valid, DiscordWebhook::isValidUrl($url));
    }

    public static function urls(): iterable
    {
        yield [self::URL, true];
        yield ['https://canary.discord.com/api/v10/webhooks/123/abc_123', true];
        yield ['https://discordapp.com/api/webhooks/123/abc-123', true];
        foreach (['http://discord.com/api/webhooks/123/token', 'https://discord.com.evil.test/api/webhooks/123/token',
            'https://discord.com@evil.test/api/webhooks/123/token', self::URL . '?wait=true',
            self::URL . '/messages/1', 'https://localhost/api/webhooks/123/token', self::URL . "\n"] as $url) {
            yield [$url, false];
        }
    }

    public function testRateLimitWaitsOnceAndRequestsConfirmation(): void
    {
        $client = $this->client([
            ['ok' => false, 'status' => 429, 'body' => '{"retry_after":0.25}'],
            ['ok' => true, 'status' => 200],
        ]);
        $client->post(self::URL, ['content' => 'Release']);
        self::assertSame([0.25], $client->waits);
        self::assertSame([self::URL . '?wait=true', self::URL . '?wait=true'], $client->urls);
    }

    #[DataProvider('failedResponses')]
    public function testFailuresNeverRevealTheWebhookToken(array $responses, int $requests): void
    {
        $client = $this->client($responses);
        try {
            $client->post(self::URL, ['content' => 'Release']);
            self::fail('Expected delivery failure.');
        } catch (RuntimeException $exception) {
            self::assertStringNotContainsString('secret-token', $exception->getMessage());
            self::assertCount($requests, $client->urls);
        }
    }

    public static function failedResponses(): iterable
    {
        yield 'network failure' => [[['ok' => false, 'status' => 0, 'error' => self::URL]], 1];
        yield 'HTTP error' => [[['ok' => false, 'status' => 400, 'body' => self::URL]], 1];
        yield 'wait too long' => [[['ok' => false, 'status' => 429, 'body' => '{"retry_after":11}']], 1];
        yield 'missing delay' => [[['ok' => false, 'status' => 429, 'body' => '{}']], 1];
        yield 'negative delay' => [[['ok' => false, 'status' => 429, 'body' => '{"retry_after":-1}']], 1];
        yield 'second limit' => [[
            ['ok' => false, 'status' => 429, 'body' => '{"retry_after":0}'],
            ['ok' => false, 'status' => 429, 'body' => '{"retry_after":0}'],
        ], 2];
    }

    public function testInvalidUrlIsRejectedBeforeAnyRequest(): void
    {
        $client = $this->client([]);
        try {
            $client->post('https://example.com', []);
            self::fail('Expected invalid URL.');
        } catch (RuntimeException) {
            self::assertSame([], $client->urls);
        }
    }

    private function client(array $responses): FakeDiscordWebhook
    {
        return new FakeDiscordWebhook($responses);
    }
}

final class FakeDiscordWebhook extends DiscordWebhook
{
    public array $urls = [];
    public array $waits = [];

    public function __construct(private array $responses)
    {
    }

    protected function send(string $url, array $payload): array
    {
        $this->urls[] = $url;

        return array_shift($this->responses) ?? ['ok' => false, 'status' => 0];
    }

    protected function pause(float $seconds): void
    {
        $this->waits[] = $seconds;
    }
}
