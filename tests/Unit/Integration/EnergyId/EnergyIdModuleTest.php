<?php

declare(strict_types=1);

namespace Tests\Unit\Integration\EnergyId;

use App\Integration\EnergyId\EnergyIdModule;
use App\Integration\IntegrationStatus;
use PHPUnit\Framework\TestCase;

final class EnergyIdModuleTest extends TestCase
{
    public function testKeyAndGlobalEnabledDefault(): void
    {
        self::assertSame('energyid', $this->module([])->key());
        self::assertTrue($this->module([])->isGloballyEnabled());
        self::assertTrue($this->module(['energyid' => []])->isGloballyEnabled());
        self::assertFalse($this->module(['energyid' => ['enabled' => false]])->isGloballyEnabled());
    }

    public function testDefaultSettingsDerivesDeviceId(): void
    {
        self::assertSame(
            ['device_id' => 'manage-energy-u42'],
            $this->module([])->defaultSettings(42),
        );

        self::assertSame(
            ['device_id' => 'custom-u7'],
            $this->module(['energyid' => ['device' => ['deviceId' => 'custom']]])->defaultSettings(7),
        );
    }

    public function testStatusForDisabledWhenNullOrOff(): void
    {
        $module = $this->module([]);

        $whenNull = $module->statusFor(42, null);
        self::assertSame(IntegrationStatus::DISABLED, $whenNull->state);
        self::assertSame('integration.energyid.off_hint', $whenNull->lines[0]['key']);
        self::assertSame('manage-energy-u42', $whenNull->lines[0]['code']);

        $whenOff = $module->statusFor(42, ['enabled' => false, 'settings' => []]);
        self::assertSame(IntegrationStatus::DISABLED, $whenOff->state);
    }

    public function testStatusForPendingAndActive(): void
    {
        $module = $this->module([]);

        $pending = $module->statusFor(1, ['enabled' => true, 'settings' => ['device_id' => 'dev-u1']]);
        self::assertSame(IntegrationStatus::PENDING, $pending->state);
        self::assertSame('integration.energyid.pending', $pending->lines[0]['key']);
        self::assertSame('integration.energyid.device', $pending->lines[1]['key']);
        self::assertSame('dev-u1', $pending->lines[1]['code']);

        $active = $module->statusFor(1, [
            'enabled'  => true,
            'settings' => ['device_id' => 'dev-u1', 'claimed_at' => '2026-07-18 01:15:00'],
        ]);
        self::assertSame(IntegrationStatus::ACTIVE, $active->state);
        self::assertSame('integration.energyid.claimed', $active->lines[0]['key']);
        self::assertSame(['date' => '2026-07-18 01:15:00'], $active->lines[0]['params']);
    }

    public function testInterpretReportsClaimsOnFirstSuccessOnce(): void
    {
        $reports = [['result' => ['ok' => true]]];

        $fresh = EnergyIdModule::interpretReports($reports, null, '2026-07-18 01:15:00');
        self::assertTrue($fresh['ok']);
        self::assertSame('2026-07-18 01:15:00', $fresh['settingsPatch']['claimed_at']);
        self::assertNull($fresh['claimLog']);

        // Déjà réclamé : pas de nouveau claimed_at (parité WHERE claimed_at IS NULL).
        $already = EnergyIdModule::interpretReports($reports, '2026-07-01 00:00:00', '2026-07-18 01:15:00');
        self::assertTrue($already['ok']);
        self::assertSame([], $already['settingsPatch']);
    }

    public function testInterpretReportsLogsNotClaimed(): void
    {
        $reports = [[
            'result' => ['ok' => false, 'type' => 'not_claimed', 'claimCode' => 'ABC', 'claimUrl' => 'https://x/claim'],
        ]];

        $out = EnergyIdModule::interpretReports($reports, null, '2026-07-18 01:15:00');
        self::assertFalse($out['ok']);
        self::assertSame([], $out['settingsPatch']);
        self::assertNotNull($out['claimLog']);
        self::assertStringContainsString('ABC', $out['claimLog']);
        self::assertStringContainsString('https://x/claim', $out['claimLog']);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function module(array $config): EnergyIdModule
    {
        return new EnergyIdModule($config);
    }
}
