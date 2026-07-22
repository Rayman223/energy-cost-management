<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Domain\ReadingGranularity;
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

    private function throttledController(ReadingGranularity $throttle): IngestController
    {
        // Plafond activé (Day = tarif fixe, QuarterHour = tarif dynamique), fuseau UTC.
        return new IngestController($this->elec, $this->gas, $this->water, $throttle, 'UTC');
    }

    private function dailyLimitController(): IngestController
    {
        return $this->throttledController(ReadingGranularity::Day);
    }

    public function testElectricityBatchIsIngested(): void
    {
        $res = $this->controller()->electricity($this->post(['readings' => [
            ['timestamp' => '2026-07-02T14:00:00Z', 'import_t1' => 100.5, 'import_t2' => 50.2],
            ['timestamp' => '2026-07-02T15:00:00Z', 'import_t1' => 101.0, 'production' => 200.0],
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
            'timestamp' => '2026-07-02T14:00:00Z',
            'export_t1' => 12.5,
        ]));

        self::assertSame(1, $res->data['inserted']);
        self::assertSame(['export_t1' => 12.5], $this->elec->calls[0]['indexes']);
    }

    public function testElectricityResendIsIdempotent(): void
    {
        $body = ['readings' => [['timestamp' => '2026-07-02T14:00:00Z', 'import_t1' => 100.0]]];

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
            ['timestamp' => '2026-07-02T14:00:00Z'],
        ]]));
    }

    public function testElectricityRejectsNegativeIndex(): void
    {
        $this->expectException(ValidationException::class);

        $this->controller()->electricity($this->post(['readings' => [
            ['timestamp' => '2026-07-02T14:00:00Z', 'import_t1' => -5],
        ]]));
    }

    public function testElectricityRejectsBadTimestamp(): void
    {
        $this->expectException(ValidationException::class);

        $this->controller()->electricity($this->post(['readings' => [
            ['timestamp' => 'pas-une-date', 'import_t1' => 1],
        ]]));
    }

    public function testElectricityRejectsTimestampWithoutOffset(): void
    {
        // Horodatage nu (sans fuseau) : refusé explicitement (#175) plutôt que
        // supposé UTC en silence.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/fuseau requis/');

        $this->controller()->electricity($this->post(['readings' => [
            ['timestamp' => '2026-07-02 14:00:00', 'import_t1' => 1],
        ]]));
    }

    public function testDailyLimitSkipsSameRegisterSameDay(): void
    {
        // Deux lectures du même registre le même jour à des heures différentes :
        // la 1re est insérée, la 2de est ignorée en silence (comme un doublon).
        $res = $this->dailyLimitController()->electricity($this->post(['readings' => [
            ['timestamp' => '2026-07-02T07:00:00Z', 'import_t1' => 100.0],
            ['timestamp' => '2026-07-02T18:00:00Z', 'import_t1' => 110.0],
        ]]));

        self::assertSame(2, $res->data['received']); // les 2 sont reçues
        self::assertSame(1, $res->data['inserted']); // seule la 1re insérée
        self::assertCount(1, $this->elec->calls);
    }

    public function testDailyLimitKeepsDifferentRegistersSameDay(): void
    {
        // import_t1 le matin, production le soir : registres indépendants, tout passe.
        $res = $this->dailyLimitController()->electricity($this->post(['readings' => [
            ['timestamp' => '2026-07-02T07:00:00Z', 'import_t1' => 100.0],
            ['timestamp' => '2026-07-02T18:00:00Z', 'production' => 200.0],
        ]]));

        self::assertSame(2, $res->data['received']);
        self::assertSame(2, $res->data['inserted']);
    }

    public function testDailyLimitInsertsFreeRegisterAndSkipsBlockedInSameReading(): void
    {
        // Pré-remplir import_t1 le 02/07 à 07:00.
        $this->dailyLimitController()->electricity($this->post([
            'timestamp' => '2026-07-02T07:00:00Z', 'import_t1' => 100.0,
        ]));
        $this->elec->calls = []; // repartir propre pour l'assertion

        // Lecture mêlant un registre déjà présent (import_t1) et un libre (import_t2).
        $res = $this->dailyLimitController()->electricity($this->post([
            'timestamp' => '2026-07-02T18:00:00Z', 'import_t1' => 110.0, 'import_t2' => 50.0,
        ]));

        self::assertSame(2, $res->data['received']);
        self::assertSame(1, $res->data['inserted']); // seul import_t2 inséré
        self::assertSame(['import_t2' => 50.0], $this->elec->calls[0]['indexes']);
    }

    public function testDailyLimitOffKeepsAllHourlyReadings(): void
    {
        // Sans plafond, les lectures horaires sont conservées.
        $res = $this->controller()->electricity($this->post(['readings' => [
            ['timestamp' => '2026-07-02T07:00:00Z', 'import_t1' => 100.0],
            ['timestamp' => '2026-07-02T18:00:00Z', 'import_t1' => 110.0],
        ]]));

        self::assertSame(2, $res->data['received']);
        self::assertSame(2, $res->data['inserted']);
    }

    public function testQuarterHourLimitSkipsSameSlotKeepsNextSlot(): void
    {
        // Tarif dynamique : dans le même quart d'heure aligné, un seul index par
        // registre ; le créneau suivant repasse.
        $res = $this->throttledController(ReadingGranularity::QuarterHour)->electricity($this->post(['readings' => [
            ['timestamp' => '2026-07-02T07:02:00Z', 'import_t1' => 100.0], // créneau [07:00–07:15)
            ['timestamp' => '2026-07-02T07:12:00Z', 'import_t1' => 101.0], // même créneau → ignoré
            ['timestamp' => '2026-07-02T07:20:00Z', 'import_t1' => 102.0], // créneau [07:15–07:30) → inséré
        ]]));

        self::assertSame(3, $res->data['received']);
        self::assertSame(2, $res->data['inserted']);
        self::assertCount(2, $this->elec->calls);
    }

    public function testGasBatchAndIdempotence(): void
    {
        $res = $this->controller()->gas($this->post(['readings' => [
            ['reading_at' => '2026-07-01T08:00:00Z', 'counter_m3' => 1234.5],
            ['reading_at' => '2026-07-01T08:00:00Z', 'counter_m3' => 1234.5], // doublon dans le batch
        ]]));

        self::assertSame(2, $res->data['received']);
        self::assertSame(1, $res->data['inserted']);
        self::assertSame(['2026-07-01 08:00:00' => 1234.5], $this->gas->saved);
    }

    public function testWaterAcceptsZeroCounter(): void
    {
        // 0 m³ = mise en service du compteur (cf. import eau).
        $res = $this->controller()->water($this->post(['reading_at' => '2026-07-01T08:00:00Z', 'counter_m3' => 0]));

        self::assertSame(1, $res->data['inserted']);
    }

    public function testGasRejectsNegativeCounter(): void
    {
        $this->expectException(ValidationException::class);

        $this->controller()->gas($this->post(['reading_at' => '2026-07-01T08:00:00Z', 'counter_m3' => -1]));
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
            $readings[] = ['reading_at' => '2026-07-01T08:00:00Z', 'counter_m3' => 1.0];
        }

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/maximum 1000/');

        $this->controller()->gas($this->post(['readings' => $readings]));
    }
}
