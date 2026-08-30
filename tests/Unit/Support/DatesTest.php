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

    /**
     * Jour civil de l'utilisateur (#252) : rendu à minuit UTC, pour se comparer
     * aux bornes DATE relues en UTC. Le fuseau demandé décide de QUEL jour, pas du
     * fuseau du résultat.
     */
    public function testTodayInReturnsUtcMidnightOfTheUsersCivilDay(): void
    {
        $today = Dates::todayIn('Pacific/Kiritimati');

        self::assertSame('UTC', $today->getTimezone()->getName());
        self::assertSame('00:00:00', $today->format('H:i:s'));
        self::assertSame(
            (new DateTimeImmutable('now', new DateTimeZone('Pacific/Kiritimati')))->format('Y-m-d'),
            $today->format('Y-m-d'),
        );
    }

    /**
     * Deux fuseaux aux antipodes ne sont pas le même jour en permanence : le helper
     * doit les distinguer, sinon il ne servirait à rien face à `new DateTimeImmutable('today')`.
     */
    public function testTodayInDependsOnTheRequestedZone(): void
    {
        // UTC+14 et UTC-11 : 25 h d'écart, jamais le même jour civil.
        self::assertNotSame(
            Dates::todayIn('Pacific/Kiritimati')->format('Y-m-d'),
            Dates::todayIn('Pacific/Niue')->format('Y-m-d'),
        );
    }

    /** Fuseau corrompu en base : repli UTC plutôt qu'une page en erreur. */
    public function testTodayInFallsBackToUtcOnUnknownZone(): void
    {
        self::assertSame(
            (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d'),
            Dates::todayIn('Mars/Olympus_Mons')->format('Y-m-d'),
        );
    }

    /**
     * Contraste avec {@see testTodayInReturnsUtcMidnightOfTheUsersCivilDay()} :
     * `todayIn()` ramène un jour civil à minuit UTC, `startOfDayIn()` l'ANCRE dans
     * son fuseau. Un contrat belge qui bascule le 1er avril s'ouvre donc le 31 mars
     * à 22:00 UTC — c'est ce décalage qui rattache les bons kWh à la bonne grille
     * (#16). Le décalage suit l'heure d'été : deux heures en avril, une en janvier.
     */
    public function testStartOfDayInAnchorsTheCivilDayInItsZone(): void
    {
        self::assertSame('2026-03-31 22:00:00', Dates::toDbString(Dates::startOfDayIn('2026-04-01', 'Europe/Brussels')));
        self::assertSame('2026-01-14 23:00:00', Dates::toDbString(Dates::startOfDayIn('2026-01-15', 'Europe/Brussels')));
    }

    /** Contrat en UTC : la frontière tombe pile à minuit, sans décalage. */
    public function testStartOfDayInIsIdentityForUtc(): void
    {
        $start = Dates::startOfDayIn('2026-06-25', 'UTC');

        self::assertSame('UTC', $start->getTimezone()->getName());
        self::assertSame('2026-06-25 00:00:00', Dates::toDbString($start));
    }

    /**
     * Nuit du passage à l'heure d'été : la bascule a lieu à 02:00, donc minuit local
     * existe bel et bien et reste en CET (UTC+1) — le jour raccourci ne déplace pas
     * son propre début.
     */
    public function testStartOfDayInHandlesTheSpringForwardDay(): void
    {
        self::assertSame('2026-03-28 23:00:00', Dates::toDbString(Dates::startOfDayIn('2026-03-29', 'Europe/Brussels')));
    }

    /** Même repli que todayIn() : un fuseau illisible ne doit pas casser un calcul de coût. */
    public function testStartOfDayInFallsBackToUtcOnUnknownZone(): void
    {
        self::assertSame('2026-04-01 00:00:00', Dates::toDbString(Dates::startOfDayIn('2026-04-01', 'Mars/Olympus_Mons')));
    }

    /** L'instant courant est rendu en UTC, jamais dans le fuseau PHP par défaut. */
    public function testNowUtcIgnoresThePhpDefaultTimezone(): void
    {
        self::assertSame('UTC', Dates::nowUtc()->getTimezone()->getName());
    }

    /**
     * Bascule de mois vue depuis un fuseau en avance (#21) : le 1er juin 00:30 à
     * Kiritimati (UTC+14), c'est encore le 31 mai 10:30 UTC. Le mois retourné doit
     * être MAI — celui dans lequel sont bornées les fenêtres du dashboard — et non
     * juin, sans quoi la fenêtre demandée serait encore à venir.
     */
    public function testYearMonthOfReadsTheMonthInUtcNotInTheCarriedZone(): void
    {
        $moment = new DateTimeImmutable('2026-06-01 00:30:00', new DateTimeZone('Pacific/Kiritimati'));

        self::assertSame([2026, 5], Dates::yearMonthOf($moment));
    }

    /** Symétrique : un fuseau en retard sur UTC bascule, lui, un mois plus tôt. */
    public function testYearMonthOfFollowsUtcForZonesBehindIt(): void
    {
        // 31 mai 23:00 à Niue (UTC-11) == 1er juin 10:00 UTC.
        $moment = new DateTimeImmutable('2026-05-31 23:00:00', new DateTimeZone('Pacific/Niue'));

        self::assertSame([2026, 6], Dates::yearMonthOf($moment));
    }

    /** Passage d'année : décembre → janvier suit la même règle. */
    public function testYearMonthOfHandlesTheYearBoundary(): void
    {
        $moment = new DateTimeImmutable('2027-01-01 00:30:00', new DateTimeZone('Pacific/Kiritimati'));

        self::assertSame([2026, 12], Dates::yearMonthOf($moment));
    }

    /**
     * Le mois courant se lit en UTC alors même que le fuseau PHP est Europe/Brussels
     * ({@see setUp()}) : c'est l'invariant que `date('Y')`/`date('n')` ne tenaient pas.
     */
    public function testCurrentYearMonthIsReadInUtc(): void
    {
        self::assertSame(
            [(int) gmdate('Y'), (int) gmdate('n')],
            Dates::currentYearMonth(),
        );
    }
}
