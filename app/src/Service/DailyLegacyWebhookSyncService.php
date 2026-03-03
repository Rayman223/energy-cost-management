<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\LegacyDailyRepository;
use DateTimeImmutable;

final class DailyLegacyWebhookSyncService
{
    public function __construct(
        private readonly LegacyDailyRepository $repository,
        private readonly EnergyIdPayloadFactory $payloadFactory,
        private readonly EnergyIdV2Client $energyIdClient,
        private readonly array $device,
    ) {
    }

    public function syncUntil(DateTimeImmutable $until): array
    {
        $reports = [];

        $hello = $this->energyIdClient->hello($this->device);
        if (!$hello['ok']) {
            return [['source' => 'provisioning', 'remoteId' => 'hello', 'result' => $hello]];
        }

        $session = $hello;
        $lastPostAt = null;

        $metrics = [
            [
                'source' => 'Data_Dries',
                'state_key' => 'prelevement-jour',
                'metric_key' => 'el.t1',
                'rows' => fn (DateTimeImmutable $from) => $this->repository->fetchDriesDailyFirstValues('Prelev_jour', $from, $until),
                'transform' => static fn (float $v): float => $v,
            ],
            [
                'source' => 'Data_Dries',
                'state_key' => 'prelevement-nuit',
                'metric_key' => 'el.t2',
                'rows' => fn (DateTimeImmutable $from) => $this->repository->fetchDriesDailyFirstValues('Prelev_nuit', $from, $until),
                'transform' => static fn (float $v): float => $v,
            ],
            [
                'source' => 'Data_Dries',
                'state_key' => 'injection-jour',
                'metric_key' => 'el-i.t1',
                'rows' => fn (DateTimeImmutable $from) => $this->repository->fetchDriesDailyFirstValues('Injec_jour', $from, $until),
                'transform' => static fn (float $v): float => $v,
            ],
            [
                'source' => 'Data_Dries',
                'state_key' => 'injection-nuit',
                'metric_key' => 'el-i.t2',
                'rows' => fn (DateTimeImmutable $from) => $this->repository->fetchDriesDailyFirstValues('Injec_nuit', $from, $until),
                'transform' => static fn (float $v): float => $v,
            ],
            [
                'source' => 'Data_Solaire',
                'state_key' => 'production-solaire',
                'metric_key' => 'pv',
                'rows' => fn (DateTimeImmutable $from) => $this->repository->fetchSolaireDailyFirstValues($from, $until),
                'transform' => static fn (float $v): float => round($v / 1000, 3),
            ],
        ];

        foreach ($metrics as $metric) {
            $from = $this->repository->getLastSentAt($metric['state_key']) ?? new DateTimeImmutable('1970-01-01 00:00:00');
            $rows = $metric['rows']($from);
            if ($rows === []) {
                continue;
            }

            $points = [];
            foreach ($rows as $row) {
                $points[] = $this->payloadFactory->makePoint(
                    $row['timestamp'],
                    [
                        $metric['metric_key'] => $metric['transform']((float) $row['value']),
                    ]
                );
            }

            $result = $this->postWithRetryAndPolicy($session, $points, $lastPostAt);
            $reports[] = ['source' => $metric['source'], 'remoteId' => $metric['metric_key'], 'result' => $result];

            if ($result['ok']) {
                $lastTs = new DateTimeImmutable(end($rows)['timestamp']);
                $this->repository->saveLastSentAt($metric['state_key'], $lastTs);
            }

            $lastPostAt = time();
        }

        return $reports;
    }

    /**
     * @param array{webhookUrl:string,headers:array<string,string>,uploadInterval:int} $session
     * @param array<int,array<string,float|int>> $points
     */
    private function postWithRetryAndPolicy(array &$session, array $points, ?int $lastPostAt): array
    {
        $this->waitUploadInterval($session['uploadInterval'], $lastPostAt);

        $first = $this->energyIdClient->postMeasurements($session['webhookUrl'], $session['headers'], $points);
        if ($first['ok']) {
            $first['attempts'] = 1;

            return $first;
        }

        if (in_array((int) $first['status'], [401, 404], true)) {
            $hello = $this->energyIdClient->hello($this->device);
            if ($hello['ok']) {
                $session = $hello;
            }
        }

        if ((int) $first['status'] === 429) {
            $retryAfter = (int) ($first['headers']['retry-after'] ?? 1);
            sleep(max(1, min($retryAfter, 300)));
        }

        $second = $this->energyIdClient->postMeasurements($session['webhookUrl'], $session['headers'], $points);
        $second['attempts'] = 2;

        return $second;
    }

    private function waitUploadInterval(int $uploadInterval, ?int $lastPostAt): void
    {
        if ($uploadInterval <= 0 || $lastPostAt === null) {
            return;
        }

        $elapsed = time() - $lastPostAt;
        $remaining = $uploadInterval - $elapsed;
        if ($remaining > 0) {
            sleep($remaining);
        }
    }
}
