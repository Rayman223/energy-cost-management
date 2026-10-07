<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\ReadingGranularity;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Politique de plafonnement des index électricité : quel créneau aligné s'applique
 * à un relevé, et dans quel fuseau il se calcule.
 *
 * La granularité est **constante** depuis #93. Elle dérivait auparavant du mode de
 * tarification de la grille active à la date de chaque relevé (issue #10), ce qui
 * confondait deux notions distinctes : le pas auquel un compteur MESURE et la
 * résolution à laquelle un contrat FACTURE. Conséquence, un utilisateur en tarif
 * fixe — ou, depuis #93, en tarif indexé au mois — ne pouvait pas pousser sa courbe
 * de charge : un seul index par jour était retenu.
 *
 * Le plafond ne protège donc plus un modèle de facturation, seulement l'unicité du
 * relevé dans son créneau. Les gardes d'unicité exacte d'horodatage et de croissance
 * chronologique des index, elles, sont inchangées.
 *
 * Deux fabriques, un seul type pour les appelants :
 *   - {@see self::electricityDefault()} — le plafond des index électricité
 *     (quart d'heure), à utiliser par tous les chemins d'écriture élec ;
 *   - {@see self::constant()} — granularité figée explicite, pour les tests et les
 *     appelants qui visent un autre créneau.
 */
final class ReadingGranularityPolicy
{
    private function __construct(
        private readonly ReadingGranularity $granularity,
        private readonly string $timezone,
    ) {
        // Validation AU PLUS TÔT de l'identifiant de fuseau, même si seule la chaîne
        // est exposée : les appelants construisent la politique dans leur bootstrap
        // gardé, où un identifiant illisible doit dégrader proprement (503 JSON côté
        // API) au lieu de lever plus tard, au milieu d'une écriture.
        new DateTimeZone($timezone);
    }

    /** Granularité figée, quelle que soit la date du relevé. */
    public static function constant(ReadingGranularity $granularity, string $timezone = 'UTC'): self
    {
        return new self($granularity, $timezone);
    }

    /**
     * Plafond des index électricité : un relevé par registre et par MTU de 15 min,
     * quel que soit le mode de tarification du contrat (#93).
     */
    public static function electricityDefault(string $timezone = 'UTC'): self
    {
        return new self(ReadingGranularity::QuarterHour, $timezone);
    }

    /**
     * Créneau applicable au relevé horodaté $moment.
     *
     * Le paramètre reste dans la signature : il documente que le plafond s'évalue
     * relevé par relevé, et laisse la porte ouverte à une politique datée sans
     * toucher aux appelants.
     */
    public function forMoment(DateTimeImmutable $moment): ReadingGranularity
    {
        unset($moment);

        return $this->granularity;
    }

    /** Fuseau dans lequel les créneaux sont délimités (repli 'UTC' neutre). */
    public function timezone(): string
    {
        return $this->timezone;
    }
}
