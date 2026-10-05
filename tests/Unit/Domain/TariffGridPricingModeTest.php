<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\TariffGrid;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Mode de tarification porté par la grille (#245) : il a quitté le profil pour être
 * versionné par valid_from/valid_to, comme la TVA avant lui (#232).
 */
final class TariffGridPricingModeTest extends TestCase
{
    private function grid(string $mode = TariffGrid::PRICING_MODE_DEFAULT): TariffGrid
    {
        return new TariffGrid(
            id: 1,
            energyType: 'electricity',
            name: 'Test',
            validFrom: new DateTimeImmutable('2026-01-01'),
            validTo: null,
            lines: [],
            pricingMode: $mode,
        );
    }

    public function testDefaultsToFixed(): void
    {
        self::assertSame('fixed', $this->grid()->pricingMode);
        self::assertFalse($this->grid()->isDynamic());
    }

    public function testBothDynamicResolutionsAreDynamic(): void
    {
        self::assertTrue($this->grid('dynamic_hourly')->isDynamic());
        self::assertTrue($this->grid('dynamic_quarter')->isDynamic());
    }

    public function testWhitelistIsClosed(): void
    {
        self::assertSame(
            ['fixed', 'dynamic_hourly', 'dynamic_quarter', 'indexed_monthly'],
            TariffGrid::PRICING_MODES,
        );
        self::assertContains(TariffGrid::PRICING_MODE_DEFAULT, TariffGrid::PRICING_MODES);
    }

    /**
     * #93 — un contrat à prix variable est bien indexé sur le marché, mais son prix est
     * PLAT sur le mois. `isDynamic()` (indexé ?) et `isTimeVarying()` (variable dans la
     * période ?) cessent donc de coïncider : c'est la distinction que le mode introduit.
     */
    public function testIndexedMonthlyIsDynamicButNotTimeVarying(): void
    {
        $grid = $this->grid('indexed_monthly');

        self::assertTrue($grid->isDynamic());
        self::assertFalse($grid->isTimeVarying());
        self::assertTrue($grid->isMonthlyIndexed());
    }

    public function testTimeVaryingCoversOnlyTheDynamicFamily(): void
    {
        self::assertSame(['dynamic_hourly', 'dynamic_quarter'], TariffGrid::TIME_VARYING_MODES);

        self::assertTrue($this->grid('dynamic_hourly')->isTimeVarying());
        self::assertTrue($this->grid('dynamic_quarter')->isTimeVarying());
        self::assertFalse($this->grid()->isTimeVarying());

        self::assertFalse($this->grid('dynamic_hourly')->isMonthlyIndexed());
        self::assertFalse($this->grid()->isMonthlyIndexed());
    }

    public function testNormalizationAcceptsIndexedMonthly(): void
    {
        self::assertSame('indexed_monthly', TariffGrid::normalizePricingMode('indexed_monthly'));
        self::assertSame('fixed', TariffGrid::normalizePricingMode('indexed'));
        self::assertSame('fixed', TariffGrid::normalizePricingMode('indexed_yearly'));
    }

    /**
     * Toute valeur hors liste retombe sur 'fixed'. C'est la dernière ligne de défense
     * de l'écriture : l'API accepte des grilles importées sans valider ce champ, et
     * un ENUM MySQL sans STRICT_TRANS_TABLES tronquerait la valeur en silence.
     */
    public function testNormalizationRejectsUnknownModes(): void
    {
        self::assertSame('fixed', TariffGrid::normalizePricingMode('dynamic'));
        self::assertSame('fixed', TariffGrid::normalizePricingMode(''));
        self::assertSame('fixed', TariffGrid::normalizePricingMode('DYNAMIC_HOURLY'));
        self::assertSame('dynamic_quarter', TariffGrid::normalizePricingMode('dynamic_quarter'));
    }
}
