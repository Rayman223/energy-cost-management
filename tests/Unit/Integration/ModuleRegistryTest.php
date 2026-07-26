<?php

declare(strict_types=1);

namespace Tests\Unit\Integration;

use App\Integration\EnergyId\EnergyIdModule;
use App\Integration\ModuleRegistry;
use PHPUnit\Framework\TestCase;

final class ModuleRegistryTest extends TestCase
{
    public function testAllContainsEnergyIdWithUniqueKeys(): void
    {
        $modules = ModuleRegistry::all([]);

        self::assertNotEmpty($modules);
        $keys = array_map(static fn ($m): string => $m->key(), $modules);
        self::assertContains('energyid', $keys);
        self::assertSame($keys, array_values(array_unique($keys)), 'Les clés de module doivent être uniques.');
    }

    public function testEnabledFiltersOnGlobalKillSwitch(): void
    {
        // Sans drapeau explicite, aucun module actif → aucune carte sur /account (#233).
        self::assertSame([], ModuleRegistry::enabled([]));
        self::assertSame([], ModuleRegistry::enabled(['energyid' => ['enabled' => false]]));

        $modules = ModuleRegistry::enabled(['energyid' => ['enabled' => true]]);

        self::assertCount(1, $modules);
        self::assertSame('energyid', $modules[0]->key());
    }

    public function testFindReturnsModuleByKey(): void
    {
        $module = ModuleRegistry::find('energyid', []);

        self::assertInstanceOf(EnergyIdModule::class, $module);
        self::assertSame('energyid', $module->key());
    }

    public function testFindReturnsNullForUnknownKey(): void
    {
        self::assertNull(ModuleRegistry::find('does-not-exist', []));
    }
}
