<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

/**
 * Sous-période homogène d'une période de calcul : l'intervalle de jours pendant
 * lequel une seule grille tarifaire s'applique (cf. #196, proration multi-grilles).
 *
 * Les bornes sont des jours à minuit et sont INCLUSES des deux côtés : un segment
 * du 1er au 15 janvier couvre 15 jours. La somme des `days` de tous les segments
 * d'une période vaut exactement le nombre de jours de cette période, de sorte que
 * la répartition au prorata ne perde ni ne duplique de consommation.
 */
final class TariffSegment
{
    public function __construct(
        public readonly TariffGrid $grid,
        public readonly DateTimeImmutable $from,
        public readonly DateTimeImmutable $to,
        public readonly int $days,
    ) {
    }

    /**
     * Part de la période totale couverte par ce segment, dans [0, 1].
     * Sert à proratiser les quantités (kWh, m³) connues seulement globalement.
     */
    public function fraction(int $totalDays): float
    {
        return $totalDays > 0 ? $this->days / $totalDays : 1.0;
    }
}
