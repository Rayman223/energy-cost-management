<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\DonateLink;
use PHPUnit\Framework\TestCase;

final class DonateLinkTest extends TestCase
{
    public function testReturnsConfiguredUrl(): void
    {
        self::assertSame(
            'https://buymeacoffee.com/rayman223',
            DonateLink::url(['donate' => ['url' => 'https://buymeacoffee.com/rayman223']]),
        );
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        self::assertSame(
            'https://ko-fi.com/rayman223',
            DonateLink::url(['donate' => ['url' => "  https://ko-fi.com/rayman223\n"]]),
        );
    }

    public function testReturnsNullWhenSectionOrKeyIsMissing(): void
    {
        self::assertNull(DonateLink::url([]));
        self::assertNull(DonateLink::url(['donate' => []]));
    }

    public function testReturnsNullWhenUrlIsEmpty(): void
    {
        self::assertNull(DonateLink::url(['donate' => ['url' => '']]));
        self::assertNull(DonateLink::url(['donate' => ['url' => '   ']]));
    }

    /**
     * Une URL non http(s) en config ne doit jamais atteindre le `href` rendu.
     */
    public function testReturnsNullForNonHttpScheme(): void
    {
        self::assertNull(DonateLink::url(['donate' => ['url' => 'javascript:alert(1)']]));
        self::assertNull(DonateLink::url(['donate' => ['url' => 'ftp://example.com/x']]));
    }

    public function testReturnsNullForMalformedUrl(): void
    {
        self::assertNull(DonateLink::url(['donate' => ['url' => 'pas une url']]));
    }

    public function testReturnsNullWhenConfigShapeIsUnexpected(): void
    {
        self::assertNull(DonateLink::url(['donate' => 'https://ko-fi.com/rayman223']));
        self::assertNull(DonateLink::url(['donate' => ['url' => 42]]));
    }
}
