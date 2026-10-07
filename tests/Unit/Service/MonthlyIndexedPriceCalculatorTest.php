<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Domain\LoadWeighting;
use App\Service\MonthlyIndexedPriceCalculator;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Réduction des cotations d'un mois à UN prix unitaire pondéré (#93).
 *
 * Ce qui est verrouillé ici, c'est la cascade de pondération — courbe réelle, profil
 * standard, baseload — et le fait qu'elle soit annoncée, car deux pondérations
 * différentes donnent deux factures différentes sur les mêmes cotations.
 *
 * Les valeurs attendues sont calculées à la main depuis la règle métier.
 */
final class MonthlyIndexedPriceCalculatorTest extends TestCase
{
    private const DELTA = 0.0001;

    private function at(string $utc): DateTimeImmutable
    {
        return new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    }

    /**
     * Série horaire couvrant intégralement un mois, au prix $base, sauf aux heures
     * surchargées par $overrides ('Y-m-d H:00:00' => prix).
     *
     * @param array<string, float> $overrides
     * @return array<string, float>
     */
    private function fullMonthHourly(string $month, int $days, float $base, array $overrides = []): array
    {
        $prices = [];
        for ($day = 1; $day <= $days; $day++) {
            for ($hour = 0; $hour < 24; $hour++) {
                $prices[sprintf('%s-%02d %02d:00:00', $month, $day, $hour)] = $base;
            }
        }

        return array_merge($prices, $overrides);
    }

    /**
     * @param array<string, float> $prices
     * @return list<array{source: string, resolution_min: int, prices: array<string, float>}>
     */
    private function hourlyCandidate(array $prices, string $source = 'native_hourly'): array
    {
        return [['source' => $source, 'resolution_min' => 60, 'prices' => $prices]];
    }

    /**
     * Cas central : la courbe réelle pondère les cotations. Deux heures cotées,
     * 1 kWh à 0,10 € et 3 kWh à 0,30 € → (1×0,10 + 3×0,30) / 4 = 0,25 €/kWh.
     *
     * La moyenne arithmétique vaudrait 0,20 € : l'écart est précisément ce que la
     * pondération capture, et ce que le baseload rate.
     */
    public function testWeightsByActualLoadCurveWhenNativeCoverageIsSufficient(): void
    {
        $prices = $this->fullMonthHourly('2026-06', 30, 0.20, [
            '2026-06-10 10:00:00' => 0.10,
            '2026-06-10 11:00:00' => 0.30,
        ]);

        $result = (new MonthlyIndexedPriceCalculator())->perMonth(
            $this->at('2026-06-01 00:00:00'),
            $this->at('2026-07-01 00:00:00'),
            $this->hourlyCandidate($prices),
            [
                ['slot' => '2026-06-10 10:00:00', 'import_kwh' => 1.0, 'native' => true],
                ['slot' => '2026-06-10 11:00:00', 'import_kwh' => 3.0, 'native' => true],
            ],
        );

        self::assertArrayHasKey('2026-06', $result);
        $june = $result['2026-06'];
        self::assertSame(LoadWeighting::ActualLoad, $june->weighting);
        self::assertEqualsWithDelta(0.25, $june->priceHtva, self::DELTA);
        self::assertFalse($june->partial);
        self::assertTrue($june->isMeasured());
    }

    /**
     * Une courbe majoritairement ÉTALÉE au prorata n'est pas une courbe : elle est
     * plate dans la journée, donc elle ne porte aucune information de forme. La retenir
     * donnerait un baseload déguisé en mesure.
     */
    public function testFallsBackWhenReadingsAreNotNative(): void
    {
        $prices = $this->fullMonthHourly('2026-06', 30, 0.20, [
            '2026-06-10 10:00:00' => 0.10,
            '2026-06-10 11:00:00' => 0.30,
        ]);

        $result = (new MonthlyIndexedPriceCalculator())->perMonth(
            $this->at('2026-06-01 00:00:00'),
            $this->at('2026-07-01 00:00:00'),
            $this->hourlyCandidate($prices),
            [
                ['slot' => '2026-06-10 10:00:00', 'import_kwh' => 1.0, 'native' => false],
                ['slot' => '2026-06-10 11:00:00', 'import_kwh' => 3.0, 'native' => false],
            ],
        );

        self::assertSame(LoadWeighting::Baseload, $result['2026-06']->weighting);
        self::assertFalse($result['2026-06']->isMeasured());
    }

    /** Sans courbe exploitable ni profil : moyenne arithmétique de toutes les cotations. */
    public function testBaseloadIsTheArithmeticMeanOfQuotations(): void
    {
        $prices = $this->fullMonthHourly('2026-06', 30, 0.20, [
            '2026-06-10 10:00:00' => 0.10,
            '2026-06-10 11:00:00' => 0.30,
        ]);

        $result = (new MonthlyIndexedPriceCalculator())->perMonth(
            $this->at('2026-06-01 00:00:00'),
            $this->at('2026-07-01 00:00:00'),
            $this->hourlyCandidate($prices),
            [],
        );

        // 718 heures à 0,20 €, une à 0,10 € et une à 0,30 € : les deux écarts se
        // compensent, la moyenne reste 0,20 €.
        self::assertSame(LoadWeighting::Baseload, $result['2026-06']->weighting);
        self::assertEqualsWithDelta(0.20, $result['2026-06']->priceHtva, self::DELTA);
    }

    /**
     * Le profil standard prend la main quand la courbe réelle manque : c'est ce que le
     * FOURNISSEUR applique, donc la bonne référence pour reproduire une facture.
     */
    public function testUsesStandardProfileWhenProvidedAndCurveIsUnusable(): void
    {
        $prices = $this->fullMonthHourly('2026-06', 30, 0.20, [
            '2026-06-10 10:00:00' => 0.10,
            '2026-06-10 11:00:00' => 0.30,
        ]);

        $result = (new MonthlyIndexedPriceCalculator())->perMonth(
            $this->at('2026-06-01 00:00:00'),
            $this->at('2026-07-01 00:00:00'),
            $this->hourlyCandidate($prices),
            [],
            [
                '2026-06-10 10:00:00' => 0.25,
                '2026-06-10 11:00:00' => 0.75,
            ],
            'RLP',
        );

        $june = $result['2026-06'];
        self::assertSame(LoadWeighting::StandardProfile, $june->weighting);
        // (0,25×0,10 + 0,75×0,30) / 1,00 = 0,25 €/kWh.
        self::assertEqualsWithDelta(0.25, $june->priceHtva, self::DELTA);
        self::assertSame('RLP', $june->profileCode);
        self::assertFalse($june->isMeasured());
    }

    /**
     * Un profil au pas de 15 min confronté à une série HORAIRE doit s'agréger sur
     * l'heure. Sans cet alignement, seules les clés en `:00:00` seraient reconnues et
     * la moyenne ne porterait que sur le premier quart de chaque heure, en ignorant
     * silencieusement les trois autres — un prix plausible et faux.
     *
     * Ici le poids est concentré sur les trois derniers quarts de 11 h : agrégé, il vaut
     * 0,9 sur l'heure de 11 h contre 0,1 sur celle de 10 h, donc
     * (0,1×0,10 + 0,9×0,30) / 1,0 = 0,28 €/kWh. Sans agrégation, le premier quart de
     * 11 h étant nul, on obtiendrait 0,10 €/kWh — le prix de la seule heure de 10 h.
     */
    public function testStandardProfileAggregatesOntoAnHourlySeries(): void
    {
        $prices = $this->fullMonthHourly('2026-06', 30, 0.20, [
            '2026-06-10 10:00:00' => 0.10,
            '2026-06-10 11:00:00' => 0.30,
        ]);

        $result = (new MonthlyIndexedPriceCalculator())->perMonth(
            $this->at('2026-06-01 00:00:00'),
            $this->at('2026-07-01 00:00:00'),
            $this->hourlyCandidate($prices),
            [],
            [
                '2026-06-10 10:00:00' => 0.1,
                '2026-06-10 11:00:00' => 0.0,
                '2026-06-10 11:15:00' => 0.3,
                '2026-06-10 11:30:00' => 0.3,
                '2026-06-10 11:45:00' => 0.3,
            ],
            'RLP0N',
        );

        self::assertSame(LoadWeighting::StandardProfile, $result['2026-06']->weighting);
        self::assertEqualsWithDelta(0.28, $result['2026-06']->priceHtva, self::DELTA);
    }

    /**
     * L'échelle des poids est indifférente : seule leur pondération relative compte.
     * Des fractions normalisées à 1 et les mêmes valeurs en pourcentage donnent donc le
     * même prix — c'est ce qui permet d'importer un export Synergrid sans le
     * renormaliser.
     */
    public function testProfileWeightScaleDoesNotChangeThePrice(): void
    {
        $prices = $this->fullMonthHourly('2026-06', 30, 0.20, [
            '2026-06-10 10:00:00' => 0.10,
            '2026-06-10 11:00:00' => 0.30,
        ]);
        $calculator = new MonthlyIndexedPriceCalculator();

        $asFraction = $calculator->perMonth(
            $this->at('2026-06-01 00:00:00'),
            $this->at('2026-07-01 00:00:00'),
            $this->hourlyCandidate($prices),
            [],
            ['2026-06-10 10:00:00' => 0.25, '2026-06-10 11:00:00' => 0.75],
        );
        $asPercent = $calculator->perMonth(
            $this->at('2026-06-01 00:00:00'),
            $this->at('2026-07-01 00:00:00'),
            $this->hourlyCandidate($prices),
            [],
            ['2026-06-10 10:00:00' => 25.0, '2026-06-10 11:00:00' => 75.0],
        );

        self::assertEqualsWithDelta(0.25, $asFraction['2026-06']->priceHtva, self::DELTA);
        self::assertEqualsWithDelta(
            $asFraction['2026-06']->priceHtva,
            $asPercent['2026-06']->priceHtva,
            self::DELTA,
        );
    }

    /**
     * La courbe réelle reste prioritaire sur le profil : elle décrit CETTE
     * consommation, là où le profil décrit celle d'un groupe.
     */
    public function testActualLoadTakesPrecedenceOverTheStandardProfile(): void
    {
        $prices = $this->fullMonthHourly('2026-06', 30, 0.20, [
            '2026-06-10 10:00:00' => 0.10,
            '2026-06-10 11:00:00' => 0.30,
        ]);

        $result = (new MonthlyIndexedPriceCalculator())->perMonth(
            $this->at('2026-06-01 00:00:00'),
            $this->at('2026-07-01 00:00:00'),
            $this->hourlyCandidate($prices),
            [
                ['slot' => '2026-06-10 10:00:00', 'import_kwh' => 3.0, 'native' => true],
                ['slot' => '2026-06-10 11:00:00', 'import_kwh' => 1.0, 'native' => true],
            ],
            ['2026-06-10 10:00:00' => 0.25, '2026-06-10 11:00:00' => 0.75],
            'RLP0N',
        );

        $june = $result['2026-06'];
        self::assertSame(LoadWeighting::ActualLoad, $june->weighting);
        // (3×0,10 + 1×0,30) / 4 = 0,15 €/kWh, et non les 0,25 € du profil.
        self::assertEqualsWithDelta(0.15, $june->priceHtva, self::DELTA);
        self::assertNull($june->profileCode);
    }

    /** Deux mois civils = deux prix, calculés chacun sur ses propres cotations. */
    public function testEachCalendarMonthIsPricedIndependently(): void
    {
        $prices = array_merge(
            $this->fullMonthHourly('2026-06', 30, 0.10),
            $this->fullMonthHourly('2026-07', 31, 0.40),
        );

        $result = (new MonthlyIndexedPriceCalculator())->perMonth(
            $this->at('2026-06-01 00:00:00'),
            $this->at('2026-08-01 00:00:00'),
            $this->hourlyCandidate($prices),
            [],
        );

        self::assertSame(['2026-06', '2026-07'], array_keys($result));
        self::assertEqualsWithDelta(0.10, $result['2026-06']->priceHtva, self::DELTA);
        self::assertEqualsWithDelta(0.40, $result['2026-07']->priceHtva, self::DELTA);
    }

    /**
     * Un mois trop lacunaire est ABSENT plutôt que calculé sur un échantillon biaisé :
     * l'appelant le facture alors au tarif fournisseur, comme un créneau sans prix.
     */
    public function testMonthBelowPriceCoverageThresholdIsOmitted(): void
    {
        // 100 heures cotées sur 720 attendues : ~14 %, très en deçà du seuil de 80 %.
        $prices = [];
        for ($hour = 0; $hour < 100; $hour++) {
            $prices[sprintf('2026-06-%02d %02d:00:00', intdiv($hour, 24) + 1, $hour % 24)] = 0.20;
        }

        $result = (new MonthlyIndexedPriceCalculator())->perMonth(
            $this->at('2026-06-01 00:00:00'),
            $this->at('2026-07-01 00:00:00'),
            $this->hourlyCandidate($prices),
            [],
        );

        self::assertSame([], $result);
    }

    /**
     * Mois en cours : Belpex_RLP_M n'est publié qu'une fois le mois clos, un prix
     * provisoire doit donc être annoncé comme tel.
     */
    public function testPartialMonthIsFlagged(): void
    {
        $prices = $this->fullMonthHourly('2026-06', 10, 0.20);

        $result = (new MonthlyIndexedPriceCalculator())->perMonth(
            $this->at('2026-06-01 00:00:00'),
            $this->at('2026-06-11 00:00:00'),
            $this->hourlyCandidate($prices),
            [],
        );

        self::assertTrue($result['2026-06']->partial);
        self::assertFalse($result['2026-06']->isMeasured());
    }

    /**
     * À couverture suffisante, la série la plus fine l'emporte : le quart natif décrit
     * le mois mieux qu'une moyenne horaire reconstruite.
     */
    public function testPrefersTheFinestSeriesThatMeetsCoverage(): void
    {
        $quarter = [];
        for ($day = 1; $day <= 30; $day++) {
            for ($hour = 0; $hour < 24; $hour++) {
                foreach ([0, 15, 30, 45] as $minute) {
                    $quarter[sprintf('2026-06-%02d %02d:%02d:00', $day, $hour, $minute)] = 0.30;
                }
            }
        }

        $result = (new MonthlyIndexedPriceCalculator())->perMonth(
            $this->at('2026-06-01 00:00:00'),
            $this->at('2026-07-01 00:00:00'),
            [
                ['source' => 'native_quarter', 'resolution_min' => 15, 'prices' => $quarter],
                ['source' => 'avg_hourly', 'resolution_min' => 60, 'prices' => $this->fullMonthHourly('2026-06', 30, 0.10)],
            ],
            [],
        );

        self::assertSame('native_quarter', $result['2026-06']->priceSource);
        self::assertSame(15, $result['2026-06']->resolutionMin);
        self::assertEqualsWithDelta(0.30, $result['2026-06']->priceHtva, self::DELTA);
    }

    /** Série la plus fine trop lacunaire : la suivante prend la main, sans mélange. */
    public function testSkipsAFinerSeriesThatDoesNotMeetCoverage(): void
    {
        $result = (new MonthlyIndexedPriceCalculator())->perMonth(
            $this->at('2026-06-01 00:00:00'),
            $this->at('2026-07-01 00:00:00'),
            [
                ['source' => 'native_quarter', 'resolution_min' => 15, 'prices' => ['2026-06-10 10:00:00' => 0.99]],
                ['source' => 'native_hourly', 'resolution_min' => 60, 'prices' => $this->fullMonthHourly('2026-06', 30, 0.10)],
            ],
            [],
        );

        self::assertSame('native_hourly', $result['2026-06']->priceSource);
        self::assertEqualsWithDelta(0.10, $result['2026-06']->priceHtva, self::DELTA);
    }

    /**
     * Une courbe au pas de 15 min pondère une série HORAIRE en s'agrégeant sur l'heure :
     * 1 kWh à 10:00 et 3 kWh à 11:00, donc (1×0,10 + 3×0,30) / 4 = 0,25 €/kWh.
     */
    public function testQuarterCurveAggregatesOntoAnHourlySeries(): void
    {
        $prices = $this->fullMonthHourly('2026-06', 30, 0.20, [
            '2026-06-10 10:00:00' => 0.10,
            '2026-06-10 11:00:00' => 0.30,
        ]);

        $result = (new MonthlyIndexedPriceCalculator())->perMonth(
            $this->at('2026-06-01 00:00:00'),
            $this->at('2026-07-01 00:00:00'),
            $this->hourlyCandidate($prices),
            [
                ['slot' => '2026-06-10 10:00:00', 'import_kwh' => 0.4, 'native' => true],
                ['slot' => '2026-06-10 10:30:00', 'import_kwh' => 0.6, 'native' => true],
                ['slot' => '2026-06-10 11:15:00', 'import_kwh' => 3.0, 'native' => true],
            ],
        );

        self::assertSame(LoadWeighting::ActualLoad, $result['2026-06']->weighting);
        self::assertEqualsWithDelta(0.25, $result['2026-06']->priceHtva, self::DELTA);
    }

    /** Fenêtre vide ou inversée : rien à calculer, pas d'erreur. */
    public function testEmptyWindowYieldsNoMonth(): void
    {
        $calculator = new MonthlyIndexedPriceCalculator();
        $prices     = $this->hourlyCandidate($this->fullMonthHourly('2026-06', 30, 0.20));

        self::assertSame([], $calculator->perMonth($this->at('2026-06-01 00:00:00'), $this->at('2026-06-01 00:00:00'), $prices, []));
        self::assertSame([], $calculator->perMonth($this->at('2026-07-01 00:00:00'), $this->at('2026-06-01 00:00:00'), $prices, []));
    }
}
