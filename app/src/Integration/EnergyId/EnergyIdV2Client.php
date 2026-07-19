<?php

declare(strict_types=1);

namespace App\Integration\EnergyId;

use App\Infrastructure\HttpClient;

final class EnergyIdV2Client
{
    private const HELLO_ENDPOINT = 'https://hooks.energyid.eu/hello';

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $provisioningKey,
        private readonly string $provisioningSecret,
        private readonly int $timeout = 15
    ) {
    }

    /**
     * @param array<string, mixed> $device
     * @return array<string, mixed>
     */
    public function hello(array $device): array
    {
        $result = $this->http->postJson(
            self::HELLO_ENDPOINT,
            $device,
            $this->timeout,
            [
                'X-Provisioning-Key' => $this->provisioningKey,
                'X-Provisioning-Secret' => $this->provisioningSecret,
            ]
        );

        if (!$result['ok']) {
            return ['ok' => false, 'type' => 'http_error', 'response' => $result];
        }

        try {
            $json = json_decode($result['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['ok' => false, 'type' => 'invalid_json', 'response' => $result];
        }

        if (isset($json['claimCode'], $json['claimUrl'])) {
            return [
                'ok' => false,
                'type' => 'not_claimed',
                'claimCode' => (string) $json['claimCode'],
                'claimUrl' => (string) $json['claimUrl'],
                'exp' => (int) ($json['exp'] ?? 0),
            ];
        }

        if (!isset($json['webhookUrl'], $json['headers']) || !is_array($json['headers'])) {
            return ['ok' => false, 'type' => 'invalid_hello_response', 'raw' => $json];
        }

        return [
            'ok' => true,
            'webhookUrl' => (string) $json['webhookUrl'],
            'headers' => array_map(static fn ($v): string => (string) $v, $json['headers']),
            'uploadInterval' => (int) ($json['webhookPolicy']['uploadInterval'] ?? 0),
        ];
    }

    /**
     * @param array<string,string> $headers
     * @param array<array-key, mixed> $payload  Liste de mesures (ou objet JSON).
     * @return array<string, mixed>
     */
    public function postMeasurements(string $webhookUrl, array $headers, array $payload): array
    {
        return $this->http->postJson($webhookUrl, $payload, $this->timeout, $headers);
    }
}
