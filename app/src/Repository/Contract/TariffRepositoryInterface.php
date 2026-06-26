<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Domain\TariffGrid;
use DateTimeImmutable;

/**
 * Résolution des grilles tarifaires actives consommées par
 * CostCalculationService. Seam de test (sans base de données).
 *
 * @see \App\Repository\TariffRepository implémentation concrète (PDO/MySQL).
 */
interface TariffRepositoryInterface
{
    /**
     * Grille tarifaire active pour un type d'énergie à une date donnée.
     * Renvoie la grille démarrée le plus récemment et valide à cette date.
     */
    public function findActiveGrid(string $energyType, ?DateTimeImmutable $on = null): ?TariffGrid;

    /**
     * Coefficient PCS le plus récent disponible pour un type d'énergie,
     * à ou avant une date de référence. Repli quand la grille active n'en définit pas.
     */
    public function findMostRecentPcs(string $energyType, DateTimeImmutable $before): ?float;

    /**
     * Toutes les grilles d'un type d'énergie, triées par date de début décroissante.
     *
     * @return TariffGrid[]
     */
    public function findAll(string $energyType): array;

    /**
     * Crée une grille tarifaire et ses lignes. Renvoie l'identifiant créé.
     *
     * @param array<string, mixed> $lines
     */
    public function saveGrid(
        string $energyType,
        string $name,
        DateTimeImmutable $validFrom,
        ?DateTimeImmutable $validTo,
        array $lines,
        ?float $pcsCoefficient = null,
    ): int;
}
