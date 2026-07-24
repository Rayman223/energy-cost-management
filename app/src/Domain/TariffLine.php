<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Ligne d'une grille tarifaire : un montant, son type de composante (kind) et un
 * libellé custom optionnel. Value object immuable.
 *
 * $label NULL = libellé du catalogue déduit de $key (TariffLineCatalog / i18n).
 * $label renseigné = champ personnalisé ajouté par l'utilisateur.
 *
 * $category NULL = catégorie d'affichage dérivée de la clé/kind (rétro-compat des
 * lignes créées avant les catégories) ; renseignée = rangement choisi par l'utilisateur.
 */
final class TariffLine
{
    public function __construct(
        public readonly string $key,
        public readonly float $amount,
        public readonly ComponentKind $kind,
        public readonly ?string $label = null,
        public readonly int $sortOrder = 0,
        public readonly ?TariffCategory $category = null,
    ) {
    }

    /** Catégorie effective : celle choisie, sinon le défaut dérivé de la clé/kind. */
    public function category(): TariffCategory
    {
        return $this->category ?? TariffCategory::defaultForLine($this->key, $this->kind);
    }
}
