<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Config\ConfigLoader;
use PHPUnit\Framework\TestCase;

final class ConfigLoaderTest extends TestCase
{
    public function testLoadUsesTheOptionalPrivateFileWithoutErasingOtherSettings(): void
    {
        $basePath = tempnam(sys_get_temp_dir(), 'ecm-config-base-');
        $overridePath = tempnam(sys_get_temp_dir(), 'ecm-config-local-');
        self::assertIsString($basePath);
        self::assertIsString($overridePath);
        try {
            file_put_contents($basePath, '<?php return ["support" => ["enabled" => true, "stripe_mode" => "test"]];');
            file_put_contents($overridePath, '<?php return ["support" => ["stripe_mode" => "live"]];');
            self::assertSame(['support' => ['enabled' => true, 'stripe_mode' => 'live']], ConfigLoader::load($basePath, $overridePath));
            self::assertSame(['support' => ['enabled' => true, 'stripe_mode' => 'test']], ConfigLoader::load($basePath, $overridePath . '.missing'));
            file_put_contents($overridePath, '<?php return false;');
            $this->expectException(\RuntimeException::class);
            ConfigLoader::load($basePath, $overridePath);
        } finally {
            unlink($basePath);
            unlink($overridePath);
        }
    }

    public function testOverridesNestedAssociativeValuesAndReplacesLists(): void
    {
        $merged = ConfigLoader::merge(
            ['database' => ['host' => 'db.internal', 'port' => 3306], 'locales' => ['fr', 'en']],
            ['database' => ['port' => 3307], 'locales' => ['nl']],
        );

        self::assertSame(['database' => ['host' => 'db.internal', 'port' => 3307], 'locales' => ['nl']], $merged);
    }
}
