<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Dates;
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
     * Série mensuelle de consommation pour le graphique du dashboard (#238).
     *
     * Les relevés gaz/eau sont manuels et clairsemés : une barre par relevé donne
     * une lecture fausse (deux relevés dans le même mois → deux barres, un relevé
     * couvrant cinq mois → une barre géante). On ventile donc la consommation sur
     * les mois calendaires avec le MÊME moteur que les cards de coût
     * ({@see interpolateMonth}), pour que graphe et cards affichent les mêmes m³.
     *
     * Différence avec {@see interpolateMonth} : la borne de fin n'est jamais
     * extrapolée. Elle est clampée sur le dernier relevé, de sorte que le mois en
     * cours affiche la consommation RÉELLE à ce jour (`partial => true`) plutôt
     * qu'une projection qui gonflerait artificiellement la dernière barre.
     *
     * Les mois entièrement hors de [premier relevé, dernier relevé] sont omis :
     * on n'invente pas de barre avant le premier relevé.
     *
     * @param list<array{ts:int,value:float}> $readingsAsc Relevés triés par timestamp ASC.
     * @param int $months  Nombre de mois de la fenêtre (le mois de $nowTs inclus).
     * @param int $nowTs   Instant « maintenant » (dernier mois de la fenêtre).
     * @return list<array{month:string, delta_m3:float, partial:bool}>
     */
    public function monthlySeries(array $readingsAsc, int $months, int $nowTs): array
    {
        // Tri défensif (le repository renvoie déjà ASC, mais le moteur reste pur).
        usort($readingsAsc, static fn (array $a, array $b): int => $a['ts'] <=> $b['ts']);

        $n = count($readingsAsc);
        if ($n < 2 || $months < 1) {
            // Un seul relevé : aucune pente connue, donc aucune consommation
            // attribuable à un mois.
            return [];
        }

        $firstTs = $readingsAsc[0]['ts'];
        $lastTs  = $readingsAsc[$n - 1]['ts'];

        // Premier jour du mois de $nowTs, puis recul de ($months - 1) mois.
        $currentMonth = (new DateTimeImmutable('@' . $nowTs))
            ->setTimezone(Dates::utc())
            ->modify('first day of this month')
            ->setTime(0, 0, 0);
        $windowStart = $currentMonth->modify('-' . ($months - 1) . ' months');

        $series = [];
        for ($i = 0; $i < $months; $i++) {
            $monthStart = $windowStart->modify('+' . $i . ' months');
            $monthEnd   = $monthStart->modify('+1 month');

            $startTs = $monthStart->getTimestamp();
            $endTs   = $monthEnd->getTimestamp();

            // Mois entièrement hors de la plage couverte par les relevés.
            if ($endTs <= $firstTs || $startTs >= $lastTs) {
                continue;
            }

            // Borne de fin clampée : pas de projection au-delà du dernier relevé.
            $partial   = $endTs > $lastTs;
            $effEndTs  = $partial ? $lastTs : $endTs;

            // Le premier mois couvert peut commencer avant le premier relevé
            // (relevé initial en cours de mois) : on part alors du relevé lui-même
            // plutôt que d'extrapoler en arrière une conso non mesurée.
            $effStartTs = max($startTs, $firstTs);

            $indexStart = $this->interpolateValueAt($readingsAsc, $effStartTs);
            $indexEnd   = $this->interpolateValueAt($readingsAsc, $effEndTs);
            if ($indexStart === null || $indexEnd === null) {
                continue;
            }

            $series[] = [
                'month'    => $monthStart->format('Y-m'),
                'delta_m3' => round(max(0.0, $indexEnd - $indexStart), 3),
                'partial'  => $partial || $effStartTs !== $startTs,
            ];
        }

        return $series;
    }

    /**
     * Interpole la consommation d'un mois calendaire à partir d'une fenêtre de
     * relevés (le dernier avant le mois, ceux du mois, le premier après le mois).
     *
     * Enveloppe de {@see interpolateRange()} : le mois n'est qu'un cas particulier
     * de période, avec ses deux bornes à minuit le 1er de M et le 1er de M+1.
     *
     * @param list<array{ts:int,value:float}> $readingsAsc Relevés triés par timestamp ASC.
     */
    public function interpolateMonth(array $readingsAsc, int $year, int $month): MonthInterpolation
    {
        // ── Bornes calendaires du mois (rollover décembre → janvier) ──────────
        $nextYear  = $month === 12 ? $year + 1 : $year;
        $nextMonth = $month === 12 ? 1         : $month + 1;

        // Bornes de mois en UTC (fuseau de stockage), indépendantes du fuseau PHP.
        $monthStartDt = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year,     $month),     Dates::utc());
        $monthEndDt   = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $nextYear, $nextMonth), Dates::utc());

        return $this->interpolateRange($readingsAsc, $monthStartDt, $monthEndDt);
    }

    /**
     * Interpole la consommation entre deux instants QUELCONQUES (#241).
     *
     * Généralisation de {@see interpolateMonth()} : le moteur n'a jamais eu besoin
     * du calendrier, seulement de deux bornes. Un bilan d'acomptes porte sur une
     * période à dates exactes (« du 06/06/2025 au 01/07/2026 ») qui ne tombe pas
     * sur des mois entiers, d'où cette entrée à bornes libres.
     *
     * `days` / `calendarDays` valent le nombre de jours de l'intervalle (au moins 1) :
     * sur un mois entier on retrouve exactement la valeur historique.
     *
     * @param list<array{ts:int,value:float}> $readingsAsc Relevés triés par timestamp ASC.
     */
    public function interpolateRange(array $readingsAsc, DateTimeImmutable $from, DateTimeImmutable $to): MonthInterpolation
    {
        // Tri défensif (le repository renvoie déjà ASC, mais le moteur reste pur).
        usort($readingsAsc, static fn (array $a, array $b): int => $a['ts'] <=> $b['ts']);

        $n = count($readingsAsc);
        if ($n === 0) {
            return MonthInterpolation::unavailable('Aucun relevé disponible pour cette période.');
        }

        $startTs = $from->getTimestamp();
        $endTs   = $to->getTimestamp();

        if ($endTs <= $startTs) {
            return MonthInterpolation::unavailable('Période invalide : la fin doit suivre le début.');
        }

        $firstTs = $readingsAsc[0]['ts'];
        $lastTs  = $readingsAsc[$n - 1]['ts'];

        // Période en cours / incomplète : aucun relevé à/après la fin. Sans un second
        // relevé, impossible d'établir une pente → on attend le prochain relevé.
        $incompleteEnd = $endTs > $lastTs;
        if ($incompleteEnd && $n < 2) {
            return MonthInterpolation::unavailable('Relevé manquant : le calcul se fera dès le prochain relevé.');
        }

        $indexStart = $this->interpolateValueAt($readingsAsc, $startTs);
        $indexEnd   = $this->interpolateValueAt($readingsAsc, $endTs);

        if ($indexStart === null || $indexEnd === null) {
            return MonthInterpolation::unavailable('Pas assez de relevés pour encadrer cette période.');
        }

        $delta = round(max(0.0, $indexEnd - $indexStart), 3);
        // Jours pleins de l'intervalle : sur un mois calendaire complet, c'est le
        // nombre de jours du mois — la valeur qu'attendaient les appelants d'origine.
        $days = max(1, (int) round(($endTs - $startTs) / 86400));

        $startKind = $this->boundaryKind($readingsAsc, $startTs, $firstTs, $lastTs);
        $endKind   = $this->boundaryKind($readingsAsc, $endTs, $firstTs, $lastTs);

        return MonthInterpolation::of(
            round($indexStart, 3),
            round($indexEnd, 3),
            $delta,
            $days,
            $days,
            $from->format('Y-m-d H:i:s'),
            $to->format('Y-m-d H:i:s'),
            $startKind,
            $endKind,
            $endKind === 'extrapolated' && $endTs > $lastTs,
            $startTs,
            $endTs,
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
