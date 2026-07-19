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
