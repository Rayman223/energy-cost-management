<?php

declare(strict_types=1);

namespace App\Integration\EnergyId;

/**
 * Fusionne les 5 jeux de relevés journaliers électricité (4 index Dries
 * jour/nuit import/injection + production solaire) par date calendaire, puis
 * assemble les points de mesure EnergyID triés par date croissante.
 *
 * Logique pure (hors HTTP/BDD) extraite de DailyLegacyWebhookSyncService pour
 * être testée indépendamment.
 */
final class ElectricityReadingMerger
{
    /** @var list<string> */
    private const METRIC_KEYS = ['el.t1', 'el.t2', 'el-i.t1', 'el-i.t2', 'pv'];

    public function __construct(private readonly EnergyIdPayloadFactory $payloadFactory)
    {
    }

    /**
     * @param array<int, array{timestamp: string, value: string}> $driesT1
     * @param array<int, array{timestamp: string, value: string}> $driesT2
     * @param array<int, array{timestamp: string, value: string}> $driesI1
     * @param array<int, array{timestamp: string, value: string}> $driesI2
     * @param array<int, array{timestamp: string, value: string}> $solar
     */
    public function build(array $driesT1, array $driesT2, array $driesI1, array $driesI2, array $solar): ElectricityPoints
    {
        $byDate = [];

        foreach ($driesT1 as $row) {
            $date = substr($row['timestamp'], 0, 10);
            $byDate[$date]['ts']    = $byDate[$date]['ts'] ?? $row['timestamp'];
            $byDate[$date]['el.t1'] = (float) $row['value'];
        }
        foreach ($driesT2 as $row) {
            $date = substr($row['timestamp'], 0, 10);
            $byDate[$date]['ts']    = $byDate[$date]['ts'] ?? $row['timestamp'];
            $byDate[$date]['el.t2'] = (float) $row['value'];
        }
        foreach ($driesI1 as $row) {
            $date = substr($row['timestamp'], 0, 10);
            $byDate[$date]['ts']      = $byDate[$date]['ts'] ?? $row['timestamp'];
            $byDate[$date]['el-i.t1'] = (float) $row['value'];
        }
        foreach ($driesI2 as $row) {
            $date = substr($row['timestamp'], 0, 10);
            $byDate[$date]['ts']      = $byDate[$date]['ts'] ?? $row['timestamp'];
            $byDate[$date]['el-i.t2'] = (float) $row['value'];
        }
        foreach ($solar as $row) {
            $date = substr($row['timestamp'], 0, 10);
            $byDate[$date]['ts'] = $byDate[$date]['ts'] ?? $row['timestamp'];
            $byDate[$date]['pv'] = round((float) $row['value'] / 1000, 3);
        }

        if ($byDate === []) {
            return ElectricityPoints::empty();
        }

        ksort($byDate);

        $points = [];
        foreach ($byDate as $data) {
            $point = ['ts' => $this->payloadFactory->unixTs($data['ts'])];
            foreach (self::METRIC_KEYS as $key) {
                if (isset($data[$key])) {
                    $point[$key] = $data[$key];
                }
            }
            $points[] = $point;
        }

        $lastDate = (string) array_key_last($byDate);

        return new ElectricityPoints(
            $points,
            (string) array_key_first($byDate),
            $lastDate,
            (string) $byDate[$lastDate]['ts'],
        );
    }
}
