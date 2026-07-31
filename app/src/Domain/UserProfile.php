<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Profil de préférences d'un utilisateur (table `user_profiles`) : localisation,
 * devise, zone de marché et marge fournisseur.
 *
 * Objet-valeur immuable couvrant la lecture ET l'écriture du profil
 * (`UserRepository::getProfile` / `updateProfile`). Simple porteur de données :
 * le bornage de la marge reste du ressort du repository — voir la note sur
 * l'asymétrie lecture/écriture dans `UserRepository::updateProfile`.
 *
 * Ni taux de TVA ni mode de tarification ici : leur source unique est
 * `tariff_grids` (#232, #245), ce qui les versionne par période de validité de la
 * grille. La zone de marché, elle, reste au profil : elle est géographique et non
 * contractuelle.
 */
final class UserProfile
{
    /**
     * @param ?string $advancesPeriodFrom Début 'Y-m-d' du dernier bilan d'acomptes
     *        consulté, restitué au retour de l'utilisateur (#241). null = aucun
     *        mémorisé, la page retombe sur son défaut. Écrit par
     *        {@see \App\Repository\UserRepository::setAdvancesPeriod()} et NON par
     *        `updateProfile` : le formulaire de /account n'a pas à connaître cette
     *        préférence, ni à l'écraser en enregistrant le reste du profil.
     * @param ?string $advancesPeriodTo Fin 'Y-m-d', EXCLUE de la période.
     */
    public function __construct(
        public readonly ?string $country,
        public readonly string $timezone,
        public readonly string $currency,
        public readonly ?string $biddingZone,
        public readonly float $supplierMarkupPerKwh,
        public readonly string $locale,
        public readonly ?string $advancesPeriodFrom = null,
        public readonly ?string $advancesPeriodTo = null,
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
            supplierMarkupPerKwh: 0.0,
            locale: 'fr',
        );
    }
}
