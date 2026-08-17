<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controller\ReadingsController;
use App\Http\Request;
use App\Repository\ElectricityReadingRepository;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Fake\FakeMeterReadingRepository;

/**
 * Historiques paginés gaz/eau (#257) : enveloppe, découpage et clamp de la page.
 *
 * Le volet électricité passe par les tests d'intégration : le contrôleur dépend
 * de `ElectricityReadingRepository` (classe `final`, donc non mockable) — ici un
 * PDO mocké suffit à la construire, aucune requête n'étant émise sur ce chemin.
 */
final class ReadingsControllerTest extends TestCase
{
    /** @return list<array{id:int,reading_at:string,counter_m3:float,delta_m3:float|null}> */
    private function readings(int $count): array
    {
        $rows = [];
        // Du plus récent au plus ancien, comme les repositories réels.
        for ($i = $count; $i >= 1; $i--) {
            $rows[] = [
                'id'         => $i,
                'reading_at' => sprintf('2026-07-%02d 08:00:00', $i),
                'counter_m3' => 100.0 + $i,
                'delta_m3'   => $i > 1 ? 1.0 : null,
            ];
        }

        return $rows;
    }

    private function controller(FakeMeterReadingRepository $gas): ReadingsController
    {
        $elec = new ElectricityReadingRepository($this->createStub(PDO::class), 1);

        return new ReadingsController($elec, $gas, new FakeMeterReadingRepository());
    }

    /** @param array<string,mixed> $query */
    private function gasHistory(FakeMeterReadingRepository $gas, array $query): mixed
    {
        return $this->controller($gas)->gasHistory(new Request('GET', $query, []))->data;
    }

    public function testFirstPageIsEnveloped(): void
    {
        $repo = new FakeMeterReadingRepository(all: $this->readings(30));

        $data = $this->gasHistory($repo, ['action' => 'gas_history']);

        self::assertIsArray($data);
        self::assertSame(30, $data['total']);
        self::assertSame(1, $data['page']);
        self::assertSame(25, $data['per_page']);
        self::assertCount(25, $data['items']);
        self::assertSame('2026-07-30 08:00:00', $data['items'][0]['reading_at'], 'Le plus récent en tête.');
    }

    public function testSecondPageReturnsRemainingReadings(): void
    {
        $repo = new FakeMeterReadingRepository(all: $this->readings(30));

        $data = $this->gasHistory($repo, ['page' => '2']);

        self::assertIsArray($data);
        self::assertSame(2, $data['page']);
        self::assertCount(5, $data['items']);
        self::assertSame('2026-07-05 08:00:00', $data['items'][0]['reading_at']);
        self::assertSame('2026-07-01 08:00:00', $data['items'][4]['reading_at'], 'Le plus ancien est atteignable.');
    }

    /**
     * Page au-delà du dernier relevé (URL forgée, ou dernière ligne d'une page
     * supprimée) : on renvoie la dernière page non vide plutôt qu'un tableau vide.
     */
    public function testPageBeyondLastIsClamped(): void
    {
        $repo = new FakeMeterReadingRepository(all: $this->readings(30));

        $data = $this->gasHistory($repo, ['page' => '9']);

        self::assertIsArray($data);
        self::assertSame(2, $data['page']);
        self::assertCount(5, $data['items']);
    }

    public function testEmptyHistoryKeepsFirstPage(): void
    {
        $data = $this->gasHistory(new FakeMeterReadingRepository(), ['page' => '3']);

        self::assertIsArray($data);
        self::assertSame(['items' => [], 'total' => 0, 'page' => 1, 'per_page' => 25], $data);
    }

    /** `?per_page=` (champ vide dans une URL forgée) : on sert la page par défaut. */
    public function testEmptyPerPageServesTheDefaultPageSize(): void
    {
        $repo = new FakeMeterReadingRepository(all: $this->readings(30));

        $data = $this->gasHistory($repo, ['per_page' => '']);

        self::assertIsArray($data);
        self::assertSame(25, $data['per_page']);
        self::assertCount(25, $data['items']);
    }

    public function testPerPageIsHonoured(): void
    {
        $repo = new FakeMeterReadingRepository(all: $this->readings(30));

        $data = $this->gasHistory($repo, ['page' => '3', 'per_page' => '10']);

        self::assertIsArray($data);
        self::assertSame(10, $data['per_page']);
        self::assertCount(10, $data['items']);
        self::assertSame('2026-07-10 08:00:00', $data['items'][0]['reading_at']);
    }
}
