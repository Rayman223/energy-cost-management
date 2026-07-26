<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\UserProfile;
use PHPUnit\Framework\TestCase;

final class UserProfileTest extends TestCase
{
    public function testExposesAllFields(): void
    {
        $profile = new UserProfile(
            country: 'FR',
            timezone: 'Europe/Paris',
            currency: 'EUR',
            biddingZone: '10YFR-RTE------C',
            pricingMode: 'dynamic_hourly',
            supplierMarkupPerKwh: 0.0123456,
            locale: 'fr',
        );

        self::assertSame('FR', $profile->country);
        self::assertSame('Europe/Paris', $profile->timezone);
        self::assertSame('EUR', $profile->currency);
        self::assertSame('10YFR-RTE------C', $profile->biddingZone);
        self::assertSame('dynamic_hourly', $profile->pricingMode);
        self::assertSame(0.0123456, $profile->supplierMarkupPerKwh);
        self::assertSame('fr', $profile->locale);
    }

    public function testDefaultsMatchHistoricalValues(): void
    {
        $profile = UserProfile::defaults();

        self::assertNull($profile->country);
        // Défaut neutre depuis #200 (ex-'Europe/Brussels') : l'UI pré-remplit le
        // fuseau du navigateur, UTC ne restant que par repli.
        self::assertSame('UTC', $profile->timezone);
        self::assertSame('EUR', $profile->currency);
        self::assertNull($profile->biddingZone);
        self::assertSame('fixed', $profile->pricingMode);
        self::assertSame(0.0, $profile->supplierMarkupPerKwh);
        self::assertSame('fr', $profile->locale);
    }

    public function testPricingModesWhitelistIsClosed(): void
    {
        self::assertSame(
            ['fixed', 'dynamic_hourly', 'dynamic_quarter'],
            UserProfile::PRICING_MODES,
        );
        // Le mode par défaut est toujours un mode valide.
        self::assertContains(UserProfile::defaults()->pricingMode, UserProfile::PRICING_MODES);
    }
}
