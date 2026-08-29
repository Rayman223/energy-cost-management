<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\ReadingGranularity;
use App\Domain\TariffGrid;
use App\Repository\Contract\TariffRepositoryInterface;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Politique de plafonnement des index électricité : quel créneau aligné s'applique
 * à un relevé donné, et dans quel fuseau il se calcule.
 *
 * Le mode de tarification appartient à la grille, donc au contrat, et il est
 * versionné par valid_from/valid_to (#245) : un même utilisateur a pu être en tarif
 * fixe en 2024 et en quart-horaire en 2026. La granularité se résout donc **relevé
 * par relevé** (issue #10) et non une fois pour toutes — sans quoi un import
 * d'historique traversant une bascule appliquerait partout la granularité du jour.
 *
 * Deux fabriques, un seul type pour les appelants :
 *   - {@see self::fromTariffs()} — la grille active à la date du relevé décide ;
 *   - {@see self::constant()} — granularité figée, utilisée quand le kill-switch
 *     serveur `dynamic_prices.enabled` est à false (l'app se comporte alors comme si
 *     chaque utilisateur était en tarif fixe, cf. {@see \App\Support\DynamicPricing})
 *     et par les tests.
 */
final class ReadingGranularityPolicy
{
    /**
     * Mémoïsation par jour local : {@see self::forMoment()} est appelée une fois par
     * ligne d'import (jusqu'à 200 000), et `findActiveGrid()` est une requête SQL.
     *
     * @var array<string, ReadingGranularity> jour local 'Y-m-d' => granularité
     */
    private array $cache = [];

    private readonly DateTimeZone $tz;

    private function __construct(
        private readonly ?TariffRepositoryInterface $tariffs,
        private readonly ?ReadingGranularity $constant,
        private readonly string $timezone,
    ) {
        $this->tz = new DateTimeZone($timezone);
    }

    /** Granularité figée, quelle que soit la date du relevé. */
    public static function constant(ReadingGranularity $granularity, string $timezone = 'UTC'): self
    {
        return new self(null, $granularity, $timezone);
    }

    /** Granularité dérivée de la grille électricité active à la date de chaque relevé. */
    public static function fromTariffs(TariffRepositoryInterface $tariffs, string $timezone = 'UTC'): self
    {
        return new self($tariffs, null, $timezone);
    }

    /**
     * Créneau applicable au relevé horodaté $moment.
     *
     * Aucune grille active (utilisateur sans tarif, relevé antérieur à sa première
     * grille) ⇒ tarif fixe ⇒ plafond le plus strict.
     */
    public function forMoment(DateTimeImmutable $moment): ReadingGranularity
    {
        if ($this->constant !== null) {
            return $this->constant;
        }

        // Jour LOCAL de l'utilisateur : la validité d'une grille se compare en dates
        // calendaires, alors que $moment est un instant UTC. Sans cette conversion, un
        // relevé du 31/12 à 23:30 heure locale (22:30 UTC) serait rattaché au 1er
        // janvier, donc à la mauvaise grille un jour de bascule.
        $day = $moment->setTimezone($this->tz)->format('Y-m-d');

        if (isset($this->cache[$day])) {
            return $this->cache[$day];
        }

        // Le jour local est ensuite passé en UTC : seule sa DATE calendaire compte pour
        // la validité d'une grille, et minuit dans un fuseau à l'est d'UTC tomberait la
        // veille une fois comparé à un `valid_from` construit en UTC
        // ({@see TariffGrid::isActiveOn()} compare des instants, pas des chaînes).
        $grid = $this->tariffs?->findActiveGrid('electricity', new DateTimeImmutable($day, new DateTimeZone('UTC')));

        return $this->cache[$day] = ReadingGranularity::forPricingMode(
            $grid->pricingMode ?? TariffGrid::PRICING_MODE_DEFAULT
        );
    }

    /** Fuseau dans lequel les créneaux sont délimités (repli 'UTC' neutre). */
    public function timezone(): string
    {
        return $this->timezone;
    }
}
