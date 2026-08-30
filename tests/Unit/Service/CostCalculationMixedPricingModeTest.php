<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Domain\ComponentKind;
use App\Domain\TariffGrid;
use App\Domain\TariffLine;
use App\Service\CostCalculationService;
use App\Service\TariffCalculatorService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Fake\FakeDynamicPriceRepository;
use Tests\Fake\FakeGasReadingRepository;
use Tests\Fake\FakeLegacyDailyRepository;
use Tests\Fake\FakeTariffRepository;

/**
 * Mode de tarification porté par la grille (#245) : une période à cheval sur un
 * changement de contrat est facturée sous-période par sous-période, chacune dans SON
 * mode, au lieu d'être reclassée en bloc.
 *
 * Le scénario de référence est le mois de bascule : grille fixe du 1er au 15,
 * dynamique à partir du 16. C'est précisément ce que l'ancien réglage global rendait
 * impossible — il fallait choisir un mode pour tout l'historique.
 */
final class CostCalculationMixedPricingModeTest extends TestCase
{
    private const T1 = 0.10;
    private const T2 = 0.08;

    private function grid(int $id, string $from, ?string $to, string $mode): TariffGrid
    {
        return new TariffGrid(
            id: $id,
            energyType: 'electricity',
            name: 'Grille ' . $id,
            validFrom: new DateTimeImmutable($from),
            validTo: $to !== null ? new DateTimeImmutable($to) : null,
            lines: [
                'energy_t1'    => new TariffLine('energy_t1', self::T1, ComponentKind::EnergyT1),
                'energy_t2'    => new TariffLine('energy_t2', self::T2, ComponentKind::EnergyT2),
                'subscription' => new TariffLine('subscription', 5.0, ComponentKind::FixedMonthly),
            ],
            vatRate: 21.0,
            pricingMode: $mode,
        );
    }

    /** @return array<string,mixed> */
    private function deltas(): array
    {
        return [
            'from'        => '2026-06-01 00:00:00',
            'to'          => '2026-06-30 00:00:00',
            'prelev_jour' => 300.0,
            'prelev_nuit' => 150.0,
            'injec_jour'  => 0.0,
            'injec_nuit'  => 0.0,
            'solar'       => 0.0,
        ];
    }

    /**
     * Un créneau consommé de chaque côté de la bascule. Le 5 juin relève de la grille
     * fixe, le 20 de la dynamique.
     *
     * @return list<array{hour: string, import_kwh: float}>
     */
    private function hourlySlots(): array
    {
        return [
            ['hour' => '2026-06-05 10:00:00', 'import_kwh' => 40.0],
            ['hour' => '2026-06-20 10:00:00', 'import_kwh' => 60.0],
        ];
    }

    /**
     * @param list<TariffGrid> $grids
     * @param array<string, float> $hourlyPrices
     * @param list<array{hour: string, import_kwh: float}>|null $slots Créneaux consommés ;
     *        `null` = ceux de {@see hourlySlots()}.
     */
    private function service(
        array $grids,
        array $hourlyPrices = [],
        bool $dynamicEnabled = true,
        ?array $slots = null,
    ): CostCalculationService {
        $tariffs = new FakeTariffRepository();
        $tariffs->gridsBetween = $grids;

        return new CostCalculationService(
            legacyRepo: new FakeLegacyDailyRepository(
                monthlyDeltasForMonth: $this->deltas(),
                hourlyImportDeltas: $slots ?? $this->hourlySlots(),
            ),
            tariffRepo: $tariffs,
            gasRepo: new FakeGasReadingRepository(),
            calculator: new TariffCalculatorService(),
            dynamicPriceRepo: new FakeDynamicPriceRepository(hourlyPricesByHour: $hourlyPrices),
            dynamicEnabled: $dynamicEnabled,
            tariffTimezone: 'Europe/Brussels',
        );
    }

    /** Sous-périodes : fixe du 01 au 15, dynamique du 16 à la fin. @return list<TariffGrid> */
    private function mixedGrids(): array
    {
        return [
            $this->grid(2, '2026-06-16', null, 'dynamic_hourly'),
            $this->grid(1, '2026-06-01', '2026-06-15', 'fixed'),
        ];
    }

    /** @return list<TariffGrid> */
    private function fixedGrids(): array
    {
        return [$this->grid(1, '2026-06-01', null, 'fixed')];
    }

    /** Un prix spot très élevé rend la part dynamique impossible à confondre. */
    private const SPOT = ['2026-06-20 10:00:00' => 1.0];

    public function testMixedPeriodIsBilledSegmentBySegment(): void
    {
        $r = $this->service($this->mixedGrids(), self::SPOT)->estimateMonthElectricity(2026, 6);

        self::assertTrue($r['available']);
        self::assertTrue($r['is_mixed']);
        self::assertSame('dynamic_hourly', $r['pricing_mode']);

        // Du 01/06 00:00 au 30/06 00:00 = 29 jours pleins ; la bascule au 16 en laisse
        // 15 sous contrat fixe et 14 sous contrat dynamique.
        self::assertSame(29, $r['days']);
        self::assertSame(14, $r['dynamic_days']);

        // Sous-périodes rendues dans l'ordre chronologique, chacune avec son mode.
        self::assertSame(['fixed', 'dynamic_hourly'], array_column($r['pricing_modes'], 'mode'));
        self::assertSame([15, 14], array_column($r['pricing_modes'], 'days'));
    }

    /**
     * Le cœur de l'issue : la part énergie des jours restés sous grille fixe ne doit
     * dépendre d'aucun prix de marché. On fait varier le spot du 20 juin d'un facteur
     * 10 — le total de la sous-période fixe ne doit pas bouger d'un centime, alors que
     * celui du mois, lui, augmente.
     */
    public function testFixedSegmentIgnoresSpotPrices(): void
    {
        $cheap = $this->service($this->mixedGrids(), ['2026-06-20 10:00:00' => 0.1])
            ->estimateMonthElectricity(2026, 6);
        $costly = $this->service($this->mixedGrids(), ['2026-06-20 10:00:00' => 1.0])
            ->estimateMonthElectricity(2026, 6);

        $fixedSegment = static function (array $r): float {
            foreach ($r['tariff_segments'] as $segment) {
                if ($segment['from'] === '2026-06-01') {
                    return (float) $segment['total'];
                }
            }

            self::fail('Sous-période fixe absente de la réponse.');
        };

        self::assertEqualsWithDelta($fixedSegment($cheap), $fixedSegment($costly), 0.001);
        self::assertGreaterThan($cheap['cost']['total'], $costly['cost']['total']);
    }

    /**
     * Sans changement de contrat, rien ne change : une période entièrement fixe rend
     * exactement le calcul classique, sans clé dynamique parasite.
     */
    public function testAllFixedPeriodKeepsTheClassicResponse(): void
    {
        $r = $this->service($this->fixedGrids(), self::SPOT)->estimateMonthElectricity(2026, 6);

        self::assertTrue($r['available']);
        self::assertFalse($r['is_mixed']);
        self::assertSame(0, $r['dynamic_days']);
        self::assertSame('fixed', $r['pricing_mode']);
        self::assertArrayNotHasKey('energy_dynamic', $r);
        self::assertArrayNotHasKey('spot_base', $r);
    }

    /**
     * Grille dynamique mais aucun prix importé : la facturation retombe sur le tarif
     * fournisseur AVEC son motif, plutôt que de rendre la période indisponible. Un
     * cron de prix en retard ne doit pas faire disparaître le coût du mois.
     */
    public function testDynamicWithoutPricesFallsBackAndSaysWhy(): void
    {
        $r = $this->service($this->mixedGrids())->estimateMonthElectricity(2026, 6);

        self::assertTrue($r['available']);
        self::assertArrayHasKey('dynamic_unavailable_reason', $r);
        self::assertNotSame('', $r['dynamic_unavailable_reason']);

        // Repli intégral : même total qu'une période entièrement fixe.
        $allFixed = $this->service($this->fixedGrids())->estimateMonthElectricity(2026, 6);
        self::assertEqualsWithDelta($allFixed['cost']['total'], $r['cost']['total'], 0.001);
    }

    /**
     * Kill-switch serveur : une grille dynamique est calculée comme une grille fixe,
     * sans rien réécrire en base — le mode ressort intact si les prix reviennent.
     */
    public function testServerKillSwitchNeutralisesTheDynamicGrid(): void
    {
        $off = $this->service($this->mixedGrids(), self::SPOT, dynamicEnabled: false)
            ->estimateMonthElectricity(2026, 6);
        $allFixed = $this->service($this->fixedGrids(), self::SPOT, dynamicEnabled: false)
            ->estimateMonthElectricity(2026, 6);

        self::assertSame(0, $off['dynamic_days']);
        self::assertSame('fixed', $off['pricing_mode']);
        self::assertEqualsWithDelta($allFixed['cost']['total'], $off['cost']['total'], 0.001);
    }

    /**
     * Couverture et prix moyen ne décrivent que la part indexée : y compter les jours
     * au tarif fournisseur ferait passer une couverture complète pour lacunaire.
     */
    public function testCoverageIsMeasuredOnTheDynamicPartOnly(): void
    {
        $r = $this->service($this->mixedGrids(), self::SPOT)->estimateMonthElectricity(2026, 6);

        // Seuls les 60 kWh du 20 juin sont indexés ; les 40 kWh du 5 juin ne le sont pas.
        self::assertEqualsWithDelta(60.0, $r['matched_kwh'], 0.001);
        self::assertEqualsWithDelta(100.0, $r['coverage_pct'], 0.001);
    }

    /**
     * La projection « tout dynamique » reste disponible pour le comparatif, et se
     * déclare comme telle : sur une période mixte elle n'est PAS une simulation — une
     * partie du mois est bien indexée — mais elle indexe tout de même le mois entier.
     */
    public function testProjectionFlagsWhetherItMatchesTheContract(): void
    {
        $mixed = $this->service($this->mixedGrids(), self::SPOT)
            ->estimateMonthElectricityDynamic(2026, 6);
        self::assertTrue($mixed['available']);
        self::assertFalse($mixed['is_simulation']);

        $fixed = $this->service($this->fixedGrids(), self::SPOT)
            ->estimateMonthElectricityDynamic(2026, 6);
        self::assertTrue($fixed['available']);
        self::assertTrue($fixed['is_simulation']);
        // Une grille fixe projetée en dynamique est annoncée au pas horaire, jamais
        // 'fixed' : le montant rendu est bien indexé.
        self::assertSame('dynamic_hourly', $fixed['pricing_mode']);
    }

    /**
     * La fenêtre de recherche des prix s'ouvre à minuit CHEZ L'UTILISATEUR, comme la
     * sous-période qu'elle sert (#16). 22:00 UTC le 15 juin, c'est déjà le 16 juin à
     * Bruxelles : ce créneau relève de la grille dynamique, et `segmentBoundaries()`
     * comme `segmentIndexForHour()` le disent.
     *
     * Une fenêtre restée à minuit UTC serait plus étroite que la sous-période : le
     * créneau ne serait pas remonté par `getHourlyImportDeltas()`, donc pas indexé,
     * et pas davantage facturé au tarif fournisseur puisque
     * `calculateElectricityCostDynamic()` remplace les lignes d'énergie de la grille
     * par le montant indexé. Son énergie disparaîtrait de la facture.
     */
    public function testDynamicWindowOpensAtContractMidnight(): void
    {
        $slots = [
            ['hour' => '2026-06-05 10:00:00', 'import_kwh' => 40.0], // grille fixe
            ['hour' => '2026-06-15 22:00:00', 'import_kwh' => 60.0], // 16 juin 00:00 à Bruxelles
        ];

        // Voie contractuelle, et non la projection « tout dynamique » d'
        // estimateMonthElectricityDynamic() : c'est ici que les sous-périodes gardent
        // chacune leur mode, donc que la fenêtre est restreinte au segment indexé.
        $r = $this->service($this->mixedGrids(), ['2026-06-15 22:00:00' => 1.0], slots: $slots)
            ->estimateMonthElectricity(2026, 6);

        self::assertTrue($r['available']);
        self::assertEqualsWithDelta(100.0, $r['coverage_pct'], 0.1);
        // 60 kWh × 1,00 €/kWh × 1,21 = 72,60 € — l'énergie du créneau frontière est
        // bien facturée, et à la grille dynamique.
        self::assertEqualsWithDelta(72.6, $r['energy_dynamic'], 0.01);
    }

    /**
     * Pendant du test précédent, côté FIN de période. La période est bornée en UTC
     * (mois calendaire), le contrat non : la dernière sous-période déborde donc de sa
     * propre date de fin, et `segmentIndexForHour()` lui rattache bien les créneaux du
     * débordement (repli sur le dernier segment). Fermer la fenêtre au minuit belge du
     * lendemain les en sortirait — 60 kWh facturés nulle part.
     *
     * Le 29 juin 22:00 UTC, c'est le 30 juin 00:00 à Bruxelles : le dernier segment
     * s'arrêtant au 29, ce créneau tombe précisément dans l'écart.
     */
    public function testDynamicWindowKeepsThePeriodTailBeyondTheLastSegmentDay(): void
    {
        $slots = [
            ['hour' => '2026-06-20 10:00:00', 'import_kwh' => 40.0],
            ['hour' => '2026-06-29 22:00:00', 'import_kwh' => 60.0], // 30 juin 00:00 à Bruxelles
        ];

        $r = $this->service(
            $this->mixedGrids(),
            ['2026-06-20 10:00:00' => 1.0, '2026-06-29 22:00:00' => 1.0],
            slots: $slots,
        )->estimateMonthElectricity(2026, 6);

        self::assertTrue($r['available']);
        // (40 + 60) kWh × 1,00 €/kWh × 1,21 = 121 € : la queue de période est facturée.
        self::assertEqualsWithDelta(121.0, $r['energy_dynamic'], 0.01);
    }

    /**
     * Symétrique pour un contrat à l'OUEST d'UTC, où le décalage joue en TÊTE de
     * période : le 1er juin 02:00 UTC, c'est encore le 31 mai à New York. La première
     * sous-période étant aussi la première du mois, `segmentIndexForHour()` lui
     * rattache ce créneau — ouvrir la fenêtre au minuit new-yorkais l'en sortirait.
     */
    public function testDynamicWindowKeepsThePeriodHeadBeforeTheFirstSegmentDay(): void
    {
        $tariffs               = new FakeTariffRepository();
        $tariffs->gridsBetween = [$this->grid(1, '2026-06-01', null, 'dynamic_hourly')];

        $r = (new CostCalculationService(
            legacyRepo: new FakeLegacyDailyRepository(
                monthlyDeltasForMonth: $this->deltas(),
                hourlyImportDeltas: [
                    ['hour' => '2026-06-01 02:00:00', 'import_kwh' => 60.0], // 31 mai 22:00 à New York
                    ['hour' => '2026-06-20 10:00:00', 'import_kwh' => 40.0],
                ],
            ),
            tariffRepo: $tariffs,
            gasRepo: new FakeGasReadingRepository(),
            calculator: new TariffCalculatorService(),
            dynamicPriceRepo: new FakeDynamicPriceRepository(hourlyPricesByHour: [
                '2026-06-01 02:00:00' => 1.0,
                '2026-06-20 10:00:00' => 1.0,
            ]),
            dynamicEnabled: true,
            tariffTimezone: 'America/New_York',
        ))->estimateMonthElectricity(2026, 6);

        self::assertTrue($r['available']);
        self::assertEqualsWithDelta(121.0, $r['energy_dynamic'], 0.01);
    }
}
