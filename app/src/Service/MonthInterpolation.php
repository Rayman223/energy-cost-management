<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Résultat de l'interpolation linéaire de la consommation d'un compteur sur un
 * mois calendaire (voir MonthlyConsumptionInterpolator). Soit indisponible (avec
 * une raison), soit l'index théorique à minuit aux deux bornes + métadonnées.
 *
 * `startKind` / `endKind` qualifient chaque borne :
 *   - 'exact'        : un relevé tombe pile à minuit ;
 *   - 'interpolated' : minuit est encadré par deux relevés (règle de trois) ;
 *   - 'extrapolated' : minuit est hors de la plage des relevés (pente du segment
 *                      de bord). `isProjection` = true uniquement quand la borne de
 *                      FIN est extrapolée en avant (mois en cours projeté).
 */
final class MonthInterpolation
{
    private function __construct(
        public readonly bool $available,
        public readonly ?string $reason,
        public readonly float $indexStart,
        public readonly float $indexEnd,
        public readonly float $monthlyDelta,
        public readonly int $days,
        public readonly int $calendarDays,
        public readonly string $monthStart,
        public readonly string $monthEnd,
        public readonly string $startKind,
        public readonly string $endKind,
        public readonly bool $isProjection,
    ) {
    }

    public static function unavailable(string $reason): self
    {
        return new self(false, $reason, 0.0, 0.0, 0.0, 0, 0, '', '', '', '', false);
    }

    public static function of(
        float $indexStart,
        float $indexEnd,
        float $monthlyDelta,
        int $days,
        int $calendarDays,
        string $monthStart,
        string $monthEnd,
        string $startKind,
        string $endKind,
        bool $isProjection,
    ): self {
        return new self(
            true,
            null,
            $indexStart,
            $indexEnd,
            $monthlyDelta,
            $days,
            $calendarDays,
            $monthStart,
            $monthEnd,
            $startKind,
            $endKind,
            $isProjection,
        );
    }
}
