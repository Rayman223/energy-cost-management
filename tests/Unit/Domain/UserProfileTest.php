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
            supplierMarkupPerKwh: 0.0123456,
            locale: 'fr',
        );

        self::assertSame('FR', $profile->country);
        self::assertSame('Europe/Paris', $profile->timezone);
        self::assertSame('EUR', $profile->currency);
        self::assertSame('10YFR-RTE------C', $profile->biddingZone);
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
        self::assertSame(0.0, $profile->supplierMarkupPerKwh);
        self::assertSame('fr', $profile->locale);
    }

    /**
     * Le mode de tarification a quitté le profil pour la grille (#245) : il est
     * versionné par période de validité et n'a plus rien à faire ici. Garde
     * anti-régression — le réintroduire recréerait la double source que l'issue a
     * supprimée, comme l'avait été `vat_rate` avant lui (#232).
     */
    public function testPricingModeNoLongerLivesOnTheProfile(): void
    {
        self::assertFalse(property_exists(UserProfile::class, 'pricingMode'));
        self::assertFalse(defined(UserProfile::class . '::PRICING_MODES'));
    }
}
