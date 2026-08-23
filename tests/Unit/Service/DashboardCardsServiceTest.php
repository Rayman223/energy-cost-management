<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Service\CostCalculationService;
use App\Service\DashboardCardsService;
use App\Service\TariffCalculatorService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Tests\Fake\FakeGasReadingRepository;
use Tests\Fake\FakeLegacyDailyRepository;
use Tests\Fake\FakeMeterReadingRepository;
use Tests\Fake\FakeTariffRepository;

/**
 * Composition de la ligne de cards du dashboard (#215) : présence/absence de la
 * card solaire, ordre, unités et variation vs mois précédent.
 *
 * Aucune base de données : les repositories sont des fakes, mais le vrai
 * CostCalculationService (et donc la vraie interpolation gaz/eau) est utilisé.
 */
final class DashboardCardsServiceTest extends TestCase
{
    /**
     * Séries de relevés couvrant mai et juin 2026, pour que le mois de référence
     * (juin) et le mois précédent (mai) donnent des volumes différents.
     *
     * Gaz : mai = 100 m³, juin = 50 m³.
     *
     * @return list<array{reading_at: string, counter_m3: float}>
     */
    private function gasReadings(): array
    {
        return [
            ['reading_at' => '2026-05-01 00:00:00', 'counter_m3' => 1000.0],
            ['reading_at' => '2026-06-01 00:00:00', 'counter_m3' => 1100.0],
            ['reading_at' => '2026-07-01 00:00:00', 'counter_m3' => 1150.0],
        ];
    }

    /**
     * Eau : mai = 30 m³, juin = 15,5 m³.
     *
     * @return list<array{reading_at: string, counter_m3: float}>
     */
    private function waterReadings(): array
    {
        return [
            ['reading_at' => '2026-05-01 00:00:00', 'counter_m3' => 100.0],
            ['reading_at' => '2026-06-01 00:00:00', 'counter_m3' => 130.0],
            ['reading_at' => '2026-07-01 00:00:00', 'counter_m3' => 145.5],
        ];
    }

    /**
     * @param list<array{reading_at: string, counter_m3: float}> $gas
     * @param list<array{reading_at: string, counter_m3: float}>|null $water null → aucun repo eau
     */
    private function makeService(
        FakeLegacyDailyRepository $legacy,
        array $gas = [],
        ?array $water = null,
    ): DashboardCardsService {
        // Aucune grille tarifaire (grid: null) : les cards n'affichent que des
        // volumes, elles doivent rester alimentées sans tarif configuré.
        $cost = new CostCalculationService(
            legacyRepo: $legacy,
            tariffRepo: new FakeTariffRepository(grid: null),
            gasRepo: new FakeGasReadingRepository(forInterpolation: $gas),
            calculator: new TariffCalculatorService(),
            waterRepo: $water !== null ? new FakeMeterReadingRepository(forInterpolation: $water) : null,
        );

        return new DashboardCardsService($legacy, $cost);
    }

    /**
     * Deltas du mois de référence des tests (juin 2026).
     *
     * `to` porte la borne de fin RÉELLE des relevés, comme le fait le repository :
     * c'est elle qui définit la fenêtre de comparaison (#5). Par défaut le mois est
     * révolu (borne au 1er juillet), donc la référence est mai ENTIER.
     *
     * @param float|null $solar production du mois en cours
     *
     * @return array<string, mixed>
     */
    private function deltas(?float $solar = 30.0, string $to = '2026-07-01 00:00:00'): array
    {
        return [
            'from'        => '2026-06-01 00:00:00',
            'to'          => $to,
            'prelev_jour' => 100.0,
            'prelev_nuit' => 50.0,
            'injec_jour'  => 20.0,
            'injec_nuit'  => 10.0,
            'solar'       => $solar,
        ];
    }

    /**
     * @param list<array<string, mixed>> $cards
     *
     * @return list<string>
     */
    private function keys(array $cards): array
    {
        return array_map(static fn (array $c): string => (string) $c['key'], $cards);
    }

    /**
     * @param list<array<string, mixed>> $cards
     *
     * @return array<string, mixed>
     */
    private function card(array $cards, string $key): array
    {
        foreach ($cards as $c) {
            if ($c['key'] === $key) {
                return $c;
            }
        }

        self::fail("Card '{$key}' absente : " . implode(', ', $this->keys($cards)));
    }

    // ── Card solaire : rendu conditionnel ────────────────────────────────────

    public function testSolarCardIsPresentWhenProductionIsPositive(): void
    {
        $svc   = $this->makeService(new FakeLegacyDailyRepository(), $this->gasReadings(), $this->waterReadings());
        $cards = $svc->build($this->deltas(30.0), 2026, 6);

        self::assertCount(7, $cards);
        self::assertContains('solar', $this->keys($cards));
    }

    public function testSolarCardIsOmittedWhenProductionIsNull(): void
    {
        $svc   = $this->makeService(new FakeLegacyDailyRepository(), $this->gasReadings(), $this->waterReadings());
        $cards = $svc->build($this->deltas(null), 2026, 6);

        self::assertCount(6, $cards);
        self::assertNotContains('solar', $this->keys($cards));
    }

    public function testSolarCardIsOmittedWhenProductionIsZero(): void
    {
        $svc   = $this->makeService(new FakeLegacyDailyRepository(), $this->gasReadings(), $this->waterReadings());
        $cards = $svc->build($this->deltas(0.0), 2026, 6);

        self::assertCount(6, $cards);
        self::assertNotContains('solar', $this->keys($cards));
    }

    /** Le gaz et l'eau, eux, restent affichés (valeur « — ») même sans donnée. */
    public function testGasAndWaterCardsRemainWithNullValueWithoutReadings(): void
    {
        $svc   = $this->makeService(new FakeLegacyDailyRepository());
        $cards = $svc->build($this->deltas(30.0), 2026, 6);

        self::assertCount(7, $cards);
        self::assertNull($this->card($cards, 'gas')['value']);
        self::assertNull($this->card($cards, 'water')['value']);
    }

    // ── Ordre, libellés et unités ────────────────────────────────────────────

    public function testCardOrderAndUnits(): void
    {
        $svc   = $this->makeService(new FakeLegacyDailyRepository(), $this->gasReadings(), $this->waterReadings());
        $cards = $svc->build($this->deltas(30.0), 2026, 6);

        self::assertSame(
            ['import_t1', 'import_t2', 'export_t1', 'export_t2', 'solar', 'gas', 'water'],
            $this->keys($cards),
        );

        foreach (['import_t1', 'import_t2', 'export_t1', 'export_t2', 'solar'] as $key) {
            self::assertSame('kWh', $this->card($cards, $key)['unit'], "unité de {$key}");
        }
        self::assertSame('m³', $this->card($cards, 'gas')['unit']);
        self::assertSame('m³', $this->card($cards, 'water')['unit']);
    }

    /** Gaz et eau réutilisent les clés d'i18n existantes plutôt que dash.card.*. */
    public function testLabelKeys(): void
    {
        $svc   = $this->makeService(new FakeLegacyDailyRepository(), $this->gasReadings(), $this->waterReadings());
        $cards = $svc->build($this->deltas(30.0), 2026, 6);

        self::assertSame('dash.card.import_t1', $this->card($cards, 'import_t1')['label_key']);
        self::assertSame('dash.card.solar', $this->card($cards, 'solar')['label_key']);
        self::assertSame('dash.gas', $this->card($cards, 'gas')['label_key']);
        self::assertSame('dash.water', $this->card($cards, 'water')['label_key']);
    }

    /** Consommation : la hausse est défavorable ; injection et production : l'inverse. */
    public function testLowerIsBetterFollowsTheNatureOfTheCard(): void
    {
        $svc   = $this->makeService(new FakeLegacyDailyRepository(), $this->gasReadings(), $this->waterReadings());
        $cards = $svc->build($this->deltas(30.0), 2026, 6);

        foreach (['import_t1', 'import_t2', 'gas', 'water'] as $key) {
            self::assertTrue($this->card($cards, $key)['lower_is_better'], $key);
        }
        foreach (['export_t1', 'export_t2', 'solar'] as $key) {
            self::assertFalse($this->card($cards, $key)['lower_is_better'], $key);
        }
    }

    // ── Variation vs mois précédent ──────────────────────────────────────────

    public function testVariationAgainstPreviousMonth(): void
    {
        $legacy = new FakeLegacyDailyRepository(monthlyDeltasForMonth: [
            'prelev_jour' => 80.0,
            'prelev_nuit' => 50.0,
            'injec_jour'  => 25.0,
            'injec_nuit'  => 10.0,
            'solar'       => 60.0,
        ]);

        $cards = $this->makeService($legacy, $this->gasReadings(), $this->waterReadings())
            ->build($this->deltas(30.0), 2026, 6);

        // Élec : (100 − 80) / 80 = +25 % ; injection : (20 − 25) / 25 = −20 %.
        self::assertEqualsWithDelta(25.0, $this->card($cards, 'import_t1')['delta_pct'], 0.001);
        self::assertEqualsWithDelta(0.0, $this->card($cards, 'import_t2')['delta_pct'], 0.001);
        self::assertEqualsWithDelta(-20.0, $this->card($cards, 'export_t1')['delta_pct'], 0.001);
        // Solaire : (30 − 60) / 60 = −50 %.
        self::assertEqualsWithDelta(-50.0, $this->card($cards, 'solar')['delta_pct'], 0.001);
        // Gaz : juin 50 m³ vs mai 100 m³ = −50 % ; eau : 15,5 vs 30 = −48,3 %.
        self::assertEqualsWithDelta(-50.0, $this->card($cards, 'gas')['delta_pct'], 0.001);
        self::assertEqualsWithDelta(-48.3, $this->card($cards, 'water')['delta_pct'], 0.001);
    }

    /** Référence absente ou nulle : un pourcentage n'aurait pas de sens. */
    public function testVariationIsNullWithoutUsablePreviousMonth(): void
    {
        $absent = $this->makeService(new FakeLegacyDailyRepository(), $this->gasReadings(), $this->waterReadings())
            ->build($this->deltas(30.0), 2026, 6);
        self::assertNull($this->card($absent, 'import_t1')['delta_pct']);

        $zero = $this->makeService(
            new FakeLegacyDailyRepository(monthlyDeltasForMonth: ['prelev_jour' => 0.0]),
            $this->gasReadings(),
            $this->waterReadings(),
        )->build($this->deltas(30.0), 2026, 6);
        self::assertNull($this->card($zero, 'import_t1')['delta_pct']);
    }

    // ── Référence à zéro : « nouveau ce mois-ci » ────────────────────────────

    /**
     * Mois précédent connu et nul, mois en cours consommé : le pourcentage est
     * impossible, mais le démarrage est une information réelle (premier mois de
     * relevés, compteur remplacé).
     */
    public function testZeroReferenceWithConsumptionIsFlaggedAsNew(): void
    {
        $legacy = new FakeLegacyDailyRepository(monthlyDeltasForMonth: [
            'prelev_jour' => 0.0,
            'prelev_nuit' => 0.0,
            'injec_jour'  => 0.0,
            'injec_nuit'  => 0.0,
            'solar'       => 0.0,
        ]);

        $cards = $this->makeService($legacy)->build($this->deltas(30.0), 2026, 6);

        foreach (['import_t1', 'import_t2', 'export_t1', 'export_t2', 'solar'] as $key) {
            self::assertNull($this->card($cards, $key)['delta_pct'], "delta_pct de {$key}");
            self::assertTrue($this->card($cards, $key)['is_new'], "is_new de {$key}");
        }
    }

    /** Référence ABSENTE : on ne sait rien, donc aucun badge — pas « nouveau ». */
    public function testAbsentReferenceIsNotFlaggedAsNew(): void
    {
        $cards = $this->makeService(new FakeLegacyDailyRepository())
            ->build($this->deltas(30.0), 2026, 6);

        self::assertFalse($this->card($cards, 'import_t1')['is_new']);
        self::assertFalse($this->card($cards, 'gas')['is_new']);
    }

    /** Rien consommé ce mois-ci non plus : il n'y a pas de démarrage à signaler. */
    public function testZeroReferenceWithoutConsumptionIsNotNew(): void
    {
        $legacy = new FakeLegacyDailyRepository(monthlyDeltasForMonth: ['prelev_jour' => 0.0]);

        $deltas = $this->deltas(30.0);
        $deltas['prelev_jour'] = 0.0;

        $cards = $this->makeService($legacy)->build($deltas, 2026, 6);

        self::assertFalse($this->card($cards, 'import_t1')['is_new']);
    }

    /** Une variation chiffrable exclut le badge « nouveau ». */
    public function testUsableReferenceIsNotFlaggedAsNew(): void
    {
        $legacy = new FakeLegacyDailyRepository(monthlyDeltasForMonth: ['prelev_jour' => 80.0]);

        $cards = $this->makeService($legacy)->build($this->deltas(30.0), 2026, 6);

        self::assertEqualsWithDelta(25.0, $this->card($cards, 'import_t1')['delta_pct'], 0.001);
        self::assertFalse($this->card($cards, 'import_t1')['is_new']);
    }

    /** Janvier : le mois de référence est décembre de l'année précédente. */
    public function testJanuaryComparesAgainstDecemberOfPreviousYear(): void
    {
        $legacy = new FakeLegacyDailyRepository(monthlyDeltasForMonth: ['prelev_jour' => 80.0]);

        $this->makeService($legacy)->build(
            $this->deltas(30.0, '2026-02-01 00:00:00'),
            2026,
            1,
        );

        self::assertSame([['2025-12-01 00:00:00', '2026-01-01 00:00:00']], $legacy->rangesRequested);
    }

    /**
     * Les deltas du mois en cours sont ceux passés par la route, jamais relus :
     * les caches de getMonthlyDeltas() et getDeltasBetween() sont distincts, un
     * second appel referait l'interpolation.
     */
    public function testCurrentMonthDeltasComeFromTheArgument(): void
    {
        $legacy = new FakeLegacyDailyRepository(
            monthlyDeltas: ['prelev_jour' => 999.0],
            monthlyDeltasForMonth: ['prelev_jour' => 80.0],
        );

        $cards = $this->makeService($legacy)->build($this->deltas(30.0), 2026, 6);

        self::assertEqualsWithDelta(100.0, $this->card($cards, 'import_t1')['value'], 0.001);
        // Seule la fenêtre du mois précédent est demandée au repository.
        self::assertSame([['2026-05-01 00:00:00', '2026-06-01 00:00:00']], $legacy->rangesRequested);
        self::assertSame([], $legacy->monthsRequested);
    }

    // ── Fenêtre de comparaison : mois en cours (#5) ──────────────────────────

    /**
     * Le cas de l'issue #5 : les premiers jours du mois, la fenêtre écoulée était
     * comparée à un mois précédent COMPLET, d'où un « −99 % » qui ne disait rien
     * d'autre que « le mois vient de commencer ».
     *
     * Ici juin s'arrête au 5 à 08:00 ; la référence doit porter sur la même
     * fenêtre de mai, et non sur ses 31 jours.
     */
    public function testPartialMonthIsComparedAgainstTheSameWindowOfThePreviousMonth(): void
    {
        $legacy = new FakeLegacyDailyRepository(deltasBetween: ['prelev_jour' => 96.9]);

        $cards = $this->makeService($legacy, $this->gasReadings(), $this->waterReadings())
            ->build($this->deltas(30.0, '2026-06-05 08:00:00'), 2026, 6);

        self::assertSame([['2026-05-01 00:00:00', '2026-05-05 08:00:00']], $legacy->rangesRequested);
        // (100 − 96,9) / 96,9 = +3,2 % — et non les −87 % d'avant le correctif.
        self::assertEqualsWithDelta(3.2, $this->card($cards, 'import_t1')['delta_pct'], 0.05);
    }

    /**
     * Gaz et eau se lisent eux aussi sur une fenêtre écoulée, comparée à la même
     * fenêtre du mois précédent : plus de projection sur le mois entier.
     *
     * Mai = 100 m³ sur 31 jours ; juin = 50 m³ sur 30 jours. Au 5 juin 00:00,
     * 4 jours écoulés → juin ≈ 6,667 m³, mai ≈ 12,903 m³, soit −48,3 %.
     */
    public function testGasAndWaterAreMeasuredOverTheElapsedWindow(): void
    {
        $cards = $this->makeService(new FakeLegacyDailyRepository(), $this->gasReadings(), $this->waterReadings())
            ->build($this->deltas(30.0), 2026, 6, new DateTimeImmutable('2026-06-05 00:00:00', new DateTimeZone('UTC')));

        self::assertEqualsWithDelta(6.667, $this->card($cards, 'gas')['value'], 0.001);
        self::assertEqualsWithDelta(-48.3, $this->card($cards, 'gas')['delta_pct'], 0.1);
        self::assertEqualsWithDelta(2.067, $this->card($cards, 'water')['value'], 0.001);
    }

    /**
     * La fenêtre gaz/eau ne dépend PAS du dernier relevé électricité.
     *
     * Les index élec saisis à la main n'arrivent parfois qu'une fois par mois :
     * caler le gaz sur eux réduirait la card à un jour et demi de consommation
     * pendant tout le reste du mois. Chaque énergie se lit donc jusqu'à
     * aujourd'hui, l'électricité seule s'arrêtant à son dernier index connu.
     */
    public function testUtilityWindowDoesNotDependOnTheLastElectricityReading(): void
    {
        $cards = $this->makeService(new FakeLegacyDailyRepository(), $this->gasReadings(), $this->waterReadings())
            ->build(
                $this->deltas(30.0, '2026-06-02 09:00:00'),
                2026,
                6,
                new DateTimeImmutable('2026-06-15 00:00:00', new DateTimeZone('UTC')),
            );

        // 14 jours de juin (50 m³ / 30 j), et non les 1,4 jour de la fenêtre élec.
        self::assertEqualsWithDelta(23.333, $this->card($cards, 'gas')['value'], 0.001);
        self::assertEqualsWithDelta(7.233, $this->card($cards, 'water')['value'], 0.001);
    }

    /**
     * Mois précédent plus court : la fenêtre de référence est clampée à sa fin.
     * 30 jours écoulés au 31 mars ne peuvent pas se reporter sur février.
     */
    public function testPreviousWindowIsClampedToAShorterPreviousMonth(): void
    {
        $legacy = new FakeLegacyDailyRepository(deltasBetween: ['prelev_jour' => 80.0]);

        $this->makeService($legacy)->build(
            [...$this->deltas(30.0), 'from' => '2026-03-01 00:00:00', 'to' => '2026-03-31 12:00:00'],
            2026,
            3,
        );

        self::assertSame([['2026-02-01 00:00:00', '2026-03-01 00:00:00']], $legacy->rangesRequested);
    }

    /**
     * Sous 24 h de fenêtre écoulée, le rapport est dominé par l'heure du relevé :
     * aucun badge, et aucune requête pour une comparaison qu'on n'affichera pas.
     */
    public function testNoVariationBelowOneDayOfElapsedWindow(): void
    {
        $legacy = new FakeLegacyDailyRepository(deltasBetween: ['prelev_jour' => 80.0]);

        $cards = $this->makeService($legacy, $this->gasReadings(), $this->waterReadings())
            ->build(
                $this->deltas(30.0, '2026-06-01 06:00:00'),
                2026,
                6,
                new DateTimeImmutable('2026-06-01 06:00:00', new DateTimeZone('UTC')),
            );

        self::assertNull($this->card($cards, 'import_t1')['delta_pct']);
        self::assertFalse($this->card($cards, 'import_t1')['is_new']);
        self::assertNull($this->card($cards, 'gas')['delta_pct']);
        self::assertSame([], $legacy->rangesRequested);

        // La valeur du mois en cours, elle, reste affichée.
        self::assertEqualsWithDelta(100.0, $this->card($cards, 'import_t1')['value'], 0.001);
    }

    /**
     * Le seuil s'applique fenêtre par fenêtre : un relevé électricité tombé le 1er
     * ne doit pas priver de badge les cards gaz et eau, alimentées par des relevés
     * qui, eux, continuent d'arriver.
     */
    public function testTheOneDayThresholdIsEvaluatedPerEnergy(): void
    {
        $legacy = new FakeLegacyDailyRepository(deltasBetween: ['prelev_jour' => 80.0]);

        $cards = $this->makeService($legacy, $this->gasReadings(), $this->waterReadings())
            ->build(
                $this->deltas(30.0, '2026-06-01 06:00:00'),
                2026,
                6,
                new DateTimeImmutable('2026-06-15 00:00:00', new DateTimeZone('UTC')),
            );

        self::assertNull($this->card($cards, 'import_t1')['delta_pct']);
        self::assertNotNull($this->card($cards, 'gas')['delta_pct']);
        self::assertNotNull($this->card($cards, 'water')['delta_pct']);
    }

    /**
     * Fin d'un mois plus long que son prédécesseur : la fenêtre de référence bute
     * sur la fin du mois précédent, et le badge annonce dès lors l'écart de
     * longueur des mois — 31 jours de mars contre 28 de février, à consommation
     * journalière égale.
     *
     * C'est voulu : c'est exactement ce qu'affichera le lendemain la comparaison de
     * deux mois révolus. La fin de mois y converge au lieu d'y sauter à minuit.
     */
    public function testEndOfALongMonthConvergesTowardsTheWholeMonthComparison(): void
    {
        $legacy = new FakeLegacyDailyRepository(deltasBetween: ['prelev_jour' => 28.0]);

        $deltas = [...$this->deltas(30.0), 'from' => '2026-03-01 00:00:00', 'to' => '2026-03-31 23:59:00'];
        $deltas['prelev_jour'] = 31.0;

        $cards = $this->makeService($legacy)->build($deltas, 2026, 3);

        self::assertSame([['2026-02-01 00:00:00', '2026-03-01 00:00:00']], $legacy->rangesRequested);
        // 31 jours vs 28 : +10,7 %, la valeur que donnera aussi le mois révolu.
        self::assertEqualsWithDelta(10.7, $this->card($cards, 'import_t1')['delta_pct'], 0.05);
    }

    /**
     * Aucun relevé élec ce mois-ci : les deltas sont vides et ne portent donc pas
     * de borne de fin. La fenêtre retombe sur l'instant de référence fourni par la
     * route, ce qui garde les cards gaz/eau alimentées.
     */
    public function testWindowFallsBackToTheReferenceInstantWithoutElectricityDeltas(): void
    {
        $legacy = new FakeLegacyDailyRepository(deltasBetween: ['prelev_jour' => 80.0]);

        $cards = $this->makeService($legacy, $this->gasReadings(), $this->waterReadings())
            ->build([], 2026, 6, new DateTimeImmutable('2026-06-05 00:00:00', new DateTimeZone('UTC')));

        self::assertSame([['2026-05-01 00:00:00', '2026-05-05 00:00:00']], $legacy->rangesRequested);
        self::assertNull($this->card($cards, 'import_t1')['value']);
        self::assertEqualsWithDelta(6.667, $this->card($cards, 'gas')['value'], 0.001);
    }

    /** Instant de référence antérieur au mois demandé : rien n'est exploitable. */
    public function testNothingIsComputedForAFutureMonth(): void
    {
        $legacy = new FakeLegacyDailyRepository(deltasBetween: ['prelev_jour' => 80.0]);

        $cards = $this->makeService($legacy, $this->gasReadings(), $this->waterReadings())
            ->build([], 2026, 6, new DateTimeImmutable('2026-05-20 00:00:00', new DateTimeZone('UTC')));

        self::assertSame([], $legacy->rangesRequested);
        self::assertNull($this->card($cards, 'gas')['value']);
        self::assertNull($this->card($cards, 'gas')['delta_pct']);
    }

    // ── Gaz sans grille tarifaire ────────────────────────────────────────────

    /**
     * estimateMonthGas() renvoie `available => false` sans tarif gaz configuré ;
     * la card doit tout de même afficher le volume (via periodGasVolume()).
     */
    public function testGasVolumeIsExposedWithoutAnyTariffGrid(): void
    {
        $cards = $this->makeService(new FakeLegacyDailyRepository(), $this->gasReadings(), $this->waterReadings())
            ->build($this->deltas(30.0), 2026, 6);

        self::assertEqualsWithDelta(50.0, $this->card($cards, 'gas')['value'], 0.001);
        self::assertEqualsWithDelta(15.5, $this->card($cards, 'water')['value'], 0.001);
    }

    /** Sans repository eau, la card reste présente mais vide. */
    public function testWaterCardIsEmptyWithoutWaterRepository(): void
    {
        $cards = $this->makeService(new FakeLegacyDailyRepository(), $this->gasReadings())
            ->build($this->deltas(30.0), 2026, 6);

        self::assertNull($this->card($cards, 'water')['value']);
        self::assertNull($this->card($cards, 'water')['delta_pct']);
    }
}
