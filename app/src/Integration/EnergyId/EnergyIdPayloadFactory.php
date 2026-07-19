<?php

declare(strict_types=1);

namespace App\Integration\EnergyId;

use DateTimeImmutable;
use DateTimeZone;

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
        // Le timestamp est issu de la base, où les dates sont stockées en UTC. On
        // l'interprète donc explicitement en UTC (indépendamment du fuseau PHP par
        // défaut) avant conversion en epoch, pour un instant absolu déterministe.
        return (new DateTimeImmutable($timestamp, new DateTimeZone('UTC')))->getTimestamp();
    }
}
