<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\LoadProfileRepository;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Profils de charge en base (#93) : bornes de fenêtre, idempotence et isolement par
 * code / pays / résolution.
 */
final class LoadProfileRepositoryDbTest extends DatabaseTestCase
{
    protected function clean(): void
    {
        $this->pdo()->exec('DELETE FROM load_profiles');
    }

    private function at(string $utc): DateTimeImmutable
    {
        return new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    }

    /** @param array<string, float> $weights */
    private function seed(array $weights, string $code = 'RLP0N', string $country = 'BE', int $resolution = 15): int
    {
        $rows = [];
        foreach ($weights as $slot => $fraction) {
            $rows[] = ['slot_start' => $this->at($slot), 'fraction' => $fraction];
        }

        return (new LoadProfileRepository($this->pdo()))
            ->upsertWeights($code, $country, $resolution, $rows, 'test');
    }

    public function testWeightsRoundTripWithinTheWindow(): void
    {
        $this->seed([
            '2026-06-10 09:45:00' => 0.001,
            '2026-06-10 10:00:00' => 0.002,
            '2026-06-10 10:15:00' => 0.003,
        ]);

        $repo    = new LoadProfileRepository($this->pdo());
        $weights = $repo->weightsBetween('RLP0N', 'BE', $this->at('2026-06-10 10:00:00'), $this->at('2026-06-10 10:15:00'));

        // Borne haute EXCLUE, comme partout dans le projet.
        self::assertSame(['2026-06-10 10:00:00'], array_keys($weights));
        self::assertEqualsWithDelta(0.002, $weights['2026-06-10 10:00:00'], 0.0000001);
    }

    /** Réimporter le même fichier corrige les valeurs au lieu de les dupliquer. */
    public function testUpsertIsIdempotent(): void
    {
        $this->seed(['2026-06-10 10:00:00' => 0.002]);
        $this->seed(['2026-06-10 10:00:00' => 0.005]);

        $weights = (new LoadProfileRepository($this->pdo()))
            ->weightsBetween('RLP0N', 'BE', $this->at('2026-06-10 00:00:00'), $this->at('2026-06-11 00:00:00'));

        self::assertCount(1, $weights);
        self::assertEqualsWithDelta(0.005, $weights['2026-06-10 10:00:00'], 0.0000001);
    }

    /**
     * Les deux résolutions coexistent au même horodatage, comme pour `dynamic_prices` :
     * c'est ce qui permet au calcul de demander 15 min puis de se rabattre sur 60.
     */
    public function testResolutionsAreIsolated(): void
    {
        $this->seed(['2026-06-10 10:00:00' => 0.002], resolution: 15);
        $this->seed(['2026-06-10 10:00:00' => 0.008], resolution: 60);

        $repo = new LoadProfileRepository($this->pdo());
        $from = $this->at('2026-06-10 00:00:00');
        $to   = $this->at('2026-06-11 00:00:00');

        self::assertEqualsWithDelta(0.002, $repo->weightsBetween('RLP0N', 'BE', $from, $to, 15)['2026-06-10 10:00:00'], 0.0000001);
        self::assertEqualsWithDelta(0.008, $repo->weightsBetween('RLP0N', 'BE', $from, $to, 60)['2026-06-10 10:00:00'], 0.0000001);
    }

    public function testCodeAndCountryAreIsolated(): void
    {
        $this->seed(['2026-06-10 10:00:00' => 0.002], code: 'RLP0N', country: 'BE');
        $this->seed(['2026-06-10 10:00:00' => 0.009], code: 'RLP0E', country: 'BE');
        $this->seed(['2026-06-10 10:00:00' => 0.007], code: 'RLP0N', country: 'FR');

        $repo = new LoadProfileRepository($this->pdo());
        $from = $this->at('2026-06-10 00:00:00');
        $to   = $this->at('2026-06-11 00:00:00');

        self::assertEqualsWithDelta(0.002, $repo->weightsBetween('RLP0N', 'BE', $from, $to)['2026-06-10 10:00:00'], 0.0000001);
        self::assertEqualsWithDelta(0.009, $repo->weightsBetween('RLP0E', 'BE', $from, $to)['2026-06-10 10:00:00'], 0.0000001);
        self::assertEqualsWithDelta(0.007, $repo->weightsBetween('RLP0N', 'FR', $from, $to)['2026-06-10 10:00:00'], 0.0000001);
    }

    public function testAvailableCodesAreListedPerCountry(): void
    {
        $this->seed(['2026-06-10 10:00:00' => 0.002], code: 'RLP0N', country: 'BE');
        $this->seed(['2026-06-10 10:15:00' => 0.002], code: 'RLP0E', country: 'BE');
        $this->seed(['2026-06-10 10:00:00' => 0.002], code: 'PROFIL_FR', country: 'FR');

        $repo = new LoadProfileRepository($this->pdo());

        self::assertSame(['RLP0E', 'RLP0N'], $repo->availableCodes('BE'));
        self::assertSame(['PROFIL_FR'], $repo->availableCodes('FR'));
        self::assertSame([], $repo->availableCodes('DE'));
    }

    /** Profil absent : map vide, pour que la cascade de pondération descende d'un cran. */
    public function testMissingProfileYieldsAnEmptyMap(): void
    {
        $repo = new LoadProfileRepository($this->pdo());

        self::assertSame([], $repo->weightsBetween(
            'RLP0N',
            'BE',
            $this->at('2026-06-01 00:00:00'),
            $this->at('2026-07-01 00:00:00')
        ));
    }

    public function testUpsertOfAnEmptySeriesWritesNothing(): void
    {
        self::assertSame(0, (new LoadProfileRepository($this->pdo()))->upsertWeights('RLP0N', 'BE', 15, [], 'test'));
    }
}
