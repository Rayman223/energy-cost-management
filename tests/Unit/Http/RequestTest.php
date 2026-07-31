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

    public function testParseDateMissingThrows422Message(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid reading_at date format');
        Request::parseDate(null, 'reading_at');
    }

    public function testParseDateEmptyThrows422Message(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid reading_at date format');
        Request::parseDate('   ', 'reading_at');
    }

    public function testParseDateNonStringThrows422Message(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid reading_at date format');
        Request::parseDate(1719000000, 'reading_at');
    }

    /**
     * Avant la garde, le cast `(string) $value` déclenchait un warning PHP
     * « Array to string conversion » avant même de lever la 422.
     */
    public function testParseDateArrayThrows422WithoutWarning(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid reading_at date format');
        Request::parseDate([], 'reading_at');
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

    /**
     * C3/B7 (#130) : le corps n'est décodé que si Content-Type est
     * `application/json`. Un `text/plain` (formulaire cross-site forgeable) est
     * ignoré → vecteur CSRF fermé.
     */
    public function testFromGlobalsIgnoresNonJsonContentType(): void
    {
        $backup = [$_SERVER, $_GET];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE']   = 'text/plain';
        $_GET = ['action' => 'gas_entry'];

        $r = Request::fromGlobals();

        self::assertSame('POST', $r->method());
        self::assertSame('gas_entry', $r->action());
        self::assertNull($r->input('counter_m3'));

        [$_SERVER, $_GET] = $backup;
    }

    public function testFromGlobalsIgnoresBodyForBodilessMethod(): void
    {
        $backup = [$_SERVER, $_GET];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['CONTENT_TYPE']   = 'application/json';
        $_GET = [];

        $r = Request::fromGlobals();

        self::assertSame('GET', $r->method());
        self::assertNull($r->input('anything'));

        [$_SERVER, $_GET] = $backup;
    }
}
