<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Request;
use App\Http\ValidationException;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function testAccessors(): void
    {
        $r = new Request('GET', ['action' => 'month_cost', 'year' => '2026', 'days' => '60'], ['counter_m3' => 12.5]);

        self::assertSame('GET', $r->method());
        self::assertSame('month_cost', $r->action());
        self::assertSame(2026, $r->queryInt('year', 1900));
        self::assertSame(30, $r->queryInt('absent', 30));
        self::assertSame(12.5, $r->input('counter_m3'));
        self::assertSame('fallback', $r->input('absent', 'fallback'));
    }

    public function testActionDefaultsToEmptyString(): void
    {
        self::assertSame('', (new Request('GET', [], []))->action());
    }

    public function testParseDateValid(): void
    {
        $d = Request::parseDate('2026-06-25', 'reading_at');
        self::assertSame('2026-06-25', $d->format('Y-m-d'));
    }

    public function testParseDateInvalidThrows422Message(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid reading_at date format');
        Request::parseDate('not-a-date', 'reading_at');
    }

    public function testOptionalDateNullWhenAbsentOrEmpty(): void
    {
        self::assertNull(Request::optionalDate(null, 'valid_to'));
        self::assertNull(Request::optionalDate('', 'valid_to'));
        self::assertNull(Request::optionalDate('   ', 'valid_to'));
    }

    public function testOptionalDateParsesWhenPresent(): void
    {
        $d = Request::optionalDate('2026-12-31', 'valid_to');
        self::assertNotNull($d);
        self::assertSame('2026-12-31', $d->format('Y-m-d'));
    }
}
