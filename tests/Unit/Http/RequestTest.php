<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Request;
use App\Http\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
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
     * #264 : `new DateTimeImmutable('2026-02-31')` ne lève pas — il renvoie
     * 2026-03-03 en ne signalant qu'un warning. Enregistré tel quel, le décalage
     * était indétectable a posteriori.
     */
    public function testParseDateImpossibleCalendarDateThrows422Message(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid reading_at date format');
        Request::parseDate('2026-02-31', 'reading_at');
    }

    public function testParseDateFebruary29OnNonLeapYearThrows422Message(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid reading_at date format');
        Request::parseDate('2026-02-29', 'reading_at');
    }

    /**
     * #264 : une entrée qui ne porte aucune date se résout sur l'horloge courante
     * — « 2026 » est lu comme l'heure 20:26 d'aujourd'hui, « 0731 » comme 07:31.
     * Même classe de bug que la chaîne vide traitée en #246, mais sans warning du
     * parseur : seule la présence effective d'année/mois/jour la détecte.
     *
     * @return iterable<string, array{string}>
     */
    public static function clocklessValueProvider(): iterable
    {
        yield 'année seule lue comme une heure' => ['2026'];
        yield 'quatre chiffres lus comme une heure' => ['0731'];
        yield 'heure sans date' => ['12:00'];
        yield 'mot-clé relatif' => ['now'];
        yield 'mot-clé relatif futur' => ['tomorrow'];
        yield 'intervalle relatif' => ['+1 day'];
    }

    /**
     * `now` était accepté et valait l'instant de la requête. La voie documentée
     * pour horodater à l'instant courant reste l'absence du champ
     * (app/docs/api-contract.md), comme le fait déjà MeterEntryController ; et
     * ReadingParser rejette de son côté la même valeur à l'ingestion.
     */
    #[DataProvider('clocklessValueProvider')]
    public function testParseDateRejectsValueWithoutCalendarDate(string $value): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid reading_at date format');
        Request::parseDate($value, 'reading_at');
    }

    /**
     * Non-régression des gardes #264 : les formats réellement en circulation
     * restent acceptés, y compris fuseau nommé et timestamp Unix.
     */
    public function testParseDateAcceptsRealWorldFormats(): void
    {
        self::assertSame('2026-07-31', Request::parseDate('2026-07-31', 'reading_at')->format('Y-m-d'));
        self::assertSame(
            '2026-07-31 12:00:00',
            Request::parseDate('2026-07-31 12:00:00', 'reading_at')->format('Y-m-d H:i:s'),
        );

        $withOffset = Request::parseDate('2026-07-31T12:00:00+02:00', 'reading_at');
        self::assertSame('2026-07-31T12:00:00+02:00', $withOffset->format('Y-m-d\TH:i:sP'));

        $utc = Request::parseDate('2026-07-31T12:00:00Z', 'reading_at');
        self::assertSame('2026-07-31T12:00:00+00:00', $utc->format('Y-m-d\TH:i:sP'));

        $micro = Request::parseDate('2026-07-31 12:00:00.123456', 'reading_at');
        self::assertSame('2026-07-31 12:00:00', $micro->format('Y-m-d H:i:s'));

        $named = Request::parseDate('2026-07-31 12:00:00 Europe/Paris', 'reading_at');
        self::assertSame('2026-07-31T12:00:00+02:00', $named->format('Y-m-d\TH:i:sP'));

        self::assertSame('1719000000', Request::parseDate('@1719000000', 'reading_at')->format('U'));
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
