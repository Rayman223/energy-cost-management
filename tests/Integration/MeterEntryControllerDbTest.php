<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Http\Controller\MeterEntryController;
use App\Http\Request;
use App\Http\ValidationException;
use App\Repository\ElectricityReadingRepository;
use App\Repository\UserRepository;
use App\Repository\UtilityReadingRepository;
use DateTimeImmutable;

/**
 * Test d'intégration du contrôleur de saisie manuelle contre de vrais
 * repositories : couvre la détection de doublon (comparaison d'horodatage
 * réelle + contrainte UNIQUE) et la validation encadrante des relevés
 * antidatés, que les tests unitaires à doublure ne peuvent exercer.
 * S'auto-skippe sans base de test joignable.
 */
final class MeterEntryControllerDbTest extends DatabaseTestCase
{
    private int $userId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (new UserRepository($this->pdo()))->create('https://iss.test', 'ctrl-test', 'test', 'Ctrl Tester')->id;
    }

    protected function clean(): void
    {
        foreach (['utility_readings', 'meter_readings', 'meter_registers', 'meters', 'user_profiles', 'users'] as $table) {
            $this->pdo()->exec('DELETE FROM ' . $table);
        }
    }

    private function controller(): MeterEntryController
    {
        return new MeterEntryController(
            new UtilityReadingRepository($this->pdo(), $this->userId, 'gas'),
            new UtilityReadingRepository($this->pdo(), $this->userId, 'water'),
            new ElectricityReadingRepository($this->pdo(), $this->userId),
        );
    }

    /** @param array<string, mixed> $body */
    private function gasPost(array $body): Request
    {
        return new Request('POST', ['action' => 'gas_entry'], $body);
    }

    public function testRejectsExactDuplicateThroughRealRepository(): void
    {
        (new UtilityReadingRepository($this->pdo(), $this->userId, 'gas'))
            ->save(new DateTimeImmutable('2026-06-15 10:00:00'), 100.0);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('already exists at this date');
        $this->controller()->gas($this->gasPost(['counter_m3' => 100.0, 'reading_at' => '2026-06-15 10:00:00']));
    }

    public function testAcceptsBackdatedReadingBetweenTwoExisting(): void
    {
        $gas = new UtilityReadingRepository($this->pdo(), $this->userId, 'gas');
        $gas->save(new DateTimeImmutable('2026-06-01 10:00:00'), 100.0);
        $gas->save(new DateTimeImmutable('2026-07-01 10:00:00'), 150.0);

        $res = $this->controller()->gas($this->gasPost(['counter_m3' => 120.0, 'reading_at' => '2026-06-15 10:00:00']));

        self::assertSame(200, $res->status);
        self::assertCount(3, $gas->getAllReadings());
    }

    public function testRejectsBackdatedValueAboveNextThroughRealRepository(): void
    {
        $gas = new UtilityReadingRepository($this->pdo(), $this->userId, 'gas');
        $gas->save(new DateTimeImmutable('2026-06-01 10:00:00'), 100.0);
        $gas->save(new DateTimeImmutable('2026-07-01 10:00:00'), 150.0);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must be ≤ next reading');
        $this->controller()->gas($this->gasPost(['counter_m3' => 999.0, 'reading_at' => '2026-06-15 10:00:00']));
    }
}
