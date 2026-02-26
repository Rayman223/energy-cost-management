<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\EnergyReading;
use App\Infrastructure\HttpClient;
use App\Repository\EnergyRepository;

final class EnergyWebhookService
{
    public function __construct(
        private readonly EnergyRepository $repository,
        private readonly HttpClient $httpClient,
        private readonly string $webhookUrl,
        private readonly string $remoteId,
        private readonly string $remoteName,
        private readonly int $timeout = 15
    ) {
    }

    public function publishHourlyBatch(): array
    {
        $readings = $this->repository->fetchUnpublishedElectricityReadings();
        if ($readings === []) {
            return ['ok' => true, 'message' => 'No data to publish'];
        }

        $payload = $this->buildPayload($readings);
        $response = $this->httpClient->postJson($this->webhookUrl, $payload, $this->timeout);

        if ($response['ok']) {
            $lastTimestamp = end($readings)->timestamp;
            $this->repository->markAsPublished('electricity', $lastTimestamp);
        }

        return $response;
    }

    /** @param EnergyReading[] $readings */
    private function buildPayload(array $readings): array
    {
        $first = $readings[0];

        return [
            'remoteId' => $this->remoteId,
            'remoteName' => $this->remoteName,
            'metric' => $first->metric,
            'metricKind' => $first->metricKind,
            'unit' => $first->unit,
            'interval' => $first->interval,
            'data' => array_map(
                static fn (EnergyReading $reading): array => [$reading->timestampIso8601(), $reading->value],
                $readings
            ),
        ];
    }
}
