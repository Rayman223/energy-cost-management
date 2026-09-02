<?php

declare(strict_types=1);

namespace Tests\Fake;

use App\Domain\ReadingGranularity;
use App\Repository\Contract\BatteryIngestionInterface;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Sink d'index de batterie en mémoire (#26).
 *
 * Reproduit les deux comportements dont dépend l'import : un compteur encore
 * vide à un horodatage est COMPLÉTÉ par une seconde écriture, un compteur déjà
 * renseigné ne l'est pas — sauf en mode écrasement.
 */
final class FakeBatteryIngestion implements BatteryIngestionInterface
{
    /** @var list<array{timestamp: string, indexes: array<string, float>}> */
    public array $calls = [];

    /** @var array<string, array<string, float>> timestamp => compteur => index */
    public array $stored = [];

    public function insertIndexes(DateTimeImmutable $timestamp, array $indexByKind, bool $replace = false): int
    {
        $at = $timestamp->format('Y-m-d H:i:s');
        $this->calls[] = ['timestamp' => $at, 'indexes' => $indexByKind];

        $written = 0;
        foreach ($indexByKind as $kind => $value) {
            if (!isset($this->stored[$at][$kind]) || $replace) {
                $this->stored[$at][$kind] = $value;
                $written++;
            }
        }

        return $written;
    }

    /**
     * @param list<string> $kinds
     * @return array<string, array{min: float|null, max: float|null, exists: bool}>
     */
    public function readingBounds(DateTimeImmutable $timestamp, array $kinds): array
    {
        $at     = $timestamp->format('Y-m-d H:i:s');
        $bounds = [];

        foreach ($kinds as $kind) {
            $min = null;
            $max = null;
            foreach ($this->stored as $storedAt => $values) {
                if (!isset($values[$kind])) {
                    continue;
                }
                if ($storedAt < $at && ($min === null || $storedAt > $min[0])) {
                    $min = [$storedAt, $values[$kind]];
                }
                if ($storedAt > $at && ($max === null || $storedAt < $max[0])) {
                    $max = [$storedAt, $values[$kind]];
                }
            }

            $bounds[$kind] = [
                'min'    => $min[1] ?? null,
                'max'    => $max[1] ?? null,
                'exists' => isset($this->stored[$at][$kind]),
            ];
        }

        return $bounds;
    }

    public function readingPresentInDay(DateTimeImmutable $timestamp, string $timezone): bool
    {
        [$start, $end] = ReadingGranularity::Day->bucket($timestamp, new DateTimeZone($timezone));
        $at = $timestamp->format('Y-m-d H:i:s');

        foreach (array_keys($this->stored) as $storedAt) {
            if ($storedAt !== $at
                && $storedAt >= $start->format('Y-m-d H:i:s')
                && $storedAt < $end->format('Y-m-d H:i:s')
            ) {
                return true;
            }
        }

        return false;
    }
}
