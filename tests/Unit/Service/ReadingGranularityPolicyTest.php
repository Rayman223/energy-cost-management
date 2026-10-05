<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Domain\ReadingGranularity;
use App\Service\ReadingGranularityPolicy;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Créneau de plafonnement des index.
 *
 * Depuis #93 la granularité ne dépend plus du mode de tarification : le pas de
 * relevé appartient au compteur, pas au contrat. Les cas qui vérifiaient la
 * résolution par grille active (#10) ont donc disparu avec la fabrique
 * `fromTariffs()` ; ce qui est verrouillé ici, c'est qu'AUCUN état tarifaire
 * n'influence plus le plafond élec.
 */
final class ReadingGranularityPolicyTest extends TestCase
{
    private const TZ = 'Europe/Brussels';

    private function at(string $utc): DateTimeImmutable
    {
        return new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    }

    public function testConstantPolicyAppliesToEveryMoment(): void
    {
        $policy = ReadingGranularityPolicy::constant(ReadingGranularity::Day, self::TZ);

        self::assertSame(ReadingGranularity::Day, $policy->forMoment($this->at('2026-06-25 07:00:00')));
        self::assertSame(ReadingGranularity::Day, $policy->forMoment($this->at('2020-01-01 07:00:00')));
        self::assertSame(self::TZ, $policy->timezone());
    }

    /**
     * Cœur de #93 : le plafond élec vaut le quart d'heure en permanence, y compris
     * aux dates où l'utilisateur était en tarif fixe ou indexé au mois.
     */
    public function testElectricityDefaultIsQuarterHourWhateverTheGrid(): void
    {
        $policy = ReadingGranularityPolicy::electricityDefault(self::TZ);

        foreach (['2020-01-01 07:00:00', '2026-05-31 09:00:00', '2026-06-25 07:15:00'] as $moment) {
            self::assertSame(
                ReadingGranularity::QuarterHour,
                $policy->forMoment($this->at($moment)),
                'relevé du ' . $moment
            );
        }
    }

    public function testElectricityDefaultKeepsTheUserTimezone(): void
    {
        self::assertSame(self::TZ, ReadingGranularityPolicy::electricityDefault(self::TZ)->timezone());
        self::assertSame('UTC', ReadingGranularityPolicy::electricityDefault()->timezone());
    }

    /**
     * Le fuseau est validé à la construction : les appelants la font dans un bootstrap
     * gardé, où un identifiant illisible doit dégrader proprement (503 JSON côté API)
     * plutôt que de lever au milieu d'une écriture.
     */
    public function testUnknownTimezoneIsRejectedAtConstruction(): void
    {
        $this->expectException(\Exception::class);

        ReadingGranularityPolicy::electricityDefault('Europe/Atlantis');
    }
}
