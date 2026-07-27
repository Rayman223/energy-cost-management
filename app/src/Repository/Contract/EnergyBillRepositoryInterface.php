<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Domain\EnergyBill;

/**
 * Lecture/écriture des montants facturés saisis, consommée par BillReconciliationService
 * (#229). Seam de test : permet une fausse implémentation sans base de données.
 *
 * Le périmètre utilisateur est porté par l'implémentation (scope dur en constructeur),
 * pas par les signatures — aucune méthode ne prend d'`userId`, il n'y a donc pas de
 * chemin par lequel un appelant lirait les factures d'un autre compte.
 *
 * @see \App\Repository\EnergyBillRepository implémentation concrète (PDO/MySQL).
 */
interface EnergyBillRepositoryInterface
{
    /**
     * Factures saisies pour un type d'énergie, de la plus récente à la plus ancienne.
     *
     * Paginé : chaque facture affichée coûte un recalcul complet du mois côté service
     * (relevés horaires + prix de marché). Sans borne, un historique de plusieurs années
     * rendrait la page inutilisable.
     *
     * @param int $limit  Nombre maximum de factures renvoyées.
     * @param int $offset Décalage, multiple de $limit.
     * @return list<EnergyBill>
     */
    public function listFor(string $energyType, int $limit, int $offset = 0): array;

    /** Nombre total de factures saisies pour ce type d'énergie (calcul du nombre de pages). */
    public function countFor(string $energyType): int;

    /**
     * Crée ou met à jour la facture du mois (clé unique user + énergie + année + mois).
     *
     * @param float|null $amountHtva Part énergie HTVA ; null = non renseignée.
     * @param float|null $amountTtc  Part énergie TTC ; null = non renseignée.
     */
    public function upsert(
        string $energyType,
        int $year,
        int $month,
        ?float $amountHtva,
        ?float $amountTtc,
        string $note = '',
    ): void;

    /** Supprime une facture de l'utilisateur courant. Sans effet si l'id ne lui appartient pas. */
    public function delete(int $id): void;
}
