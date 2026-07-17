<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\DiscordLink;
use PHPUnit\Framework\TestCase;

final class DiscordLinkTest extends TestCase
{
    public function testReturnsConfiguredInviteUrl(): void
    {
        self::assertSame(
            'https://discord.gg/abc123',
            DiscordLink::inviteUrl(['discord' => ['invite_url' => 'https://discord.gg/abc123']]),
        );
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        self::assertSame(
            'https://discord.gg/abc123',
            DiscordLink::inviteUrl(['discord' => ['invite_url' => "  https://discord.gg/abc123\n"]]),
        );
    }

    public function testReturnsNullWhenSectionOrKeyIsMissing(): void
    {
        self::assertNull(DiscordLink::inviteUrl([]));
        self::assertNull(DiscordLink::inviteUrl(['discord' => []]));
    }

    public function testReturnsNullWhenUrlIsEmpty(): void
    {
        self::assertNull(DiscordLink::inviteUrl(['discord' => ['invite_url' => '']]));
        self::assertNull(DiscordLink::inviteUrl(['discord' => ['invite_url' => '   ']]));
    }

    /**
     * Une URL non http(s) en config ne doit jamais atteindre le `href` rendu.
     */
    public function testReturnsNullForNonHttpScheme(): void
    {
        self::assertNull(DiscordLink::inviteUrl(['discord' => ['invite_url' => 'javascript:alert(1)']]));
        self::assertNull(DiscordLink::inviteUrl(['discord' => ['invite_url' => 'ftp://example.com/x']]));
    }

    public function testReturnsNullForMalformedUrl(): void
    {
        self::assertNull(DiscordLink::inviteUrl(['discord' => ['invite_url' => 'pas une url']]));
    }

    public function testReturnsNullWhenConfigShapeIsUnexpected(): void
    {
        self::assertNull(DiscordLink::inviteUrl(['discord' => 'https://discord.gg/abc123']));
        self::assertNull(DiscordLink::inviteUrl(['discord' => ['invite_url' => 42]]));
    }
}
