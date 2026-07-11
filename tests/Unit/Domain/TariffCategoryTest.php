<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\ComponentKind;
use App\Domain\TariffCategory;
use App\Domain\TariffLine;
use PHPUnit\Framework\TestCase;

final class TariffCategoryTest extends TestCase
{
    public function testValuesAreOrderedAndClosed(): void
    {
        self::assertSame(
            ['energy', 'distribution', 'fixed', 'taxes', 'injection'],
            TariffCategory::values(),
        );
    }

    public function testFromStringOrDefaultFallsBackToTaxes(): void
    {
        self::assertSame(TariffCategory::Distribution, TariffCategory::fromStringOrDefault('distribution'));
        self::assertSame(TariffCategory::Taxes, TariffCategory::fromStringOrDefault('inconnue'));
        self::assertSame(TariffCategory::Taxes, TariffCategory::fromStringOrDefault(''));
    }

    /**
     * Le défaut reproduit le groupe historique dérivé du kind : jamais
     * `distribution` (catégorie purement opt-in).
     */
    public function testDefaultForKindReproducesHistoricalGroup(): void
    {
        self::assertSame(TariffCategory::Energy, TariffCategory::defaultForKind(ComponentKind::EnergyT1));
        self::assertSame(TariffCategory::Fixed, TariffCategory::defaultForKind(ComponentKind::FixedAnnual));
        self::assertSame(TariffCategory::Injection, TariffCategory::defaultForKind(ComponentKind::InjectionT1));
        self::assertSame(TariffCategory::Taxes, TariffCategory::defaultForKind(ComponentKind::PerKwh));
        // Une distribution (per_kwh_t1) retombe sur taxes tant qu'elle n'est pas recatégorisée.
        self::assertSame(TariffCategory::Taxes, TariffCategory::defaultForKind(ComponentKind::PerKwhT1));

        // Chaque kind doit produire une catégorie valide (pas d'exception).
        foreach (ComponentKind::cases() as $kind) {
            self::assertContains(TariffCategory::defaultForKind($kind)->value, TariffCategory::values());
        }
    }

    public function testRelevantForExcludesInjectionOutsideElectricity(): void
    {
        self::assertSame(TariffCategory::values(), TariffCategory::relevantFor('electricity'));

        foreach (['gas', 'water'] as $energy) {
            $relevant = TariffCategory::relevantFor($energy);
            self::assertNotContains('injection', $relevant, "injection ne devrait pas être pertinent pour $energy");
            self::assertContains('distribution', $relevant);
            self::assertContains('energy', $relevant);
        }
    }

    public function testTariffLineCategoryFallsBackToKindDefaultWhenNull(): void
    {
        $line = new TariffLine('transport', 0.01, ComponentKind::PerKwh);
        self::assertSame(TariffCategory::Taxes, $line->category());
    }

    public function testTariffLineCategoryHonoursExplicitChoice(): void
    {
        $line = new TariffLine('transport', 0.01, ComponentKind::PerKwh, category: TariffCategory::Distribution);
        self::assertSame(TariffCategory::Distribution, $line->category());
    }
}
