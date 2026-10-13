<?php

declare(strict_types=1);

namespace App\Infrastructure;

use RuntimeException;

/** Discord transport adapted from Battle-Conquest, using the existing HTTP client. */
class DiscordWebhook
{
    public const RETRY_AFTER_MAX_SECONDS = 10.0;

    public static function isValidUrl(string $url): bool
    {
        return preg_match(
            '#^https://(?:(?:ptb|canary)\.)?discord(?:app)?\.com/api/(?:v\d+/)?webhooks/\d+/[A-Za-z0-9_-]+$#D',
            $url,
        ) === 1;
    }

    /** @param array<string, mixed> $payload */
    public function post(string $url, array $payload): void
    {
        if (!self::isValidUrl($url)) {
            throw new RuntimeException('Invalid Discord release webhook URL.');
        }
        // wait=true requests confirmation that Discord has saved the message.
        $response = $this->send($url . '?wait=true', $payload);
        $retryAfter = self::retryAfter($response);
        if ($retryAfter !== null && $retryAfter <= self::RETRY_AFTER_MAX_SECONDS) {
            $this->pause($retryAfter);
            $response = $this->send($url . '?wait=true', $payload);
        }
        if (($response['ok'] ?? false) !== true) {
            // Neither the response body nor curl_error is logged: either may expose the token.
            throw new RuntimeException('Discord release delivery failed (HTTP ' . (int) ($response['status'] ?? 0) . ').');
        }
    }

    /**
     * Overridden by tests to avoid any real Discord requests.
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    protected function send(string $url, array $payload): array
    {
        return (new HttpClient())->postJson($url, $payload, 10);
    }

    protected function pause(float $seconds): void
    {
        usleep((int) ceil($seconds * 1_000_000));
    }

    /** @param array<string, mixed> $response */
    private static function retryAfter(array $response): ?float
    {
        if (($response['status'] ?? 0) !== 429 || ($response['error'] ?? '') !== '') {
            return null;
        }
        $data = json_decode((string) ($response['body'] ?? ''), true);
        $seconds = is_array($data) ? ($data['retry_after'] ?? null) : null;

        return is_numeric($seconds) && is_finite((float) $seconds) && (float) $seconds >= 0 ? (float) $seconds : null;
    }
}
