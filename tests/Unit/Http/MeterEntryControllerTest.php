<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controller\MeterEntryController;
use App\Http\Request;
use App\Http\ValidationException;
use PHPUnit\Framework\TestCase;
use Tests\Fake\FakeMeterReadingRepository;

final class MeterEntryControllerTest extends TestCase
{
    private function controller(FakeMeterReadingRepository $gas, FakeMeterReadingRepository $water): MeterEntryController
    {
        return new MeterEntryController($gas, $water);
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

    public function testRejectsReadingDateBeforeLatest(): void
    {
        $gas = new FakeMeterReadingRepository(latest: ['reading_at' => '2026-06-25 12:00:00', 'counter_m3' => 50.0]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Reading date must be after the latest entry');
        $this->controller($gas, new FakeMeterReadingRepository())
            ->gas($this->post(['counter_m3' => 100, 'reading_at' => '2026-06-25 10:00:00']));
    }

    public function testRejectsCounterBelowLatest(): void
    {
        $gas = new FakeMeterReadingRepository(latest: ['reading_at' => '2026-06-25 08:00:00', 'counter_m3' => 200.0]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Counter value must be');
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
}
