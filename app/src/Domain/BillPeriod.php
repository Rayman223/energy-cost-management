<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Un mois du rapprochement facture (#229) : ce que le fournisseur a facturé, ce que
 * l'application a calculé, et la décomposition qui relie les deux à la formule du contrat.
 *
 * L'équation d'un mois, telle que la produit la boucle horaire de CostCalculationService :
 *
 *     computedTtc = coefficient × indexedBaseTtc + offsetTtc × coveredKwh + uncoveredTtc
 *
 * `uncoveredTtc` regroupe les heures sans prix de marché, facturées au tarif classique :
 * constante vis-à-vis de la formule, elle doit être retirée du montant facturé avant de
 * résoudre, sans quoi elle serait absorbée par le coefficient.
 *
 * Value object immuable, sans dépendance : le cœur algébrique se teste sans base.
 */
final class BillPeriod
{
    /**
     * @param float $billedTtc      Part énergie facturée TTC (déjà convertie si l'utilisateur n'a saisi que le HTVA).
     * @param float $indexedBaseTtc Σ kWh × spot × (1 + TVA/100) sur les heures couvertes — le terme que multiplie le coefficient.
     * @param float $coveredKwh     Σ kWh sur les heures couvertes — le terme que multiplie l'offset.
     * @param float $uncoveredTtc   Coût TTC des heures sans prix de marché — constante hors formule.
     * @param float $computedTtc    Part énergie calculée par l'application avec la formule actuelle.
     */
    public function __construct(
        public readonly int $year,
        public readonly int $month,
        public readonly float $billedTtc,
        public readonly float $indexedBaseTtc,
        public readonly float $coveredKwh,
        public readonly float $uncoveredTtc,
        public readonly float $computedTtc,
    ) {
    }

    /** Écart facturé − calculé : positif = l'application sous-estime la facture. */
    public function gap(): float
    {
        return $this->billedTtc - $this->computedTtc;
    }

    /**
     * Prix moyen indexé TTC du mois (€/kWh), soit indexedBaseTtc / coveredKwh.
     *
     * C'est l'indicateur de conditionnement du système : deux mois qui partagent le même
     * prix moyen donnent deux équations colinéaires et ne permettent PAS de séparer le
     * coefficient de l'offset. Null si aucune heure couverte.
     */
    public function averageIndexedPrice(): ?float
    {
        return $this->coveredKwh > 0.0 ? $this->indexedBaseTtc / $this->coveredKwh : null;
    }

    /** Part énergie qu'aurait facturée un couple (coefficient, offset) donné. */
    public function costFor(float $coefficient, float $offsetTtc): float
    {
        return $coefficient * $this->indexedBaseTtc + $offsetTtc * $this->coveredKwh + $this->uncoveredTtc;
    }

    /** Le mois contraint-il la formule ? Un mois sans heure couverte n'apporte aucune équation. */
    public function isUsable(): bool
    {
        return $this->coveredKwh > 0.0 && $this->indexedBaseTtc > 0.0;
    }

    /** Clé de période 'YYYY-MM'. */
    public function periodKey(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }
}
