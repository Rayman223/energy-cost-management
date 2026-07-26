<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Domain\ComponentKind;
use App\Domain\SpotFormula;
use App\Domain\TariffGrid;
use App\Domain\TariffLine;
use App\Service\SpotFormulaResolver;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class SpotFormulaResolverTest extends TestCase
{
    /** @param array<string,TariffLine> $lines */
    private function grid(array $lines): TariffGrid
    {
        return new TariffGrid(
            id: 1,
            energyType: 'electricity',
            name: 'Elec test',
            validFrom: new DateTimeImmutable('2026-01-01'),
            validTo: null,
            lines: $lines,
        );
    }

    public function testFallsBackToProfileMarkupWhenGridHasNoSpotLine(): void
    {
        $grid = $this->grid([
            'energy_t1' => new TariffLine('energy_t1', 0.10, ComponentKind::EnergyT1),
        ]);

        $formula = (new SpotFormulaResolver())->resolve($grid, 0.0145);

        self::assertSame(SpotFormula::SOURCE_PROFILE, $formula->offsetSource);
        self::assertSame(1.0, $formula->coefficient);
        self::assertSame(0.0145, $formula->offsetTtc);
    }

    public function testGridOffsetTakesPrecedenceOverProfileMarkup(): void
    {
        // Règle anti-double-comptage : une ligne spot_offset écarte la marge du profil —
        // sinon une marge saisie aux deux endroits serait comptée deux fois.
        $grid = $this->grid([
            'spot_offset' => new TariffLine('spot_offset', 0.02, ComponentKind::SpotOffset),
        ]);

        $formula = (new SpotFormulaResolver())->resolve($grid, 0.0145);

        self::assertSame(SpotFormula::SOURCE_GRID, $formula->offsetSource);
        self::assertSame(0.02, $formula->offsetTtc);
    }

    /**
     * Précédence PAR COMPOSANTE : un coefficient seul ne fait pas double emploi avec la
     * marge du profil. L'écarter la ferait disparaître de chaque heure alors que
     * /account continue de l'afficher — perte silencieuse.
     */
    public function testACoefficientAloneKeepsTheProfileMarkup(): void
    {
        $grid = $this->grid([
            'spot_coefficient' => new TariffLine('spot_coefficient', 1.08, ComponentKind::SpotCoefficient),
        ]);

        $formula = (new SpotFormulaResolver())->resolve($grid, 0.0145);

        self::assertEqualsWithDelta(1.08, $formula->coefficient, 0.0000001);
        self::assertSame(0.0145, $formula->offsetTtc);
        self::assertSame(SpotFormula::SOURCE_PROFILE, $formula->offsetSource);
    }

    /** Une ligne de marge à 0 est un choix explicite : elle écarte bien le profil. */
    public function testAnExplicitZeroOffsetStillDiscardsTheProfileMarkup(): void
    {
        $grid = $this->grid([
            'spot_offset' => new TariffLine('spot_offset', 0.0, ComponentKind::SpotOffset),
        ]);

        $formula = (new SpotFormulaResolver())->resolve($grid, 0.0145);

        self::assertSame(0.0, $formula->offsetTtc);
        self::assertSame(SpotFormula::SOURCE_GRID, $formula->offsetSource);
    }

    public function testMultipleOffsetsAreSummedAndCoefficientsMultiplied(): void
    {
        $grid = $this->grid([
            'spot_coefficient' => new TariffLine('spot_coefficient', 1.05, ComponentKind::SpotCoefficient),
            'losses'           => new TariffLine('losses', 1.02, ComponentKind::SpotCoefficient),
            'spot_offset'      => new TariffLine('spot_offset', 0.0145, ComponentKind::SpotOffset),
            'balancing'        => new TariffLine('balancing', 0.003, ComponentKind::SpotOffset),
        ]);

        $formula = (new SpotFormulaResolver())->resolve($grid);

        self::assertEqualsWithDelta(1.071, $formula->coefficient, 0.0000001);
        self::assertEqualsWithDelta(0.0175, $formula->offsetTtc, 0.0000001);
    }

    public function testZeroOrNegativeCoefficientIsNeutralisedAndReported(): void
    {
        // Un coefficient nul annulerait tout le coût énergie, un négatif l'inverserait.
        foreach ([0.0, -1.08] as $bad) {
            $grid = $this->grid([
                'spot_coefficient' => new TariffLine('spot_coefficient', $bad, ComponentKind::SpotCoefficient),
            ]);

            $formula = (new SpotFormulaResolver())->resolve($grid, 0.0145);

            self::assertSame(1.0, $formula->coefficient, 'coefficient ' . $bad);
            // Signalé : sans ce drapeau, le dashboard afficherait une formule neutre
            // sans rapport ni avec la grille saisie, ni avec le repli profil.
            self::assertTrue($formula->coefficientRejected, 'coefficient ' . $bad);
            // Aucune ligne de marge en grille → la marge du profil s'applique toujours.
            self::assertSame(0.0145, $formula->offsetTtc);
        }
    }

    public function testOutOfRangeCoefficientIsNeutralisedAndReported(): void
    {
        // 108 au lieu de 1,08 : pourcentage saisi tel quel.
        $grid = $this->grid([
            'spot_coefficient' => new TariffLine('spot_coefficient', 108.0, ComponentKind::SpotCoefficient),
        ]);

        $formula = (new SpotFormulaResolver())->resolve($grid);

        self::assertSame(1.0, $formula->coefficient);
        self::assertTrue($formula->coefficientRejected);
    }

    public function testProductOutOfRangeIsNeutralisedAndReported(): void
    {
        // Chaque facteur est valide, mais leur produit (8.0) dépasse la borne.
        $grid = $this->grid([
            'a' => new TariffLine('a', 2.0, ComponentKind::SpotCoefficient),
            'b' => new TariffLine('b', 4.0, ComponentKind::SpotCoefficient),
        ]);

        $formula = (new SpotFormulaResolver())->resolve($grid);

        self::assertSame(1.0, $formula->coefficient);
        self::assertTrue($formula->coefficientRejected);
    }

    public function testAValidCoefficientIsNotFlaggedAsRejected(): void
    {
        $grid = $this->grid([
            'spot_coefficient' => new TariffLine('spot_coefficient', 1.08, ComponentKind::SpotCoefficient),
        ]);

        self::assertFalse((new SpotFormulaResolver())->resolve($grid)->coefficientRejected);
    }

    public function testNegativeOffsetIsAllowed(): void
    {
        // Une remise contractuelle au kWh est légitime, contrairement à un coefficient ≤ 0.
        $grid = $this->grid([
            'discount' => new TariffLine('discount', -0.005, ComponentKind::SpotOffset),
        ]);

        self::assertEqualsWithDelta(-0.005, (new SpotFormulaResolver())->resolve($grid)->offsetTtc, 0.0000001);
    }
}
