<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Service\LoadProfileFreshness;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Mois de profil exigés et manquants (#101).
 */
final class LoadProfileFreshnessTest extends TestCase
{
    private function at(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date, new DateTimeZone('UTC'));
    }

    /** @return array{code: string, country: string, valid_from: string, valid_to: ?string} */
    private function grid(string $from, ?string $to = null, string $code = 'RLP0N', string $country = 'BE'): array
    {
        return ['code' => $code, 'country' => $country, 'valid_from' => $from, 'valid_to' => $to];
    }

    public function testLatestRequiredMonthWaitsForTheGracePeriod(): void
    {
        $freshness = new LoadProfileFreshness(graceDays: 5);

        // Le 7 octobre, septembre est clos depuis plus de 5 jours : il est exigé.
        self::assertSame('2026-09', $freshness->latestRequiredMonth($this->at('2026-10-07 12:00')));
        // Le 3 octobre, on attend encore la publication de septembre.
        self::assertSame('2026-08', $freshness->latestRequiredMonth($this->at('2026-10-03 12:00')));
        // Sans délai, le mois est exigé dès le 1er.
        self::assertSame('2026-09', (new LoadProfileFreshness(graceDays: 0))->latestRequiredMonth($this->at('2026-10-01 00:00')));
    }

    public function testRequiredMonthsFollowTheGridValidity(): void
    {
        $required = (new LoadProfileFreshness(graceDays: 5))->requiredMonths(
            [$this->grid('2026-06-15')],
            $this->at('2026-10-07'),
        );

        self::assertSame(
            ['RLP0N/BE' => ['code' => 'RLP0N', 'country' => 'BE', 'months' => ['2026-06', '2026-07', '2026-08', '2026-09']]],
            $required,
        );
    }

    /** Fin EXCLUE (#1) : une grille close au 1er août ne couvre pas août. */
    public function testValidToIsExclusive(): void
    {
        $required = (new LoadProfileFreshness())->requiredMonths(
            [$this->grid('2026-06-01', '2026-08-01')],
            $this->at('2026-10-07'),
        );

        self::assertSame(['2026-06', '2026-07'], $required['RLP0N/BE']['months']);
    }

    public function testLookbackBoundsOldGaps(): void
    {
        $required = (new LoadProfileFreshness(graceDays: 5, lookbackMonths: 3))->requiredMonths(
            [$this->grid('2024-01-01')],
            $this->at('2026-10-07'),
        );

        self::assertSame(['2026-07', '2026-08', '2026-09'], $required['RLP0N/BE']['months']);
    }

    /** Deux grilles successives sur le même profil : chaque mois n'est exigé qu'une fois. */
    public function testOverlappingGridsAreMerged(): void
    {
        $required = (new LoadProfileFreshness())->requiredMonths(
            [$this->grid('2026-07-01', '2026-08-16'), $this->grid('2026-08-16'), $this->grid('2026-09-01', null, 'RLP0N', 'NL')],
            $this->at('2026-10-07'),
        );

        self::assertSame(['2026-07', '2026-08', '2026-09'], $required['RLP0N/BE']['months']);
        self::assertSame(['2026-09'], $required['RLP0N/NL']['months']);
    }

    /** Un contrat qui démarre ce mois-ci n'exige encore rien. */
    public function testGridStartingAfterTheLatestRequiredMonthRequiresNothing(): void
    {
        $required = (new LoadProfileFreshness())->requiredMonths([$this->grid('2026-10-01')], $this->at('2026-10-07'));

        self::assertSame([], $required['RLP0N/BE']['months']);
    }

    public function testCoverageTakesTheBestResolution(): void
    {
        // Septembre : 30 jours = 2 880 quarts d'heure = 720 heures.
        self::assertSame(100.0, LoadProfileFreshness::coveragePct('2026-09', [15 => 2880]));
        self::assertSame(80.0, LoadProfileFreshness::coveragePct('2026-09', [15 => 2304]));
        self::assertSame(100.0, LoadProfileFreshness::coveragePct('2026-09', [15 => 10, 60 => 720]));
        self::assertSame(0.0, LoadProfileFreshness::coveragePct('2026-09', []));
    }

    public function testMissingListsAbsentAndTruncatedMonths(): void
    {
        $calls     = [];
        $pointsFor = static function (string $code, string $country, DateTimeImmutable $from, DateTimeImmutable $to) use (&$calls): array {
            $calls[] = [$code, $country, $from->format('Y-m-d'), $to->format('Y-m-d')];

            return [
                '2026-07' => [15 => 2976],      // complet
                '2026-08' => [15 => 1000],      // tronqué (~34 %)
                // 2026-09 absent
            ];
        };

        $freshness = new LoadProfileFreshness(graceDays: 5);
        $report    = $freshness->report([$this->grid('2026-07-01')], $pointsFor, $this->at('2026-10-07'));
        $missing   = LoadProfileFreshness::missing($report);

        // Une seule requête, sur la fenêtre englobant les mois exigés.
        self::assertSame([['RLP0N', 'BE', '2026-07-01', '2026-10-01']], $calls);
        self::assertSame(100.0, $report[0]['months']['2026-07']);
        self::assertSame(['2026-08', '2026-09'], array_column($missing, 'month'));
        self::assertSame(0.0, $missing[1]['coverage_pct']);
    }

    /**
     * Un mois importé à 60 min au milieu d'une série quart-horaire : le calcul lit le
     * pas de 15 min dès qu'il en existe et ignore ce mois — il manque donc.
     */
    public function testHourlyMonthAmidQuarterHourSeriesIsMissing(): void
    {
        $pointsFor = static fn (): array => [
            '2026-08' => [15 => 2976],
            '2026-09' => [60 => 720],
        ];

        $report = (new LoadProfileFreshness(graceDays: 5))->report([$this->grid('2026-08-01')], $pointsFor, $this->at('2026-10-07'));

        self::assertSame(['2026-09'], array_column(LoadProfileFreshness::missing($report), 'month'));
    }

    /** Une série entièrement horaire est lue telle quelle. */
    public function testHourlyOnlySeriesIsComplete(): void
    {
        $pointsFor = static fn (): array => ['2026-09' => [60 => 720]];

        $report = (new LoadProfileFreshness(graceDays: 5))->report([$this->grid('2026-09-01')], $pointsFor, $this->at('2026-10-07'));

        self::assertSame([], LoadProfileFreshness::missing($report));
    }

    public function testNoQueryWhenNothingIsRequired(): void
    {
        $called    = false;
        $pointsFor = static function () use (&$called): array {
            $called = true;

            return [];
        };

        $report = (new LoadProfileFreshness())->report([$this->grid('2026-10-01')], $pointsFor, $this->at('2026-10-07'));

        self::assertFalse($called);
        self::assertSame([], LoadProfileFreshness::missing($report));
    }
}
