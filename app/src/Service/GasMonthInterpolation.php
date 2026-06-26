<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Résultat de l'interpolation linéaire de la consommation gaz sur un mois
 * calendaire (voir GasMonthInterpolator). Soit indisponible (avec une raison),
 * soit les valeurs interpolées + métadonnées de couverture.
 */
final class GasMonthInterpolation
{
    private function __construct(
        public readonly bool $available,
        public readonly ?string $reason,
        public readonly float $monthlyM3,
        public readonly int $days,
        public readonly int $calendarDays,
        public readonly bool $isFull,
        public readonly bool $interpolated,
        public readonly string $monthStart,
        public readonly string $monthEnd,
    ) {
    }

    public static function unavailable(string $reason): self
    {
        return new self(false, $reason, 0.0, 0, 0, false, false, '', '');
    }

    public static function of(
        float $monthlyM3,
        int $days,
        int $calendarDays,
        bool $isFull,
        bool $interpolated,
        string $monthStart,
        string $monthEnd,
    ): self {
        return new self(true, null, $monthlyM3, $days, $calendarDays, $isFull, $interpolated, $monthStart, $monthEnd);
    }
}
