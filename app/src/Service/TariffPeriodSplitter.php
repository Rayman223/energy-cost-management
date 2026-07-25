<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\TariffGrid;
use App\Domain\TariffSegment;
use DateTimeImmutable;

/**
 * Découpe une période de calcul en sous-périodes tarifaires homogènes (#196).
 *
 * Chaque jour de la période est attribué à la PREMIÈRE grille active ce jour-là
 * dans la liste fournie. Cette liste étant triée par priorité décroissante
 * (surcharge personnelle avant catalogue partagé, puis `valid_from` décroissant,
 * cf. TariffRepository::findActiveGridsBetween), l'arbitrage d'un jour couvert par
 * plusieurs grilles est identique à celui de findActiveGrid() : le jour de bascule
 * n'est facturé qu'une fois (bornes `valid_to` incluses, cf. #190).
 *
 * Les jours non couverts par une grille sont rattachés au segment voisin (le
 * précédent, ou le premier segment de la période s'ils sont en tête) : la période
 * garde son nombre de jours et sa consommation, comme le faisait le repli
 * `findActiveGrid($from) ?? findActiveGrid($to)` de l'implémentation mono-grille.
 */
final class TariffPeriodSplitter
{
    /**
     * @param list<TariffGrid> $grids     Grilles candidates, triées par priorité décroissante.
     * @param DateTimeImmutable $from     Début de la période (l'heure est ignorée).
     * @param int $totalDays              Nombre de jours de la période (cf. CostCalculationService::computeDays()).
     * @return list<TariffSegment>        Segments consécutifs ; [] si aucune grille n'est active sur la période.
     */
    public function split(array $grids, DateTimeImmutable $from, int $totalDays): array
    {
        if ($grids === [] || $totalDays < 1) {
            return [];
        }

        $start = $from->setTime(0, 0, 0);

        /** @var list<DateTimeImmutable> $days */
        $days = [];
        /** @var list<TariffGrid|null> $gridPerDay */
        $gridPerDay = [];

        for ($i = 0; $i < $totalDays; $i++) {
            $day          = $start->modify(sprintf('+%d day', $i));
            $days[]       = $day;
            $gridPerDay[] = $this->gridFor($grids, $day);
        }

        $gridPerDay = $this->fillGaps($gridPerDay);
        if ($gridPerDay === null) {
            return []; // aucune grille active sur un seul jour de la période
        }

        return $this->groupConsecutive($days, $gridPerDay);
    }

    /**
     * Grille applicable un jour donné : la première active de la liste (déjà
     * triée par priorité).
     *
     * @param list<TariffGrid> $grids
     */
    private function gridFor(array $grids, DateTimeImmutable $day): ?TariffGrid
    {
        foreach ($grids as $grid) {
            if ($grid->isActiveOn($day)) {
                return $grid;
            }
        }

        return null;
    }

    /**
     * Comble les jours sans grille : report de la grille du jour précédent, et
     * pour les jours de tête, de la première grille rencontrée ensuite.
     *
     * @param  list<TariffGrid|null> $gridPerDay
     * @return list<TariffGrid>|null null si aucun jour n'a de grille.
     */
    private function fillGaps(array $gridPerDay): ?array
    {
        $firstIndex = null;
        foreach ($gridPerDay as $i => $grid) {
            if ($grid !== null) {
                $firstIndex = $i;
                break;
            }
        }

        if ($firstIndex === null) {
            return null;
        }

        /** @var list<TariffGrid> $filled */
        $filled  = [];
        $current = $gridPerDay[$firstIndex];

        foreach ($gridPerDay as $i => $grid) {
            if ($i < $firstIndex) {
                $filled[] = $current; // jours de tête → première grille de la période
                continue;
            }

            $current  = $grid ?? $current;
            $filled[] = $current;
        }

        return $filled;
    }

    /**
     * Regroupe les jours consécutifs partageant la même grille.
     *
     * @param  list<DateTimeImmutable> $days
     * @param  list<TariffGrid>        $gridPerDay
     * @return list<TariffSegment>
     */
    private function groupConsecutive(array $days, array $gridPerDay): array
    {
        $segments = [];
        $current  = $gridPerDay[0];
        $segStart = $days[0];
        $count    = 0;

        foreach ($days as $i => $day) {
            if ($gridPerDay[$i] !== $current) {
                $segments[] = new TariffSegment($current, $segStart, $days[$i - 1], $count);
                $current    = $gridPerDay[$i];
                $segStart   = $day;
                $count      = 0;
            }
            $count++;
        }

        $segments[] = new TariffSegment($current, $segStart, $days[count($days) - 1], $count);

        return $segments;
    }
}
