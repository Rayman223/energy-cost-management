<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Pagination;
use App\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Bornage et clamp de la fenêtre de pagination des historiques (#257).
 */
final class PaginationTest extends TestCase
{
    /** @param array<string,mixed> $query */
    private function fromQuery(array $query): Pagination
    {
        return Pagination::fromRequest(new Request('GET', $query, []));
    }

    public function testDefaultsToFirstPageOf25(): void
    {
        $p = $this->fromQuery(['action' => 'gas_history']);

        self::assertSame(1, $p->page());
        self::assertSame(25, $p->perPage());
        self::assertSame(0, $p->offset());
    }

    public function testReadsPageAndPerPage(): void
    {
        $p = $this->fromQuery(['page' => '3', 'per_page' => '10']);

        self::assertSame(3, $p->page());
        self::assertSame(10, $p->perPage());
        self::assertSame(20, $p->offset());
    }

    /**
     * Une page 0 ou négative (URL forgée) retombe sur la première page plutôt
     * que de produire un OFFSET négatif — que MySQL rejetterait.
     */
    public function testPageIsAtLeastOne(): void
    {
        self::assertSame(1, $this->fromQuery(['page' => '0'])->page());
        self::assertSame(1, $this->fromQuery(['page' => '-5'])->page());
        self::assertSame(0, $this->fromQuery(['page' => '-5'])->offset());
    }

    public function testPerPageIsBounded(): void
    {
        self::assertSame(Pagination::MAX_PER_PAGE, $this->fromQuery(['per_page' => '9999'])->perPage());
    }

    /**
     * `per_page` qui n'exprime aucune intention (0, négatif, vide, non numérique)
     * retombe sur le défaut, pas sur le plancher 1 : servir un seul relevé par
     * page pour une valeur invalide serait un piège, et le contrat annonce 25.
     */
    public function testUnusablePerPageFallsBackToDefault(): void
    {
        foreach (['0', '-5', '', 'xyz'] as $raw) {
            self::assertSame(
                Pagination::DEFAULT_PER_PAGE,
                $this->fromQuery(['per_page' => $raw])->perPage(),
                sprintf('per_page=%s', var_export($raw, true)),
            );
        }
    }

    /** `page` non numérique : `queryInt` caste à 0, le plancher ramène à 1. */
    public function testNonNumericPageFallsBackToFirst(): void
    {
        $p = $this->fromQuery(['page' => 'abc', 'per_page' => 'xyz']);

        self::assertSame(1, $p->page());
        self::assertSame(Pagination::DEFAULT_PER_PAGE, $p->perPage());
    }

    public function testCustomDefaultPerPage(): void
    {
        $p = Pagination::fromRequest(new Request('GET', [], []), 50);

        self::assertSame(50, $p->perPage());
    }

    public function testClampToKeepsPageInRange(): void
    {
        $p = $this->fromQuery(['page' => '2', 'per_page' => '10']);

        self::assertSame(2, $p->clampTo(25)->page());
        self::assertSame(2, $p->clampTo(20)->page(), 'Total multiple exact de per_page : la dernière page reste pleine.');
    }

    public function testClampToFallsBackToLastPage(): void
    {
        $p = $this->fromQuery(['page' => '7', 'per_page' => '10']);

        self::assertSame(3, $p->clampTo(25)->page());
        self::assertSame(10, $p->clampTo(25)->perPage(), 'Le clamp ne touche pas la taille de page.');
        self::assertSame(20, $p->clampTo(25)->offset());
    }

    /** Historique vide : la page 1 existe toujours (tableau « aucune entrée »). */
    public function testClampToEmptyTotalKeepsFirstPage(): void
    {
        $p = $this->fromQuery(['page' => '4', 'per_page' => '10'])->clampTo(0);

        self::assertSame(1, $p->page());
        self::assertSame(0, $p->offset());
    }

    public function testEnvelopeShape(): void
    {
        $p = $this->fromQuery(['page' => '2', 'per_page' => '10']);

        self::assertSame(
            ['items' => ['a', 'b'], 'total' => 12, 'page' => 2, 'per_page' => 10],
            $p->envelope(['a', 'b'], 12),
        );
    }

    public function testEnvelopeCarriesExtraFields(): void
    {
        $envelope = $this->fromQuery([])->envelope([], 0, ['previous' => null]);

        self::assertArrayHasKey('previous', $envelope);
        self::assertNull($envelope['previous']);
        self::assertSame(0, $envelope['total']);
    }

    /** `$extra` ne peut qu'ajouter des champs : jamais réécrire le contrat de page. */
    public function testExtraFieldsCannotOverrideEnvelope(): void
    {
        $envelope = $this->fromQuery(['page' => '2', 'per_page' => '10'])
            ->envelope(['a'], 12, ['page' => 99, 'total' => 0, 'items' => [], 'per_page' => 1, 'previous' => 'x']);

        self::assertSame(2, $envelope['page']);
        self::assertSame(12, $envelope['total']);
        self::assertSame(['a'], $envelope['items']);
        self::assertSame(10, $envelope['per_page']);
        self::assertSame('x', $envelope['previous'], 'Un champ additionnel passe toujours.');
    }
}
