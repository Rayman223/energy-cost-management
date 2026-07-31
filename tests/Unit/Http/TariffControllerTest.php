<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Domain\ComponentKind;
use App\Domain\TariffGrid;
use App\Domain\TariffLine;
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
        $this->expectExceptionMessage('energy_type must be electricity, gas or water');
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

    public function testSaveRejectsTariffWithoutValidLines(): void
    {
        $repo = new FakeTariffRepository();

        $body = $this->validBody();
        $body['lines'] = ['energy_t1' => '', 'energy_t2' => null];

        try {
            (new TariffController($repo))->save($this->post($body));
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame('At least one valid tariff line is required', $e->getMessage());
        }

        // Rien ne doit être persisté : le refus intervient avant saveGrid().
        self::assertNull($repo->savedGrid);
    }

    /**
     * Un montant renseigné mais illisible est un défaut d'intégration, pas une ligne
     * absente : il était sauté en silence, laissant enregistrer une grille amputée (#262).
     */
    public function testSaveRejectsMalformedLineAmount(): void
    {
        $repo = new FakeTariffRepository();

        $body = $this->validBody();
        $body['lines'] = ['energy_t1' => 0.10, 'energy_t2' => '0,08'];

        try {
            (new TariffController($repo))->save($this->post($body));
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame('Invalid amount for tariff line: energy_t2', $e->getMessage());
        }

        // Refus global : la ligne saine ne doit pas être persistée pour autant.
        self::assertNull($repo->savedGrid);
    }

    /** Un montant vide/absent reste une ligne non renseignée : sautée, sans erreur. */
    public function testSaveSkipsBlankLineAmounts(): void
    {
        $repo = new FakeTariffRepository();

        $body = $this->validBody();
        $body['lines'] = ['energy_t1' => 0.10, 'energy_t2' => '  ', 'energy_t3' => null];

        (new TariffController($repo))->save($this->post($body));

        self::assertNotNull($repo->savedGrid);
        self::assertSame([
            ['key' => 'energy_t1', 'amount' => 0.10, 'kind' => 'energy_t1', 'label' => null, 'category' => null],
        ], $repo->savedGrid['lines']);
    }

    /**
     * Une clé hors format était persistée telle quelle, retombait sur per_kwh faute
     * d'être reconnue par le catalogue, puis rendait la grille non réenregistrable
     * depuis le formulaire web — qui, lui, refuse déjà ces clés (#265).
     */
    public function testSaveRejectsMalformedLineKey(): void
    {
        // Clé entière : `lines` envoyé comme liste JSON ([0.1, 0.2]) plutôt qu'objet.
        // « energy_t1\n » : sans le modificateur `D` de KEY_PATTERN, `$` matche avant
        // le saut de ligne final et la clé polluée passait — l'API ne trime pas.
        $cases = ['Energy_T1', 'Energy T1', '0bad', 'energy-t1', 'énergie', '_energy', '0', "energy_t1\n"];

        foreach ($cases as $key) {
            $repo = new FakeTariffRepository();

            $body          = $this->validBody();
            $body['lines'] = [$key => 0.10];

            try {
                (new TariffController($repo))->save($this->post($body));
                self::fail(sprintf('Expected ValidationException for key: %s', $key));
            } catch (ValidationException $e) {
                self::assertSame('Invalid tariff line key: ' . $key, $e->getMessage());
            }

            // Refus avant toute persistance, comme pour un montant illisible.
            self::assertNull($repo->savedGrid, $key);
        }
    }

    /**
     * Borne haute de la regex : 100 caractères passent, 101 non. Elle protège le
     * VARCHAR(100) de `tariff_grid_lines.line_key` — d'où le cas « 100 caractères
     * + \n », 101 octets qui franchissaient la garde sans le modificateur `D`.
     */
    public function testSaveRejectsOverlongLineKey(): void
    {
        $repo = new FakeTariffRepository();
        $max  = 'a' . str_repeat('b', 99);

        $body          = $this->validBody();
        $body['lines'] = [$max => 0.10];

        (new TariffController($repo))->save($this->post($body));
        self::assertNotNull($repo->savedGrid);

        foreach (['a' . str_repeat('b', 100), $max . "\n"] as $tooLong) {
            $repo          = new FakeTariffRepository();
            $body['lines'] = [$tooLong => 0.10];

            try {
                (new TariffController($repo))->save($this->post($body));
                self::fail('Expected ValidationException for a 101-byte key');
            } catch (ValidationException $e) {
                self::assertSame('Invalid tariff line key: ' . $tooLong, $e->getMessage());
            }

            self::assertNull($repo->savedGrid);
        }
    }

    /** Les clés du catalogue et les clés custom du formulaire web restent acceptées. */
    public function testSaveAcceptsCatalogAndCustomLineKeys(): void
    {
        $repo = new FakeTariffRepository();

        $body          = $this->validBody();
        $body['lines'] = ['energy_t1' => 0.10, 'custom_ma_ligne' => 0.02];

        (new TariffController($repo))->save($this->post($body));

        self::assertNotNull($repo->savedGrid);
        self::assertSame([
            ['key' => 'energy_t1', 'amount' => 0.10, 'kind' => 'energy_t1', 'label' => null, 'category' => null],
            // Hors catalogue mais bien formée : repli documenté sur per_kwh.
            ['key' => 'custom_ma_ligne', 'amount' => 0.02, 'kind' => 'per_kwh', 'label' => null, 'category' => null],
        ], $repo->savedGrid['lines']);
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
        // Les lignes plates de l'API sont converties en lignes structurées (kind déduit).
        self::assertSame([
            ['key' => 'energy_t1', 'amount' => 0.10, 'kind' => 'energy_t1', 'label' => null, 'category' => null],
            ['key' => 'energy_t2', 'amount' => 0.08, 'kind' => 'energy_t2', 'label' => null, 'category' => null],
        ], $repo->savedGrid['lines']);
    }

    /** L'API plate accepte aussi la formule dynamique (#228), kind déduit du catalogue. */
    public function testSaveMapsSpotFormulaLines(): void
    {
        $repo = new FakeTariffRepository();

        $body = $this->validBody();
        $body['lines'] = ['spot_coefficient' => 1.08, 'spot_offset' => 0.0145];

        (new TariffController($repo))->save($this->post($body));

        self::assertNotNull($repo->savedGrid);
        self::assertSame([
            ['key' => 'spot_coefficient', 'amount' => 1.08, 'kind' => 'spot_coefficient', 'label' => null, 'category' => null],
            ['key' => 'spot_offset', 'amount' => 0.0145, 'kind' => 'spot_offset', 'label' => null, 'category' => null],
        ], $repo->savedGrid['lines']);
    }

    public function testIndexMapsGridsByEnergyType(): void
    {
        $repo = new FakeTariffRepository();
        $repo->allGrids = [
            new TariffGrid(7, 'electricity', 'Grille A', new DateTimeImmutable('2026-01-01'), null, [
                'energy_t1' => new TariffLine('energy_t1', 0.1, ComponentKind::EnergyT1),
            ]),
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
