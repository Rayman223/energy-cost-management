<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Devises manipulées par l'application (`user_profiles.currency`,
 * `tariff_grids.currency`) et leur symbole d'affichage.
 *
 * Source unique du symbole, partagée par le domaine (unités composées d'un
 * barème, cf. {@see ComponentKind::unit()}) et par le formatage localisé
 * ({@see \App\I18n\Formatter}). Sans elle, « € » se retrouve codé en dur dans
 * les unités et un utilisateur en CHF lit des « €/kWh ».
 */
final class Currency
{
    /** Code ISO 4217 → symbole d'affichage. */
    private const SYMBOLS = [
        'EUR' => '€', 'USD' => '$', 'GBP' => '£', 'CHF' => 'CHF',
        'PLN' => 'zł', 'SEK' => 'kr', 'DKK' => 'kr', 'NOK' => 'kr', 'CZK' => 'Kč', 'HUF' => 'Ft',
    ];

    /** Devise retenue quand aucune n'est renseignée (défaut de la base). */
    public const DEFAULT = 'EUR';

    /**
     * Symbole d'une devise. Repli sur le code ISO (« RON ») pour une devise hors
     * table : plus lisible qu'un symbole approximatif, et toujours compréhensible.
     */
    public static function symbol(string $code = self::DEFAULT): string
    {
        return self::SYMBOLS[$code] ?? $code;
    }
}
