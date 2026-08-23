<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\TariffCoverage;
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
 * n'est facturé qu'une fois (#190). Depuis #1 il ne peut d'ailleurs plus être
 * revendiqué par deux grilles à la fois : `valid_to` est le premier jour NON
 * couvert, donc deux grilles successives se partagent une frontière, pas un jour.
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
        return $this->analyse($grids, $from, $totalDays)->segments;
    }

    /**
     * Découpage + diagnostic de couverture, issus du même balayage (#6) : les jours
     * comblés par {@see fillGaps()} sont invisibles dans les segments rendus, c'est
     * `missingDays` qui les compte.
     *
     * @param list<TariffGrid> $grids  Grilles candidates, triées par priorité décroissante.
     */
    public function analyse(array $grids, DateTimeImmutable $from, int $totalDays): TariffCoverage
    {
        if ($totalDays < 1) {
            return new TariffCoverage([], 0);
        }

        $start = $from->setTime(0, 0, 0);

        // Aucune grille candidate : la période est un trou d'un seul tenant. Le dire
        // explicitement plutôt que de rendre une couverture « complète mais vide »,
        // que l'appelant confondrait avec une période correctement tarifée (#6).
        if ($grids === []) {
            return new TariffCoverage(
                [],
                $totalDays,
                $totalDays,
                $start,
                $start->modify(sprintf('+%d day', $totalDays - 1)),
            );
        }

        /** @var list<DateTimeImmutable> $days */
        $days = [];
        /** @var list<TariffGrid|null> $gridPerDay */
        $gridPerDay = [];

        for ($i = 0; $i < $totalDays; $i++) {
            $day          = $start->modify(sprintf('+%d day', $i));
            $days[]       = $day;
            $gridPerDay[] = $this->gridFor($grids, $day);
        }

        $missing = $this->missingDays($days, $gridPerDay);

        $filled = $this->fillGaps($gridPerDay);
        if ($filled === null) {
            // Aucune grille active sur un seul jour de la période : rien à facturer,
            // mais le diagnostic reste utile à l'appelant (période entièrement à trou).
            return new TariffCoverage([], $totalDays, $missing['days'], $missing['from'], $missing['to']);
        }

        return new TariffCoverage(
            $this->groupConsecutive($days, $filled),
            $totalDays,
            $missing['days'],
            $missing['from'],
            $missing['to'],
        );
    }

    /**
     * Jours sans aucune grille active, et leurs bornes (premier et dernier jour
     * découvert, même si le trou n'est pas d'un seul tenant).
     *
     * @param  list<DateTimeImmutable> $days
     * @param  list<TariffGrid|null>   $gridPerDay
     * @return array{days: int, from: ?DateTimeImmutable, to: ?DateTimeImmutable}
     */
    private function missingDays(array $days, array $gridPerDay): array
    {
        $count = 0;
        $first = null;
        $last  = null;

        foreach ($gridPerDay as $i => $grid) {
            if ($grid !== null) {
                continue;
            }

            $count++;
            $first ??= $days[$i];
            $last    = $days[$i];
        }

        return ['days' => $count, 'from' => $first, 'to' => $last];
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
