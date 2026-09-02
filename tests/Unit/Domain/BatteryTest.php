<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Battery;
use App\Domain\BatteryDischargeProfile;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Les prédicats de {@see Battery} décident de ce qui entre dans le bilan : une
 * batterie comptée en service un jour de trop valorise une économie qu'elle n'a
 * pas produite, et un ratio mal converti fausse chaque euro annoncé.
 */
final class BatteryTest extends TestCase
{
    private function at(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC'));
    }

    private function battery(
        string $brand = 'BYD',
        string $model = 'HVS 10.2',
        float $capacity = 10.24,
        string $commissioned = '2026-01-01',
        ?string $decommissioned = null,
        ?float $usable = null,
        ?string $warranty = null,
        int $pvShare = 100,
        BatteryDischargeProfile $profile = BatteryDischargeProfile::ImportMix,
        ?int $t1Share = null,
    ): Battery {
        return new Battery(
            id: 1,
            brand: $brand,
            model: $model,
            capacityKwh: $capacity,
            commissionedOn: $this->at($commissioned),
            decommissionedOn: $decommissioned !== null ? $this->at($decommissioned) : null,
            usableCapacityKwh: $usable,
            purchasePrice: 7500.0,
            warrantyUntil: $warranty !== null ? $this->at($warranty) : null,
            ratedCycles: 6000,
            pvChargeShare: $pvShare,
            dischargeProfile: $profile,
            dischargeT1Share: $t1Share,
        );
    }

    // ── Période de service : borne de fin EXCLUE (#1) ──────────────────────

    public function testInServiceOnTheCommissioningDay(): void
    {
        $battery = $this->battery(commissioned: '2026-01-01');

        self::assertTrue($battery->isInServiceOn($this->at('2026-01-01')));
    }

    public function testNotYetInServiceTheDayBeforeCommissioning(): void
    {
        $battery = $this->battery(commissioned: '2026-01-01');

        self::assertFalse($battery->isInServiceOn($this->at('2025-12-31')));
        // Pas encore installée n'est pas déposée : les deux états sont distincts.
        self::assertFalse($battery->isDecommissionedOn($this->at('2025-12-31')));
    }

    public function testDecommissioningDateIsTheFirstDayOutOfService(): void
    {
        $battery = $this->battery(commissioned: '2026-01-01', decommissioned: '2026-07-01');

        // La veille : encore en service. Le jour même : déjà déposée (#1).
        self::assertTrue($battery->isInServiceOn($this->at('2026-06-30')));
        self::assertFalse($battery->isInServiceOn($this->at('2026-07-01')));
        self::assertTrue($battery->isDecommissionedOn($this->at('2026-07-01')));
    }

    public function testOpenEndedBatteryStaysInServiceIndefinitely(): void
    {
        $battery = $this->battery(commissioned: '2026-01-01', decommissioned: null);

        self::assertTrue($battery->isInServiceOn($this->at('2099-12-31')));
        self::assertFalse($battery->isDecommissionedOn($this->at('2099-12-31')));
    }

    // ── Garantie ───────────────────────────────────────────────────────────

    public function testWarrantyExpiresOnItsOwnDate(): void
    {
        $battery = $this->battery(warranty: '2036-01-01');

        self::assertFalse($battery->isOutOfWarrantyOn($this->at('2035-12-31')));
        self::assertTrue($battery->isOutOfWarrantyOn($this->at('2036-01-01')));
    }

    public function testNoWarrantyDateNeverReportsExpiry(): void
    {
        self::assertFalse($this->battery(warranty: null)->isOutOfWarrantyOn($this->at('2099-01-01')));
    }

    // ── Capacité ───────────────────────────────────────────────────────────

    public function testEffectiveCapacityPrefersTheUsableOne(): void
    {
        self::assertSame(9.2, $this->battery(capacity: 10.24, usable: 9.2)->effectiveCapacityKwh());
    }

    public function testEffectiveCapacityFallsBackToNominal(): void
    {
        self::assertSame(10.24, $this->battery(capacity: 10.24, usable: null)->effectiveCapacityKwh());
    }

    // ── Libellé : une batterie doit rester désignable dans une liste ────────

    public function testLabelJoinsBrandAndModel(): void
    {
        self::assertSame('BYD HVS 10.2', $this->battery()->label());
    }

    public function testLabelKeepsTheOnlyFieldFilled(): void
    {
        self::assertSame('BYD', $this->battery(brand: 'BYD', model: '')->label());
        self::assertSame('HVS 10.2', $this->battery(brand: '', model: 'HVS 10.2')->label());
    }

    public function testLabelFallsBackToCapacityWhenUnnamed(): void
    {
        self::assertSame('10.24 kWh', $this->battery(brand: '', model: '', capacity: 10.24)->label());
        self::assertSame('10 kWh', $this->battery(brand: ' ', model: ' ', capacity: 10.0)->label());
    }

    // ── Ratios : la saisie est en %, le calcul en fraction ─────────────────

    public function testChargeRatiosSplitBetweenSolarAndGrid(): void
    {
        $battery = $this->battery(pvShare: 70);

        self::assertSame(0.7, $battery->pvChargeRatio());
        self::assertEqualsWithDelta(0.3, $battery->gridChargeRatio(), 1e-9);
    }

    /**
     * Une ligne écrite hors application (import SQL, migration manuelle) ne passe
     * pas par la validation du formulaire. Sans clamp, un ratio hors bornes
     * produirait une économie négative ou supérieure à l'énergie déchargée, sans
     * rien signaler à l'écran.
     */
    public function testOutOfRangeSharesAreClampedRatherThanTrusted(): void
    {
        self::assertSame(1.0, $this->battery(pvShare: 150)->pvChargeRatio());
        self::assertSame(0.0, $this->battery(pvShare: -20)->pvChargeRatio());
    }

    public function testDischargeT1RatioIsOnlyExposedForTheRatioProfile(): void
    {
        $ratio = $this->battery(profile: BatteryDischargeProfile::Ratio, t1Share: 60);
        self::assertSame(0.6, $ratio->dischargeT1Ratio());

        // Les autres profils résolvent leur part T1 au moment du calcul : renvoyer
        // une valeur ici laisserait croire qu'elle a été saisie.
        foreach ([BatteryDischargeProfile::ImportMix, BatteryDischargeProfile::T1, BatteryDischargeProfile::T2] as $profile) {
            self::assertNull($this->battery(profile: $profile, t1Share: 60)->dischargeT1Ratio());
        }
    }

    public function testRatioProfileWithoutShareStaysNull(): void
    {
        self::assertNull($this->battery(profile: BatteryDischargeProfile::Ratio, t1Share: null)->dischargeT1Ratio());
    }

    // ── Hydratation depuis la base ─────────────────────────────────────────

    public function testFromRowParsesDatesInUtcAndNullableColumns(): void
    {
        $battery = Battery::fromRow([
            'id'                  => '7',
            'brand'               => 'BYD',
            'model'               => 'HVS 10.2',
            'capacity_kwh'        => '10.240',
            'usable_capacity_kwh' => null,
            'purchase_price'      => '7500.00',
            'commissioned_on'     => '2026-03-15',
            'decommissioned_on'   => null,
            'warranty_until'      => '2036-03-15',
            'rated_cycles'        => null,
            'pv_charge_share'     => '80',
            'discharge_profile'   => 'ratio',
            'discharge_t1_share'  => '55',
            'note'                => 'onduleur hybride',
        ]);

        self::assertSame(7, $battery->id);
        self::assertSame(10.24, $battery->capacityKwh);
        self::assertNull($battery->usableCapacityKwh);
        self::assertNull($battery->ratedCycles);
        self::assertSame('UTC', $battery->commissionedOn->getTimezone()->getName());
        self::assertSame('2026-03-15', $battery->commissionedOn->format('Y-m-d'));
        self::assertNull($battery->decommissionedOn);
        self::assertSame(BatteryDischargeProfile::Ratio, $battery->dischargeProfile);
        self::assertSame(0.55, $battery->dischargeT1Ratio());
    }

    /**
     * Un profil inconnu en base — colonne élargie puis rétrécie, écriture manuelle
     * — ne doit pas faire exploser la page : il retombe sur le profil qui n'ajoute
     * aucune hypothèse.
     */
    public function testUnknownDischargeProfileFallsBackToImportMix(): void
    {
        self::assertSame(
            BatteryDischargeProfile::ImportMix,
            BatteryDischargeProfile::fromStringOrDefault('quantum')
        );
    }

    public function testOnlyTheRatioProfileRequiresAT1Share(): void
    {
        self::assertTrue(BatteryDischargeProfile::Ratio->requiresT1Share());

        foreach ([BatteryDischargeProfile::ImportMix, BatteryDischargeProfile::T1, BatteryDischargeProfile::T2] as $profile) {
            self::assertFalse($profile->requiresT1Share());
        }
    }

    public function testServiceLabelShowsTheOpenEndAsEllipsis(): void
    {
        self::assertSame('2026-01-01 → …', $this->battery(commissioned: '2026-01-01')->serviceLabel());
        self::assertSame(
            '2026-01-01 → 2026-07-01',
            $this->battery(commissioned: '2026-01-01', decommissioned: '2026-07-01')->serviceLabel()
        );
    }
}
