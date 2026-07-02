<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controller\IngestController;
use App\Http\Request;
use App\Http\ValidationException;
use PHPUnit\Framework\TestCase;
use Tests\Fake\FakeElectricityIngestion;
use Tests\Fake\FakeUtilityIngestion;

final class IngestControllerTest extends TestCase
{
    private FakeElectricityIngestion $elec;

    private FakeUtilityIngestion $gas;

    private FakeUtilityIngestion $water;

    protected function setUp(): void
    {
        $this->elec  = new FakeElectricityIngestion();
        $this->gas   = new FakeUtilityIngestion();
        $this->water = new FakeUtilityIngestion();
    }

    /** @param array<string,mixed> $body */
    private function post(array $body): Request
    {
        return new Request('POST', ['action' => 'ingest'], $body);
    }

    private function controller(): IngestController
    {
        return new IngestController($this->elec, $this->gas, $this->water);
    }

    public function testElectricityBatchIsIngested(): void
    {
        $res = $this->controller()->electricity($this->post(['readings' => [
            ['timestamp' => '2026-07-02 14:00:00', 'import_t1' => 100.5, 'import_t2' => 50.2],
            ['timestamp' => '2026-07-02 15:00:00', 'import_t1' => 101.0, 'production' => 200.0],
        ]]));

        self::assertSame(200, $res->status);
        self::assertSame(4, $res->data['received']);
        self::assertSame(4, $res->data['inserted']);
        self::assertCount(2, $this->elec->calls);
        self::assertSame(['import_t1' => 100.5, 'import_t2' => 50.2], $this->elec->calls[0]['indexes']);
    }

    public function testElectricitySingleReadingAtRoot(): void
    {
        $res = $this->controller()->electricity($this->post([
            'timestamp' => '2026-07-02 14:00:00',
            'export_t1' => 12.5,
        ]));

        self::assertSame(1, $res->data['inserted']);
        self::assertSame(['export_t1' => 12.5], $this->elec->calls[0]['indexes']);
    }

    public function testElectricityResendIsIdempotent(): void
    {
        $body = ['readings' => [['timestamp' => '2026-07-02 14:00:00', 'import_t1' => 100.0]]];

        $this->controller()->electricity($this->post($body));
        $res = $this->controller()->electricity($this->post($body)); // renvoi

        self::assertSame(1, $res->data['received']);
        self::assertSame(0, $res->data['inserted']); // doublon ignoré
    }

    public function testElectricityRejectsReadingWithoutRegister(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/au moins un registre/');

        $this->controller()->electricity($this->post(['readings' => [
            ['timestamp' => '2026-07-02 14:00:00'],
        ]]));
    }

    public function testElectricityRejectsNegativeIndex(): void
    {
        $this->expectException(ValidationException::class);

        $this->controller()->electricity($this->post(['readings' => [
            ['timestamp' => '2026-07-02 14:00:00', 'import_t1' => -5],
        ]]));
    }

    public function testElectricityRejectsBadTimestamp(): void
    {
        $this->expectException(ValidationException::class);

        $this->controller()->electricity($this->post(['readings' => [
            ['timestamp' => 'pas-une-date', 'import_t1' => 1],
        ]]));
    }

    public function testGasBatchAndIdempotence(): void
    {
        $res = $this->controller()->gas($this->post(['readings' => [
            ['reading_at' => '2026-07-01 08:00:00', 'counter_m3' => 1234.5],
            ['reading_at' => '2026-07-01 08:00:00', 'counter_m3' => 1234.5], // doublon dans le batch
        ]]));

        self::assertSame(2, $res->data['received']);
        self::assertSame(1, $res->data['inserted']);
        self::assertSame(['2026-07-01 08:00:00' => 1234.5], $this->gas->saved);
    }

    public function testWaterAcceptsZeroCounter(): void
    {
        // 0 m³ = mise en service du compteur (cf. import eau).
        $res = $this->controller()->water($this->post(['reading_at' => '2026-07-01 08:00:00', 'counter_m3' => 0]));

        self::assertSame(1, $res->data['inserted']);
    }

    public function testGasRejectsNegativeCounter(): void
    {
        $this->expectException(ValidationException::class);

        $this->controller()->gas($this->post(['reading_at' => '2026-07-01 08:00:00', 'counter_m3' => -1]));
    }

    public function testRejectsMissingBody(): void
    {
        $this->expectException(ValidationException::class);

        $this->controller()->gas($this->post([]));
    }

    public function testRejectsOversizedBatch(): void
    {
        $readings = [];
        for ($i = 0; $i < 1001; $i++) {
            $readings[] = ['reading_at' => '2026-07-01 08:00:00', 'counter_m3' => 1.0];
        }

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/maximum 1000/');

        $this->controller()->gas($this->post(['readings' => $readings]));
    }
}
