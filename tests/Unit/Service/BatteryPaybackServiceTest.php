<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Domain\Battery;
use App\Domain\BatteryDischargeProfile;
use App\Service\BatteryPaybackService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Projection du temps d'amortissement (#26).
 *
 * C'est la réponse au « en combien de temps » de l'issue, et la plus facile à
 * rendre fausse : une moyenne prise sur un été promet un amortissement qui ne
 * viendra pas, un mois en cours compté comme complet l'éloigne sans raison.
 */
final class BatteryPaybackServiceTest extends TestCase
{
    private BatteryPaybackService $service;

    protected function setUp(): void
    {
        $this->service = new BatteryPaybackService();
    }

    private function at(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date, new DateTimeZone('UTC'));
    }

    private function battery(
        ?float $price = 7500.0,
        string $commissioned = '2026-01-01',
        ?string $warranty = null,
        ?int $ratedCycles = null,
        float $capacity = 10.0,
        ?float $usable = null,
    ): Battery {
        return new Battery(
            id: 1,
            brand: 'BYD',
            model: 'HVS',
            capacityKwh: $capacity,
            commissionedOn: $this->at($commissioned),
            usableCapacityKwh: $usable,
            purchasePrice: $price,
            warrantyUntil: $warranty !== null ? $this->at($warranty) : null,
            ratedCycles: $ratedCycles,
            dischargeProfile: BatteryDischargeProfile::T1,
        );
    }

    /**
     * Construit un bilan à la forme de {@see \App\Service\BatterySavingsService}.
     *
     * @param list<array{string, float|null, bool}> $months [mois, économie brute, incomplet]
     * @return array<string, mixed>
     */
    private function balance(array $months, float $dischargeKwh = 0.0): array
    {
        $rows  = [];
        $total = 0.0;
        foreach ($months as [$month, $savings, $partial]) {
            $rows[] = [
                'month'            => $month,
                'charge_kwh'       => null,
                'discharge_kwh'    => null,
                'avoided_rate'     => null,
                'injection_rate'   => null,
                't1_share'         => null,
                'gross_savings'    => $savings,
                'opportunity_cost' => null,
                'net_savings'      => $savings,
                'efficiency'       => null,
                'partial'          => $partial,
                'unsupported_mode' => false,
                'no_tariff'        => $savings === null,
                'currency'         => 'EUR',
            ];
            $total += $savings ?? 0.0;
        }

        return ['months' => $rows, 'gross_savings' => $total, 'discharge_kwh' => $dischargeKwh];
    }

    /**
     * @param int $count Nombre de mois consécutifs à partir de $from.
     * @return list<array{string, float|null, bool}>
     */
    private function series(string $from, int $count, float $savings): array
    {
        $months = [];
        $cursor = $this->at($from . '-01');
        for ($i = 0; $i < $count; $i++) {
            $months[] = [$cursor->format('Y-m'), $savings, false];
            $cursor   = $cursor->modify('+1 month');
        }

        return $months;
    }

    // ── Base de projection ─────────────────────────────────────────────────

    /**
     * Moins de 12 mois d'historique : la moyenne depuis la mise en service est
     * utilisée, et le biais saisonnier explicitement signalé. Sans ce drapeau, une
     * estimation faite en plein été promettrait un amortissement qui ne viendra pas.
     */
    public function testShortHistoryFallsBackToTheBiasedAverage(): void
    {
        $payback = $this->service->project(
            $this->battery(),
            $this->balance($this->series('2026-01', 6, 50.0)),
            $this->at('2026-07-15'),
        );

        self::assertSame(BatteryPaybackService::BASIS_SINCE_COMMISSIONING, $payback['basis']);
        self::assertTrue($payback['seasonally_biased']);
        self::assertSame(6, $payback['months_observed']);
        self::assertEqualsWithDelta(50.0, $payback['monthly_rate'], 1e-9);
    }

    /** Dès qu'une année est couverte, la fenêtre glissante prend le relais. */
    public function testTwelveMonthsOfHistorySwitchToTheRollingWindow(): void
    {
        $payback = $this->service->project(
            $this->battery(),
            $this->balance($this->series('2026-01', 12, 50.0)),
            $this->at('2027-01-15'),
        );

        self::assertSame(BatteryPaybackService::BASIS_ROLLING_12M, $payback['basis']);
        self::assertFalse($payback['seasonally_biased']);
        self::assertSame(12, $payback['months_observed']);
    }

    /**
     * Historique long : seuls les 12 derniers mois comptent. Une première année
     * exceptionnelle ne doit pas continuer de tirer la projection vers le haut.
     */
    public function testTheRollingWindowIgnoresOlderMonths(): void
    {
        $months = array_merge(
            $this->series('2025-01', 12, 200.0), // année faste, hors fenêtre
            $this->series('2026-01', 12, 50.0),  // les 12 derniers mois
        );

        $payback = $this->service->project($this->battery(), $this->balance($months), $this->at('2027-01-15'));

        self::assertSame(BatteryPaybackService::BASIS_ROLLING_12M, $payback['basis']);
        self::assertEqualsWithDelta(50.0, $payback['monthly_rate'], 1e-9);
    }

    /**
     * Un mois incomplet — le mois en cours, ou celui de l'installation — ne
     * représente pas un mois de fonctionnement. Le compter comme tel écraserait la
     * moyenne et éloignerait la date annoncée sans raison.
     */
    public function testPartialMonthsAreExcludedFromTheRate(): void
    {
        $months   = $this->series('2026-01', 3, 60.0);
        $months[] = ['2026-04', 5.0, true]; // mois en cours, à peine entamé

        $payback = $this->service->project($this->battery(), $this->balance($months), $this->at('2026-04-03'));

        self::assertEqualsWithDelta(60.0, $payback['monthly_rate'], 1e-9, 'le mois entamé ne doit pas peser dans le rythme');
        self::assertSame(3, $payback['months_observed']);
        // Il compte en revanche dans l'économie DÉJÀ acquise : c'est de l'argent réel.
        self::assertEqualsWithDelta(185.0, $payback['savings'], 1e-9);
    }

    /** Les mois non valorisés (contrat indexé, pas de grille) ne comptent pas comme zéro. */
    public function testUnvaluedMonthsAreSkippedRatherThanCountedAsZero(): void
    {
        $months   = $this->series('2026-01', 3, 60.0);
        $months[] = ['2026-04', null, false];

        $payback = $this->service->project($this->battery(), $this->balance($months), $this->at('2026-05-01'));

        self::assertEqualsWithDelta(60.0, $payback['monthly_rate'], 1e-9);
        self::assertSame(3, $payback['months_observed']);
    }

    public function testNoUsableMonthMeansNoProjection(): void
    {
        $payback = $this->service->project(
            $this->battery(),
            $this->balance([['2026-01', null, false], ['2026-02', 10.0, true]]),
            $this->at('2026-02-15'),
        );

        self::assertNull($payback['monthly_rate']);
        self::assertNull($payback['basis']);
        self::assertNull($payback['payback_on']);
        // Le reste à amortir, lui, reste connu : il ne dépend d'aucune projection.
        self::assertEqualsWithDelta(7490.0, $payback['remaining'], 1e-9);
    }

    // ── Date d'amortissement ───────────────────────────────────────────────

    /**
     * 7 500 € investis, 500 € déjà économisés, 100 €/mois : il reste 70 mois, soit
     * juillet 2032 à partir de janvier 2027.
     */
    public function testProjectsTheMonthWhenSavingsOvertakeTheInvestment(): void
    {
        $payback = $this->service->project(
            $this->battery(price: 7500.0),
            $this->balance($this->series('2026-01', 12, 500.0 / 12)),
            $this->at('2027-01-20'),
        );

        self::assertEqualsWithDelta(7000.0, $payback['remaining'], 1e-6);
        self::assertEqualsWithDelta(500.0 / 12, $payback['monthly_rate'], 0.01);
        self::assertNotNull($payback['payback_on']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}$/', $payback['payback_on']);
    }

    /** Arrondi au mois SUPÉRIEUR : le mois où le cumul DÉPASSE l'investissement. */
    public function testTheProjectedMonthIsRoundedUp(): void
    {
        // Reste 250 € à 100 €/mois = 2,5 mois → 3 mois après janvier 2027.
        $payback = $this->service->project(
            $this->battery(price: 1450.0),
            $this->balance($this->series('2026-01', 12, 100.0)),
            $this->at('2027-01-20'),
        );

        self::assertEqualsWithDelta(250.0, $payback['remaining'], 1e-6);
        self::assertEqualsWithDelta(2.5, $payback['months_remaining'], 1e-6);
        self::assertSame('2027-04', $payback['payback_on']);
    }

    public function testAnAlreadyPaidBackBatteryReportsNoFurtherDate(): void
    {
        $payback = $this->service->project(
            $this->battery(price: 500.0),
            $this->balance($this->series('2026-01', 12, 100.0)),
            $this->at('2027-01-20'),
        );

        self::assertTrue($payback['paid_back']);
        self::assertEqualsWithDelta(0.0, $payback['remaining'], 1e-9);
        self::assertEqualsWithDelta(100.0, $payback['progress_pct'], 1e-9, 'la progression est plafonnée à 100 %');
        self::assertNull($payback['payback_on']);
    }

    /**
     * Sans prix d'achat, il n'y a rien à amortir. Le trou est explicite plutôt
     * qu'un investissement supposé — l'économie et le rythme restent affichés.
     */
    public function testWithoutAPurchasePriceThereIsNothingToPayBack(): void
    {
        $payback = $this->service->project(
            $this->battery(price: null),
            $this->balance($this->series('2026-01', 12, 100.0)),
            $this->at('2027-01-20'),
        );

        self::assertNull($payback['investment']);
        self::assertNull($payback['remaining']);
        self::assertNull($payback['payback_on']);
        self::assertEqualsWithDelta(1200.0, $payback['savings'], 1e-9);
        self::assertEqualsWithDelta(100.0, $payback['monthly_rate'], 1e-9);
    }

    /**
     * Un rythme dérisoire donnerait une échéance dans un siècle : la batterie
     * aurait cédé bien avant. On le dit plutôt que d'afficher une date que
     * personne ne peut prendre au sérieux.
     */
    public function testAnAbsurdlyDistantPaybackIsReportedAsBeyondHorizon(): void
    {
        $payback = $this->service->project(
            $this->battery(price: 7500.0),
            $this->balance($this->series('2026-01', 12, 1.0)),
            $this->at('2027-01-20'),
        );

        self::assertTrue($payback['beyond_horizon']);
        self::assertNull($payback['payback_on']);
        self::assertEqualsWithDelta(7488.0, $payback['remaining'], 1e-6);
    }

    // ── Situer l'amortissement dans la vie de la batterie ──────────────────

    /** Un amortissement projeté après la fin de garantie n'en est pas vraiment un. */
    public function testAPaybackFallingAfterTheWarrantyIsFlagged(): void
    {
        $late = $this->service->project(
            $this->battery(price: 7500.0, warranty: '2028-01-01'),
            $this->balance($this->series('2026-01', 12, 100.0)),
            $this->at('2027-01-20'),
        );
        self::assertTrue($late['after_warranty'], '63 mois restants dépassent largement janvier 2028');

        $early = $this->service->project(
            $this->battery(price: 7500.0, warranty: '2040-01-01'),
            $this->balance($this->series('2026-01', 12, 100.0)),
            $this->at('2027-01-20'),
        );
        self::assertFalse($early['after_warranty']);
    }

    /** Cycles équivalents pleins : énergie déchargée ÷ capacité utile. */
    public function testEquivalentFullCyclesUseTheUsableCapacity(): void
    {
        $payback = $this->service->project(
            $this->battery(capacity: 10.0, usable: 9.0, ratedCycles: 6000),
            $this->balance($this->series('2026-01', 12, 100.0), dischargeKwh: 4500.0),
            $this->at('2027-01-20'),
        );

        self::assertEqualsWithDelta(500.0, $payback['cycles_used'], 1e-6, '4500 kWh ÷ 9 kWh utiles');
        self::assertSame(6000, $payback['rated_cycles']);
        self::assertEqualsWithDelta(8.3, $payback['cycles_pct'], 0.05);
    }

    public function testCyclesFallBackToNominalCapacityWhenUsableIsUnknown(): void
    {
        $payback = $this->service->project(
            $this->battery(capacity: 10.0, usable: null),
            $this->balance($this->series('2026-01', 12, 100.0), dischargeKwh: 5000.0),
            $this->at('2027-01-20'),
        );

        self::assertEqualsWithDelta(500.0, $payback['cycles_used'], 1e-6);
        self::assertNull($payback['cycles_pct'], 'aucun cycle annoncé : pas de pourcentage à afficher');
    }

    public function testNoDischargeMeansNoCycleCount(): void
    {
        $payback = $this->service->project(
            $this->battery(),
            $this->balance($this->series('2026-01', 12, 100.0), dischargeKwh: 0.0),
            $this->at('2027-01-20'),
        );

        self::assertNull($payback['cycles_used']);
    }
}
