<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Domain\ReadingGranularity;
use App\Http\Controller\MeterEntryController;
use App\Http\Request;
use App\Http\ValidationException;
use App\Service\ReadingGranularityPolicy;
use PHPUnit\Framework\TestCase;
use Tests\Fake\FakeElectricityIngestion;
use Tests\Fake\FakeMeterReadingRepository;
use Tests\Fake\FakeTariffRepository;

final class MeterEntryControllerTest extends TestCase
{
    private function controller(
        FakeMeterReadingRepository $gas,
        FakeMeterReadingRepository $water,
        ?FakeElectricityIngestion $elec = null,
    ): MeterEntryController {
        return new MeterEntryController($gas, $water, $elec ?? new FakeElectricityIngestion());
    }

    /** @param array<string, mixed> $body */
    private function post(array $body): Request
    {
        return new Request('POST', ['action' => 'gas_entry'], $body);
    }

    public function testRejectsNonPositiveCounter(): void
    {
        $controller = $this->controller(new FakeMeterReadingRepository(), new FakeMeterReadingRepository());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid counter_m3 value');
        $controller->gas($this->post(['counter_m3' => -5]));
    }

    public function testRejectsInvalidReadingDate(): void
    {
        $controller = $this->controller(new FakeMeterReadingRepository(), new FakeMeterReadingRepository());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid reading_at date format');
        $controller->gas($this->post(['counter_m3' => 100, 'reading_at' => 'not-a-date']));
    }

    public function testSavesGasReadingWhenValid(): void
    {
        $gas = new FakeMeterReadingRepository();
        $res = $this->controller($gas, new FakeMeterReadingRepository())
            ->gas($this->post(['counter_m3' => 123.5, 'reading_at' => '2026-06-25 10:00:00']));

        self::assertSame(200, $res->status);
        self::assertIsArray($res->data);
        self::assertTrue($res->data['ok']);
        self::assertSame(123.5, $res->data['counter_m3']);
        self::assertNotNull($gas->saved);
        self::assertSame(123.5, $gas->saved['counter_m3']);
    }

    public function testAcceptsBackdatedReadingWithinBounds(): void
    {
        $gas = new FakeMeterReadingRepository(
            before: ['reading_at' => '2026-06-25 08:00:00', 'counter_m3' => 50.0],
            after:  ['reading_at' => '2026-06-25 12:00:00', 'counter_m3' => 150.0],
        );

        $res = $this->controller($gas, new FakeMeterReadingRepository())
            ->gas($this->post(['counter_m3' => 100, 'reading_at' => '2026-06-25 10:00:00']));

        self::assertSame(200, $res->status);
        self::assertNotNull($gas->saved);
        self::assertSame(100.0, $gas->saved['counter_m3']);
    }

    public function testRejectsCounterBelowPreviousReading(): void
    {
        $gas = new FakeMeterReadingRepository(before: ['reading_at' => '2026-06-25 08:00:00', 'counter_m3' => 200.0]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must be ≥ previous reading');
        $this->controller($gas, new FakeMeterReadingRepository())
            ->gas($this->post(['counter_m3' => 100, 'reading_at' => '2026-06-25 10:00:00']));
    }

    public function testRejectsCounterAboveNextReading(): void
    {
        $gas = new FakeMeterReadingRepository(after: ['reading_at' => '2026-06-25 12:00:00', 'counter_m3' => 80.0]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must be ≤ next reading');
        $this->controller($gas, new FakeMeterReadingRepository())
            ->gas($this->post(['counter_m3' => 100, 'reading_at' => '2026-06-25 10:00:00']));
    }

    public function testRejectsDuplicateTimestamp(): void
    {
        $gas = new FakeMeterReadingRepository(before: ['reading_at' => '2026-06-25 10:00:00', 'counter_m3' => 100.0]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('already exists at this date');
        $this->controller($gas, new FakeMeterReadingRepository())
            ->gas($this->post(['counter_m3' => 100, 'reading_at' => '2026-06-25 10:00:00']));
    }

    public function testWaterRoutesToWaterRepository(): void
    {
        $gas   = new FakeMeterReadingRepository();
        $water = new FakeMeterReadingRepository();

        $this->controller($gas, $water)
            ->water(new Request('POST', ['action' => 'water_entry'], ['counter_m3' => 77.0, 'reading_at' => '2026-06-25 10:00:00']));

        self::assertNotNull($water->saved);
        self::assertNull($gas->saved);
        self::assertSame(77.0, $water->saved['counter_m3']);
    }

    /** @param array<string, mixed> $body */
    private function elecPost(array $body): Request
    {
        return new Request('POST', ['action' => 'electricity_entry'], $body);
    }

    public function testSavesElectricityIndexesWhenValid(): void
    {
        $elec = new FakeElectricityIngestion();
        $res = $this->controller(new FakeMeterReadingRepository(), new FakeMeterReadingRepository(), $elec)
            ->electricity($this->elecPost([
                'reading_at' => '2026-06-25 10:00:00',
                'import_t1'  => 1234.5,
                'export_t2'  => 42.0,
            ]));

        self::assertSame(200, $res->status);
        self::assertIsArray($res->data);
        self::assertTrue($res->data['ok']);
        self::assertSame(2, $res->data['received']);
        self::assertSame(2, $res->data['inserted']);

        self::assertCount(1, $elec->calls);
        self::assertSame('2026-06-25 10:00:00', $elec->calls[0]['timestamp']);
        self::assertSame(['import_t1' => 1234.5, 'export_t2' => 42.0], $elec->calls[0]['indexes']);
    }

    public function testRejectsNegativeElectricityIndex(): void
    {
        $controller = $this->controller(new FakeMeterReadingRepository(), new FakeMeterReadingRepository());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid export_t1 value');
        $controller->electricity($this->elecPost(['reading_at' => '2026-06-25 10:00:00', 'export_t1' => -1]));
    }

    public function testRejectsElectricityWithoutAnyIndex(): void
    {
        $controller = $this->controller(new FakeMeterReadingRepository(), new FakeMeterReadingRepository());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('At least one electricity index is required');
        $controller->electricity($this->elecPost(['reading_at' => '2026-06-25 10:00:00']));
    }

    public function testRejectsElectricityInvalidReadingDate(): void
    {
        $controller = $this->controller(new FakeMeterReadingRepository(), new FakeMeterReadingRepository());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid reading_at date format');
        $controller->electricity($this->elecPost(['reading_at' => 'not-a-date', 'import_t1' => 100]));
    }

    public function testAcceptsBackdatedElectricityWithinBounds(): void
    {
        $elec = new FakeElectricityIngestion(['import_t1' => ['min' => 1000.0, 'max' => 2000.0]]);
        $res  = $this->controller(new FakeMeterReadingRepository(), new FakeMeterReadingRepository(), $elec)
            ->electricity($this->elecPost(['reading_at' => '2026-06-25 10:00:00', 'import_t1' => 1500.0]));

        self::assertSame(200, $res->status);
        self::assertCount(1, $elec->calls);
        self::assertSame(['import_t1' => 1500.0], $elec->calls[0]['indexes']);
    }

    public function testRejectsElectricityIndexBelowPrevious(): void
    {
        $elec = new FakeElectricityIngestion(['import_t1' => ['min' => 1000.0, 'max' => null]]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('import_t1 must be ≥ previous reading');
        $this->controller(new FakeMeterReadingRepository(), new FakeMeterReadingRepository(), $elec)
            ->electricity($this->elecPost(['reading_at' => '2026-06-25 10:00:00', 'import_t1' => 500.0]));
    }

    public function testRejectsElectricityIndexAboveNext(): void
    {
        $elec = new FakeElectricityIngestion(['export_t2' => ['min' => null, 'max' => 100.0]]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('export_t2 must be ≤ next reading');
        $this->controller(new FakeMeterReadingRepository(), new FakeMeterReadingRepository(), $elec)
            ->electricity($this->elecPost(['reading_at' => '2026-06-25 10:00:00', 'export_t2' => 200.0]));
    }

    public function testRejectsElectricityDuplicateTimestamp(): void
    {
        $elec = new FakeElectricityIngestion(['import_t1' => ['min' => 100.0, 'max' => 300.0, 'exists' => true]]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('already exists at this date for import_t1');
        $this->controller(new FakeMeterReadingRepository(), new FakeMeterReadingRepository(), $elec)
            ->electricity($this->elecPost(['reading_at' => '2026-06-25 10:00:00', 'import_t1' => 200.0]));
    }

    private function throttledController(FakeElectricityIngestion $elec, ReadingGranularity $throttle): MeterEntryController
    {
        // Plafond activé, granularité figée (la résolution par grille est couverte
        // par ReadingGranularityPolicyTest), fuseau UTC.
        return $this->policyController($elec, ReadingGranularityPolicy::constant($throttle, 'UTC'));
    }

    private function policyController(FakeElectricityIngestion $elec, ReadingGranularityPolicy $policy): MeterEntryController
    {
        return new MeterEntryController(
            new FakeMeterReadingRepository(),
            new FakeMeterReadingRepository(),
            $elec,
            null,
            $policy,
        );
    }

    private function dailyLimitController(FakeElectricityIngestion $elec): MeterEntryController
    {
        return $this->throttledController($elec, ReadingGranularity::Day);
    }

    public function testDailyLimitRejectsSameRegisterSameDayAtAnotherTime(): void
    {
        $elec = new FakeElectricityIngestion();
        // Un import_t1 existe déjà le 25/06 à 07:00.
        $elec->insertIndexes(new \DateTimeImmutable('2026-06-25 07:00:00', new \DateTimeZone('UTC')), ['import_t1' => 1000.0]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('import_t1 : un seul index par jour est autorisé');
        $this->dailyLimitController($elec)
            ->electricity($this->elecPost(['reading_at' => '2026-06-25 18:00:00', 'import_t1' => 1100.0]));
    }

    public function testDailyLimitAllowsDifferentRegisterSameDay(): void
    {
        $elec = new FakeElectricityIngestion();
        // import_t1 relevé le matin ; le solaire (production) le soir doit passer.
        $elec->insertIndexes(new \DateTimeImmutable('2026-06-25 07:00:00', new \DateTimeZone('UTC')), ['import_t1' => 1000.0]);

        $res = $this->dailyLimitController($elec)
            ->electricity($this->elecPost(['reading_at' => '2026-06-25 18:00:00', 'production' => 55.0]));

        self::assertSame(200, $res->status);
        self::assertIsArray($res->data);
        self::assertSame(1, $res->data['inserted']);
        // 2 appels au sink : le seed + l'insertion du soir.
        self::assertCount(2, $elec->calls);
        self::assertSame(['production' => 55.0], $elec->calls[1]['indexes']);
    }

    public function testDailyLimitAllowsSameRegisterOnNextDay(): void
    {
        $elec = new FakeElectricityIngestion();
        $elec->insertIndexes(new \DateTimeImmutable('2026-06-25 07:00:00', new \DateTimeZone('UTC')), ['import_t1' => 1000.0]);

        $res = $this->dailyLimitController($elec)
            ->electricity($this->elecPost(['reading_at' => '2026-06-26 07:00:00', 'import_t1' => 1100.0]));

        self::assertSame(200, $res->status);
        self::assertIsArray($res->data);
        self::assertSame(1, $res->data['inserted']);
    }

    public function testDailyLimitOffKeepsMultiplePerDay(): void
    {
        $elec = new FakeElectricityIngestion();
        $elec->insertIndexes(new \DateTimeImmutable('2026-06-25 07:00:00', new \DateTimeZone('UTC')), ['import_t1' => 1000.0]);

        // Limite désactivée (contrôleur par défaut) : un 2e index le même jour passe.
        $res = $this->controller(new FakeMeterReadingRepository(), new FakeMeterReadingRepository(), $elec)
            ->electricity($this->elecPost(['reading_at' => '2026-06-25 18:00:00', 'import_t1' => 1100.0]));

        self::assertSame(200, $res->status);
        self::assertIsArray($res->data);
        self::assertSame(1, $res->data['inserted']);
    }

    public function testQuarterHourLimitRejectsSameRegisterSameSlot(): void
    {
        // Tarif dynamique : deux index du même registre dans le même quart d'heure
        // aligné [07:00–07:15) → le 2e est refusé.
        $elec = new FakeElectricityIngestion();
        $elec->insertIndexes(new \DateTimeImmutable('2026-06-25 07:02:00', new \DateTimeZone('UTC')), ['import_t1' => 1000.0]);

        // Le message affiche le DÉBUT du créneau aligné (07:00), pas l'heure de la
        // tentative (07:12).
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('import_t1 : un seul index par tranche de 15 minutes est autorisé (25/06/2026 07:00).');
        $this->throttledController($elec, ReadingGranularity::QuarterHour)
            ->electricity($this->elecPost(['reading_at' => '2026-06-25 07:12:00', 'import_t1' => 1005.0]));
    }

    public function testQuarterHourLimitAllowsNextSlot(): void
    {
        // 07:14 puis 07:16 : créneaux [07:00–07:15) et [07:15–07:30) distincts → OK.
        $elec = new FakeElectricityIngestion();
        $elec->insertIndexes(new \DateTimeImmutable('2026-06-25 07:14:00', new \DateTimeZone('UTC')), ['import_t1' => 1000.0]);

        $res = $this->throttledController($elec, ReadingGranularity::QuarterHour)
            ->electricity($this->elecPost(['reading_at' => '2026-06-25 07:16:00', 'import_t1' => 1005.0]));

        self::assertSame(200, $res->status);
        self::assertIsArray($res->data);
        self::assertSame(1, $res->data['inserted']);
    }

    public function testHourLimitRejectsSameRegisterSameHour(): void
    {
        // Tarif dynamique horaire (#10) : deux index du même registre dans l'heure
        // pleine [07:00–08:00) → le 2e est refusé, message ancré sur 07:00.
        $elec = new FakeElectricityIngestion();
        $elec->insertIndexes(new \DateTimeImmutable('2026-06-25 07:02:00', new \DateTimeZone('UTC')), ['import_t1' => 1000.0]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('import_t1 : un seul index par heure est autorisé (25/06/2026 07:00).');
        $this->throttledController($elec, ReadingGranularity::Hour)
            ->electricity($this->elecPost(['reading_at' => '2026-06-25 07:45:00', 'import_t1' => 1005.0]));
    }

    public function testHourLimitAllowsNextHour(): void
    {
        $elec = new FakeElectricityIngestion();
        $elec->insertIndexes(new \DateTimeImmutable('2026-06-25 07:45:00', new \DateTimeZone('UTC')), ['import_t1' => 1000.0]);

        $res = $this->throttledController($elec, ReadingGranularity::Hour)
            ->electricity($this->elecPost(['reading_at' => '2026-06-25 08:05:00', 'import_t1' => 1005.0]));

        self::assertSame(200, $res->status);
        self::assertIsArray($res->data);
        self::assertSame(1, $res->data['inserted']);
    }

    /**
     * Câblage bout en bout de la politique tarifaire (#10) : le créneau appliqué est
     * celui de la grille active à la date du relevé, pas un réglage global. Deux
     * index à 15 min d'écart passent sous une grille quart-horaire.
     */
    public function testPolicyDerivedFromDynamicQuarterGridAllowsTwoReadingsInOneHour(): void
    {
        $elec = new FakeElectricityIngestion();
        $elec->insertIndexes(new \DateTimeImmutable('2026-06-25 07:02:00', new \DateTimeZone('UTC')), ['import_t1' => 1000.0]);

        $tariffs = new FakeTariffRepository(new \App\Domain\TariffGrid(
            id: 1,
            energyType: 'electricity',
            name: 'Dyn 15 min',
            validFrom: new \DateTimeImmutable('2026-01-01'),
            validTo: null,
            lines: [],
            pricingMode: 'dynamic_quarter',
        ));

        $res = $this->policyController($elec, ReadingGranularityPolicy::fromTariffs($tariffs, 'UTC'))
            ->electricity($this->elecPost(['reading_at' => '2026-06-25 07:20:00', 'import_t1' => 1005.0]));

        self::assertSame(200, $res->status);
        self::assertIsArray($res->data);
        self::assertSame(1, $res->data['inserted']);
    }

    /** Même relevé, mais sous une grille fixe : le plafond journalier s'applique. */
    public function testPolicyDerivedFromFixedGridKeepsDailyLimit(): void
    {
        $elec = new FakeElectricityIngestion();
        $elec->insertIndexes(new \DateTimeImmutable('2026-06-25 07:02:00', new \DateTimeZone('UTC')), ['import_t1' => 1000.0]);

        $tariffs = new FakeTariffRepository(new \App\Domain\TariffGrid(
            id: 1,
            energyType: 'electricity',
            name: 'Fixe',
            validFrom: new \DateTimeImmutable('2026-01-01'),
            validTo: null,
            lines: [],
        ));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('import_t1 : un seul index par jour est autorisé');
        $this->policyController($elec, ReadingGranularityPolicy::fromTariffs($tariffs, 'UTC'))
            ->electricity($this->elecPost(['reading_at' => '2026-06-25 07:20:00', 'import_t1' => 1005.0]));
    }
}
