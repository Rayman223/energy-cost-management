<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Domain\ComponentKind;
use App\Domain\TariffGrid;
use App\Domain\TariffLine;
use App\Service\AnnualConsumptionService;
use App\Service\CostCalculationService;
use App\Service\TariffCalculatorService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Fake\FakeGasReadingRepository;
use Tests\Fake\FakeLegacyDailyRepository;
use Tests\Fake\FakeMeterReadingRepository;
use Tests\Fake\FakeTariffRepository;

/**
 * Récapitulatif annuel du dashboard (#41), monté sur un CostCalculationService
 * réel branché sur les fakes de repository : ce sont les vraies interpolations et
 * le vrai découpage tarifaire qui sont exercés, pas une reformulation en double.
 */
final class AnnualConsumptionServiceTest extends TestCase
{
    private const YEAR = 2025;

    /** Deltas électricité sur l'année entière, tels que les rend getDeltasBetween(). */
    private function elecDeltas(string $dataTo = '2026-01-01 00:00:00'): array
    {
        return [
            'from'        => '2025-01-01 00:00:00',
            'to'          => $dataTo,
            'data_from'   => '2025-01-01 00:00:00',
            'data_to'     => $dataTo,
            'prelev_jour' => 3214.0,
            'prelev_nuit' => 1902.0,
            'injec_jour'  => 880.0,
            'injec_nuit'  => 41.0,
            'solar'       => 4120.0,
        ];
    }

    private function elecGrid(): TariffGrid
    {
        return new TariffGrid(1, 'electricity', 'Élec', new DateTimeImmutable('2024-01-01'), null, [
            'energy_t1' => new TariffLine('energy_t1', 0.10, ComponentKind::EnergyT1),
            'energy_t2' => new TariffLine('energy_t2', 0.08, ComponentKind::EnergyT2),
        ]);
    }

    /**
     * Grille gaz. `$validFrom`/`$validTo` et le PCS sont paramétrés : le test des
     * deux grilles successives repose sur des PCS différents sur la même année.
     */
    private function gasGrid(
        int $id,
        float $pcs,
        string $validFrom = '2024-01-01',
        ?string $validTo = null,
    ): TariffGrid {
        return new TariffGrid(
            $id,
            'gas',
            'Gaz ' . $id,
            new DateTimeImmutable($validFrom),
            $validTo === null ? null : new DateTimeImmutable($validTo),
            ['energy_flat' => new TariffLine('energy_flat', 0.05, ComponentKind::EnergyFlat)],
            $pcs,
        );
    }

    private function waterGrid(): TariffGrid
    {
        return new TariffGrid(9, 'water', 'Eau', new DateTimeImmutable('2024-01-01'), null, [
            'per_m3' => new TariffLine('per_m3', 4.0, ComponentKind::PerM3),
        ]);
    }

    /**
     * Relevés mensuels d'un compteur volumétrique, du 1er janvier au 1er janvier
     * suivant : un index qui progresse de `$perMonth` m³ par mois.
     *
     * @return list<array{reading_at: string, counter_m3: float}>
     */
    private function monthlyReadings(float $perMonth, int $months = 13, float $start = 0.0): array
    {
        $readings = [];
        for ($i = 0; $i < $months; $i++) {
            $at = (new DateTimeImmutable(sprintf('%d-01-01 00:00:00', self::YEAR)))->modify("+{$i} month");
            $readings[] = [
                'reading_at' => $at->format('Y-m-d H:i:s'),
                'counter_m3' => $start + $perMonth * $i,
            ];
        }

        return $readings;
    }

    /**
     * @param array<string, list<TariffGrid>> $gridsByType
     */
    private function service(
        FakeLegacyDailyRepository $elec,
        FakeGasReadingRepository $gas,
        ?FakeMeterReadingRepository $water,
        array $gridsByType,
    ): AnnualConsumptionService {
        $tariff = new FakeTariffRepository();
        $tariff->gridsBetweenByType = $gridsByType;

        $cost = new CostCalculationService(
            $elec,
            $tariff,
            $gas,
            new TariffCalculatorService(),
            waterRepo: $water,
        );

        return new AnnualConsumptionService($cost);
    }

    /** Service complet, avec des données exploitables pour les trois énergies. */
    private function fullService(string $dataTo = '2026-01-01 00:00:00'): AnnualConsumptionService
    {
        return $this->service(
            new FakeLegacyDailyRepository(deltasBetween: $this->elecDeltas($dataTo)),
            new FakeGasReadingRepository(forInterpolation: $this->monthlyReadings(100.0)),
            new FakeMeterReadingRepository(forInterpolation: $this->monthlyReadings(8.0)),
            [
                'electricity' => [$this->elecGrid()],
                'gas'         => [$this->gasGrid(2, 10.0)],
                'water'       => [$this->waterGrid()],
            ],
        );
    }

    public function testWindowIsTheCalendarYearInUtc(): void
    {
        $elec = new FakeLegacyDailyRepository(deltasBetween: $this->elecDeltas());
        $svc  = $this->service(
            $elec,
            new FakeGasReadingRepository(forInterpolation: $this->monthlyReadings(100.0)),
            null,
            ['electricity' => [$this->elecGrid()], 'gas' => [$this->gasGrid(2, 10.0)]],
        );

        $result = $svc->build(self::YEAR);

        self::assertSame(self::YEAR, $result['year']);
        self::assertSame('2025-01-01', $result['from']);
        self::assertSame('2026-01-01', $result['to']);
        // Les bornes envoyées au repository sont bien celles de l'année, en UTC :
        // dérivées du fuseau PHP, elles glisseraient d'une heure ou deux (#21).
        self::assertSame(['2025-01-01 00:00:00', '2026-01-01 00:00:00'], $elec->rangesRequested[0]);
    }

    public function testElectricityListsEveryRegisterSeparately(): void
    {
        $result = $this->fullService()->build(self::YEAR);

        self::assertTrue($result['electricity']['available']);
        self::assertSame(
            ['import_t1', 'import_t2', 'export_t1', 'export_t2', 'production'],
            array_column($result['electricity']['registers'], 'key'),
        );
        self::assertSame(
            [3214.0, 1902.0, 880.0, 41.0, 4120.0],
            array_column($result['electricity']['registers'], 'kwh'),
        );
        self::assertIsFloat($result['electricity']['cost']);
    }

    public function testMonoTariffMeterStillReportsItsFourImportExportRegisters(): void
    {
        // Un compteur mono-horaire n'a pas de registre T2 : le repository rend 0.
        // Les lignes restent affichées (à zéro), comme les cards mensuelles — c'est
        // le solaire, et lui seul, qui disparaît quand il n'a rien à montrer.
        $deltas = $this->elecDeltas();
        $deltas['prelev_nuit'] = 0.0;
        $deltas['injec_nuit']  = 0.0;
        $deltas['solar']       = 0.0;

        $svc = $this->service(
            new FakeLegacyDailyRepository(deltasBetween: $deltas),
            new FakeGasReadingRepository(forInterpolation: $this->monthlyReadings(100.0)),
            null,
            ['electricity' => [$this->elecGrid()], 'gas' => [$this->gasGrid(2, 10.0)]],
        );

        $registers = $svc->build(self::YEAR)['electricity']['registers'];

        self::assertSame(['import_t1', 'import_t2', 'export_t1', 'export_t2'], array_column($registers, 'key'));
        self::assertSame(0.0, $registers[1]['kwh']);
    }

    public function testGasEnergyIsConvertedGridByGridAndSummed(): void
    {
        // Deux grilles sur l'année, de PCS différents : le kWh annuel ne peut pas
        // être « m³ × un PCS ». Il doit valoir la somme des sous-périodes, et le
        // PCS exposé leur moyenne effective — sinon les deux chiffres affichés
        // côte à côte se contredisent.
        $svc = $this->service(
            new FakeLegacyDailyRepository(deltasBetween: $this->elecDeltas()),
            new FakeGasReadingRepository(forInterpolation: $this->monthlyReadings(100.0)),
            null,
            [
                'electricity' => [$this->elecGrid()],
                'gas'         => [
                    $this->gasGrid(2, 10.0, '2024-01-01', '2025-07-01'),
                    $this->gasGrid(3, 11.0, '2025-07-01'),
                ],
            ],
        );

        $gas = $svc->build(self::YEAR)['gas'];

        self::assertTrue($gas['available']);
        self::assertEqualsWithDelta(1200.0, $gas['m3'], 0.01);

        // Le volume est réparti à la consommation RÉELLE et non au prorata des
        // jours (#255) : la bascule du 1er juillet tombe sur un relevé, donc
        // 600 m³ à PCS 10 puis 600 m³ à PCS 11.
        self::assertEqualsWithDelta(600.0 * 10.0 + 600.0 * 11.0, $gas['kwh'], 0.5);

        // PCS effectif = kWh rendus / m³ consommés, strictement entre les deux.
        self::assertEqualsWithDelta($gas['kwh'] / $gas['m3'], $gas['pcs'], 0.001);
        self::assertGreaterThan(10.0, $gas['pcs']);
        self::assertLessThan(11.0, $gas['pcs']);
    }

    public function testGasKeepsItsVolumeWhenNoTariffGridCoversTheYear(): void
    {
        $svc = $this->service(
            new FakeLegacyDailyRepository(deltasBetween: $this->elecDeltas()),
            new FakeGasReadingRepository(forInterpolation: $this->monthlyReadings(100.0)),
            null,
            ['electricity' => [$this->elecGrid()], 'gas' => []],
        );

        $gas = $svc->build(self::YEAR)['gas'];

        // Les m³ ne dépendent que des relevés : les perdre parce qu'aucune grille
        // n'est configurée priverait l'utilisateur de la moitié de ce qu'il demande.
        self::assertTrue($gas['available']);
        self::assertEqualsWithDelta(1200.0, $gas['m3'], 0.01);
        self::assertNull($gas['kwh']);
        self::assertNull($gas['cost']);
        self::assertSame('dash.reason.no_tariff_gas', $gas['reason_key']);
    }

    public function testWaterReportsVolumeAndCost(): void
    {
        $water = $this->fullService()->build(self::YEAR)['water'];

        self::assertTrue($water['available']);
        self::assertEqualsWithDelta(96.0, $water['m3'], 0.01);
        self::assertEqualsWithDelta(96.0 * 4.0, $water['cost'], 0.5);
    }

    public function testWaterIsUnavailableWithoutRepository(): void
    {
        $svc = $this->service(
            new FakeLegacyDailyRepository(deltasBetween: $this->elecDeltas()),
            new FakeGasReadingRepository(forInterpolation: $this->monthlyReadings(100.0)),
            null,
            ['electricity' => [$this->elecGrid()], 'gas' => [$this->gasGrid(2, 10.0)]],
        );

        $water = $svc->build(self::YEAR)['water'];

        self::assertFalse($water['available']);
        self::assertNull($water['m3']);
        self::assertSame('dash.reason.water_no_readings', $water['reason_key']);
    }

    public function testYearIsCompleteWhenReadingsCoverItEntirely(): void
    {
        self::assertFalse($this->fullService()->build(self::YEAR)['partial']);
    }

    public function testYearIsPartialWhenTheReadingStreamStopsEarly(): void
    {
        // Le flux électricité s'arrête fin septembre : les totaux ne couvrent pas
        // douze mois, et rien dans le tableau ne le dirait sans ce drapeau.
        $result = $this->fullService('2025-10-01 00:00:00')->build(self::YEAR);

        self::assertTrue($result['partial']);
        self::assertSame('2025-10-01 00:00:00', $result['electricity']['data_to']);
    }

    public function testYearWithoutAnyDataIsUnavailableRatherThanZero(): void
    {
        $svc = $this->service(
            new FakeLegacyDailyRepository(deltasBetween: []),
            new FakeGasReadingRepository(),
            new FakeMeterReadingRepository(),
            ['electricity' => [$this->elecGrid()], 'gas' => [$this->gasGrid(2, 10.0)], 'water' => [$this->waterGrid()]],
        );

        $result = $svc->build(self::YEAR);

        foreach (['electricity', 'gas', 'water'] as $energy) {
            self::assertFalse($result[$energy]['available'], "{$energy} devrait être indisponible");
            self::assertNull($result[$energy]['cost']);
            self::assertNotNull($result[$energy]['reason_key'], "{$energy} doit motiver son indisponibilité");
        }

        // Aucune donnée n'est « partiel » : c'est « indisponible », que chaque bloc
        // porte déjà. Un bandeau « année incomplète » serait un bruit de plus.
        self::assertFalse($result['partial']);
    }
}
