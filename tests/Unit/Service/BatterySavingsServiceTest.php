<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Domain\Battery;
use App\Domain\BatteryDischargeProfile;
use App\Domain\ComponentKind;
use App\Domain\TariffGrid;
use App\Domain\TariffLine;
use App\Service\BatterySavingsService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Tests\Fake\FakeBatteryReadings;
use Tests\Fake\FakeLegacyDailyRepository;
use Tests\Fake\FakeTariffRepository;

/**
 * Bilan d'économie d'une batterie (#26).
 *
 * Chaque euro annoncé ici finit dans un temps d'amortissement affiché à
 * l'utilisateur : une erreur de pondération T1/T2, un abonnement compté comme
 * évité, ou une projection sur le mois en cours, et la promesse est fausse.
 */
final class BatterySavingsServiceTest extends TestCase
{
    /** Tarifs de la grille de test, en €/kWh TTC. */
    private const ENERGY_T1   = 0.20;
    private const ENERGY_T2   = 0.10;
    private const PER_KWH     = 0.05;  // taxe, sans distinction horaire
    private const INJECTION   = 0.04;
    private const SUBSCRIPTION = 8.0;  // €/mois — NE DOIT PAS entrer dans le taux évité

    private function at(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date, new DateTimeZone('UTC'));
    }

    private function grid(
        string $mode = 'fixed',
        string $from = '2025-01-01',
        ?string $to = null,
        bool $withInjection = true,
        string $currency = 'EUR',
    ): TariffGrid {
        $lines = [
            'energy_t1'    => new TariffLine('energy_t1', self::ENERGY_T1, ComponentKind::EnergyT1),
            'energy_t2'    => new TariffLine('energy_t2', self::ENERGY_T2, ComponentKind::EnergyT2),
            'taxes'        => new TariffLine('taxes', self::PER_KWH, ComponentKind::PerKwh),
            'subscription' => new TariffLine('subscription', self::SUBSCRIPTION, ComponentKind::FixedMonthly),
        ];
        if ($withInjection) {
            $lines['injection_t1'] = new TariffLine('injection_t1', self::INJECTION, ComponentKind::InjectionT1);
        }

        return new TariffGrid(
            id: 1,
            energyType: 'electricity',
            name: 'Grille test',
            validFrom: $this->at($from),
            validTo: $to !== null ? $this->at($to) : null,
            lines: $lines,
            currency: $currency,
            pricingMode: $mode,
        );
    }

    private function battery(
        BatteryDischargeProfile $profile = BatteryDischargeProfile::T1,
        int $pvShare = 100,
        ?int $t1Share = null,
        string $commissioned = '2026-01-01',
        ?string $decommissioned = null,
    ): Battery {
        return new Battery(
            id: 1,
            brand: 'BYD',
            model: 'HVS',
            capacityKwh: 10.0,
            commissionedOn: $this->at($commissioned),
            decommissionedOn: $decommissioned !== null ? $this->at($decommissioned) : null,
            pvChargeShare: $pvShare,
            dischargeProfile: $profile,
            dischargeT1Share: $t1Share,
        );
    }

    /**
     * @param list<TariffGrid> $grids
     * @param array<string,mixed> $deltas Deltas élec rendus par getDeltasBetween().
     */
    private function service(array $grids, array $deltas = []): BatterySavingsService
    {
        $tariffs = new FakeTariffRepository();
        $tariffs->gridsBetween = $grids;

        return new BatterySavingsService($tariffs, new FakeLegacyDailyRepository(deltasBetween: $deltas));
    }

    /**
     * @param array<string,float> $charge
     * @param array<string,float> $discharge
     * @return array{batteries: list<array<string,mixed>>, fleet: array<string,mixed>|null}
     */
    private function balance(
        BatterySavingsService $service,
        Battery $battery,
        array $charge,
        array $discharge,
        string $asOf = '2026-03-01',
    ): array {
        return $service->balance(
            [['battery' => $battery, 'readings' => new FakeBatteryReadings($charge, $discharge)]],
            $this->at($asOf),
        );
    }

    // ── Économie brute : le chiffre qui pilote l'amortissement ─────────────

    /**
     * Profil T1, charge 100 % photovoltaïque. Sur janvier : 100 kWh déchargés,
     * 120 kWh chargés.
     *
     * Taux évité = énergie T1 + taxe = 0,20 + 0,05 = 0,25 €/kWh.
     * L'abonnement de 8 €/mois n'y entre PAS : il se paie qu'on prélève ou non.
     */
    public function testGrossSavingsValueTheDischargedKwhAtTheAvoidedImportRate(): void
    {
        $result = $this->balance(
            $this->service([$this->grid()]),
            $this->battery(),
            ['2026-01-01' => 0.0, '2026-02-01' => 120.0],
            ['2026-01-01' => 0.0, '2026-02-01' => 100.0],
        );

        $january = $result['batteries'][0]['months'][0];

        self::assertSame('2026-01', $january['month']);
        self::assertEqualsWithDelta(0.25, $january['avoided_rate'], 1e-9, 'l\'abonnement ne doit pas être compté comme évité');
        self::assertEqualsWithDelta(100.0 * 0.25, $january['gross_savings'], 1e-6);
    }

    /**
     * Le coût d'opportunité est calculé ET affiché, mais l'économie brute — celle
     * qui pilote l'amortissement — ne s'en trouve pas amputée.
     */
    public function testOpportunityCostIsReportedSeparatelyFromGrossSavings(): void
    {
        $result = $this->balance(
            $this->service([$this->grid()]),
            $this->battery(pvShare: 100),
            ['2026-01-01' => 0.0, '2026-02-01' => 120.0],
            ['2026-01-01' => 0.0, '2026-02-01' => 100.0],
        );

        $january = $result['batteries'][0]['months'][0];

        // 100 % PV : les 120 kWh chargés auraient été injectés à 0,04 €/kWh.
        self::assertEqualsWithDelta(120.0 * self::INJECTION, $january['opportunity_cost'], 1e-6);
        self::assertEqualsWithDelta(25.0, $january['gross_savings'], 1e-6, 'la brute reste intacte');
        self::assertEqualsWithDelta(25.0 - 4.8, $january['net_savings'], 1e-6);
    }

    /**
     * Charge intégralement prélevée au réseau : elle a été payée au prix qu'on
     * évite ensuite. L'aller-retour ne rapporte alors que les pertes… en négatif.
     */
    public function testGridChargedBatteryPaysTheAvoidedRateOnTheWayIn(): void
    {
        $result = $this->balance(
            $this->service([$this->grid()]),
            $this->battery(pvShare: 0),
            ['2026-01-01' => 0.0, '2026-02-01' => 120.0],
            ['2026-01-01' => 0.0, '2026-02-01' => 100.0],
        );

        $january = $result['batteries'][0]['months'][0];

        self::assertEqualsWithDelta(120.0 * 0.25, $january['opportunity_cost'], 1e-6);
        self::assertEqualsWithDelta(25.0 - 30.0, $january['net_savings'], 1e-6, 'recharger au réseau coûte plus que ça ne rapporte');
    }

    public function testHalfSolarHalfGridSplitsTheOpportunityCost(): void
    {
        $result = $this->balance(
            $this->service([$this->grid()]),
            $this->battery(pvShare: 50),
            ['2026-01-01' => 0.0, '2026-02-01' => 100.0],
            ['2026-01-01' => 0.0, '2026-02-01' => 90.0],
        );

        // 50 kWh injectés perdus (0,04) + 50 kWh prélevés (0,25).
        self::assertEqualsWithDelta(50 * 0.04 + 50 * 0.25, $result['batteries'][0]['months'][0]['opportunity_cost'], 1e-6);
    }

    /** Sans ligne d'injection, stocker du surplus PV ne coûte aucun manque à gagner. */
    public function testAGridWithoutInjectionLineCostsNothingToChargeFromSolar(): void
    {
        $result = $this->balance(
            $this->service([$this->grid(withInjection: false)]),
            $this->battery(pvShare: 100),
            ['2026-01-01' => 0.0, '2026-02-01' => 120.0],
            ['2026-01-01' => 0.0, '2026-02-01' => 100.0],
        );

        $january = $result['batteries'][0]['months'][0];

        self::assertEqualsWithDelta(0.0, $january['opportunity_cost'], 1e-9);
        self::assertEqualsWithDelta($january['gross_savings'], $january['net_savings'], 1e-9);
    }

    // ── Profils de décharge ────────────────────────────────────────────────

    public function testDischargeProfilesPickDifferentRates(): void
    {
        $readings = [['2026-01-01' => 0.0, '2026-02-01' => 100.0], ['2026-01-01' => 0.0, '2026-02-01' => 100.0]];

        $rateFor = function (BatteryDischargeProfile $profile, ?int $t1Share = null) use ($readings): float {
            $result = $this->balance(
                $this->service([$this->grid()]),
                $this->battery(profile: $profile, t1Share: $t1Share),
                $readings[0],
                $readings[1],
            );

            return (float) $result['batteries'][0]['months'][0]['avoided_rate'];
        };

        self::assertEqualsWithDelta(0.25, $rateFor(BatteryDischargeProfile::T1), 1e-9, 'T1 : 0,20 + 0,05');
        self::assertEqualsWithDelta(0.15, $rateFor(BatteryDischargeProfile::T2), 1e-9, 'T2 : 0,10 + 0,05');
        // 60 % T1 : 0,6×0,20 + 0,4×0,10 + 0,05.
        self::assertEqualsWithDelta(0.21, $rateFor(BatteryDischargeProfile::Ratio, 60), 1e-9);
    }

    /**
     * `import_mix` suit la répartition RÉELLE des prélèvements du mois : 75 kWh en
     * T1 pour 25 en T2 donnent 0,75×0,20 + 0,25×0,10 + 0,05 = 0,225 €/kWh.
     */
    public function testImportMixProfileFollowsTheActualT1T2Split(): void
    {
        $service = $this->service(
            [$this->grid()],
            ['prelev_jour' => 75.0, 'prelev_nuit' => 25.0],
        );

        $result = $this->balance(
            $service,
            $this->battery(profile: BatteryDischargeProfile::ImportMix),
            ['2026-01-01' => 0.0, '2026-02-01' => 100.0],
            ['2026-01-01' => 0.0, '2026-02-01' => 100.0],
        );

        self::assertEqualsWithDelta(0.225, $result['batteries'][0]['months'][0]['avoided_rate'], 1e-9);
        self::assertEqualsWithDelta(0.75, $result['batteries'][0]['months'][0]['t1_share'], 1e-9);
    }

    /**
     * Mois sans le moindre prélèvement réseau : le mix est indéterminable. Le repli
     * est la convention 50/50 déjà retenue par les statistiques communautaires, et
     * non le tarif le plus cher ou le moins cher au hasard.
     */
    public function testImportMixFallsBackToFiftyFiftyWhenNothingWasImported(): void
    {
        $service = $this->service([$this->grid()], ['prelev_jour' => 0.0, 'prelev_nuit' => 0.0]);

        $result = $this->balance(
            $service,
            $this->battery(profile: BatteryDischargeProfile::ImportMix),
            ['2026-01-01' => 0.0, '2026-02-01' => 100.0],
            ['2026-01-01' => 0.0, '2026-02-01' => 100.0],
        );

        // 0,5×0,20 + 0,5×0,10 + 0,05 = 0,20.
        self::assertEqualsWithDelta(0.20, $result['batteries'][0]['months'][0]['avoided_rate'], 1e-9);
        self::assertEqualsWithDelta(0.5, $result['batteries'][0]['months'][0]['t1_share'], 1e-9);
    }

    // ── Rendement ──────────────────────────────────────────────────────────

    public function testEfficiencyIsComputedPerMonthAndCumulatively(): void
    {
        $result = $this->balance(
            $this->service([$this->grid()]),
            $this->battery(),
            ['2026-01-01' => 0.0, '2026-02-01' => 100.0, '2026-03-01' => 300.0],
            ['2026-01-01' => 0.0, '2026-02-01' => 90.0,  '2026-03-01' => 250.0],
            '2026-03-15',
        );

        $balance = $result['batteries'][0];

        self::assertEqualsWithDelta(0.90, $balance['months'][0]['efficiency'], 1e-9, 'janvier : 90/100');
        self::assertEqualsWithDelta(0.80, $balance['months'][1]['efficiency'], 1e-9, 'février : 160/200');
        // Cumulé : 250/300, et surtout PAS la moyenne de 0,90 et 0,80.
        self::assertEqualsWithDelta(250.0 / 300.0, $balance['efficiency'], 1e-4);
    }

    /**
     * Le rendement ne dépend d'aucun tarif : il doit rester calculé là où l'économie
     * ne l'est pas — c'est même la seule chose exploitable en contrat dynamique.
     */
    public function testEfficiencySurvivesAnUnsupportedContract(): void
    {
        $result = $this->balance(
            $this->service([$this->grid(mode: 'dynamic_hourly')]),
            $this->battery(),
            ['2026-01-01' => 0.0, '2026-02-01' => 100.0],
            ['2026-01-01' => 0.0, '2026-02-01' => 90.0],
        );

        $january = $result['batteries'][0]['months'][0];

        self::assertTrue($january['unsupported_mode']);
        self::assertNull($january['gross_savings'], 'aucun chiffre plutôt qu\'un chiffre faux');
        self::assertEqualsWithDelta(0.90, $january['efficiency'], 1e-9);
        self::assertTrue($result['batteries'][0]['has_unsupported_months']);
    }

    public function testAMonthWithoutAnyGridIsFlaggedRatherThanValuedAtZero(): void
    {
        $result = $this->balance(
            $this->service([]),
            $this->battery(),
            ['2026-01-01' => 0.0, '2026-02-01' => 100.0],
            ['2026-01-01' => 0.0, '2026-02-01' => 90.0],
        );

        $january = $result['batteries'][0]['months'][0];

        self::assertTrue($january['no_tariff']);
        self::assertNull($january['gross_savings']);
        self::assertTrue($result['batteries'][0]['has_untariffed_months']);
    }

    // ── Fenêtre de service et bornes ───────────────────────────────────────

    /**
     * Le mois en cours n'est PAS projeté : la valeur affichée est la consommation
     * mesurée à ce jour. Extrapoler gonflerait l'économie annoncée, et avec elle la
     * date d'amortissement.
     */
    public function testTheCurrentMonthIsNotProjectedForward(): void
    {
        $result = $this->balance(
            $this->service([$this->grid()]),
            $this->battery(),
            ['2026-01-01' => 0.0, '2026-01-16' => 50.0],
            ['2026-01-01' => 0.0, '2026-01-16' => 40.0],
            '2026-01-31',
        );

        $january = $result['batteries'][0]['months'][0];

        self::assertEqualsWithDelta(40.0, $january['discharge_kwh'], 1e-9, 'la moitié du mois ne doit pas être extrapolée en mois entier');
        self::assertTrue($january['partial']);
    }

    /**
     * `decommissioned_on` est une fin EXCLUE (#1) : une batterie déposée le 1er mars
     * a servi jusqu'en février, et mars ne doit pas figurer au bilan.
     */
    public function testDecommissioningDateIsExclusive(): void
    {
        $result = $this->balance(
            $this->service([$this->grid()]),
            $this->battery(decommissioned: '2026-03-01'),
            ['2026-01-01' => 0.0, '2026-02-01' => 100.0, '2026-03-01' => 200.0],
            ['2026-01-01' => 0.0, '2026-02-01' => 90.0,  '2026-03-01' => 180.0],
            '2026-06-01',
        );

        $months = array_column($result['batteries'][0]['months'], 'month');

        self::assertSame(['2026-01', '2026-02'], $months);
    }

    public function testNoReadingsMeansNoMonthsRatherThanZeroedOnes(): void
    {
        $result = $this->balance($this->service([$this->grid()]), $this->battery(), [], []);

        self::assertSame([], $result['batteries'][0]['months']);
        self::assertEqualsWithDelta(0.0, $result['batteries'][0]['gross_savings'], 1e-9);
        self::assertNull($result['batteries'][0]['efficiency']);
    }

    /** Un seul relevé ne donne aucune pente : rien n'est attribuable à un mois. */
    public function testASingleReadingYieldsNothing(): void
    {
        $result = $this->balance(
            $this->service([$this->grid()]),
            $this->battery(),
            ['2026-01-15' => 100.0],
            ['2026-01-15' => 90.0],
        );

        self::assertSame([], $result['batteries'][0]['months']);
    }

    // ── Bascule de grille en cours de mois ─────────────────────────────────

    /**
     * Contrat changé le 16 janvier : le taux du mois est la moyenne pondérée des
     * deux grilles, au prorata de leurs jours (15 puis 16 sur 31).
     */
    public function testAContractSwitchMidMonthWeightsBothGrids(): void
    {
        $cheap = new TariffGrid(
            id: 2,
            energyType: 'electricity',
            name: 'Nouveau contrat',
            validFrom: $this->at('2026-01-16'),
            validTo: null,
            lines: ['energy_t1' => new TariffLine('energy_t1', 0.10, ComponentKind::EnergyT1)],
        );
        $old = $this->grid(from: '2025-01-01', to: '2026-01-16');

        // Ordre de priorité décroissante, comme findActiveGridsBetween().
        $result = $this->balance(
            $this->service([$cheap, $old]),
            $this->battery(),
            ['2026-01-01' => 0.0, '2026-02-01' => 100.0],
            ['2026-01-01' => 0.0, '2026-02-01' => 100.0],
        );

        // 15 jours à 0,25 puis 16 jours à 0,10.
        $expected = (15 * 0.25 + 16 * 0.10) / 31;
        self::assertEqualsWithDelta($expected, $result['batteries'][0]['months'][0]['avoided_rate'], 1e-6);
    }

    /**
     * Une seule sous-période indexée au marché rend TOUT le mois non valorisable :
     * panacher un demi-mois chiffré avec un demi-mois inconnu donnerait un total
     * faux sans que rien ne le signale.
     */
    public function testASingleDynamicSegmentDisablesTheWholeMonth(): void
    {
        $result = $this->balance(
            $this->service([
                $this->grid(mode: 'dynamic_hourly', from: '2026-01-16'),
                $this->grid(from: '2025-01-01', to: '2026-01-16'),
            ]),
            $this->battery(),
            ['2026-01-01' => 0.0, '2026-02-01' => 100.0],
            ['2026-01-01' => 0.0, '2026-02-01' => 100.0],
        );

        self::assertTrue($result['batteries'][0]['months'][0]['unsupported_mode']);
        self::assertNull($result['batteries'][0]['months'][0]['gross_savings']);
    }

    // ── Parc ───────────────────────────────────────────────────────────────

    public function testFleetAggregatesQuantitiesAndAmountsAcrossBatteries(): void
    {
        $service = $this->service([$this->grid()]);

        $result = $service->balance([
            [
                'battery'  => $this->battery(),
                'readings' => new FakeBatteryReadings(
                    ['2026-01-01' => 0.0, '2026-02-01' => 100.0],
                    ['2026-01-01' => 0.0, '2026-02-01' => 90.0],
                ),
            ],
            [
                'battery'  => new Battery(
                    id: 2,
                    brand: 'Sonnen',
                    model: '',
                    capacityKwh: 5.0,
                    commissionedOn: $this->at('2026-01-01'),
                    dischargeProfile: BatteryDischargeProfile::T1,
                ),
                'readings' => new FakeBatteryReadings(
                    ['2026-01-01' => 0.0, '2026-02-01' => 50.0],
                    ['2026-01-01' => 0.0, '2026-02-01' => 40.0],
                ),
            ],
        ], $this->at('2026-02-15'));

        self::assertCount(2, $result['batteries']);

        $fleet = $result['fleet'];
        self::assertNotNull($fleet);
        self::assertEqualsWithDelta(150.0, $fleet['charge_kwh'], 1e-9);
        self::assertEqualsWithDelta(130.0, $fleet['discharge_kwh'], 1e-9);
        self::assertEqualsWithDelta(130.0 * 0.25, $fleet['gross_savings'], 1e-6);
        self::assertEqualsWithDelta(130.0 / 150.0, $fleet['efficiency'], 1e-4);
        // Les taux ne se moyennent pas entre batteries : leurs hypothèses diffèrent.
        self::assertNull($fleet['months'][0]['avoided_rate']);
    }

    // ── Un seul mois (tableau de bord) ─────────────────────────────────────

    /**
     * `fleetMonth()` doit rendre exactement ce que `balance()` rendrait pour ce
     * mois-là : le tableau de bord ne paie pas le calcul complet, mais il ne doit
     * pas non plus afficher autre chose que la page /batteries.
     */
    public function testFleetMonthMatchesTheFullBalanceForThatMonth(): void
    {
        $service  = $this->service([$this->grid()]);
        $readings = new FakeBatteryReadings(
            ['2026-01-01' => 0.0, '2026-02-01' => 120.0, '2026-03-01' => 300.0],
            ['2026-01-01' => 0.0, '2026-02-01' => 100.0, '2026-03-01' => 250.0],
        );
        $fleet = [['battery' => $this->battery(), 'readings' => $readings]];

        $full  = $service->balance($fleet, $this->at('2026-03-15'));
        $month = $service->fleetMonth($fleet, $this->at('2026-02-20'));

        self::assertNotNull($month);
        self::assertSame($full['batteries'][0]['months'][1], $month, 'février doit être identique par les deux voies');
    }

    public function testFleetMonthSumsEveryBatteryOfTheFleet(): void
    {
        $service = $this->service([$this->grid()]);

        $month = $service->fleetMonth([
            [
                'battery'  => $this->battery(),
                'readings' => new FakeBatteryReadings(
                    ['2026-01-01' => 0.0, '2026-02-01' => 100.0],
                    ['2026-01-01' => 0.0, '2026-02-01' => 90.0],
                ),
            ],
            [
                'battery'  => $this->battery(),
                'readings' => new FakeBatteryReadings(
                    ['2026-01-01' => 0.0, '2026-02-01' => 50.0],
                    ['2026-01-01' => 0.0, '2026-02-01' => 40.0],
                ),
            ],
        ], $this->at('2026-01-20'));

        self::assertNotNull($month);
        self::assertEqualsWithDelta(150.0, $month['charge_kwh'], 1e-9);
        self::assertEqualsWithDelta(130.0, $month['discharge_kwh'], 1e-9);
        self::assertEqualsWithDelta(130.0 * 0.25, $month['gross_savings'], 1e-6);
    }

    /** Batterie hors service ce mois-là : elle ne doit rien y peser. */
    public function testFleetMonthIgnoresABatteryOutOfServiceThatMonth(): void
    {
        $service = $this->service([$this->grid()]);

        $month = $service->fleetMonth([[
            'battery'  => $this->battery(commissioned: '2026-06-01'),
            'readings' => new FakeBatteryReadings(
                ['2026-01-01' => 0.0, '2026-02-01' => 100.0],
                ['2026-01-01' => 0.0, '2026-02-01' => 90.0],
            ),
        ]], $this->at('2026-01-20'));

        self::assertNull($month);
    }

    public function testFleetMonthWithoutAnyMeasureIsNull(): void
    {
        $month = $this->service([$this->grid()])->fleetMonth(
            [['battery' => $this->battery(), 'readings' => new FakeBatteryReadings()]],
            $this->at('2026-01-20'),
        );

        self::assertNull($month, 'aucune mesure : pas de card, plutôt que des zéros');
    }

    public function testAnEmptyFleetHasNoBalanceAtAll(): void
    {
        $result = $this->service([$this->grid()])->balance([], $this->at('2026-03-01'));

        self::assertSame([], $result['batteries']);
        self::assertNull($result['fleet'], 'un parc vide n\'a pas un bilan à zéro, il n\'en a pas');
    }

    /** Le bilan rappelle les hypothèses retenues : un chiffre supposé doit se lire comme tel. */
    public function testTheBalanceCarriesTheDeclaredAssumptions(): void
    {
        $result = $this->balance(
            $this->service([$this->grid()]),
            $this->battery(profile: BatteryDischargeProfile::Ratio, pvShare: 70, t1Share: 60),
            ['2026-01-01' => 0.0, '2026-02-01' => 100.0],
            ['2026-01-01' => 0.0, '2026-02-01' => 90.0],
        );

        self::assertSame(70, $result['batteries'][0]['pv_charge_share']);
        self::assertSame('ratio', $result['batteries'][0]['discharge_profile']);
        self::assertSame('EUR', $result['batteries'][0]['currency']);
    }
}
