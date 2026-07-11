<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Catégorie d'affichage d'une ligne tarifaire, choisie par l'utilisateur.
 *
 * Contrairement au groupe historique dérivé du {@see ComponentKind::group()},
 * la catégorie est une donnée persistée : l'utilisateur peut ranger un champ
 * dans la catégorie de son choix (ex. déplacer une distribution hors des taxes).
 *
 * Jeu fermé et ordonné (l'ordre des cases = ordre d'affichage). Une ligne sans
 * catégorie explicite (legacy, template fourni, API plate) retombe sur
 * {@see self::defaultForKind()}, qui reproduit le groupe historique — donc
 * jamais `distribution` par défaut : cette catégorie est purement opt-in.
 *
 * Libellés affichés : clés i18n `tariffs.group_<value>`.
 */
enum TariffCategory: string
{
    case Energy       = 'energy';
    case Distribution = 'distribution';
    case Fixed        = 'fixed';
    case Taxes        = 'taxes';
    case Injection    = 'injection';

    /**
     * Valeurs dans l'ordre d'affichage.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /** Repli sûr : une valeur inconnue devient la catégorie des taxes. */
    public static function fromStringOrDefault(string $value): self
    {
        return self::tryFrom($value) ?? self::Taxes;
    }

    /**
     * Catégorie par défaut d'une ligne dépourvue de catégorie explicite : reprend
     * le groupe historique dérivé du kind (energy/fixed/taxes/injection), pour
     * préserver l'affichage des grilles créées avant l'introduction des catégories.
     * Repli sûr si un futur groupe n'a pas de catégorie correspondante.
     */
    public static function defaultForKind(ComponentKind $kind): self
    {
        return self::fromStringOrDefault($kind->group());
    }

    /**
     * Catégories pertinentes pour un type d'énergie : affichées même vides (pour y
     * ranger un champ). L'injection ne concerne que l'électricité ; les autres
     * s'appliquent à toutes les énergies. Une catégorie non pertinente mais portant
     * déjà des lignes reste affichée par l'appelant.
     *
     * @return list<string>
     */
    public static function relevantFor(string $energyType): array
    {
        return array_values(array_filter(
            self::values(),
            static fn (string $v): bool => $v !== self::Injection->value || $energyType === 'electricity',
        ));
    }
}
