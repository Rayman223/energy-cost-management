<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\ComponentKind;
use App\Domain\TariffGrid;
use App\Domain\TariffLine;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Plage de validité d'une grille tarifaire : `[valid_from, valid_to[`, borne de
 * fin EXCLUE (#1, cf. app/docs/date-bounds.md).
 *
 * C'est `isActiveOn()` qui décide du tarif appliqué à chaque journée, via
 * {@see \App\Service\TariffPeriodSplitter}. Un jour du mauvais côté de la borne
 * est facturé au mauvais prix — et un jour revendiqué par deux grilles à la fois
 * l'était deux fois.
 */
final class TariffGridValidityTest extends TestCase
{
    private function grid(string $validFrom, ?string $validTo): TariffGrid
    {
        return new TariffGrid(
            id: 1,
            energyType: 'electricity',
            name: 'A',
            validFrom: new DateTimeImmutable($validFrom, new DateTimeZone('UTC')),
            validTo: $validTo !== null ? new DateTimeImmutable($validTo, new DateTimeZone('UTC')) : null,
            lines: ['energy_t1' => new TariffLine('energy_t1', 0.10, ComponentKind::EnergyT1)],
        );
    }

    private function at(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC'));
    }

    public function testStartIsIncludedAndEndIsExcluded(): void
    {
        $g = $this->grid('2026-01-01', '2027-01-01');

        self::assertTrue($g->isActiveOn($this->at('2026-01-01')));
        self::assertTrue($g->isActiveOn($this->at('2026-12-31')));
        self::assertFalse($g->isActiveOn($this->at('2027-01-01')));
        self::assertFalse($g->isActiveOn($this->at('2025-12-31')));
    }

    public function testOpenEndedGridStaysActiveIndefinitely(): void
    {
        $g = $this->grid('2026-01-01', null);

        self::assertTrue($g->isActiveOn($this->at('2099-12-31')));
        self::assertFalse($g->isActiveOn($this->at('2025-12-31')));
    }

    /**
     * Deux grilles successives se partagent une FRONTIÈRE, pas un jour : le jour de
     * bascule appartient à la nouvelle seule. C'est ce qui rend le découpage d'une
     * période insensible à l'ordre de priorité sur ce jour-là.
     */
    public function testSwitchDayBelongsToTheNewGridOnly(): void
    {
        $before = $this->grid('2026-01-01', '2026-07-01');
        $after  = $this->grid('2026-07-01', null);
        $switch = $this->at('2026-07-01');

        self::assertFalse($before->isActiveOn($switch));
        self::assertTrue($after->isActiveOn($switch));

        // Et la veille appartient encore à l'ancienne, sans trou entre les deux.
        $eve = $this->at('2026-06-30');
        self::assertTrue($before->isActiveOn($eve));
        self::assertFalse($after->isActiveOn($eve));
    }

    /**
     * L'heure du jour ne doit pas décider : les appelants passent tantôt un jour nu,
     * tantôt un instant horodaté (une période de coût démarre à l'heure du relevé).
     * Comparer un `14:00` à une borne stockée à minuit sortait la journée de sa
     * propre grille.
     */
    public function testTimeOfDayDoesNotChangeTheVerdict(): void
    {
        $g = $this->grid('2026-01-01', '2026-07-01');

        self::assertTrue($g->isActiveOn(new DateTimeImmutable('2026-06-30 23:59:59', new DateTimeZone('UTC'))));
        self::assertTrue($g->isActiveOn(new DateTimeImmutable('2026-01-01 14:00:00', new DateTimeZone('UTC'))));
        self::assertFalse($g->isActiveOn(new DateTimeImmutable('2026-07-01 00:00:01', new DateTimeZone('UTC'))));
    }

    /** Plage vide : refusée à la saisie, elle ne couvre aucun jour si elle survient. */
    public function testEmptyRangeCoversNothing(): void
    {
        $g = $this->grid('2026-07-01', '2026-07-01');

        self::assertFalse($g->isActiveOn($this->at('2026-06-30')));
        self::assertFalse($g->isActiveOn($this->at('2026-07-01')));
    }
}
