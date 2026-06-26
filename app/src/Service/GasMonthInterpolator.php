<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;

/**
 * Interpolation linéaire de la consommation gaz sur un mois calendaire.
 *
 * Les deux relevés encadrant le mois peuvent tomber à n'importe quelle date
 * (ex. 31-août et 1er-oct pour septembre). On interpole linéairement par
 * timestamp Unix l'index théorique à minuit le 1er du mois M et du mois M+1,
 * puis la différence donne la consommation du mois. Les forfaits fixes sont
 * proratisés sur le nombre de jours réellement couverts (mois plein → jours
 * calendaires exacts).
 */
final class GasMonthInterpolator
{
    public function interpolate(
        DateTimeImmutable $from,
        float $fromM3,
        DateTimeImmutable $to,
        float $toM3,
        int $year,
        int $month,
    ): GasMonthInterpolation {
        // ── Timestamps (secondes Unix, arithmétique entière) ──────────────────
        $fromTs    = $from->getTimestamp();
        $toTs      = $to->getTimestamp();
        $totalSecs = $toTs - $fromTs;

        if ($totalSecs <= 0) {
            return GasMonthInterpolation::unavailable('Les deux relevés ont le même horodatage.');
        }

        // ── Bornes calendaires du mois demandé ────────────────────────────────
        $nextYear  = $month === 12 ? $year + 1 : $year;
        $nextMonth = $month === 12 ? 1         : $month + 1;

        $monthStartDt = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year,     $month));
        $monthEndDt   = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $nextYear, $nextMonth));

        $monthStartTs = $monthStartDt->getTimestamp();
        $monthEndTs   = $monthEndDt->getTimestamp();

        // ── Fenêtre de couverture effective dans le mois ──────────────────────
        $effStartTs = max($monthStartTs, $fromTs); // pas avant le premier relevé
        $effEndTs   = min($monthEndTs,   $toTs);   // pas au-delà du dernier relevé

        if ($effStartTs >= $effEndTs) {
            return GasMonthInterpolation::unavailable('Les relevés ne couvrent pas cette période.');
        }

        // ── Interpolation linéaire de l'index compteur ────────────────────────
        $totalDeltaM3 = $toM3 - $fromM3;

        $fracStart = ($effStartTs - $fromTs) / $totalSecs;
        $fracEnd   = ($effEndTs   - $fromTs) / $totalSecs;

        $indexAtEffStart = $fromM3 + $totalDeltaM3 * $fracStart;
        $indexAtEffEnd   = $fromM3 + $totalDeltaM3 * $fracEnd;

        $monthlyM3 = max(0.0, $indexAtEffEnd - $indexAtEffStart);

        // ── Jours pour la proratisation des forfaits fixes ────────────────────
        // Mois entièrement encadré (from ≤ monthStart ET to ≥ monthEnd) → jours
        // calendaires exacts ; sinon (couverture partielle) → jours couverts.
        $calendarDays = (int) $monthStartDt->format('t'); // 28/29/30/31
        $coverageDays = (int) round(($effEndTs - $effStartTs) / 86400);
        $isFull       = ($fromTs <= $monthStartTs && $toTs >= $monthEndTs);
        $days         = $isFull ? $calendarDays : max(1, $coverageDays);

        $interpolated = !($fromTs === $monthStartTs && $toTs === $monthEndTs);

        return GasMonthInterpolation::of(
            $monthlyM3,
            $days,
            $calendarDays,
            $isFull,
            $interpolated,
            $monthStartDt->format('Y-m-d H:i:s'),
            $monthEndDt->format('Y-m-d H:i:s'),
        );
    }
}
