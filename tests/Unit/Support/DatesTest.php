<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Dates;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Convention de stockage UTC : {@see Dates} doit normaliser en UTC quel que soit
 * le fuseau porté par l'objet fourni ou le fuseau PHP par défaut.
 */
final class DatesTest extends TestCase
{
    private string $previousTz;

    protected function setUp(): void
    {
        // Fuseau PHP volontairement non-UTC pour prouver l'indépendance.
        $this->previousTz = date_default_timezone_get();
        date_default_timezone_set('Europe/Brussels');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previousTz);
    }

    public function testToDbStringNormalisesOffsetToUtc(): void
    {
        // 00:30 en +02:00 == 22:30 UTC la veille.
        $moment = new DateTimeImmutable('2026-06-25 00:30:00', new DateTimeZone('+02:00'));

        self::assertSame('2026-06-24 22:30:00', Dates::toDbString($moment));
    }

    public function testToDbStringKeepsUtcInstant(): void
    {
        $moment = new DateTimeImmutable('2026-01-15 08:00:00', new DateTimeZone('UTC'));

        self::assertSame('2026-01-15 08:00:00', Dates::toDbString($moment));
    }

    public function testFromDbStringInterpretsAsUtc(): void
    {
        $dt = Dates::fromDbString('2026-06-24 22:30:00');

        self::assertSame('UTC', $dt->getTimezone()->getName());
        self::assertSame('2026-06-24 22:30:00 +00:00', $dt->format('Y-m-d H:i:s P'));
    }

    public function testRoundTripIsStable(): void
    {
        $original = '2026-03-29 01:30:00';

        self::assertSame($original, Dates::toDbString(Dates::fromDbString($original)));
    }
}
