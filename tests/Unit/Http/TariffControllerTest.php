<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Domain\TariffGrid;
use App\Http\Controller\TariffController;
use App\Http\Request;
use App\Http\ValidationException;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Fake\FakeTariffRepository;

final class TariffControllerTest extends TestCase
{
    /** @param array<string, mixed> $body */
    private function post(array $body): Request
    {
        return new Request('POST', ['action' => 'save_tariff'], $body);
    }

    /** @return array<string, mixed> */
    private function validBody(): array
    {
        return [
            'energy_type' => 'electricity',
            'name'        => 'Engie fév. 2026',
            'valid_from'  => '2026-02-01',
            'lines'       => ['energy_t1' => 0.10, 'energy_t2' => 0.08],
        ];
    }

    public function testSaveRejectsMissingField(): void
    {
        $controller = new TariffController(new FakeTariffRepository());

        $body = $this->validBody();
        unset($body['name']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Missing field: name');
        $controller->save($this->post($body));
    }

    public function testSaveRejectsInvalidEnergyType(): void
    {
        $controller = new TariffController(new FakeTariffRepository());

        $body = $this->validBody();
        $body['energy_type'] = 'nuclear';

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('energy_type must be electricity or gas');
        $controller->save($this->post($body));
    }

    public function testSaveRejectsInvalidValidFromDate(): void
    {
        $controller = new TariffController(new FakeTariffRepository());

        $body = $this->validBody();
        $body['valid_from'] = 'not-a-date';

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid valid_from date format');
        $controller->save($this->post($body));
    }

    public function testSavePersistsAndReturnsId(): void
    {
        $repo = new FakeTariffRepository();
        $repo->nextId = 99;

        $res = (new TariffController($repo))->save($this->post($this->validBody()));

        self::assertSame(200, $res->status);
        self::assertSame(['ok' => true, 'id' => 99], $res->data);
        self::assertNotNull($repo->savedGrid);
        self::assertSame('electricity', $repo->savedGrid['energy_type']);
        self::assertSame('Engie fév. 2026', $repo->savedGrid['name']);
        self::assertSame(['energy_t1' => 0.10, 'energy_t2' => 0.08], $repo->savedGrid['lines']);
    }

    public function testIndexMapsGridsByEnergyType(): void
    {
        $repo = new FakeTariffRepository();
        $repo->allGrids = [
            new TariffGrid(7, 'electricity', 'Grille A', new DateTimeImmutable('2026-01-01'), null, ['energy_t1' => 0.1]),
        ];

        $res = (new TariffController($repo))->index(new Request('GET', ['action' => 'tariffs'], []));

        self::assertSame(200, $res->status);
        self::assertIsArray($res->data);
        self::assertArrayHasKey('electricity', $res->data);
        self::assertArrayHasKey('gas', $res->data);

        $first = $res->data['electricity'][0];
        self::assertSame(7, $first['id']);
        self::assertSame('Grille A', $first['name']);
        self::assertSame('2026-01-01', $first['valid_from']);
        self::assertNull($first['valid_to']);
        self::assertSame(['energy_t1' => 0.1], $first['lines']);
    }
}
