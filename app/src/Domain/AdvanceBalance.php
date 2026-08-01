<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Bilan acomptes/consommation d'une énergie sur une période (#241).
 *
 * `paid` est toujours connu (il ne dépend que des barèmes saisis) ; `cost` peut
 * manquer — pas de relevés, pas de grille tarifaire sur la période. Ces deux cas
 * ne sont pas fusionnés en « 0 € » : un solde qui compterait un coût inconnu
 * comme nul annoncerait un remboursement imaginaire. La ligne est alors rendue
 * avec sa raison, et exclue du total.
 *
 * Value object immuable, sans dépendance.
 */
final class AdvanceBalance
{
    /**
     * @param string      $energyType  electricity | gas | water
     * @param float       $paid        Total prélevé sur la période (somme des échéances).
     * @param int         $dueCount    Nombre d'échéances comptées.
     * @param float|null  $cost        Coût total TTC calculé, null si indisponible.
     * @param string|null $unavailable Raison brute de l'indisponibilité du coût.
     * @param string|null $currency    Devise de la grille ayant servi au calcul.
     * @param bool        $partialData Le coût ne porte que sur une FRACTION de la
     *        période — relevés arrêtés avant la fin, ou bornes extrapolées. Le
     *        montant reste affiché, mais comparé à des acomptes couvrant, eux,
     *        toute la période : le solde penche alors artificiellement.
     * @param bool        $partialAdvances Le déséquilibre inverse : les barèmes ne
     *        couvrent qu'une partie des mois de la période (contrat démarré en cours
     *        de route), et un coût de douze mois est comparé à sept prélèvements.
     * @param bool        $hasSchedule Un barème de cette énergie couvre au moins un
     *        jour de la fenêtre. Distinct de `dueCount > 0` : une fenêtre plus courte
     *        qu'un mois — ou tombant entre deux prélèvements — n'attrape aucune
     *        échéance alors que le contrat court bel et bien (#254). Sans cette
     *        nuance, les deux cas se confondent en « aucun acompte saisi », et l'écran
     *        conseille d'ajouter un barème qui existe déjà.
     */
    public function __construct(
        public readonly string $energyType,
        public readonly float $paid,
        public readonly int $dueCount,
        public readonly ?float $cost = null,
        public readonly ?string $unavailable = null,
        public readonly ?string $currency = null,
        public readonly bool $partialData = false,
        public readonly bool $partialAdvances = false,
        public readonly bool $hasSchedule = false,
    ) {
    }

    /**
     * Solde : positif ⇒ trop payé, remboursement attendu ; négatif ⇒ complément à
     * payer. `null` tant que le coût n'est pas calculable.
     */
    public function balance(): ?float
    {
        return $this->cost !== null ? $this->paid - $this->cost : null;
    }

    /** Cette énergie peut-elle entrer dans le total ? */
    public function isComparable(): bool
    {
        return $this->cost !== null;
    }

    /**
     * Barème actif sur la fenêtre, mais aucune date de prélèvement n'y tombe : le
     * montant payé est nul sans qu'aucun acompte ne manque. Le remède n'est pas
     * d'en saisir un — il existe — mais d'élargir la période (#254).
     */
    public function hasScheduleWithoutInstalment(): bool
    {
        return $this->hasSchedule && $this->dueCount === 0;
    }
}
