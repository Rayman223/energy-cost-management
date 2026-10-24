<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Config\ConfigLoader;
use PHPUnit\Framework\TestCase;

final class ConfigLoaderTest extends TestCase
{
    public function testOverridesNestedAssociativeValuesAndReplacesLists(): void
    {
        $merged = ConfigLoader::merge(
            ['database' => ['host' => 'db.internal', 'port' => 3306], 'locales' => ['fr', 'en']],
            ['database' => ['port' => 3307], 'locales' => ['nl']],
        );

        self::assertSame(['database' => ['host' => 'db.internal', 'port' => 3307], 'locales' => ['nl']], $merged);
    }
}
