<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;

/**
 * Interpolation linéaire de la consommation d'un compteur (index cumulatif) sur
 * un mois calendaire. Moteur PUR (aucun I/O) partagé par le gaz, l'eau et
 * l'électricité — testé indépendamment (MonthlyConsumptionInterpolatorTest).
 *
 * Principe : pour un mois M, on estime l'index théorique **à minuit** le 1er de M
 * et le 1er de M+1 par interpolation linéaire (règle de trois) entre le couple de
 * relevés le plus serré qui encadre chaque instant. La consommation du mois est
 * la différence des deux index, arrondie à 3 décimales. Les relevés intermédiaires
 * servent d'ancrages : chaque borne utilise le segment réel qui l'entoure, ce qui
 * récupère le décalage horaire des relevés manuels (relevé à 07:54 → minuit).
 *
 * Règles aux bords :
 *   - minuit avant le 1er relevé fourni → extrapolation arrière (pente du 1er segment) ;
 *   - minuit après le dernier relevé fourni → extrapolation avant (pente du dernier
 *     segment) si ≥ 2 relevés ; sinon indisponible (« relevé manquant »).
 */
final class MonthlyConsumptionInterpolator
{
    /**
     * Interpole/extrapole l'index cumulatif à un instant donné.
     *
     * @param list<array{ts:int,value:float}> $readingsAsc Relevés triés par timestamp ASC.
     * @return float|null  null si l'instant est hors plage et qu'il manque un relevé
     *                      pour établir une pente (0 ou 1 relevé).
     */
    public function interpolateValueAt(array $readingsAsc, int $instantTs): ?float
    {
        $n = count($readingsAsc);
        if ($n === 0) {
            return null;
        }

        $first = $readingsAsc[0];
        $last  = $readingsAsc[$n - 1];

        // Avant (ou à) le premier relevé.
        if ($instantTs <= $first['ts']) {
            if ($instantTs === $first['ts']) {
                return $first['value'];
            }
            // Extrapolation arrière avec le premier segment.
            return $n >= 2 ? $this->lineAt($readingsAsc[0], $readingsAsc[1], $instantTs) : null;
        }

        // Après (ou à) le dernier relevé.
        if ($instantTs >= $last['ts']) {
            if ($instantTs === $last['ts']) {
                return $last['value'];
            }
            // Extrapolation avant avec le dernier segment.
            return $n >= 2 ? $this->lineAt($readingsAsc[$n - 2], $readingsAsc[$n - 1], $instantTs) : null;
        }

        // Strictement à l'intérieur : on cherche le segment encadrant le plus serré.
        for ($i = 0; $i < $n - 1; $i++) {
            $a = $readingsAsc[$i];
            $b = $readingsAsc[$i + 1];

            if ($instantTs === $a['ts']) {
                return $a['value'];
            }
            if ($instantTs > $a['ts'] && $instantTs < $b['ts']) {
                return $this->lineAt($a, $b, $instantTs);
            }
        }

        // Inatteignable en pratique (les deux bornes sont déjà traitées ci-dessus).
        return $last['value'];
    }

    /**
     * Interpole la consommation d'un mois calendaire à partir d'une fenêtre de
     * relevés (le dernier avant le mois, ceux du mois, le premier après le mois).
     *
     * @param list<array{ts:int,value:float}> $readingsAsc Relevés triés par timestamp ASC.
     */
    public function interpolateMonth(array $readingsAsc, int $year, int $month): MonthInterpolation
    {
        // Tri défensif (le repository renvoie déjà ASC, mais le moteur reste pur).
        usort($readingsAsc, static fn (array $a, array $b): int => $a['ts'] <=> $b['ts']);

        $n = count($readingsAsc);
        if ($n === 0) {
            return MonthInterpolation::unavailable('Aucun relevé disponible pour cette période.');
        }

        // ── Bornes calendaires du mois (rollover décembre → janvier) ──────────
        $nextYear  = $month === 12 ? $year + 1 : $year;
        $nextMonth = $month === 12 ? 1         : $month + 1;

        $monthStartDt = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year,     $month));
        $monthEndDt   = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $nextYear, $nextMonth));

        $monthStartTs = $monthStartDt->getTimestamp();
        $monthEndTs   = $monthEndDt->getTimestamp();

        $firstTs = $readingsAsc[0]['ts'];
        $lastTs  = $readingsAsc[$n - 1]['ts'];

        // Mois en cours / incomplet : aucun relevé à/après la fin du mois. Sans un
        // second relevé, impossible d'établir une pente → on attend le prochain relevé.
        $incompleteEnd = $monthEndTs > $lastTs;
        if ($incompleteEnd && $n < 2) {
            return MonthInterpolation::unavailable('Relevé manquant : le calcul se fera dès le prochain relevé.');
        }

        $indexStart = $this->interpolateValueAt($readingsAsc, $monthStartTs);
        $indexEnd   = $this->interpolateValueAt($readingsAsc, $monthEndTs);

        if ($indexStart === null || $indexEnd === null) {
            return MonthInterpolation::unavailable('Pas assez de relevés pour encadrer cette période.');
        }

        $monthlyDelta = round(max(0.0, $indexEnd - $indexStart), 3);
        $calendarDays = (int) $monthStartDt->format('t');

        $startKind = $this->boundaryKind($readingsAsc, $monthStartTs, $firstTs, $lastTs);
        $endKind   = $this->boundaryKind($readingsAsc, $monthEndTs, $firstTs, $lastTs);

        return MonthInterpolation::of(
            round($indexStart, 3),
            round($indexEnd, 3),
            $monthlyDelta,
            $calendarDays,
            $calendarDays,
            $monthStartDt->format('Y-m-d H:i:s'),
            $monthEndDt->format('Y-m-d H:i:s'),
            $startKind,
            $endKind,
            $endKind === 'extrapolated' && $monthEndTs > $lastTs,
        );
    }

    /**
     * Interpolation/extrapolation linéaire entre deux relevés.
     *
     * @param array{ts:int,value:float} $a
     * @param array{ts:int,value:float} $b
     */
    private function lineAt(array $a, array $b, int $instantTs): float
    {
        $span = $b['ts'] - $a['ts'];
        if ($span === 0) {
            return $a['value'];
        }

        return $a['value'] + ($b['value'] - $a['value']) * (($instantTs - $a['ts']) / $span);
    }

    /**
     * Qualifie une borne : 'exact' (relevé pile à minuit), 'extrapolated' (hors
     * plage des relevés) ou 'interpolated' (encadrée par deux relevés).
     *
     * @param list<array{ts:int,value:float}> $readingsAsc
     */
    private function boundaryKind(array $readingsAsc, int $instantTs, int $firstTs, int $lastTs): string
    {
        foreach ($readingsAsc as $r) {
            if ($r['ts'] === $instantTs) {
                return 'exact';
            }
        }

        if ($instantTs < $firstTs || $instantTs > $lastTs) {
            return 'extrapolated';
        }

        return 'interpolated';
    }
}
