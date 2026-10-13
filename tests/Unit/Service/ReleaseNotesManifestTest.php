<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Service\ReleaseNotesManifest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ReleaseNotesManifestTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/energy-manifest-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory);
    }

    public function testEnglishManifestIsNormalized(): void
    {
        $this->write(['version' => '1.2.3', 'user_impact' => true, 'announcements' => [
            'en' => ['title' => ' Version 1.2.3 ', 'content' => " **Fixes**\r\n- Updated costs. "],
        ]]);

        self::assertSame(['title' => 'Version 1.2.3', 'content' => "**Fixes**\n- Updated costs."],
            ReleaseNotesManifest::load($this->directory, '1.2.3')['announcements']['en']);
    }

    public function testNoImpactManifestHasNoAnnouncements(): void
    {
        $this->write(['version' => '1.2.3', 'user_impact' => false, 'announcements' => []]);
        self::assertFalse(ReleaseNotesManifest::load($this->directory, '1.2.3')['user_impact']);
    }

    /** @param array<string, mixed> $manifest */
    #[DataProvider('invalidManifests')]
    public function testInvalidManifestIsRejected(array $manifest): void
    {
        $this->write($manifest);
        $this->expectException(RuntimeException::class);
        ReleaseNotesManifest::load($this->directory, '1.2.3');
    }

    public static function invalidManifests(): iterable
    {
        $valid = ['version' => '1.2.3', 'user_impact' => true, 'announcements' => [
            'en' => ['title' => 'Version 1.2.3', 'content' => 'Useful changes.'],
        ]];
        yield 'wrong version' => [array_replace($valid, ['version' => '1.2.4'])];
        yield 'non boolean impact' => [array_replace($valid, ['user_impact' => 1])];
        yield 'no impact with text' => [array_replace($valid, ['user_impact' => false])];
        yield 'missing announcements' => [['version' => '1.2.3', 'user_impact' => false]];
        yield 'missing English' => [array_replace($valid, ['announcements' => []])];
        yield 'extra language' => [array_replace($valid, ['announcements' => $valid['announcements'] + ['fr' => $valid['announcements']['en']]])];
        foreach ([
            'missing version in title' => ['title' => 'Changes', 'content' => 'Good'],
            'version substring' => ['title' => 'Version 11.2.3', 'content' => 'Good'],
            'multiline title' => ['title' => "Version 1.2.3\nChanges", 'content' => 'Good'],
            'emoji title too long' => ['title' => '1.2.3 ' . str_repeat('🌍', 126), 'content' => 'Good'],
            'empty content' => ['title' => '1.2.3', 'content' => ' '],
            'oversized content' => ['title' => '1.2.3', 'content' => str_repeat('a', 20_001)],
            'oversized UTF8' => ['title' => '1.2.3', 'content' => str_repeat('🌍', 17_000)],
            'control character' => ['title' => '1.2.3', 'content' => "Bad\x00text"],
        ] as $label => $text) {
            yield $label => [array_replace($valid, ['announcements' => ['en' => $text]])];
        }
    }

    #[DataProvider('invalidVersions')]
    public function testInvalidVersionCannotBecomeAFilePath(string $version): void
    {
        $this->expectException(RuntimeException::class);
        ReleaseNotesManifest::exists($this->directory, $version);
    }

    public static function invalidVersions(): iterable
    {
        foreach (['../1.2.3', '1.2', 'v1.2.3', '01.2.3', "1.2.3\n", str_repeat('1', 33) . '.2.3'] as $value) {
            yield [$value];
        }
    }

    public function testVersionsAreOrderedSemanticallyAndExcludeFutureReleases(): void
    {
        foreach (['1.10.0', '1.2.3', '2.0.0', 'example'] as $version) {
            file_put_contents($this->directory . '/' . $version . '.json', '{}');
        }
        self::assertSame(['1.2.3', '1.10.0'], ReleaseNotesManifest::versionsUpTo($this->directory, '1.10.0'));
        self::assertFalse(ReleaseNotesManifest::exists($this->directory, '1.9.0'));
    }

    public function testMissingDirectoryIsNotTreatedAsUpToDate(): void
    {
        $this->expectException(RuntimeException::class);
        ReleaseNotesManifest::versionsUpTo($this->directory . '/missing', '1.2.3');
    }

    public function testInvalidJsonIsRejected(): void
    {
        file_put_contents($this->directory . '/1.2.3.json', '{invalid');
        $this->expectException(\JsonException::class);
        ReleaseNotesManifest::load($this->directory, '1.2.3');
    }

    public function testInstalledVersionIsReadFromVersionFile(): void
    {
        file_put_contents($this->directory . '/VERSION', "1.2.3\n");
        self::assertSame('1.2.3', ReleaseNotesManifest::installedVersion($this->directory));
    }

    /** @param array<string, mixed> $manifest */
    private function write(array $manifest): void
    {
        file_put_contents($this->directory . '/1.2.3.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    }
}
