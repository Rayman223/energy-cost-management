<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

final class ConfigCheckCommandTest extends TestCase
{
    public function testCheckingASymlinkTargetUsesTheApplicationsPrivateOverlay(): void
    {
        $root = sys_get_temp_dir() . '/ecm-config-check-' . bin2hex(random_bytes(8));
        $files = [
            '/app/config/config.php', '/app/config/config.local.php', '/app/scripts/config_check.php',
            '/shared/base.php', '/shared/config.local.php', '/vendor',
        ];
        try {
            mkdir($root . '/app/config', 0700, true);
            mkdir($root . '/app/scripts', 0700, true);
            mkdir($root . '/shared', 0700, true);
            copy(__DIR__ . '/../../../app/config/config.example.php', $root . '/shared/base.php');
            copy(__DIR__ . '/../../../app/scripts/config_check.php', $root . '/app/scripts/config_check.php');
            symlink($root . '/shared/base.php', $root . '/app/config/config.php');
            symlink(__DIR__ . '/../../../vendor', $root . '/vendor');
            file_put_contents($root . '/app/config/config.local.php', '<?php return ["oidc" => ["enabled" => true], "support" => ["enabled" => true, "stripe_mode" => "typo"]];');
            // Un autre fichier local placé auprès de la cible ne fait pas partie de l'application.
            file_put_contents($root . '/shared/config.local.php', '<?php return ["support" => ["enabled" => false]];');

            $default = $this->execute($root . '/app/scripts/config_check.php');
            $alias = $this->execute($root . '/app/scripts/config_check.php', '--file=' . $root . '/shared/base.php');
            self::assertSame(2, $default[0]);
            self::assertStringContainsString('support.stripe_mode', $default[1]);
            self::assertSame($default, $alias, 'Le diagnostic doit lire la même surcharge locale quelle que soit la manière de désigner config.php.');
        } finally {
            foreach ($files as $file) if (is_link($root . $file) || is_file($root . $file)) unlink($root . $file);
            foreach (['/app/config', '/app/scripts', '/app', '/shared', ''] as $dir) if (is_dir($root . $dir)) rmdir($root . $dir);
        }
    }

    /** @return array{int, string, string} */
    private function execute(string $script, string ...$arguments): array
    {
        $process = proc_open([PHP_BINARY, $script, '--schema-only', '--strict', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $stdout, $stderr];
    }
}
