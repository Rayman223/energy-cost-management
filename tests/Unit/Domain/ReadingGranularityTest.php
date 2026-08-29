<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\ReadingGranularity;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Créneaux de plafonnement des index (#165) et leur dérivation depuis le mode de
 * tarification de la grille active (#10).
 */
final class ReadingGranularityTest extends TestCase
{
    private const TZ = 'Europe/Brussels';

    /** @return array{string, string} bornes [start, end) formatées en heure locale */
    private function bucket(ReadingGranularity $g, string $moment, string $timezone = self::TZ): array
    {
        [$start, $end] = $g->bucket(new DateTimeImmutable($moment, new DateTimeZone('UTC')), new DateTimeZone($timezone));

        return [$start->format('Y-m-d H:i'), $end->format('Y-m-d H:i')];
    }

    public function testPricingModeDecidesGranularity(): void
    {
        self::assertSame(ReadingGranularity::Day, ReadingGranularity::forPricingMode('fixed'));
        self::assertSame(ReadingGranularity::Hour, ReadingGranularity::forPricingMode('dynamic_hourly'));
        self::assertSame(ReadingGranularity::QuarterHour, ReadingGranularity::forPricingMode('dynamic_quarter'));
    }

    /** Un mode hors liste blanche retombe sur le plafond le plus strict, pas sur le plus permissif. */
    public function testUnknownPricingModeFallsBackToDay(): void
    {
        self::assertSame(ReadingGranularity::Day, ReadingGranularity::forPricingMode('dynamic_minute'));
        self::assertSame(ReadingGranularity::Day, ReadingGranularity::forPricingMode(''));
    }

    public function testHourBucketIsAlignedOnTheFullHour(): void
    {
        // 12:37 UTC = 14:37 à Bruxelles (été) → créneau [14:00, 15:00).
        self::assertSame(['2026-06-25 14:00', '2026-06-25 15:00'], $this->bucket(ReadingGranularity::Hour, '2026-06-25 12:37:41'));
    }

    public function testHourBucketBoundsAreHalfOpen(): void
    {
        // Une heure pile appartient au créneau qu'elle ouvre, pas à celui qu'elle ferme.
        self::assertSame(['2026-06-25 14:00', '2026-06-25 15:00'], $this->bucket(ReadingGranularity::Hour, '2026-06-25 12:00:00'));
        self::assertSame(['2026-06-25 14:00', '2026-06-25 15:00'], $this->bucket(ReadingGranularity::Hour, '2026-06-25 12:59:59'));
        self::assertSame(['2026-06-25 15:00', '2026-06-25 16:00'], $this->bucket(ReadingGranularity::Hour, '2026-06-25 13:00:00'));
    }

    /**
     * Nuit du passage à l'heure d'hiver : 02:00 locale est jouée deux fois. Le créneau
     * est calé sur l'heure de MUR et conserve l'offset du relevé, si bien que la
     * première occurrence (CEST) englobe les deux passages — le plafond y est donc un
     * cran plus strict — tandis que la seconde (CET) retrouve une heure pleine. Aucun
     * instant n'échappe à un créneau : c'est ce qui compte pour un plafond.
     */
    public function testHourBucketSurvivesDstFallBack(): void
    {
        $tz = new DateTimeZone(self::TZ);

        // 00:30 UTC = 02:30 CEST (1re occurrence).
        [$start, $end] = ReadingGranularity::Hour->bucket(new DateTimeImmutable('2026-10-25 00:30:00', new DateTimeZone('UTC')), $tz);
        self::assertSame('2026-10-25 02:00:00 +0200', $start->format('Y-m-d H:i:s O'));
        self::assertSame('2026-10-25 03:00:00 +0100', $end->format('Y-m-d H:i:s O'));

        // 01:30 UTC = 02:30 CET (2e occurrence) : heure pleine réelle.
        [$start, $end] = ReadingGranularity::Hour->bucket(new DateTimeImmutable('2026-10-25 01:30:00', new DateTimeZone('UTC')), $tz);
        self::assertSame('2026-10-25 02:00:00 +0100', $start->format('Y-m-d H:i:s O'));
        self::assertSame(3600, $end->getTimestamp() - $start->getTimestamp());
    }

    /** L'heure sautée au passage à l'heure d'été ne crée pas de créneau vide. */
    public function testHourBucketSurvivesDstSpringForward(): void
    {
        // 00:30 UTC = 01:30 CET, juste avant le saut 02:00 → 03:00 locale.
        [$start, $end] = ReadingGranularity::Hour->bucket(
            new DateTimeImmutable('2026-03-29 00:30:00', new DateTimeZone('UTC')),
            new DateTimeZone(self::TZ),
        );

        self::assertSame('2026-03-29 01:00:00', $start->format('Y-m-d H:i:s'));
        self::assertSame('2026-03-29 03:00:00', $end->format('Y-m-d H:i:s'));
        self::assertSame(3600, $end->getTimestamp() - $start->getTimestamp());
    }

    public function testDayAndQuarterHourBucketsAreUnchanged(): void
    {
        self::assertSame(['2026-06-25 00:00', '2026-06-26 00:00'], $this->bucket(ReadingGranularity::Day, '2026-06-25 12:37:41'));
        self::assertSame(['2026-06-25 14:30', '2026-06-25 14:45'], $this->bucket(ReadingGranularity::QuarterHour, '2026-06-25 12:37:41'));
    }

    public function testLimitLabels(): void
    {
        self::assertSame('un seul index par jour', ReadingGranularity::Day->limitLabelFr());
        self::assertSame('un seul index par heure', ReadingGranularity::Hour->limitLabelFr());
        self::assertSame('un seul index par tranche de 15 minutes', ReadingGranularity::QuarterHour->limitLabelFr());
    }

    /** Le message de rejet ancre le créneau, pas l'instant de la tentative. */
    public function testFormatBucketShowsSlotStart(): void
    {
        $moment = new DateTimeImmutable('2026-06-25 12:37:41', new DateTimeZone('UTC'));
        $tz     = new DateTimeZone(self::TZ);

        self::assertSame('25/06/2026', ReadingGranularity::Day->formatBucketFr($moment, $tz));
        self::assertSame('25/06/2026 14:00', ReadingGranularity::Hour->formatBucketFr($moment, $tz));
        self::assertSame('25/06/2026 14:30', ReadingGranularity::QuarterHour->formatBucketFr($moment, $tz));
    }
}
