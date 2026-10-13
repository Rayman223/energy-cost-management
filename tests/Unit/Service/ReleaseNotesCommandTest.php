<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReleaseNotesCommandTest extends TestCase
{
    public function testInitialVersionCanBeValidatedWithoutLoadingApplicationConfig(): void
    {
        [$code, $stdout, $stderr] = $this->execute(['--validate', '--version=1.0.0']);
        self::assertSame(0, $code, $stderr);
        self::assertSame(['ok' => true, 'version' => '1.0.0', 'status' => 'ignored'], json_decode($stdout, true, 512, JSON_THROW_ON_ERROR));
        self::assertSame('', $stderr);
    }

    public function testAbsentPreparationManifestReturnsWarning(): void
    {
        [$code, $stdout] = $this->execute(['--validate', '--version=999999.0.0']);
        self::assertSame(0, $code);
        $result = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ignored', $result['status']);
        self::assertArrayHasKey('warning', $result);
    }

    public function testTechnicalReleaseCanBeValidatedWithoutLoadingApplicationConfig(): void
    {
        [$code, $stdout, $stderr] = $this->execute(['--validate', '--version=1.0.1']);
        self::assertSame(0, $code, $stderr);
        self::assertSame(['ok' => true, 'version' => '1.0.1', 'status' => 'validated'], json_decode($stdout, true, 512, JSON_THROW_ON_ERROR));
        self::assertSame('', $stderr);
    }

    #[DataProvider('invalidArguments')]
    public function testInvalidModesCannotPublish(array $arguments): void
    {
        [$code, $stdout, $stderr] = $this->execute($arguments);
        self::assertSame(1, $code);
        self::assertSame('', $stdout);
        self::assertNotEmpty($stderr);
    }

    public static function invalidArguments(): iterable
    {
        yield [[]];
        yield [['--publish', '--version=999999.0.0']];
        yield [['--status', '--version=999999.0.0']];
        yield [['--status', '--publish']];
        yield [['--validate', '--validate']];
        yield [['--validate', '--unknown']];
        yield [['--validate', '--version=../1.0.0']];
        yield [['--validate', '--version=']];
        yield [['--validate', '--version=1.0.0', '--version=1.0.1']];
    }

    /** @return array{int, string, string} */
    private function execute(array $arguments): array
    {
        $process = proc_open([
            PHP_BINARY, __DIR__ . '/../../../app/scripts/release-notes.php', ...$arguments,
        ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
