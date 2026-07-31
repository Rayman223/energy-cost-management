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
     * Toutes les grilles chevauchant l'intervalle [$from, $to] (bornes incluses),
     * triées par priorité décroissante : surcharge personnelle d'abord, puis
     * `valid_from` décroissant — même ordre que {@see findActiveGrid()}, de sorte
     * que la première grille active un jour donné est celle qu'aurait retenue
     * findActiveGrid() pour ce jour.
     *
     * Alimente le découpage d'une période de calcul en sous-périodes tarifaires
     * ({@see \App\Service\TariffPeriodSplitter}).
     *
     * @return list<TariffGrid>
     */
    public function findActiveGridsBetween(string $energyType, DateTimeImmutable $from, DateTimeImmutable $to): array;

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
     * @param list<array{key: string, amount: float, kind: string, label: ?string}> $lines
     * @param string $pricingMode Électricité : mode du contrat porté par CETTE grille (#245).
     */
    public function saveGrid(
        string $energyType,
        string $name,
        DateTimeImmutable $validFrom,
        ?DateTimeImmutable $validTo,
        array $lines,
        ?float $pcsCoefficient = null,
        ?string $country = null,
        string $currency = 'EUR',
        bool $shared = false,
        float $vatRate = 21.0,
        string $pricingMode = TariffGrid::PRICING_MODE_DEFAULT,
    ): int;

    /**
     * L'utilisateur dispose-t-il d'au moins une grille (perso ou catalogue) dont le
     * contrat est indexé sur le prix de marché ? Sert à décider de l'accès aux pages
     * qui n'ont de sens qu'en tarif dynamique (#245).
     */
    public function hasDynamicGrid(string $energyType = 'electricity'): bool;
}
