<?php

declare(strict_types=1);

namespace Tests\Fake;

use App\Repository\Contract\LegacyDailyRepositoryInterface;
use DateTimeImmutable;

/**
 * Faux repository legacy : renvoie des deltas en mémoire, sans base de données.
 */
final class FakeLegacyDailyRepository implements LegacyDailyRepositoryInterface
{
    /**
     * @param array<string,mixed> $monthlyDeltas
     * @param array<string,mixed> $monthlyDeltasForMonth
     * @param array<int, array{hour: string, import_kwh: float}> $hourlyImportDeltas
     * @param array<int, array{quarter: string, import_kwh: float, native: bool}> $quarterImportDeltas
     */
    /** Couples (année, mois) demandés à getMonthlyDeltasForMonth(), dans l'ordre.
     *
     * @var list<array{int, int}>
     */
    public array $monthsRequested = [];

    /** Couples (from, to) demandés à getDeltasBetween(), dans l'ordre.
     *
     * @var list<array{string, string}>
     */
    public array $rangesRequested = [];

    /** Listes de bornes demandées à getDeltasByBoundaries(), dans l'ordre.
     *
     * @var list<list<string>>
     */
    public array $boundariesRequested = [];

    /**
     * Séries d'index cumulés par registre, interpolées par getDeltasByBoundaries()
     * pour décrire une consommation NON uniforme — pendant électricité du
     * `forInterpolation:` de FakeGasReadingRepository.
     *
     * Laissée vide, la méthode rend `[]` et le service retombe sur sa répartition au
     * prorata des jours : les tests écrits avant #2 n'ont rien à déclarer.
     *
     * Clés attendues : prelev_jour, prelev_nuit, injec_jour, injec_nuit, solar.
     *
     * @var array<string, list<array{at: string, index: float}>>
     */
    public array $indexSeries = [];

    /**
     * @param array<string,mixed> $deltasBetween Deltas rendus par getDeltasBetween() ;
     *        `null` = retomber sur $monthlyDeltasForMonth, pour que les tests écrits
     *        avant #241 n'aient rien à déclarer.
     */
    public function __construct(
        public array $monthlyDeltas = [],
        public array $monthlyDeltasForMonth = [],
        public array $hourlyImportDeltas = [],
        public array $quarterImportDeltas = [],
        public ?array $deltasBetween = null,
    ) {
    }

    public function getMonthlyDeltas(): array
    {
        return $this->monthlyDeltas;
    }

    public function getMonthlyDeltasForMonth(int $year, int $month): array
    {
        $this->monthsRequested[] = [$year, $month];

        if ($this->indexSeries !== []) {
            $next = (new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month)))->modify('+1 month');

            return $this->deltasFromSeries(
                sprintf('%04d-%02d-01 00:00:00', $year, $month),
                $next->format('Y-m-d H:i:s'),
            );
        }

        return $this->monthlyDeltasForMonth;
    }

    public function getHourlyImportDeltas(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->hourlyImportDeltas;
    }

    public function getQuarterImportDeltas(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->quarterImportDeltas;
    }

    public function getDeltasBetween(string $from, string $to): array
    {
        $this->rangesRequested[] = [$from, $to];

        if ($this->indexSeries !== []) {
            return $this->deltasFromSeries($from, $to);
        }

        return $this->deltasBetween ?? $this->monthlyDeltasForMonth;
    }

    /**
     * Deltas d'une fenêtre dérivés de {@see $indexSeries}, comme le fait le vrai
     * repository dans interpolatedDeltasBetween(). C'est ce qui permet à un test de
     * confronter la somme des mois à une période libre : les deux voies partent
     * alors de la MÊME consommation, seule leur découpe diffère.
     *
     * @return array<string, mixed>
     */
    private function deltasFromSeries(string $from, string $to): array
    {
        $deltas = $this->getDeltasByBoundaries([$from, $to])[0] ?? [];
        array_pop($this->boundariesRequested); // usage interne : pas un appel du service

        return [...$deltas, 'from' => $from, 'to' => $to, 'data_from' => $from, 'data_to' => $to];
    }

    public function getDeltasByBoundaries(array $boundaries): array
    {
        $this->boundariesRequested[] = $boundaries;

        if ($this->indexSeries === [] || count($boundaries) < 2) {
            return [];
        }

        $out = [];
        for ($i = 0, $n = count($boundaries) - 1; $i < $n; $i++) {
            $row = [];
            foreach (['prelev_jour', 'prelev_nuit', 'injec_jour', 'injec_nuit', 'solar'] as $key) {
                $series = $this->indexSeries[$key] ?? [];
                // Même plafonnement à 0 que le repository réel : un compteur qui
                // recule ne rend pas un poids négatif.
                $row[$key] = $series === []
                    ? 0.0
                    : max(0.0, self::indexAt($series, $boundaries[$i + 1]) - self::indexAt($series, $boundaries[$i]));
            }

            $out[] = $row;
        }

        return $out;
    }

    /**
     * Index interpolé linéairement à un instant, clampé sur le relevé le plus proche
     * hors plage — sémantique de ElectricityReadingRepository::interpolatedValuesAt().
     *
     * @param list<array{at: string, index: float}> $series Série croissante, non vide.
     */
    private static function indexAt(array $series, string $instant): float
    {
        $ts = strtotime($instant . ' UTC');

        $before = null;
        $after  = null;
        foreach ($series as $point) {
            $pointTs = strtotime($point['at'] . ' UTC');
            if ($pointTs <= $ts) {
                $before = ['ts' => $pointTs, 'index' => $point['index']];
            } elseif ($after === null) {
                $after = ['ts' => $pointTs, 'index' => $point['index']];
            }
        }

        if ($before === null) {
            return $after['index'];
        }
        if ($after === null || $after['ts'] === $before['ts']) {
            return $before['index'];
        }

        $ratio = ($ts - $before['ts']) / ($after['ts'] - $before['ts']);

        return $before['index'] + ($after['index'] - $before['index']) * $ratio;
    }
}
