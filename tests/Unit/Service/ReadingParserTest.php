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

    public function testParseTimestampWithZoneAcceptsOffsetBearingFormats(): void
    {
        // Offset explicite (± ou Z), séparateur T ou espace, secondes optionnelles.
        self::assertNotNull(ReadingParser::parseTimestampWithZone('2026-01-01T08:00:00+02:00'));
        self::assertNotNull(ReadingParser::parseTimestampWithZone('2026-01-01 08:00:00+02:00'));
        self::assertNotNull(ReadingParser::parseTimestampWithZone('2026-01-01T08:00:00Z'));
        self::assertNotNull(ReadingParser::parseTimestampWithZone('2026-01-01T08:00Z'));
        self::assertNotNull(ReadingParser::parseTimestampWithZone('2026-01-01T08:00:00+0200'));
        // Secondes fractionnaires (RFC 3339, courant en télémétrie machine).
        self::assertNotNull(ReadingParser::parseTimestampWithZone('2026-01-01T08:00:00.123Z'));
        self::assertNotNull(ReadingParser::parseTimestampWithZone('2026-01-01T08:00:00.5+02:00'));

        // L'espace de bordure est toléré (trim).
        self::assertNotNull(ReadingParser::parseTimestampWithZone('  2026-01-01T08:00:00Z  '));
    }

    public function testParseTimestampWithZoneRejectsOutOfRangeCalendarDates(): void
    {
        // Dates calendairement invalides : la regex passe mais DateTimeImmutable les
        // reporterait en silence (29/02 non bissextile → 01/03…). date_parse les
        // rejette → aucun décalage silencieux stocké.
        self::assertNull(ReadingParser::parseTimestampWithZone('2026-02-29T10:00:00Z')); // 2026 non bissextile
        self::assertNull(ReadingParser::parseTimestampWithZone('2026-02-30T10:00:00Z'));
        self::assertNull(ReadingParser::parseTimestampWithZone('2026-04-31T10:00:00+02:00'));
        self::assertNull(ReadingParser::parseTimestampWithZone('0000-00-00T00:00:00Z'));

        // Le 29 février d'une vraie année bissextile reste accepté.
        self::assertNotNull(ReadingParser::parseTimestampWithZone('2024-02-29T10:00:00Z'));
    }

    public function testParseTimestampWithZoneNormalisesToTheAbsoluteInstant(): void
    {
        // +02:00 → l'instant UTC recule de 2 h (racine du bug #175).
        $dt = ReadingParser::parseTimestampWithZone('2026-07-15T00:00:00+02:00');
        self::assertNotNull($dt);
        self::assertSame('2026-07-14 22:00:00', $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
    }

    public function testParseTimestampWithZoneRejectsNaiveAndLenientValues(): void
    {
        // Horodatage nu (sans fuseau) → ambigu, refusé (#175).
        self::assertNull(ReadingParser::parseTimestampWithZone('2026-01-01 08:00:00'));
        self::assertNull(ReadingParser::parseTimestampWithZone('2026-01-01T08:00:00'));
        self::assertNull(ReadingParser::parseTimestampWithZone('2026-01-01 08:00'));
        // Date seule : aucun fuseau possible → refusée.
        self::assertNull(ReadingParser::parseTimestampWithZone('2026-01-02'));
        // Valeurs souples de DateTimeImmutable → refusées.
        self::assertNull(ReadingParser::parseTimestampWithZone('now'));
        self::assertNull(ReadingParser::parseTimestampWithZone('2026'));
        self::assertNull(ReadingParser::parseTimestampWithZone('+1 day'));
        self::assertNull(ReadingParser::parseTimestampWithZone(''));
    }
}
