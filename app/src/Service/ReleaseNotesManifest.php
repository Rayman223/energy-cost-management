<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

/** Versioned release notes, validated without configuration or a database. */
final class ReleaseNotesManifest
{
    public const MISSING_WARNING = 'No manifest for the installed version: nothing to announce for it.';

    public static function assertVersion(string $version): void
    {
        if (strlen($version) > 32 || preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/D', $version) !== 1) {
            throw new RuntimeException('Invalid release version: expected X.Y.Z.');
        }
    }

    public static function installedVersion(string $root): string
    {
        $text = @file_get_contents($root . '/VERSION');
        if ($text === false) {
            throw new RuntimeException('Missing or unreadable VERSION file.');
        }
        $version = trim($text);
        self::assertVersion($version);

        return $version;
    }

    public static function exists(string $directory, string $version): bool
    {
        self::assertVersion($version);
        $path = $directory . '/' . $version . '.json';

        return file_exists($path) || is_link($path);
    }

    /** @return array{version: string, announcements: array<string, array{title: string, content: string}>} */
    public static function load(string $directory, string $version): array
    {
        self::assertVersion($version);
        $path = $directory . '/' . $version . '.json';
        $text = is_file($path) ? @file_get_contents($path) : false;
        if ($text === false) {
            throw new RuntimeException('Missing or unreadable release manifest: ' . $version);
        }
        $data = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['version'] ?? null) !== $version) {
            throw new RuntimeException('Invalid version in manifest: ' . $version);
        }
        $announcements = $data['announcements'] ?? null;
        // Preserve the historical empty baseline; every subsequent release needs notes.
        // Legacy user_impact fields are ignored: technical changes matter too.
        if ($version === '1.0.0' && $announcements === []) {
            return ['version' => $version, 'announcements' => []];
        }
        if (!is_array($announcements) || array_keys($announcements) !== ['en']) {
            throw new RuntimeException('Exactly one English announcement is required: ' . $version);
        }
        $english = $announcements['en'];
        if (!is_array($english) || !is_string($english['title'] ?? null) || !is_string($english['content'] ?? null)) {
            throw new RuntimeException('Invalid English announcement: ' . $version);
        }
        $title = trim($english['title']);
        $content = trim(str_replace(["\r\n", "\r"], "\n", $english['content']));
        if ($title === '' || $content === '' || preg_match('/(?<![0-9.])' . preg_quote($version, '/') . '(?![0-9.])/', $title) !== 1
            || preg_match('/[\r\n]/', $title) || self::length($title) > 256
            || mb_strlen($content, 'UTF-8') > 20_000 || strlen($content) > 65_535
            || !mb_check_encoding($title . $content, 'UTF-8')
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $title . $content)) {
            throw new RuntimeException('Empty, oversized or invalid English announcement: ' . $version);
        }

        return ['version' => $version, 'announcements' => ['en' => ['title' => $title, 'content' => $content]]];
    }

    /** @return list<string> */
    public static function versionsUpTo(string $directory, string $installed): array
    {
        self::assertVersion($installed);
        if (!is_dir($directory)) {
            throw new RuntimeException('Missing releases directory.');
        }
        $versions = [];
        foreach (glob($directory . '/*.json') ?: [] as $path) {
            $version = basename($path, '.json');
            // Unrelated JSON files are not release manifests.
            if (preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/D', $version) !== 1) {
                continue;
            }
            self::assertVersion($version);
            if (version_compare($version, $installed, '<=')) {
                $versions[] = $version;
            }
        }
        usort($versions, 'version_compare');

        return $versions;
    }

    /** Discord measures text in UTF-16 code units, including emoji. */
    public static function length(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }
}
