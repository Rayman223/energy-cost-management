<?php

declare(strict_types=1);

namespace App\Service\Import;

use DateTimeImmutable;

/**
 * Résultat de l'analyse d'un CSV de profil de charge (#93, #101) : les poids prêts à
 * écrire, et de quoi juger le fichier AVANT de l'écrire — nombre de points, bornes,
 * lignes rejetées ou agrégées, statistiques des poids.
 *
 * Ces indicateurs sont ce qui permet de repérer à l'œil le piège du fichier de poids
 * MENSUELS de Synergrid (12 valeurs par an) importé à la place des coefficients
 * quart-horaires : quelques points au lieu de ~2 900 par mois.
 */
final class LoadProfileParseResult
{
    /**
     * @param list<array{slot_start: DateTimeImmutable, fraction: float}> $weights Triés par créneau.
     */
    public function __construct(
        public readonly array $weights,
        public readonly int $resolutionMin,
        public readonly int $rejected,
        public readonly int $merged,
    ) {
    }

    public function count(): int
    {
        return count($this->weights);
    }

    /** Premier créneau (UTC, 'Y-m-d H:i:00'). */
    public function firstSlot(): string
    {
        return $this->weights[0]['slot_start']->format('Y-m-d H:i:00');
    }

    /** Dernier créneau (UTC, 'Y-m-d H:i:00'). */
    public function lastSlot(): string
    {
        return $this->weights[count($this->weights) - 1]['slot_start']->format('Y-m-d H:i:00');
    }

    public function sum(): float
    {
        return array_sum(array_column($this->weights, 'fraction'));
    }

    public function min(): float
    {
        return min(array_column($this->weights, 'fraction'));
    }

    public function max(): float
    {
        return max(array_column($this->weights, 'fraction'));
    }

    /**
     * Mois (UTC, 'Y-m') touchés par le fichier, avec leur nombre de points.
     *
     * @return array<string, int>
     */
    public function pointsByMonth(): array
    {
        $months = [];
        foreach ($this->weights as $weight) {
            $month          = $weight['slot_start']->format('Y-m');
            $months[$month] = ($months[$month] ?? 0) + 1;
        }

        return $months;
    }
}
