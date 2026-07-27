<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\Contract\MeterReadingRepositoryInterface;
use App\Support\Dates;

/**
 * Série de consommation MENSUELLE d'un compteur volumétrique (gaz / eau) pour le
 * graphique du dashboard (#238).
 *
 * Le graphe traçait auparavant une barre par relevé : avec des relevés manuels
 * clairsemés, la représentation était fausse (deux relevés dans un même mois →
 * deux barres, un relevé couvrant plusieurs mois → une barre géante). On ventile
 * donc la consommation sur les mois calendaires via
 * {@see MonthlyConsumptionInterpolator}, le moteur qui alimente déjà les cards de
 * coût mensuel — graphe et cards affichent ainsi les mêmes m³.
 *
 * Les relevés gaz/eau se comptent en dizaines de lignes (saisie manuelle) : on
 * charge toute la série via `getAllReadings()` plutôt que d'ajouter une requête
 * par mois.
 */
final class UtilityConsumptionSeriesService
{
    /** Bornes de la fenêtre demandée : 1 mois minimum, 5 ans maximum. */
    public const MIN_MONTHS = 1;
    public const MAX_MONTHS = 60;

    public function __construct(
        private readonly MonthlyConsumptionInterpolator $interpolator = new MonthlyConsumptionInterpolator(),
    ) {
    }

    /**
     * @param int $months Fenêtre demandée (bornée sur [MIN_MONTHS, MAX_MONTHS]).
     * @param int|null $nowTs Instant de référence (tests) ; `time()` par défaut.
     * @return list<array{month:string, delta_m3:float, partial:bool}>
     */
    public function build(MeterReadingRepositoryInterface $repo, int $months = 12, ?int $nowTs = null): array
    {
        $months = max(self::MIN_MONTHS, min(self::MAX_MONTHS, $months));

        return $this->interpolator->monthlySeries(
            $this->toSeries($repo->getAllReadings()),
            $months,
            $nowTs ?? time(),
        );
    }

    /**
     * Convertit les relevés du repository (DESC, avec delta brut) en série
     * {ts, value} croissante attendue par l'interpolateur.
     *
     * @param array<int,array{id:int,reading_at:string,counter_m3:float,delta_m3:float|null}> $readings
     * @return list<array{ts:int,value:float}>
     */
    private function toSeries(array $readings): array
    {
        $series = array_map(
            // reading_at est stocké en UTC : instant absolu déterministe.
            static fn (array $r): array => [
                'ts'    => Dates::fromDbString($r['reading_at'])->getTimestamp(),
                'value' => (float) $r['counter_m3'],
            ],
            array_values($readings),
        );

        usort($series, static fn (array $a, array $b): int => $a['ts'] <=> $b['ts']);

        return $series;
    }
}
