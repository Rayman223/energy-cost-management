<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Domain\ComponentKind;
use App\Domain\TariffGrid;
use App\Domain\TariffLine;
use App\Service\CostCalculationService;
use App\Service\TariffCalculatorService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Fake\FakeDynamicPriceRepository;
use Tests\Fake\FakeGasReadingRepository;
use Tests\Fake\FakeLegacyDailyRepository;
use Tests\Fake\FakeMeterReadingRepository;
use Tests\Fake\FakeTariffRepository;

/**
 * Tests d'orchestration : branches available/false et propagation des calculs.
 * Les repositories sont remplacés par des fakes (aucune base de données) ;
 * le vrai TariffCalculatorService est utilisé pour valider l'intégration.
 */
final class CostCalculationServiceTest extends TestCase
{
    private function makeService(
        FakeLegacyDailyRepository $legacy,
        FakeTariffRepository $tariff,
        FakeGasReadingRepository $gas,
        ?FakeMeterReadingRepository $water = null,
    ): CostCalculationService {
        return new CostCalculationService(
            legacyRepo: $legacy,
            tariffRepo: $tariff,
            gasRepo: $gas,
            calculator: new TariffCalculatorService(),
            waterRepo: $water,
        );
    }

    /** @return array<string,mixed> */
    private function electricityDeltas(): array
    {
        return $this->electricityDeltasFor('2026-06-01 00:00:00', '2026-06-15 12:00:00');
    }

    /** @return array<string,mixed> */
    private function electricityDeltasFor(string $from, string $to): array
    {
        return [
            'from'        => $from,
            'to'          => $to,
            'prelev_jour' => 100.0,
            'prelev_nuit' => 50.0,
            'injec_jour'  => 20.0,
            'injec_nuit'  => 10.0,
            'solar'       => 0.0,
        ];
    }

    private function electricityGrid(): TariffGrid
    {
        return new TariffGrid(
            id: 1,
            energyType: 'electricity',
            name: 'Elec test',
            validFrom: new DateTimeImmutable('2026-01-01'),
            validTo: null,
            lines: [
                'energy_t1'    => new TariffLine('energy_t1', 0.10, ComponentKind::EnergyT1),
                'energy_t2'    => new TariffLine('energy_t2', 0.08, ComponentKind::EnergyT2),
                'subscription' => new TariffLine('subscription', 5.0, ComponentKind::FixedMonthly),
            ],
        );
    }

    /** Grille élec portant la formule d'indexation dynamique du contrat (#228). */
    private function electricityGridWithSpot(float $coefficient, float $offsetTtc, float $vatRate = 21.0): TariffGrid
    {
        return $this->elecGridWithSpot(1, 'Elec spot', '2026-01-01', null, $coefficient, $offsetTtc, $vatRate);
    }

    private function elecGridWithSpot(
        int $id,
        string $name,
        string $validFrom,
        ?string $validTo,
        float $coefficient,
        float $offsetTtc,
        float $vatRate = 21.0,
    ): TariffGrid {
        return new TariffGrid(
            id: $id,
            energyType: 'electricity',
            name: $name,
            validFrom: new DateTimeImmutable($validFrom),
            validTo: $validTo !== null ? new DateTimeImmutable($validTo) : null,
            lines: [
                'energy_t1'        => new TariffLine('energy_t1', 0.10, ComponentKind::EnergyT1),
                'energy_t2'        => new TariffLine('energy_t2', 0.08, ComponentKind::EnergyT2),
                'subscription'     => new TariffLine('subscription', 5.0, ComponentKind::FixedMonthly),
                'spot_coefficient' => new TariffLine('spot_coefficient', $coefficient, ComponentKind::SpotCoefficient),
                'spot_offset'      => new TariffLine('spot_offset', $offsetTtc, ComponentKind::SpotOffset),
            ],
            vatRate: $vatRate,
        );
    }

    private function gasGrid(float $pcs): TariffGrid
    {
        return new TariffGrid(
            id: 2,
            energyType: 'gas',
            name: 'Gaz test',
            validFrom: new DateTimeImmutable('2026-01-01'),
            validTo: null,
            lines: [
                'energy'       => new TariffLine('energy', 0.05, ComponentKind::EnergyFlat),
                'subscription' => new TariffLine('subscription', 3.0, ComponentKind::FixedMonthly),
                'distribution' => new TariffLine('distribution', 0.02, ComponentKind::PerKwh),
            ],
            pcsCoefficient: $pcs,
        );
    }

    // ── Électricité ──────────────────────────────────────────────────────────

    public function testCurrentMonthElectricityUnavailableWhenNoData(): void
    {
        $svc = $this->makeService(
            new FakeLegacyDailyRepository(monthlyDeltas: []),
            new FakeTariffRepository(grid: $this->electricityGrid()),
            new FakeGasReadingRepository(),
        );

        $r = $svc->estimateCurrentMonthElectricity();

        self::assertFalse($r['available']);
        self::assertSame('No data for current month', $r['reason']);
    }

    public function testCurrentMonthElectricityUnavailableWhenNoTariff(): void
    {
        $svc = $this->makeService(
            new FakeLegacyDailyRepository(monthlyDeltas: $this->electricityDeltas()),
            new FakeTariffRepository(grid: null),
            new FakeGasReadingRepository(),
        );

        $r = $svc->estimateCurrentMonthElectricity();

        self::assertFalse($r['available']);
        self::assertSame('No active electricity tariff configured', $r['reason']);
    }

    public function testCurrentMonthElectricityHappyPath(): void
    {
        $svc = $this->makeService(
            new FakeLegacyDailyRepository(monthlyDeltas: $this->electricityDeltas()),
            new FakeTariffRepository(grid: $this->electricityGrid()),
            new FakeGasReadingRepository(),
        );

        $r = $svc->estimateCurrentMonthElectricity();

        self::assertTrue($r['available']);
        self::assertSame(14, $r['days']);                 // [01 00:00, 15 00:00) = 14 j pleins (#130 B1)
        self::assertSame('Elec test', $r['tariff_name']);
        self::assertIsArray($r['cost']);
        self::assertArrayHasKey('total', $r['cost']);
    }

    public function testMonthElectricityUnavailableWhenNoData(): void
    {
        $svc = $this->makeService(
            new FakeLegacyDailyRepository(monthlyDeltasForMonth: []),
            new FakeTariffRepository(grid: $this->electricityGrid()),
            new FakeGasReadingRepository(),
        );

        $r = $svc->estimateMonthElectricity(2026, 3);

        self::assertFalse($r['available']);
        self::assertSame('No data for 2026-03', $r['reason']);
    }

    public function testMonthElectricityHappyPath(): void
    {
        $svc = $this->makeService(
            new FakeLegacyDailyRepository(monthlyDeltasForMonth: $this->electricityDeltas()),
            new FakeTariffRepository(grid: $this->electricityGrid()),
            new FakeGasReadingRepository(),
        );

        $r = $svc->estimateMonthElectricity(2026, 6);

        self::assertTrue($r['available']);
        self::assertSame(14, $r['days']);                 // [01 00:00, 15 00:00) = 14 j pleins (#130 B1)
        self::assertArrayHasKey('total', $r['cost']);
    }

    /**
     * Convention `[from, to)` (#130 B1) : jours pleins, borne droite exclue,
     * comme le gaz. Une heure partielle ne compte pas un jour de plus.
     */
    public function testElectricityDayCountUsesHalfOpenInterval(): void
    {
        $svc = $this->makeService(
            new FakeLegacyDailyRepository(monthlyDeltasForMonth: $this->electricityDeltasFor('2026-07-01 00:00:00', '2026-07-13 08:00:00')),
            new FakeTariffRepository(grid: $this->electricityGrid()),
            new FakeGasReadingRepository(),
        );

        // [01 00:00, 13 00:00) = 12 jours pleins (les 8 h du 13 ne comptent pas).
        self::assertSame(12, $svc->estimateMonthElectricity(2026, 7)['days']);
    }

    public function testElectricityDayCountIntraDayIsOne(): void
    {
        $svc = $this->makeService(
            new FakeLegacyDailyRepository(monthlyDeltasForMonth: $this->electricityDeltasFor('2026-07-05 06:00:00', '2026-07-05 20:00:00')),
            new FakeTariffRepository(grid: $this->electricityGrid()),
            new FakeGasReadingRepository(),
        );

        // Période intra-journée : minimum 1 jour (jamais 0).
        self::assertSame(1, $svc->estimateMonthElectricity(2026, 7)['days']);
    }

    // ── Électricité : tarif dynamique ────────────────────────────────────────

    private function makeDynamicService(
        FakeLegacyDailyRepository $legacy,
        FakeTariffRepository $tariff,
        FakeDynamicPriceRepository $dynamic,
        bool $enabled = true,
        float $supplierMarkupPerKwh = 0.0,
        string $tariffTimezone = 'Europe/Brussels',
        string $pricingMode = 'dynamic_hourly',
    ): CostCalculationService {
        // Pas de taux de TVA ici : il vient de la grille du segment (#232).
        return new CostCalculationService(
            legacyRepo: $legacy,
            tariffRepo: $tariff,
            gasRepo: new FakeGasReadingRepository(),
            calculator: new TariffCalculatorService(),
            dynamicPriceRepo: $dynamic,
            dynamicEnabled: $enabled,
            pricingMode: $pricingMode,
            supplierMarkupPerKwh: $supplierMarkupPerKwh,
            tariffTimezone: $tariffTimezone,
        );
    }

    public function testMonthElectricityDynamicHappyPathWithFallback(): void
    {
        $legacy = new FakeLegacyDailyRepository(
            monthlyDeltasForMonth: $this->electricityDeltas(),
            hourlyImportDeltas: [
                ['hour' => '2026-06-10 10:00:00', 'import_kwh' => 10.0],
                ['hour' => '2026-06-10 23:00:00', 'import_kwh' => 5.0],
            ],
        );
        // Seule l'heure de jour a un prix ; l'heure de nuit retombe sur energy_t2 (0.08).
        $dynamic = new FakeDynamicPriceRepository(pricesByHour: ['2026-06-10 10:00:00' => 0.20]);

        $svc = $this->makeDynamicService($legacy, new FakeTariffRepository(grid: $this->electricityGrid()), $dynamic);
        $r   = $svc->estimateMonthElectricityDynamic(2026, 6);

        self::assertTrue($r['available']);
        // 10 × (0.20 × 1.21) + 5 × 0.08 = 2.42 + 0.40
        self::assertEqualsWithDelta(2.82, $r['energy_dynamic'], 0.0001);
        self::assertEqualsWithDelta(15.0, $r['matched_kwh'], 0.0001);
        self::assertEqualsWithDelta(66.7, $r['coverage_pct'], 0.1); // 10/15 couverts
        self::assertSame('dynamic', $r['cost']['mode']);
        self::assertCount(1, $r['daily']);
    }

    /**
     * Verrou anti-facteur-100 (#153) : `tariff_grids.vat_rate` est un POURCENTAGE
     * (21.0), pas une fraction (0.21). La même valeur numérique interprétée dans la
     * mauvaise unité produit un résultat manifestement différent.
     */
    public function testDynamicVatRateIsPercentNotFraction(): void
    {
        $legacy = new FakeLegacyDailyRepository(
            monthlyDeltasForMonth: $this->electricityDeltas(),
            hourlyImportDeltas: [['hour' => '2026-06-10 10:00:00', 'import_kwh' => 10.0]],
        );
        $prices = new FakeDynamicPriceRepository(pricesByHour: ['2026-06-10 10:00:00' => 0.20]);

        // 21.0 % (correct) : 10 × (0.20 × 1.21) = 2.42.
        $correctGrid = $this->elecGrid(1, 'Elec', '2026-01-01', null, 0.10, 0.08, 21.0);
        $correct     = $this->makeDynamicService($legacy, new FakeTariffRepository(grid: $correctGrid), $prices)
            ->estimateMonthElectricityDynamic(2026, 6);
        self::assertEqualsWithDelta(2.42, $correct['energy_dynamic'], 0.0001);

        // 0.21 (fraction saisie par erreur comme %) : 10 × (0.20 × 1.0021) = 2.0042,
        // arrondi à 2 décimales dans la réponse → 2.0. La différence avec 2.42 reste
        // manifeste (le prix moyen non arrondi la confirme au 6e chiffre).
        $wrongGrid = $this->elecGrid(1, 'Elec', '2026-01-01', null, 0.10, 0.08, 0.21);
        $wrong     = $this->makeDynamicService($legacy, new FakeTariffRepository(grid: $wrongGrid), $prices)
            ->estimateMonthElectricityDynamic(2026, 6);
        self::assertEqualsWithDelta(2.0, $wrong['energy_dynamic'], 0.0001);
        self::assertEqualsWithDelta(0.20042, $wrong['avg_price_kwh'], 0.000001);

        // Les deux unités doivent produire des montants nettement distincts.
        self::assertGreaterThan(0.4, abs($correct['energy_dynamic'] - $wrong['energy_dynamic']));
    }

    /**
     * Source unique du taux (#232) : la TVA du prix spot vient de la grille, donc une
     * seule saisie gouverne à la fois l'indexation du spot et la décomposition HTVA.
     */
    public function testDynamicVatComesFromTheGrid(): void
    {
        $legacy = new FakeLegacyDailyRepository(
            monthlyDeltasForMonth: $this->electricityDeltas(),
            hourlyImportDeltas: [['hour' => '2026-06-10 10:00:00', 'import_kwh' => 10.0]],
        );
        $prices = new FakeDynamicPriceRepository(pricesByHour: ['2026-06-10 10:00:00' => 0.20]);

        $r = $this->makeDynamicService(
            $legacy,
            new FakeTariffRepository(grid: $this->elecGrid(1, 'Elec 6 %', '2026-01-01', null, 0.10, 0.08, 6.0)),
            $prices,
        )->estimateMonthElectricityDynamic(2026, 6);

        // 10 × (0.20 × 1.06) = 2.12, et non 2.42 (21 %).
        self::assertEqualsWithDelta(2.12, $r['energy_dynamic'], 0.0001);
        self::assertSame(6.0, $r['formula']['vat_rate']);
        self::assertSame(6.0, $r['cost']['vat_rate']);
    }

    /**
     * Le taux étant porté par la grille, il est VERSIONNÉ : un passage de 21 % à 6 %
     * en cours de période ne s'applique qu'à partir de sa sous-période (#232), là où
     * un taux unique au profil aurait réécrit tout le mois.
     */
    public function testDynamicVatIsResolvedPerTariffSegment(): void
    {
        $tariffs = new FakeTariffRepository();
        $tariffs->gridsBetween = [
            $this->elecGrid(2, 'Elec 6 %', '2026-01-11', null, 0.10, 0.08, 6.0),
            $this->elecGrid(1, 'Elec 21 %', '2026-01-01', '2026-01-10', 0.10, 0.08, 21.0),
        ];

        $legacy = new FakeLegacyDailyRepository(
            monthlyDeltasForMonth: $this->electricityDeltasFor('2026-01-01 00:00:00', '2026-01-31 00:00:00'),
            hourlyImportDeltas: [
                ['hour' => '2026-01-05 10:00:00', 'import_kwh' => 10.0], // grille à 21 %
                ['hour' => '2026-01-20 10:00:00', 'import_kwh' => 10.0], // grille à 6 %
            ],
        );
        $prices = new FakeDynamicPriceRepository(pricesByHour: [
            '2026-01-05 10:00:00' => 0.20,
            '2026-01-20 10:00:00' => 0.20,
        ]);

        $r = $this->makeDynamicService($legacy, $tariffs, $prices)->estimateMonthElectricityDynamic(2026, 1);

        // 10 × 0.20 × 1.21 + 10 × 0.20 × 1.06 = 2.42 + 2.12 = 4.54.
        self::assertEqualsWithDelta(4.54, $r['energy_dynamic'], 0.0001);
        // 20 jours à 6 % contre 10 à 21 % → la formule exposée est celle du segment dominant.
        self::assertSame(6.0, $r['formula']['vat_rate']);
    }

    /**
     * VERROU #229 — l'invariant dont dépend tout le rapprochement facture :
     *
     *     energy_dynamic = coefficient × indexed_ttc + offset × covered_kwh + uncovered_ttc
     *
     * Il permet de retrouver (coefficient, offset) à partir d'un montant facturé sans
     * rejouer le croisement conso × prix heure par heure. Toute modification de la boucle
     * horaire qui le romprait ferait silencieusement dériver le couple proposé : ce test
     * doit casser à ce moment-là, pas l'écran de rapprochement.
     *
     * Le jeu mélange volontairement une heure couverte par un prix de marché et une heure
     * de repli au tarif classique — c'est la seule configuration où les trois termes sont
     * non nuls en même temps.
     */
    public function testSpotBaseReconstitutesEnergyDynamic(): void
    {
        $legacy = new FakeLegacyDailyRepository(
            monthlyDeltasForMonth: $this->electricityDeltas(),
            hourlyImportDeltas: [
                ['hour' => '2026-06-10 10:00:00', 'import_kwh' => 10.0], // couverte
                ['hour' => '2026-06-10 23:00:00', 'import_kwh' => 5.0],  // repli energy_t2
            ],
        );
        $prices = new FakeDynamicPriceRepository(pricesByHour: ['2026-06-10 10:00:00' => 0.20]);

        $grid = $this->electricityGridWithSpot(coefficient: 1.08, offsetTtc: 0.02);
        $r    = $this->makeDynamicService($legacy, new FakeTariffRepository(grid: $grid), $prices)
            ->estimateMonthElectricityDynamic(2026, 6);

        // Base indexée AVANT coefficient et offset : 10 × 0.20 × 1.21 = 2.42.
        self::assertEqualsWithDelta(2.42, $r['spot_base']['indexed_ttc'], 0.0001);
        self::assertEqualsWithDelta(10.0, $r['spot_base']['covered_kwh'], 0.0001);
        // Heure de repli : 5 × 0.08 (energy_t2, déjà TTC) = 0.40.
        self::assertEqualsWithDelta(0.40, $r['spot_base']['uncovered_ttc'], 0.0001);
        self::assertTrue($r['spot_base']['formula_uniform']);

        // L'invariant lui-même, reconstruit depuis les seules valeurs exposées :
        // 1.08 × 2.42 + 0.02 × 10 + 0.40 = 3.2136.
        $reconstructed = $r['formula']['spot_coefficient'] * $r['spot_base']['indexed_ttc']
            + $r['formula']['spot_offset_ttc'] * $r['spot_base']['covered_kwh']
            + $r['spot_base']['uncovered_ttc'];
        // Tolérance d'un demi-centime : `energy_dynamic` est arrondi à 2 décimales pour
        // l'affichage, alors que les termes de `spot_base` gardent leur précision — c'est
        // justement pourquoi la résolution s'appuie sur ces derniers et non sur le total.
        self::assertEqualsWithDelta($r['energy_dynamic'], $reconstructed, 0.005);
    }

    /**
     * Un mois à cheval sur deux contrats aux formules différentes n'admet pas de couple
     * unique : son équation mêlerait les deux. `formula_uniform` doit le dire, sinon le
     * rapprochement attribuerait la moyenne des deux contrats à celui en cours (#229).
     */
    public function testSpotBaseFlagsMonthSpanningTwoDifferentFormulas(): void
    {
        $tariffs = new FakeTariffRepository();
        $tariffs->gridsBetween = [
            $this->elecGridWithSpot(2, 'Contrat 2', '2026-01-11', null, 1.05, 0.03),
            $this->elecGridWithSpot(1, 'Contrat 1', '2026-01-01', '2026-01-10', 1.08, 0.02),
        ];

        $legacy = new FakeLegacyDailyRepository(
            monthlyDeltasForMonth: $this->electricityDeltasFor('2026-01-01 00:00:00', '2026-01-31 00:00:00'),
            hourlyImportDeltas: [
                ['hour' => '2026-01-05 10:00:00', 'import_kwh' => 10.0],
                ['hour' => '2026-01-20 10:00:00', 'import_kwh' => 10.0],
            ],
        );
        $prices = new FakeDynamicPriceRepository(pricesByHour: [
            '2026-01-05 10:00:00' => 0.20,
            '2026-01-20 10:00:00' => 0.20,
        ]);

        $r = $this->makeDynamicService($legacy, $tariffs, $prices)->estimateMonthElectricityDynamic(2026, 1);

        self::assertFalse($r['spot_base']['formula_uniform']);
    }

    /**
     * Le taux de TVA compte dans `formula_uniform` bien qu'il n'apparaisse pas dans
     * l'invariant : l'appelant s'en sert pour convertir un montant facturé HTVA en TTC.
     * Deux sous-périodes de même formule mais de TVA différente rendraient cette
     * conversion ambiguë, alors même que la résolution resterait exacte (suivi de revue
     * de la PR #236).
     */
    public function testSpotBaseFlagsMonthSpanningTwoVatRates(): void
    {
        $tariffs = new FakeTariffRepository();
        $tariffs->gridsBetween = [
            $this->elecGridWithSpot(2, 'TVA 6 %', '2026-01-11', null, 1.08, 0.02, 6.0),
            $this->elecGridWithSpot(1, 'TVA 21 %', '2026-01-01', '2026-01-10', 1.08, 0.02, 21.0),
        ];

        $legacy = new FakeLegacyDailyRepository(
            monthlyDeltasForMonth: $this->electricityDeltasFor('2026-01-01 00:00:00', '2026-01-31 00:00:00'),
            hourlyImportDeltas: [
                ['hour' => '2026-01-05 10:00:00', 'import_kwh' => 10.0],
                ['hour' => '2026-01-20 10:00:00', 'import_kwh' => 10.0],
            ],
        );
        $prices = new FakeDynamicPriceRepository(pricesByHour: [
            '2026-01-05 10:00:00' => 0.20,
            '2026-01-20 10:00:00' => 0.20,
        ]);

        $r = $this->makeDynamicService($legacy, $tariffs, $prices)->estimateMonthElectricityDynamic(2026, 1);

        self::assertFalse($r['spot_base']['formula_uniform']);
    }

    /**
     * À l'inverse, un mois découpé en deux sous-périodes aux paramètres IDENTIQUES reste
     * uniforme : la comparaison est à tolérance, pour qu'un coefficient composé
     * différemment (1,08 d'un côté, 1,04 × 1,0385 de l'autre) ne le déclare pas
     * faussement non-uniforme sur un écart de dernier bit.
     */
    public function testSpotBaseStaysUniformAcrossIdenticalConsecutiveGrids(): void
    {
        $tariffs = new FakeTariffRepository();
        $tariffs->gridsBetween = [
            $this->elecGridWithSpot(2, 'Renouvellement', '2026-01-11', null, 1.08, 0.02),
            $this->elecGridWithSpot(1, 'Contrat initial', '2026-01-01', '2026-01-10', 1.08, 0.02),
        ];

        $legacy = new FakeLegacyDailyRepository(
            monthlyDeltasForMonth: $this->electricityDeltasFor('2026-01-01 00:00:00', '2026-01-31 00:00:00'),
            hourlyImportDeltas: [
                ['hour' => '2026-01-05 10:00:00', 'import_kwh' => 10.0],
                ['hour' => '2026-01-20 10:00:00', 'import_kwh' => 10.0],
            ],
        );
        $prices = new FakeDynamicPriceRepository(pricesByHour: [
            '2026-01-05 10:00:00' => 0.20,
            '2026-01-20 10:00:00' => 0.20,
        ]);

        $r = $this->makeDynamicService($legacy, $tariffs, $prices)->estimateMonthElectricityDynamic(2026, 1);

        self::assertTrue($r['spot_base']['formula_uniform']);
    }

    /**
     * Non-régression #228 : sans ligne spot dans la grille, la formule doit reproduire
     * exactement l'ancien calcul `spot × (1+TVA) + supplier_markup_per_kwh`. Ce test est
     * le garde-fou du lot : aucun montant déjà affiché ne doit bouger.
     */
    public function testDynamicWithoutSpotLinesReproducesProfileMarkupBehaviour(): void
    {
        $legacy = new FakeLegacyDailyRepository(
            monthlyDeltasForMonth: $this->electricityDeltas(),
            hourlyImportDeltas: [['hour' => '2026-06-10 10:00:00', 'import_kwh' => 10.0]],
        );
        $prices = new FakeDynamicPriceRepository(pricesByHour: ['2026-06-10 10:00:00' => 0.20]);

        $r = $this->makeDynamicService(
            $legacy,
            new FakeTariffRepository(grid: $this->electricityGrid()),
            $prices,
            supplierMarkupPerKwh: 0.0145,
        )->estimateMonthElectricityDynamic(2026, 6);

        // 10 × (0.20 × 1.0 × 1.21 + 0.0145) = 2.565, arrondi à 2 décimales dans la réponse.
        self::assertEqualsWithDelta(2.57, $r['energy_dynamic'], 0.0001);
        self::assertEqualsWithDelta(0.2565, $r['avg_price_kwh'], 0.000001);
        self::assertSame(1.0, $r['formula']['spot_coefficient']);
        self::assertEqualsWithDelta(0.0145, $r['formula']['spot_offset_ttc'], 0.0000001);
        self::assertSame('profile', $r['formula']['offset_source']);
        self::assertFalse($r['formula']['coefficient_rejected']);
    }

    /** La grille porte la formule du contrat : coefficient × spot + marge (#228). */
    public function testDynamicAppliesGridCoefficientAndOffset(): void
    {
        $legacy = new FakeLegacyDailyRepository(
            monthlyDeltasForMonth: $this->electricityDeltas(),
            hourlyImportDeltas: [['hour' => '2026-06-10 10:00:00', 'import_kwh' => 10.0]],
        );
        $prices = new FakeDynamicPriceRepository(pricesByHour: ['2026-06-10 10:00:00' => 0.20]);
        $grid   = $this->electricityGridWithSpot(coefficient: 1.08, offsetTtc: 0.0145, vatRate: 6.0);

        $r = $this->makeDynamicService($legacy, new FakeTariffRepository(grid: $grid), $prices)
            ->estimateMonthElectricityDynamic(2026, 6);

        // 10 × (0.20 × 1.08 × 1.06 + 0.0145) = 2.4346, arrondi à 2 décimales.
        self::assertEqualsWithDelta(2.43, $r['energy_dynamic'], 0.0001);
        self::assertEqualsWithDelta(0.24346, $r['avg_price_kwh'], 0.000001);
        self::assertEqualsWithDelta(1.08, $r['formula']['spot_coefficient'], 0.0000001);
        self::assertSame('grid', $r['formula']['offset_source']);
    }

    /** Anti-double-comptage : une marge en grille écarte celle du profil (#228). */
    public function testDynamicGridOffsetOverridesProfileMarkup(): void
    {
        $legacy = new FakeLegacyDailyRepository(
            monthlyDeltasForMonth: $this->electricityDeltas(),
            hourlyImportDeltas: [['hour' => '2026-06-10 10:00:00', 'import_kwh' => 10.0]],
        );
        $prices = new FakeDynamicPriceRepository(pricesByHour: ['2026-06-10 10:00:00' => 0.20]);
        $grid   = $this->electricityGridWithSpot(coefficient: 1.0, offsetTtc: 0.02);

        $r = $this->makeDynamicService(
            $legacy,
            new FakeTariffRepository(grid: $grid),
            $prices,
            supplierMarkupPerKwh: 0.0145,
        )->estimateMonthElectricityDynamic(2026, 6);

        // 10 × (0.20 × 1.21 + 0.02) = 2.62 — et non 2.765 (0.02 + 0.0145 cumulés).
        self::assertEqualsWithDelta(2.62, $r['energy_dynamic'], 0.0001);
        self::assertEqualsWithDelta(0.02, $r['formula']['spot_offset_ttc'], 0.0000001);
    }

    /**
     * Un coefficient saisi SANS ligne de marge ne doit pas escamoter celle du profil :
     * la perte serait invisible (le champ reste rempli dans /account) et porterait sur
     * chaque heure de la période.
     */
    public function testDynamicCoefficientAloneKeepsProfileMarkup(): void
    {
        $legacy = new FakeLegacyDailyRepository(
            monthlyDeltasForMonth: $this->electricityDeltas(),
            hourlyImportDeltas: [['hour' => '2026-06-10 10:00:00', 'import_kwh' => 10.0]],
        );
        $prices = new FakeDynamicPriceRepository(pricesByHour: ['2026-06-10 10:00:00' => 0.20]);

        $grid = new TariffGrid(
            id: 1,
            energyType: 'electricity',
            name: 'Coef seul',
            validFrom: new DateTimeImmutable('2026-01-01'),
            validTo: null,
            lines: [
                'energy_t1'        => new TariffLine('energy_t1', 0.10, ComponentKind::EnergyT1),
                'spot_coefficient' => new TariffLine('spot_coefficient', 1.08, ComponentKind::SpotCoefficient),
            ],
        );

        $r = $this->makeDynamicService(
            $legacy,
            new FakeTariffRepository(grid: $grid),
            $prices,
            supplierMarkupPerKwh: 0.025,
        )->estimateMonthElectricityDynamic(2026, 6);

        // 10 × (0.20 × 1.08 × 1.21 + 0.025) = 2.8636 — et non 2.6136 (marge perdue).
        self::assertEqualsWithDelta(2.86, $r['energy_dynamic'], 0.0001);
        self::assertEqualsWithDelta(0.025, $r['formula']['spot_offset_ttc'], 0.0000001);
        self::assertSame('profile', $r['formula']['offset_source']);
    }

    /**
     * Un coefficient hors bornes est neutralisé, mais l'utilisateur doit le savoir :
     * sinon le dashboard annonce une formule qui ne correspond ni à sa grille, ni au
     * repli profil. Chemin atteignable par l'API, non validée sur ce point.
     */
    public function testDynamicReportsRejectedCoefficient(): void
    {
        $legacy = new FakeLegacyDailyRepository(
            monthlyDeltasForMonth: $this->electricityDeltas(),
            hourlyImportDeltas: [['hour' => '2026-06-10 10:00:00', 'import_kwh' => 10.0]],
        );
        $prices = new FakeDynamicPriceRepository(pricesByHour: ['2026-06-10 10:00:00' => 0.20]);
        // 108 au lieu de 1,08 : pourcentage saisi tel quel.
        $grid   = $this->electricityGridWithSpot(coefficient: 108.0, offsetTtc: 0.0145);

        $r = $this->makeDynamicService($legacy, new FakeTariffRepository(grid: $grid), $prices)
            ->estimateMonthElectricityDynamic(2026, 6);

        self::assertSame(1.0, $r['formula']['spot_coefficient']);
        self::assertTrue($r['formula']['coefficient_rejected']);
        // 10 × (0.20 × 1.21 + 0.0145) = 2.565 → le spot n'a PAS été multiplié par 108.
        self::assertEqualsWithDelta(2.57, $r['energy_dynamic'], 0.0001);
    }

    /** Les paramètres de formule ne sont jamais facturés comme un poste de coût (#228). */
    public function testSpotLinesAreNotBilledAsCostComponents(): void
    {
        $legacy = new FakeLegacyDailyRepository(
            monthlyDeltasForMonth: $this->electricityDeltas(),
            hourlyImportDeltas: [['hour' => '2026-06-10 10:00:00', 'import_kwh' => 10.0]],
        );
        $prices = new FakeDynamicPriceRepository(pricesByHour: ['2026-06-10 10:00:00' => 0.20]);
        $grid   = $this->electricityGridWithSpot(coefficient: 1.08, offsetTtc: 0.0145);

        $r    = $this->makeDynamicService($legacy, new FakeTariffRepository(grid: $grid), $prices)
            ->estimateMonthElectricityDynamic(2026, 6);
        $keys = array_column($r['cost']['lines'], 'key');

        self::assertNotContains('spot_coefficient', $keys);
        self::assertNotContains('spot_offset', $keys);
    }

    /**
     * Même grille en tarif FIXE : les lignes spot restent inertes. Un coefficient 1,08
     * facturé comme 1,08 €/kWh gonflerait le total de 162 € sur 150 kWh.
     */
    public function testSpotLinesAreInertInFixedMode(): void
    {
        $withSpot = $this->makeService(
            new FakeLegacyDailyRepository(monthlyDeltasForMonth: $this->electricityDeltas()),
            new FakeTariffRepository(grid: $this->electricityGridWithSpot(coefficient: 1.08, offsetTtc: 0.0145)),
            new FakeGasReadingRepository(),
        )->estimateMonthElectricity(2026, 6);

        $withoutSpot = $this->makeService(
            new FakeLegacyDailyRepository(monthlyDeltasForMonth: $this->electricityDeltas()),
            new FakeTariffRepository(grid: $this->electricityGrid()),
            new FakeGasReadingRepository(),
        )->estimateMonthElectricity(2026, 6);

        self::assertEqualsWithDelta($withoutSpot['cost']['total'], $withSpot['cost']['total'], 0.0001);
    }

    /**
     * Les paramètres vivant dans la grille, ils changent avec elle : chaque sous-période
     * doit indexer son énergie avec SON coefficient (#196 + #228).
     */
    public function testDynamicResolvesFormulaPerTariffSegment(): void
    {
        $tariffs = new FakeTariffRepository();
        $tariffs->gridsBetween = [
            $this->elecGridWithSpot(2, 'Elec B', '2026-01-11', null, 2.0, 0.0),
            $this->elecGridWithSpot(1, 'Elec A', '2026-01-01', '2026-01-10', 1.0, 0.0),
        ];

        $legacy = new FakeLegacyDailyRepository(
            monthlyDeltasForMonth: $this->electricityDeltasFor('2026-01-01 00:00:00', '2026-01-31 00:00:00'),
            hourlyImportDeltas: [
                ['hour' => '2026-01-05 10:00:00', 'import_kwh' => 10.0], // grille A, coef 1,0
                ['hour' => '2026-01-20 10:00:00', 'import_kwh' => 10.0], // grille B, coef 2,0
            ],
        );
        $prices = new FakeDynamicPriceRepository(pricesByHour: [
            '2026-01-05 10:00:00' => 0.20,
            '2026-01-20 10:00:00' => 0.20,
        ]);

        $r = $this->makeDynamicService($legacy, $tariffs, $prices)->estimateMonthElectricityDynamic(2026, 1);

        // A : 10 × 0.20 × 1.0 × 1.21 = 2.42 ; B : 10 × 0.20 × 2.0 × 1.21 = 4.84 → 7.26.
        // Une formule unique, quelle qu'elle soit, ne pourrait pas produire ce total.
        self::assertEqualsWithDelta(7.26, $r['energy_dynamic'], 0.0001);
        // 20 jours pour B contre 10 pour A → la formule exposée est celle de B, comme
        // `tariff_rates` expose la grille de la sous-période la plus longue.
        self::assertEqualsWithDelta(2.0, $r['formula']['spot_coefficient'], 0.0000001);
    }

    public function testMonthElectricityDynamicUsesNativeHourlyOverAverage(): void
    {
        $legacy = new FakeLegacyDailyRepository(
            monthlyDeltasForMonth: $this->electricityDeltas(),
            hourlyImportDeltas: [['hour' => '2026-06-10 10:00:00', 'import_kwh' => 10.0]],
        );
        // Prix horaire natif (0.30) ≠ moyenne des 15 min (0.20) : le natif prime.
        $dynamic = new FakeDynamicPriceRepository(
            pricesByHour: ['2026-06-10 10:00:00' => 0.20],
            hourlyPricesByHour: ['2026-06-10 10:00:00' => 0.30],
        );

        $svc = $this->makeDynamicService($legacy, new FakeTariffRepository(grid: $this->electricityGrid()), $dynamic);
        $r   = $svc->estimateMonthElectricityDynamic(2026, 6);

        self::assertTrue($r['available']);
        self::assertSame('native_hourly', $r['price_source']);
        self::assertSame('hourly', $r['resolution']);
        // 10 × (0.30 × 1.21) = 3.63 (natif), et non 10 × (0.20 × 1.21) = 2.42 (moyenne).
        self::assertEqualsWithDelta(3.63, $r['energy_dynamic'], 0.0001);
    }

    public function testMonthElectricityDynamicFallsBackToAverageWhenNoNativeHourly(): void
    {
        $legacy = new FakeLegacyDailyRepository(
            monthlyDeltasForMonth: $this->electricityDeltas(),
            hourlyImportDeltas: [['hour' => '2026-06-10 10:00:00', 'import_kwh' => 10.0]],
        );
        // Aucune série horaire native → repli sur la moyenne des 15 min (0.20).
        $dynamic = new FakeDynamicPriceRepository(pricesByHour: ['2026-06-10 10:00:00' => 0.20]);

        $svc = $this->makeDynamicService($legacy, new FakeTariffRepository(grid: $this->electricityGrid()), $dynamic);
        $r   = $svc->estimateMonthElectricityDynamic(2026, 6);

        self::assertTrue($r['available']);
        self::assertSame('avg_hourly', $r['price_source']);
        self::assertEqualsWithDelta(2.42, $r['energy_dynamic'], 0.0001);
    }

    /**
     * Mode 'dynamic_quarter' (#230) : quand la zone publie des prix PT15M et que les
     * relevés sont au pas de 15 min, chaque quart d'heure est facturé à SON prix.
     * Le jeu de données est choisi pour que les deux calculs divergent franchement —
     * toute la consommation tombe dans le quart le moins cher, donc un calcul resté
     * horaire (prix moyen 0,20) coûterait le double.
     */
    public function testMonthElectricityDynamicUsesQuarterPricesWhenReadingsAreQuarterHourly(): void
    {
        $legacy = new FakeLegacyDailyRepository(
            monthlyDeltasForMonth: $this->electricityDeltas(),
            hourlyImportDeltas: [['hour' => '2026-06-10 10:00:00', 'import_kwh' => 10.0]],
            quarterImportDeltas: [
                ['quarter' => '2026-06-10 10:00:00', 'import_kwh' => 10.0, 'native' => true],
                ['quarter' => '2026-06-10 10:15:00', 'import_kwh' => 0.0,  'native' => true],
                ['quarter' => '2026-06-10 10:30:00', 'import_kwh' => 0.0,  'native' => true],
                ['quarter' => '2026-06-10 10:45:00', 'import_kwh' => 0.0,  'native' => true],
            ],
        );
        $dynamic = new FakeDynamicPriceRepository(
            hourlyPricesByHour: ['2026-06-10 10:00:00' => 0.20],
            quarterPricesBySlot: [
                '2026-06-10 10:00:00' => 0.10,
                '2026-06-10 10:15:00' => 0.10,
                '2026-06-10 10:30:00' => 0.10,
                '2026-06-10 10:45:00' => 0.50,
            ],
        );

        $svc = $this->makeDynamicService(
            $legacy,
            new FakeTariffRepository(grid: $this->electricityGrid()),
            $dynamic,
            pricingMode: 'dynamic_quarter',
        );
        $r = $svc->estimateMonthElectricityDynamic(2026, 6);

        self::assertTrue($r['available']);
        self::assertSame('quarter', $r['resolution']);
        self::assertSame('quarter', $r['resolution_requested']);
        self::assertNull($r['resolution_fallback']);
        self::assertSame('native_quarter', $r['price_source']);
        // 10 × (0.10 × 1.21) = 1.21 au quart d'heure, contre 2.42 à l'heure.
        self::assertEqualsWithDelta(1.21, $r['energy_dynamic'], 0.0001);
        self::assertEqualsWithDelta(100.0, $r['coverage_pct'], 0.1);
    }

    /**
     * Relevés trop grossiers : la consommation intra-horaire serait reconstruite par
     * étalement (`native` faux), pas mesurée. On préfère alors le prix horaire NATIF
     * à un quart-horaire de façade, et on dit pourquoi.
     */
    public function testMonthElectricityDynamicFallsBackToHourlyWhenReadingsAreNotQuarterHourly(): void
    {
        $legacy = new FakeLegacyDailyRepository(
            monthlyDeltasForMonth: $this->electricityDeltas(),
            hourlyImportDeltas: [['hour' => '2026-06-10 10:00:00', 'import_kwh' => 10.0]],
            quarterImportDeltas: [
                ['quarter' => '2026-06-10 10:00:00', 'import_kwh' => 2.5, 'native' => false],
                ['quarter' => '2026-06-10 10:15:00', 'import_kwh' => 2.5, 'native' => false],
                ['quarter' => '2026-06-10 10:30:00', 'import_kwh' => 2.5, 'native' => false],
                ['quarter' => '2026-06-10 10:45:00', 'import_kwh' => 2.5, 'native' => false],
            ],
        );
        $dynamic = new FakeDynamicPriceRepository(
            hourlyPricesByHour: ['2026-06-10 10:00:00' => 0.20],
            quarterPricesBySlot: ['2026-06-10 10:00:00' => 0.10, '2026-06-10 10:45:00' => 0.50],
        );

        $svc = $this->makeDynamicService(
            $legacy,
            new FakeTariffRepository(grid: $this->electricityGrid()),
            $dynamic,
            pricingMode: 'dynamic_quarter',
        );
        $r = $svc->estimateMonthElectricityDynamic(2026, 6);

        self::assertSame('hourly', $r['resolution']);
        self::assertSame('quarter', $r['resolution_requested']);
        self::assertSame('readings_not_quarter', $r['resolution_fallback']);
        self::assertSame('native_hourly', $r['price_source']);
        // 10 × (0.20 × 1.21) = 2.42 : le prix horaire natif, pas les prix 15 min.
        self::assertEqualsWithDelta(2.42, $r['energy_dynamic'], 0.0001);
    }

    /** Zone sans série PT15M sur la période : repli horaire, avec la raison distincte. */
    public function testMonthElectricityDynamicFallsBackToHourlyWithoutQuarterPrices(): void
    {
        $legacy = new FakeLegacyDailyRepository(
            monthlyDeltasForMonth: $this->electricityDeltas(),
            hourlyImportDeltas: [['hour' => '2026-06-10 10:00:00', 'import_kwh' => 10.0]],
            quarterImportDeltas: [
                ['quarter' => '2026-06-10 10:00:00', 'import_kwh' => 10.0, 'native' => true],
            ],
        );
        $dynamic = new FakeDynamicPriceRepository(hourlyPricesByHour: ['2026-06-10 10:00:00' => 0.20]);

        $svc = $this->makeDynamicService(
            $legacy,
            new FakeTariffRepository(grid: $this->electricityGrid()),
            $dynamic,
            pricingMode: 'dynamic_quarter',
        );
        $r = $svc->estimateMonthElectricityDynamic(2026, 6);

        self::assertSame('hourly', $r['resolution']);
        self::assertSame('no_quarter_prices', $r['resolution_fallback']);
        self::assertEqualsWithDelta(2.42, $r['energy_dynamic'], 0.0001);
    }

    /**
     * Prix PT15M présents mais ne couvrant qu'une bribe de la consommation (mois de
     * bascule MTU15, cron qui n'a rétro-rempli que les derniers jours). Basculer
     * quand même serait pire qu'un retour à l'horaire : les créneaux sans prix 15 min
     * ne retombent pas sur le spot horaire mais sur le tarif fournisseur CLASSIQUE,
     * donc l'essentiel du mois sortirait du tarif dynamique.
     */
    public function testMonthElectricityDynamicFallsBackWhenQuarterPricesBarelyCoverConsumption(): void
    {
        $legacy = new FakeLegacyDailyRepository(
            monthlyDeltasForMonth: $this->electricityDeltas(),
            hourlyImportDeltas: [['hour' => '2026-06-10 10:00:00', 'import_kwh' => 10.0]],
            quarterImportDeltas: [
                ['quarter' => '2026-06-10 10:00:00', 'import_kwh' => 1.0, 'native' => true],
                ['quarter' => '2026-06-10 10:15:00', 'import_kwh' => 9.0, 'native' => true],
            ],
        );
        // Un seul créneau tarifé sur les deux, soit 10 % des kWh couverts.
        $dynamic = new FakeDynamicPriceRepository(
            hourlyPricesByHour: ['2026-06-10 10:00:00' => 0.20],
            quarterPricesBySlot: ['2026-06-10 10:00:00' => 0.10],
        );

        $svc = $this->makeDynamicService(
            $legacy,
            new FakeTariffRepository(grid: $this->electricityGrid()),
            $dynamic,
            pricingMode: 'dynamic_quarter',
        );
        $r = $svc->estimateMonthElectricityDynamic(2026, 6);

        self::assertSame('hourly', $r['resolution']);
        self::assertSame('no_quarter_prices', $r['resolution_fallback']);
        self::assertSame('native_hourly', $r['price_source']);
        // 10 × (0.20 × 1.21) = 2.42, entièrement au spot horaire — et non 9 kWh au
        // tarif classique T1 (0.10) faute de prix 15 min.
        self::assertEqualsWithDelta(2.42, $r['energy_dynamic'], 0.0001);
        self::assertEqualsWithDelta(100.0, $r['coverage_pct'], 0.1);
    }

    /** Symétrique : une couverture de prix quasi complète laisse bien passer le quart. */
    public function testMonthElectricityDynamicKeepsQuarterWhenPriceCoverageIsNearlyComplete(): void
    {
        $legacy = new FakeLegacyDailyRepository(
            monthlyDeltasForMonth: $this->electricityDeltas(),
            hourlyImportDeltas: [['hour' => '2026-06-10 10:00:00', 'import_kwh' => 10.0]],
            quarterImportDeltas: [
                ['quarter' => '2026-06-10 10:00:00', 'import_kwh' => 9.0, 'native' => true],
                ['quarter' => '2026-06-10 10:15:00', 'import_kwh' => 1.0, 'native' => true],
            ],
        );
        $dynamic = new FakeDynamicPriceRepository(
            hourlyPricesByHour: ['2026-06-10 10:00:00' => 0.20],
            quarterPricesBySlot: ['2026-06-10 10:00:00' => 0.10],
        );

        $svc = $this->makeDynamicService(
            $legacy,
            new FakeTariffRepository(grid: $this->electricityGrid()),
            $dynamic,
            pricingMode: 'dynamic_quarter',
        );
        $r = $svc->estimateMonthElectricityDynamic(2026, 6);

        self::assertSame('quarter', $r['resolution']);
        self::assertNull($r['resolution_fallback']);
        // 9 × (0.10 × 1.21) au spot 15 min + 1 × 0.10 au tarif classique T1.
        self::assertEqualsWithDelta(1.189, $r['energy_dynamic'], 0.01);
        self::assertEqualsWithDelta(90.0, $r['coverage_pct'], 0.1);
    }

    /**
     * Le seuil (80 % de la conso mesurée au pas de 15 min) tolère les trous ponctuels
     * d'un flux quart-horaire sans faire basculer un mois majoritairement reconstruit.
     */
    public function testMonthElectricityDynamicQuarterThresholdToleratesGaps(): void
    {
        $quarterPrices = [
            '2026-06-10 10:00:00' => 0.10,
            '2026-06-10 10:15:00' => 0.10,
            '2026-06-10 10:30:00' => 0.10,
            '2026-06-10 10:45:00' => 0.10,
        ];

        $slotsFor = static fn (float $nativeKwh, float $estimatedKwh): array => [
            ['quarter' => '2026-06-10 10:00:00', 'import_kwh' => $nativeKwh,    'native' => true],
            ['quarter' => '2026-06-10 10:15:00', 'import_kwh' => $estimatedKwh, 'native' => false],
        ];

        // 8,5 / 10 kWh mesurés au quart d'heure → 85 %, au-dessus du seuil.
        $above = $this->makeDynamicService(
            new FakeLegacyDailyRepository(
                monthlyDeltasForMonth: $this->electricityDeltas(),
                hourlyImportDeltas: [['hour' => '2026-06-10 10:00:00', 'import_kwh' => 10.0]],
                quarterImportDeltas: $slotsFor(8.5, 1.5),
            ),
            new FakeTariffRepository(grid: $this->electricityGrid()),
            new FakeDynamicPriceRepository(
                hourlyPricesByHour: ['2026-06-10 10:00:00' => 0.20],
                quarterPricesBySlot: $quarterPrices,
            ),
            pricingMode: 'dynamic_quarter',
        );

        // 7 / 10 kWh seulement → 70 %, sous le seuil.
        $below = $this->makeDynamicService(
            new FakeLegacyDailyRepository(
                monthlyDeltasForMonth: $this->electricityDeltas(),
                hourlyImportDeltas: [['hour' => '2026-06-10 10:00:00', 'import_kwh' => 10.0]],
                quarterImportDeltas: $slotsFor(7.0, 3.0),
            ),
            new FakeTariffRepository(grid: $this->electricityGrid()),
            new FakeDynamicPriceRepository(
                hourlyPricesByHour: ['2026-06-10 10:00:00' => 0.20],
                quarterPricesBySlot: $quarterPrices,
            ),
            pricingMode: 'dynamic_quarter',
        );

        self::assertSame('quarter', $above->estimateMonthElectricityDynamic(2026, 6)['resolution']);
        self::assertSame('hourly', $below->estimateMonthElectricityDynamic(2026, 6)['resolution']);
    }

    /**
     * Non-régression du mode horaire : des prix 15 min disponibles ne doivent pas
     * changer le calcul d'un utilisateur qui a choisi le tarif horaire.
     */
    public function testMonthElectricityDynamicHourlyModeIgnoresQuarterData(): void
    {
        $legacy = new FakeLegacyDailyRepository(
            monthlyDeltasForMonth: $this->electricityDeltas(),
            hourlyImportDeltas: [['hour' => '2026-06-10 10:00:00', 'import_kwh' => 10.0]],
            quarterImportDeltas: [
                ['quarter' => '2026-06-10 10:00:00', 'import_kwh' => 10.0, 'native' => true],
            ],
        );
        $dynamic = new FakeDynamicPriceRepository(
            hourlyPricesByHour: ['2026-06-10 10:00:00' => 0.20],
            quarterPricesBySlot: ['2026-06-10 10:00:00' => 0.10],
        );

        $svc = $this->makeDynamicService(
            $legacy,
            new FakeTariffRepository(grid: $this->electricityGrid()),
            $dynamic,
            pricingMode: 'dynamic_hourly',
        );
        $r = $svc->estimateMonthElectricityDynamic(2026, 6);

        self::assertSame('hourly', $r['resolution']);
        self::assertSame('hourly', $r['resolution_requested']);
        self::assertNull($r['resolution_fallback']);
        self::assertEqualsWithDelta(2.42, $r['energy_dynamic'], 0.0001);
    }

    /**
     * Mois à cheval sur la bascule MTU15 : des heures natives sur la première moitié,
     * des points 15 min sur la seconde. Se contenter de « la série native est-elle
     * vide ? » ferait facturer la seconde moitié au tarif fournisseur classique, sans
     * que `price_source` en dise rien. La moyenne horaire agrège les deux résolutions
     * et couvre tout le mois : c'est elle qu'il faut prendre.
     */
    public function testMonthElectricityDynamicPrefersAverageWhenNativeHourlyIsPartial(): void
    {
        $legacy = new FakeLegacyDailyRepository(
            monthlyDeltasForMonth: $this->electricityDeltas(),
            hourlyImportDeltas: [
                ['hour' => '2026-06-05 10:00:00', 'import_kwh' => 2.0],
                ['hour' => '2026-06-25 10:00:00', 'import_kwh' => 8.0],
            ],
        );
        $dynamic = new FakeDynamicPriceRepository(
            // La moyenne couvre les deux heures ; le natif s'arrête à la bascule.
            pricesByHour: ['2026-06-05 10:00:00' => 0.20, '2026-06-25 10:00:00' => 0.20],
            hourlyPricesByHour: ['2026-06-05 10:00:00' => 0.30],
        );

        $svc = $this->makeDynamicService($legacy, new FakeTariffRepository(grid: $this->electricityGrid()), $dynamic);
        $r   = $svc->estimateMonthElectricityDynamic(2026, 6);

        self::assertSame('avg_hourly', $r['price_source']);
        self::assertEqualsWithDelta(100.0, $r['coverage_pct'], 0.1);
        // 10 × (0.20 × 1.21) = 2.42, et non 2 kWh au spot + 8 kWh au tarif classique T1.
        self::assertEqualsWithDelta(2.42, $r['energy_dynamic'], 0.0001);
    }

    public function testMonthElectricityDynamicUnavailableWithoutPrices(): void
    {
        $legacy = new FakeLegacyDailyRepository(
            monthlyDeltasForMonth: $this->electricityDeltas(),
            hourlyImportDeltas: [['hour' => '2026-06-10 10:00:00', 'import_kwh' => 10.0]],
        );

        $svc = $this->makeDynamicService(
            $legacy,
            new FakeTariffRepository(grid: $this->electricityGrid()),
            new FakeDynamicPriceRepository(),
        );
        $r = $svc->estimateMonthElectricityDynamic(2026, 6);

        self::assertFalse($r['available']);
    }

    /**
     * Repli tarif classique : la bascule jour/nuit T1/T2 doit être évaluée dans le
     * fuseau du contrat, pas sur l'heure UTC stockée. 05:00 UTC = 07:00 Europe/Brussels
     * (été, UTC+2) → JOUR (T1 0.10). Une lecture brute de l'heure UTC (05) classerait
     * à tort en NUIT (T2 0.08).
     */
    public function testDynamicFallbackClassifiesDayNightInContractTimezone(): void
    {
        $legacy = new FakeLegacyDailyRepository(
            monthlyDeltasForMonth: $this->electricityDeltas(),
            hourlyImportDeltas: [
                ['hour' => '2026-06-10 12:00:00', 'import_kwh' => 1.0],   // couverte par un prix
                ['hour' => '2026-06-10 05:00:00', 'import_kwh' => 10.0],  // sans prix → repli classique
            ],
        );
        $dynamic = new FakeDynamicPriceRepository(pricesByHour: ['2026-06-10 12:00:00' => 0.20]);

        // Fuseau contrat (défaut Europe/Brussels) : 05:00 UTC = 07:00 local → T1 (0.10).
        // 1 × (0.20 × 1.21) + 10 × 0.10 = 0.242 + 1.0 = 1.242.
        $local = $this->makeDynamicService($legacy, new FakeTariffRepository(grid: $this->electricityGrid()), $dynamic)
            ->estimateMonthElectricityDynamic(2026, 6);
        self::assertEqualsWithDelta(1.24, $local['energy_dynamic'], 0.001);

        // Contre-preuve : borné en UTC brut, 05:00 → T2 (0.08) → 0.242 + 0.8 = 1.042.
        $utc = $this->makeDynamicService($legacy, new FakeTariffRepository(grid: $this->electricityGrid()), $dynamic, tariffTimezone: 'UTC')
            ->estimateMonthElectricityDynamic(2026, 6);
        self::assertEqualsWithDelta(1.04, $utc['energy_dynamic'], 0.001);
    }

    public function testDynamicUnavailableWhenDisabled(): void
    {
        $legacy = new FakeLegacyDailyRepository(
            monthlyDeltasForMonth: $this->electricityDeltas(),
            hourlyImportDeltas: [['hour' => '2026-06-10 10:00:00', 'import_kwh' => 10.0]],
        );
        $dynamic = new FakeDynamicPriceRepository(pricesByHour: ['2026-06-10 10:00:00' => 0.20]);

        $svc = $this->makeDynamicService(
            $legacy,
            new FakeTariffRepository(grid: $this->electricityGrid()),
            $dynamic,
            enabled: false,
        );
        $r = $svc->estimateMonthElectricityDynamic(2026, 6);

        self::assertFalse($r['available']);
        self::assertSame('Tarif dynamique non configuré.', $r['reason']);
    }

    // ── Gaz : dernière période ───────────────────────────────────────────────

    public function testLastGasPeriodNeedsTwoReadings(): void
    {
        $svc = $this->makeService(
            new FakeLegacyDailyRepository(),
            new FakeTariffRepository(grid: $this->gasGrid(10.0)),
            new FakeGasReadingRepository(lastTwo: ['from' => null, 'to' => null]),
        );

        $r = $svc->estimateLastGasPeriod();

        self::assertFalse($r['available']);
        self::assertSame('Il faut au moins deux relevés gaz.', $r['reason']);
    }

    public function testLastGasPeriodUnavailableWhenNoTariff(): void
    {
        $svc = $this->makeService(
            new FakeLegacyDailyRepository(),
            new FakeTariffRepository(grid: null),
            new FakeGasReadingRepository(lastTwo: [
                'from' => ['reading_at' => '2026-01-01 00:00:00', 'counter_m3' => 1000.0],
                'to'   => ['reading_at' => '2026-01-31 00:00:00', 'counter_m3' => 1100.0],
            ]),
        );

        $r = $svc->estimateLastGasPeriod();

        self::assertFalse($r['available']);
    }

    public function testLastGasPeriodHappyPath(): void
    {
        $svc = $this->makeService(
            new FakeLegacyDailyRepository(),
            new FakeTariffRepository(grid: $this->gasGrid(10.0)),
            new FakeGasReadingRepository(lastTwo: [
                'from' => ['reading_at' => '2026-01-01 00:00:00', 'counter_m3' => 1000.0],
                'to'   => ['reading_at' => '2026-01-31 00:00:00', 'counter_m3' => 1100.0],
            ]),
        );

        $r = $svc->estimateLastGasPeriod();

        self::assertTrue($r['available']);
        self::assertSame(30, $r['days']);
        self::assertEqualsWithDelta(100.0, $r['delta_m3'], 0.0001);
        self::assertEqualsWithDelta(10.0, $r['pcs_coefficient'], 0.0001);
        self::assertEqualsWithDelta(1000.0, $r['kwh'], 0.0001); // 100 m³ × PCS 10.0
    }

    // ── Gaz : mois calendaire (interpolation à minuit) ───────────────────────

    public function testMonthGasUnavailableWhenNoReadings(): void
    {
        $svc = $this->makeService(
            new FakeLegacyDailyRepository(),
            new FakeTariffRepository(grid: $this->gasGrid(10.0)),
            new FakeGasReadingRepository(forInterpolation: []),
        );

        $r = $svc->estimateMonthGas(2026, 4);

        self::assertFalse($r['available']);
        self::assertSame('Aucun relevé disponible pour cette période.', $r['reason']);
    }

    public function testMonthGasFullMonthAlignedBoundaries(): void
    {
        $svc = $this->makeService(
            new FakeLegacyDailyRepository(),
            new FakeTariffRepository(grid: $this->gasGrid(10.0)),
            new FakeGasReadingRepository(forInterpolation: [
                ['reading_at' => '2026-04-01 00:00:00', 'counter_m3' => 1000.0],
                ['reading_at' => '2026-05-01 00:00:00', 'counter_m3' => 1300.0],
            ]),
        );

        $r = $svc->estimateMonthGas(2026, 4);

        self::assertTrue($r['available']);
        self::assertFalse($r['is_projection']);
        self::assertSame(30, $r['days']);          // avril = 30 jours
        self::assertSame(30, $r['calendar_days']);
        self::assertEqualsWithDelta(300.0, $r['delta_m3'], 0.0001);
        self::assertEqualsWithDelta(3000.0, $r['kwh'], 0.0001); // 300 m³ × PCS 10
    }

    public function testMonthGasRecoversMidnightOffsetIssueExample(): void
    {
        // Exemple chiffré de l'issue #34 : relevés à 07:54 / 08:05 → janvier doit
        // valoir 999,754 m³ (et non ~989 comme l'ancien calcul « clampé »).
        $svc = $this->makeService(
            new FakeLegacyDailyRepository(),
            new FakeTariffRepository(grid: $this->gasGrid(10.0)),
            new FakeGasReadingRepository(forInterpolation: [
                ['reading_at' => '2026-01-01 07:54:00', 'counter_m3' => 74000.0],
                ['reading_at' => '2026-02-01 08:05:00', 'counter_m3' => 75000.0],
            ]),
        );

        $r = $svc->estimateMonthGas(2026, 1);

        self::assertTrue($r['available']);
        self::assertEqualsWithDelta(999.754, $r['delta_m3'], 0.001);
        self::assertSame('2026-01-01 00:00:00', $r['month_start']);
        self::assertSame('2026-02-01 00:00:00', $r['month_end']);
    }

    public function testMonthGasMissingReadingForOngoingMonth(): void
    {
        // Un seul relevé pour un mois sans relevé après sa fin → message d'attente.
        $svc = $this->makeService(
            new FakeLegacyDailyRepository(),
            new FakeTariffRepository(grid: $this->gasGrid(10.0)),
            new FakeGasReadingRepository(forInterpolation: [
                ['reading_at' => '2026-06-10 09:00:00', 'counter_m3' => 1000.0],
            ]),
        );

        $r = $svc->estimateMonthGas(2026, 6);

        self::assertFalse($r['available']);
        self::assertSame('Relevé manquant : le calcul se fera dès le prochain relevé.', $r['reason']);
    }

    // ── Eau : conso mensuelle (volume, sans coût) ────────────────────────────

    public function testMonthWaterUnavailableWithoutWaterRepo(): void
    {
        $svc = $this->makeService(
            new FakeLegacyDailyRepository(),
            new FakeTariffRepository(grid: null),
            new FakeGasReadingRepository(),
        );

        $r = $svc->estimateMonthWater(2026, 4);

        self::assertFalse($r['available']);
        self::assertSame('Relevés eau indisponibles.', $r['reason']);
    }

    public function testMonthWaterHappyPathVolumeOnly(): void
    {
        $svc = $this->makeService(
            new FakeLegacyDailyRepository(),
            new FakeTariffRepository(grid: null),
            new FakeGasReadingRepository(),
            new FakeMeterReadingRepository(forInterpolation: [
                ['reading_at' => '2026-04-01 00:00:00', 'counter_m3' => 100.0],
                ['reading_at' => '2026-05-01 00:00:00', 'counter_m3' => 130.0],
            ]),
        );

        $r = $svc->estimateMonthWater(2026, 4);

        self::assertTrue($r['available']);
        self::assertEqualsWithDelta(30.0, $r['delta_m3'], 0.0001);
        self::assertSame(30, $r['days']);
        self::assertArrayNotHasKey('cost', $r); // pas de coût pour l'eau
    }

    // ── Proration multi-grilles (#196) ───────────────────────────────────────

    /** Grille électricité paramétrable, pour composer deux tarifs successifs. */
    private function elecGrid(
        int $id,
        string $name,
        string $validFrom,
        ?string $validTo,
        float $t1,
        float $t2,
        float $vatRate = 21.0,
    ): TariffGrid {
        return new TariffGrid(
            id: $id,
            energyType: 'electricity',
            name: $name,
            validFrom: new DateTimeImmutable($validFrom),
            validTo: $validTo !== null ? new DateTimeImmutable($validTo) : null,
            lines: [
                'energy_t1'    => new TariffLine('energy_t1', $t1, ComponentKind::EnergyT1),
                'energy_t2'    => new TariffLine('energy_t2', $t2, ComponentKind::EnergyT2),
                'subscription' => new TariffLine('subscription', 5.0, ComponentKind::FixedMonthly),
            ],
            vatRate: $vatRate,
        );
    }

    /** @return list<TariffGrid> Deux grilles élec successives, dans l'ordre de priorité du repository. */
    private function twoElectricityGrids(float $vatRateB = 21.0): array
    {
        return [
            $this->elecGrid(2, 'Elec B', '2026-01-16', null, 0.20, 0.16, $vatRateB),
            $this->elecGrid(1, 'Elec A', '2026-01-01', '2026-01-15', 0.10, 0.08),
        ];
    }

    /**
     * Deux grilles sur le mois → chaque sous-période est facturée à son tarif,
     * au prorata des jours (15 j + 15 j sur une période de 30 jours).
     */
    public function testMonthElectricitySplitsCostBetweenSuccessiveGrids(): void
    {
        $tariffs = new FakeTariffRepository();
        $tariffs->gridsBetween = $this->twoElectricityGrids();

        $svc = $this->makeService(
            new FakeLegacyDailyRepository(monthlyDeltasForMonth: $this->electricityDeltasFor('2026-01-01 00:00:00', '2026-01-31 00:00:00')),
            $tariffs,
            new FakeGasReadingRepository(),
        );

        $r = $svc->estimateMonthElectricity(2026, 1);

        self::assertTrue($r['available']);
        self::assertSame(30, $r['days']);
        self::assertSame('Elec A + Elec B', $r['tariff_name']);

        // A : 50 kWh T1 × 0,10 + 25 kWh T2 × 0,08 = 7,00 €
        // B : 50 kWh T1 × 0,20 + 25 kWh T2 × 0,16 = 14,00 €
        // Abonnement : 1 mois sur la période, réparti 0,5 + 0,5 → 5,00 € une seule fois.
        self::assertEqualsWithDelta(26.0, $r['cost']['total'], 0.01);
    }

    /** L'abonnement mensuel reste facturé UNE fois quand la période est découpée. */
    public function testMonthlySubscriptionIsNotBilledTwiceAcrossGrids(): void
    {
        $tariffs = new FakeTariffRepository();
        $tariffs->gridsBetween = $this->twoElectricityGrids();

        $svc = $this->makeService(
            new FakeLegacyDailyRepository(monthlyDeltasForMonth: $this->electricityDeltasFor('2026-01-01 00:00:00', '2026-01-31 00:00:00')),
            $tariffs,
            new FakeGasReadingRepository(),
        );

        $lines = $svc->estimateMonthElectricity(2026, 1)['cost']['lines'];
        $subs  = array_values(array_filter($lines, static fn (array $l): bool => $l['key'] === 'subscription'));

        self::assertCount(1, $subs);
        self::assertEqualsWithDelta(1.0, $subs[0]['quantity'], 0.0001); // 0,5 + 0,5 mois
        self::assertEqualsWithDelta(5.0, $subs[0]['amount'], 0.0001);
    }

    /** Le détail par sous-période est exposé au dashboard (dates, jours, sous-total). */
    public function testMonthElectricityExposesTariffSegments(): void
    {
        $tariffs = new FakeTariffRepository();
        $tariffs->gridsBetween = $this->twoElectricityGrids();

        $svc = $this->makeService(
            new FakeLegacyDailyRepository(monthlyDeltasForMonth: $this->electricityDeltasFor('2026-01-01 00:00:00', '2026-01-31 00:00:00')),
            $tariffs,
            new FakeGasReadingRepository(),
        );

        $segments = $svc->estimateMonthElectricity(2026, 1)['tariff_segments'];

        self::assertCount(2, $segments);
        self::assertSame('Elec A', $segments[0]['name']);
        self::assertSame('2026-01-01', $segments[0]['from']);
        self::assertSame('2026-01-15', $segments[0]['to']);
        self::assertSame(15, $segments[0]['days']);
        self::assertSame('Elec B', $segments[1]['name']);
        self::assertSame('2026-01-16', $segments[1]['from']);
        self::assertSame(15, $segments[1]['days']);
        self::assertEqualsWithDelta(
            $svc->estimateMonthElectricity(2026, 1)['cost']['total'],
            $segments[0]['total'] + $segments[1]['total'],
            0.02,
        );
    }

    /** Grilles à taux de TVA différents : HTVA sommé, pas de taux unique affichable. */
    public function testMixedVatRatesLeaveVatRateNull(): void
    {
        $tariffs = new FakeTariffRepository();
        $tariffs->gridsBetween = $this->twoElectricityGrids(vatRateB: 6.0);

        $svc = $this->makeService(
            new FakeLegacyDailyRepository(monthlyDeltasForMonth: $this->electricityDeltasFor('2026-01-01 00:00:00', '2026-01-31 00:00:00')),
            $tariffs,
            new FakeGasReadingRepository(),
        );

        $cost = $svc->estimateMonthElectricity(2026, 1)['cost'];

        self::assertNull($cost['vat_rate']);
        self::assertEqualsWithDelta($cost['total'] - $cost['htva'], $cost['vat_included'], 0.01);
        self::assertGreaterThan(0.0, $cost['htva']);
    }

    /** Une seule grille active → résultat strictement identique au calcul mono-grille. */
    public function testSingleGridResultIsUnchanged(): void
    {
        $single = new FakeTariffRepository(grid: $this->electricityGrid());
        $multi  = new FakeTariffRepository();
        $multi->gridsBetween = [$this->electricityGrid()];

        $deltas = $this->electricityDeltasFor('2026-01-01 00:00:00', '2026-01-31 00:00:00');

        $a = $this->makeService(new FakeLegacyDailyRepository(monthlyDeltasForMonth: $deltas), $single, new FakeGasReadingRepository());
        $b = $this->makeService(new FakeLegacyDailyRepository(monthlyDeltasForMonth: $deltas), $multi, new FakeGasReadingRepository());

        $ra = $a->estimateMonthElectricity(2026, 1);
        $rb = $b->estimateMonthElectricity(2026, 1);

        self::assertSame($ra['cost'], $rb['cost']);
        self::assertSame(21.0, $ra['cost']['vat_rate']);
        self::assertCount(1, $ra['tariff_segments']);
    }

    /**
     * Grille créée aujourd'hui (valid_from = borne de fin de la période) : aucune
     * grille n'est active pendant les jours balayés, mais l'ancien calcul retombait
     * sur `findActiveGrid($to)`. Le coût doit rester rendu, pas « aucun tarif ».
     */
    public function testGridStartingAtThePeriodEndStillCoversThePeriod(): void
    {
        $tariffs = new FakeTariffRepository();
        $tariffs->gridsBetween = [$this->elecGrid(9, 'Nouvelle', '2026-01-31', null, 0.10, 0.08)];

        $svc = $this->makeService(
            new FakeLegacyDailyRepository(monthlyDeltasForMonth: $this->electricityDeltasFor('2026-01-01 00:00:00', '2026-01-31 00:00:00')),
            $tariffs,
            new FakeGasReadingRepository(),
        );

        $r = $svc->estimateMonthElectricity(2026, 1);

        self::assertTrue($r['available']);
        self::assertSame('Nouvelle', $r['tariff_name']);
        self::assertSame(30, $r['tariff_segments'][0]['days']);
        // 100 kWh × 0,10 + 50 × 0,08 + 5,00 € d'abonnement.
        self::assertEqualsWithDelta(19.0, $r['cost']['total'], 0.01);
    }

    /** Gaz : le PCS exposé doit rester cohérent avec delta_m3 → kWh affichés. */
    public function testGasEffectivePcsMatchesDisplayedKwh(): void
    {
        $tariffs = new FakeTariffRepository();
        $tariffs->gridsBetween = [
            new TariffGrid(
                id: 4,
                energyType: 'gas',
                name: 'Gaz B',
                validFrom: new DateTimeImmutable('2026-01-16'),
                validTo: null,
                lines: ['energy' => new TariffLine('energy', 0.10, ComponentKind::EnergyFlat)],
                pcsCoefficient: 11.0,
            ),
            new TariffGrid(
                id: 3,
                energyType: 'gas',
                name: 'Gaz A',
                validFrom: new DateTimeImmutable('2026-01-01'),
                validTo: new DateTimeImmutable('2026-01-15'),
                lines: ['energy' => new TariffLine('energy', 0.05, ComponentKind::EnergyFlat)],
                pcsCoefficient: 10.0,
            ),
        ];

        $svc = $this->makeService(
            new FakeLegacyDailyRepository(),
            $tariffs,
            new FakeGasReadingRepository(lastTwo: [
                'from' => ['reading_at' => '2026-01-01 00:00:00', 'counter_m3' => 1000.0],
                'to'   => ['reading_at' => '2026-01-31 00:00:00', 'counter_m3' => 1100.0],
            ]),
        );

        $r = $svc->estimateLastGasPeriod();

        // PCS effectif = 1050 kWh / 100 m³ = 10,5 (et non 10,0 ou 11,0).
        self::assertEqualsWithDelta(10.5, $r['pcs_coefficient'], 0.0001);
        self::assertEqualsWithDelta($r['delta_m3'] * $r['pcs_coefficient'], $r['kwh'], 0.01);
    }

    /**
     * Tarif dynamique : chaque heure est rattachée à sa sous-période par sa date
     * réelle (pas de prorata), et le repli des heures sans prix utilise le tarif
     * de la grille applicable à CETTE heure.
     */
    public function testDynamicFallbackUsesTheGridOfTheHour(): void
    {
        $tariffs = new FakeTariffRepository();
        $tariffs->gridsBetween = $this->twoElectricityGrids();

        $legacy = new FakeLegacyDailyRepository(
            monthlyDeltasForMonth: $this->electricityDeltasFor('2026-01-01 00:00:00', '2026-01-31 00:00:00'),
            hourlyImportDeltas: [
                ['hour' => '2026-01-10 10:00:00', 'import_kwh' => 10.0], // grille A, prix connu
                ['hour' => '2026-01-20 10:00:00', 'import_kwh' => 10.0], // grille B, repli T1 = 0,20
            ],
        );
        $dynamic = new FakeDynamicPriceRepository(pricesByHour: ['2026-01-10 10:00:00' => 0.20]);

        $r = $this->makeDynamicService($legacy, $tariffs, $dynamic)->estimateMonthElectricityDynamic(2026, 1);

        self::assertTrue($r['available']);
        // 10 × (0,20 × 1,21) = 2,42 (prix spot) + 10 × 0,20 (repli grille B) = 4,42.
        // Avec le repli de la grille A (0,10) on aurait obtenu 3,42.
        self::assertEqualsWithDelta(4.42, $r['energy_dynamic'], 0.0001);
        self::assertCount(2, $r['tariff_segments']);
        self::assertSame('dynamic', $r['cost']['mode']);
    }

    /** Gaz : chaque sous-période convertit les m³ avec le PCS de SA grille. */
    public function testLastGasPeriodSplitsAcrossGridsWithOwnPcs(): void
    {
        $tariffs = new FakeTariffRepository();
        $tariffs->gridsBetween = [
            new TariffGrid(
                id: 4,
                energyType: 'gas',
                name: 'Gaz B',
                validFrom: new DateTimeImmutable('2026-01-16'),
                validTo: null,
                lines: ['energy' => new TariffLine('energy', 0.10, ComponentKind::EnergyFlat)],
                pcsCoefficient: 11.0,
            ),
            new TariffGrid(
                id: 3,
                energyType: 'gas',
                name: 'Gaz A',
                validFrom: new DateTimeImmutable('2026-01-01'),
                validTo: new DateTimeImmutable('2026-01-15'),
                lines: ['energy' => new TariffLine('energy', 0.05, ComponentKind::EnergyFlat)],
                pcsCoefficient: 10.0,
            ),
        ];

        $svc = $this->makeService(
            new FakeLegacyDailyRepository(),
            $tariffs,
            new FakeGasReadingRepository(lastTwo: [
                'from' => ['reading_at' => '2026-01-01 00:00:00', 'counter_m3' => 1000.0],
                'to'   => ['reading_at' => '2026-01-31 00:00:00', 'counter_m3' => 1100.0],
            ]),
        );

        $r = $svc->estimateLastGasPeriod();

        self::assertTrue($r['available']);
        self::assertSame('Gaz A + Gaz B', $r['tariff_name']);
        // 50 m³ × 10,0 + 50 m³ × 11,0 = 1050 kWh
        self::assertEqualsWithDelta(1050.0, $r['kwh'], 0.001);
        // 500 kWh × 0,05 + 550 kWh × 0,10 = 80,00 €
        self::assertEqualsWithDelta(80.0, $r['cost']['total'], 0.01);
    }

    /** Eau : volume réparti au prorata des jours entre les deux grilles. */
    public function testMonthWaterSplitsAcrossGrids(): void
    {
        $waterGrid = static fn (int $id, string $name, string $from, ?string $to, float $rate): TariffGrid => new TariffGrid(
            id: $id,
            energyType: 'water',
            name: $name,
            validFrom: new DateTimeImmutable($from),
            validTo: $to !== null ? new DateTimeImmutable($to) : null,
            lines: ['water' => new TariffLine('water', $rate, ComponentKind::PerM3)],
        );

        $tariffs = new FakeTariffRepository();
        $tariffs->gridsBetween = [
            $waterGrid(6, 'Eau B', '2026-04-16', null, 4.0),
            $waterGrid(5, 'Eau A', '2026-04-01', '2026-04-15', 2.0),
        ];

        $svc = $this->makeService(
            new FakeLegacyDailyRepository(),
            $tariffs,
            new FakeGasReadingRepository(),
            new FakeMeterReadingRepository(forInterpolation: [
                ['reading_at' => '2026-04-01 00:00:00', 'counter_m3' => 100.0],
                ['reading_at' => '2026-05-01 00:00:00', 'counter_m3' => 130.0],
            ]),
        );

        $r = $svc->estimateMonthWater(2026, 4);

        self::assertTrue($r['available']);
        self::assertSame('Eau A + Eau B', $r['tariff_name']);
        // 30 m³ sur 30 jours : 15 m³ × 2 € + 15 m³ × 4 € = 90,00 €
        self::assertEqualsWithDelta(90.0, $r['cost']['total'], 0.01);
        self::assertCount(2, $r['tariff_segments']);
    }
}
