<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Domain\ReadingGranularity;
use App\Domain\TariffGrid;
use App\Service\ReadingGranularityPolicy;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Tests\Fake\FakeTariffRepository;

/**
 * Résolution du créneau de plafonnement à partir de la grille active à la date du
 * relevé (#10). Le mode étant versionné par valid_from/valid_to (#245), la
 * granularité doit suivre l'historique et non l'état du jour.
 */
final class ReadingGranularityPolicyTest extends TestCase
{
    private const TZ = 'Europe/Brussels';

    private function grid(string $mode, string $validFrom, ?string $validTo = null): TariffGrid
    {
        return new TariffGrid(
            id: 1,
            energyType: 'electricity',
            name: 'Grille ' . $mode,
            validFrom: new DateTimeImmutable($validFrom),
            validTo: $validTo === null ? null : new DateTimeImmutable($validTo),
            lines: [],
            pricingMode: $mode,
        );
    }

    private function at(string $utc): DateTimeImmutable
    {
        return new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    }

    public function testConstantPolicyIgnoresTariffs(): void
    {
        $policy = ReadingGranularityPolicy::constant(ReadingGranularity::Day, self::TZ);

        self::assertSame(ReadingGranularity::Day, $policy->forMoment($this->at('2026-06-25 07:00:00')));
        self::assertSame(ReadingGranularity::Day, $policy->forMoment($this->at('2020-01-01 07:00:00')));
        self::assertSame(self::TZ, $policy->timezone());
    }

    public function testEachPricingModeMapsToItsGranularity(): void
    {
        foreach ([
            'fixed'           => ReadingGranularity::Day,
            'dynamic_hourly'  => ReadingGranularity::Hour,
            'dynamic_quarter' => ReadingGranularity::QuarterHour,
        ] as $mode => $expected) {
            $policy = ReadingGranularityPolicy::fromTariffs(new FakeTariffRepository($this->grid($mode, '2026-01-01')), self::TZ);

            self::assertSame($expected, $policy->forMoment($this->at('2026-06-25 07:00:00')), 'mode ' . $mode);
        }
    }

    /** Sans grille (utilisateur sans tarif, relevé antérieur à sa 1re grille) : plafond strict. */
    public function testNoActiveGridFallsBackToDay(): void
    {
        $tariffs = new FakeTariffRepository();
        $tariffs->activeGrids = [$this->grid('dynamic_quarter', '2026-06-01')];

        $policy = ReadingGranularityPolicy::fromTariffs($tariffs, self::TZ);

        self::assertSame(ReadingGranularity::Day, $policy->forMoment($this->at('2026-05-31 07:00:00')));
    }

    /**
     * Cœur de l'issue : un import d'historique traversant une bascule doit appliquer
     * la granularité de CHAQUE période, pas celle de la grille courante.
     */
    public function testGranularityFollowsTheGridActiveAtEachMoment(): void
    {
        $tariffs = new FakeTariffRepository();
        $tariffs->activeGrids = [
            $this->grid('dynamic_quarter', '2026-06-01'),
            $this->grid('fixed', '2024-01-01', '2026-06-01'),
        ];

        $policy = ReadingGranularityPolicy::fromTariffs($tariffs, self::TZ);

        self::assertSame(ReadingGranularity::Day, $policy->forMoment($this->at('2025-03-10 09:00:00')));
        // valid_to = premier jour NON couvert (#1) : le 31/05 est encore fixe.
        self::assertSame(ReadingGranularity::Day, $policy->forMoment($this->at('2026-05-31 09:00:00')));
        self::assertSame(ReadingGranularity::QuarterHour, $policy->forMoment($this->at('2026-06-01 09:00:00')));
    }

    /**
     * Un relevé du 31/12 à 23:30 heure locale (22:30 UTC) appartient au 31/12 de
     * l'utilisateur, donc à la grille de l'année écoulée — pas à celle qui prend effet
     * le 1er janvier.
     */
    public function testDayIsResolvedInTheUserTimezone(): void
    {
        $tariffs = new FakeTariffRepository();
        $tariffs->activeGrids = [
            $this->grid('dynamic_quarter', '2027-01-01'),
            $this->grid('fixed', '2026-01-01', '2027-01-01'),
        ];

        $policy = ReadingGranularityPolicy::fromTariffs($tariffs, self::TZ);

        // 22:30 UTC = 23:30 locale le 31/12 → encore la grille fixe.
        self::assertSame(ReadingGranularity::Day, $policy->forMoment($this->at('2026-12-31 22:30:00')));
        // 23:30 UTC = 00:30 locale le 01/01 → la nouvelle grille.
        self::assertSame(ReadingGranularity::QuarterHour, $policy->forMoment($this->at('2026-12-31 23:30:00')));
    }

    /**
     * forMoment() est appelée une fois par ligne d'import (jusqu'à 200 000) : sans
     * mémoïsation par jour, chaque ligne déclencherait une requête findActiveGrid().
     */
    public function testResolutionIsMemoizedPerLocalDay(): void
    {
        $tariffs = new FakeTariffRepository($this->grid('dynamic_hourly', '2026-01-01'));
        $policy  = ReadingGranularityPolicy::fromTariffs($tariffs, self::TZ);

        foreach (['07:00:00', '07:15:00', '12:00:00', '18:45:00'] as $time) {
            $policy->forMoment($this->at('2026-06-25 ' . $time));
        }
        self::assertSame(1, $tariffs->findActiveGridCalls);

        $policy->forMoment($this->at('2026-06-26 07:00:00'));
        self::assertSame(2, $tariffs->findActiveGridCalls);
    }
}
