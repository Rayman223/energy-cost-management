<?php

declare(strict_types=1);

namespace App\Integration\EnergyId;

use DateTimeImmutable;

final class EnergyIdPayloadFactory
{
    /**
     * @param array<string,float|int> $metrics
     * @return array<string,float|int>
     */
    public function makePoint(string $timestamp, array $metrics): array
    {
        $point = ['ts' => $this->unixTs($timestamp)];

        foreach ($metrics as $key => $value) {
            $point[$key] = $value;
        }

        return $point;
    }

    public function unixTs(string $timestamp): int
    {
        // Le timestamp (issu de la base, heure murale locale) est interprété dans
        // le fuseau applicatif imposé par app/bootstrap.php (date_default_timezone_set
        // depuis la config). Ne pas exécuter ce code hors de ce bootstrap (#130 B6).
        return (new DateTimeImmutable($timestamp))->getTimestamp();
    }
}
