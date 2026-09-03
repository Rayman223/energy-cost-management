<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\ConfigUrl;
use PHPUnit\Framework\TestCase;

final class ConfigUrlTest extends TestCase
{
    public function testReturnsConfiguredUrl(): void
    {
        self::assertSame(
            'https://example.com/soutien',
            ConfigUrl::httpUrl(['donate' => ['url' => 'https://example.com/soutien']], 'donate', 'url'),
        );
    }

    public function testAcceptsPlainHttp(): void
    {
        self::assertSame(
            'http://example.com/x',
            ConfigUrl::httpUrl(['discord' => ['invite_url' => 'http://example.com/x']], 'discord', 'invite_url'),
        );
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        self::assertSame(
            'https://example.com/x',
            ConfigUrl::httpUrl(['donate' => ['url' => "  https://example.com/x\n"]], 'donate', 'url'),
        );
    }

    public function testReturnsNullWhenSectionOrKeyIsMissing(): void
    {
        self::assertNull(ConfigUrl::httpUrl([], 'donate', 'url'));
        self::assertNull(ConfigUrl::httpUrl(['donate' => []], 'donate', 'url'));
        self::assertNull(ConfigUrl::httpUrl(['donate' => ['url' => 'https://example.com/x']], 'donate', 'autre'));
    }

    public function testReturnsNullWhenUrlIsEmpty(): void
    {
        self::assertNull(ConfigUrl::httpUrl(['donate' => ['url' => '']], 'donate', 'url'));
        self::assertNull(ConfigUrl::httpUrl(['donate' => ['url' => '   ']], 'donate', 'url'));
    }

    /**
     * Une URL non http(s) en config ne doit jamais atteindre le `href` rendu.
     */
    public function testReturnsNullForNonHttpScheme(): void
    {
        self::assertNull(ConfigUrl::httpUrl(['donate' => ['url' => 'javascript:alert(1)']], 'donate', 'url'));
        self::assertNull(ConfigUrl::httpUrl(['donate' => ['url' => 'ftp://example.com/x']], 'donate', 'url'));
    }

    public function testReturnsNullForMalformedUrl(): void
    {
        self::assertNull(ConfigUrl::httpUrl(['donate' => ['url' => 'pas une url']], 'donate', 'url'));
    }

    public function testReturnsNullWhenConfigShapeIsUnexpected(): void
    {
        self::assertNull(ConfigUrl::httpUrl(['donate' => 'https://example.com/x'], 'donate', 'url'));
        self::assertNull(ConfigUrl::httpUrl(['donate' => ['url' => 42]], 'donate', 'url'));
    }

    /**
     * Le couple (section, clé) sélectionne bien la bonne entrée : deux liens
     * configurés côte à côte ne doivent pas se confondre.
     */
    public function testReadsTheRequestedSectionOnly(): void
    {
        $config = [
            'discord' => ['invite_url' => 'https://discord.gg/abc123'],
            'donate'  => ['url' => 'https://example.com/soutien'],
        ];

        self::assertSame('https://discord.gg/abc123', ConfigUrl::httpUrl($config, 'discord', 'invite_url'));
        self::assertSame('https://example.com/soutien', ConfigUrl::httpUrl($config, 'donate', 'url'));
    }
}
