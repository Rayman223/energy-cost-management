<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Profil de préférences d'un utilisateur (table `user_profiles`) : localisation,
 * devise, zone de marché et paramètres de tarification électricité.
 *
 * Objet-valeur immuable couvrant la lecture ET l'écriture du profil
 * (`UserRepository::getProfile` / `updateProfile`). Simple porteur de données :
 * le bornage (marge) et la normalisation du mode de tarification restent du
 * ressort du repository — voir la note sur l'asymétrie lecture/écriture dans
 * `UserRepository::updateProfile`.
 *
 * Pas de taux de TVA ici : sa source unique est `tariff_grids.vat_rate` (#232),
 * ce qui le versionne par période de validité de la grille.
 */
final class UserProfile
{
    /**
     * Modes de tarification électricité valides (colonne `user_profiles.pricing_mode`).
     *
     * @var list<string>
     */
    public const PRICING_MODES = ['fixed', 'dynamic_hourly', 'dynamic_quarter'];

    public function __construct(
        public readonly ?string $country,
        public readonly string $timezone,
        public readonly string $currency,
        public readonly ?string $biddingZone,
        public readonly string $pricingMode,
        public readonly float $supplierMarkupPerKwh,
        public readonly string $locale,
    ) {
    }

    /**
     * Profil par défaut (compte sans ligne `user_profiles`, ou fallback UI).
     * Valeurs alignées sur les défauts historiques du repository et du formulaire.
     */
    public static function defaults(): self
    {
        return new self(
            country: null,
            timezone: 'UTC',
            currency: 'EUR',
            biddingZone: null,
            pricingMode: 'fixed',
            supplierMarkupPerKwh: 0.0,
            locale: 'fr',
        );
    }
}
