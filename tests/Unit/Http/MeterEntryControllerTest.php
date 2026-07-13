<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controller\MeterEntryController;
use App\Http\Request;
use App\Http\ValidationException;
use PHPUnit\Framework\TestCase;
use Tests\Fake\FakeElectricityIngestion;
use Tests\Fake\FakeMeterReadingRepository;

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
}
