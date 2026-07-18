<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Config\ConfigValidator;
use PHPUnit\Framework\TestCase;

/**
 * Garde anti-dérive : config.example.php doit valider proprement contre le schéma
 * en mode `--schema-only` (le même contrôle que le job CI `lint`). Une clé ajoutée
 * au template sans l'être au schéma — ou l'inverse — casse ce test avant la CI.
 */
final class ConfigExampleTest extends TestCase
{
    public function testExampleMatchesSchemaWithoutSentinelChecks(): void
    {
        /** @var array<string, mixed> $config */
        $config = require __DIR__ . '/../../../app/config/config.example.php';

        // checkSentinels=false : les `change_me` sont attendus dans le template.
        self::assertSame([], ConfigValidator::validate($config, false));
    }
}
