<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Service\Import\ReadingParser;
use PHPUnit\Framework\TestCase;

final class ReadingParserTest extends TestCase
{
    public function testParseValueAcceptsNonNegativeFloats(): void
    {
        self::assertSame(0.0, ReadingParser::parseValue('0'));
        self::assertSame(42.5, ReadingParser::parseValue('42.5'));
        self::assertSame(100.0, ReadingParser::parseValue(100));
    }

    public function testParseValueRejectsNegativeAndNonNumeric(): void
    {
        self::assertNull(ReadingParser::parseValue('-3'));
        self::assertNull(ReadingParser::parseValue('abc'));
        self::assertNull(ReadingParser::parseValue(''));
        self::assertNull(ReadingParser::parseValue(null));
    }

    public function testParseTimestampStrictAcceptsSupportedFormats(): void
    {
        self::assertNotNull(ReadingParser::parseTimestampStrict('2026-01-01 08:00:00'));
        self::assertNotNull(ReadingParser::parseTimestampStrict('2026-01-01T08:00:00'));
        self::assertNotNull(ReadingParser::parseTimestampStrict('2026-01-01 08:00'));

        // Date seule → minuit.
        $midnight = ReadingParser::parseTimestampStrict('2026-01-02');
        self::assertNotNull($midnight);
        self::assertSame('2026-01-02 00:00:00', $midnight->format('Y-m-d H:i:s'));
    }

    public function testParseTimestampStrictRejectsLenientRelativeValues(): void
    {
        // « now »/« 2026 »/« +1 day » seraient datés à l'instant de l'import et
        // casseraient l'idempotence (racine du bug #1) → doivent être rejetés.
        self::assertNull(ReadingParser::parseTimestampStrict('now'));
        self::assertNull(ReadingParser::parseTimestampStrict('2026'));
        self::assertNull(ReadingParser::parseTimestampStrict('+1 day'));
        self::assertNull(ReadingParser::parseTimestampStrict(''));
    }
}
