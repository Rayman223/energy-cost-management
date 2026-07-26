<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\SpotFormula;
use PHPUnit\Framework\TestCase;

final class SpotFormulaTest extends TestCase
{
    public function testDefaultFormulaIsNeutralAndOnlyAppliesVat(): void
    {
        $formula = new SpotFormula();

        self::assertTrue($formula->isNeutral());
        self::assertSame(SpotFormula::SOURCE_PROFILE, $formula->offsetSource);
        self::assertFalse($formula->coefficientRejected);
        // 0.20 × 1.0 × 1.21 = 0.242 : seule la TVA s'applique.
        self::assertEqualsWithDelta(0.242, $formula->rateTtc(0.20, 21.0), 0.0000001);
    }

    public function testCoefficientMultipliesSpotBeforeVat(): void
    {
        $formula = new SpotFormula(1.08, 0.0, SpotFormula::SOURCE_GRID);

        // 0.20 × 1.08 × 1.06 = 0.22896.
        self::assertEqualsWithDelta(0.22896, $formula->rateTtc(0.20, 6.0), 0.0000001);
        self::assertFalse($formula->isNeutral());
    }

    public function testOffsetIsAddedAfterVatBecauseItIsAlreadyTtc(): void
    {
        $formula = new SpotFormula(1.0, 0.0145, SpotFormula::SOURCE_GRID);

        // 0.20 × 1.06 + 0.0145 = 0.2265 — et non (0.20 + 0.0145) × 1.06 = 0.22737.
        self::assertEqualsWithDelta(0.2265, $formula->rateTtc(0.20, 6.0), 0.0000001);
    }

    public function testCoefficientAndOffsetCombine(): void
    {
        $formula = new SpotFormula(1.08, 0.0145, SpotFormula::SOURCE_GRID);

        // 0.20 × 1.08 × 1.06 + 0.0145 = 0.24346.
        self::assertEqualsWithDelta(0.24346, $formula->rateTtc(0.20, 6.0), 0.0000001);
    }

    public function testNegativeSpotPriceStaysNegative(): void
    {
        // Les prix day-ahead passent régulièrement en négatif : la formule ne doit ni
        // planchonner ni inverser le signe, seulement le transformer.
        $formula = new SpotFormula(1.08, 0.0145, SpotFormula::SOURCE_GRID);

        // -0.05 × 1.08 × 1.06 + 0.0145 = -0.04274.
        self::assertEqualsWithDelta(-0.04274, $formula->rateTtc(-0.05, 6.0), 0.0000001);
    }

    public function testIsNeutralRequiresBothCoefficientAndOffset(): void
    {
        self::assertFalse((new SpotFormula(1.0, 0.0145))->isNeutral());
        self::assertFalse((new SpotFormula(1.08, 0.0))->isNeutral());
        self::assertTrue((new SpotFormula(1.0, 0.0))->isNeutral());
    }
}
