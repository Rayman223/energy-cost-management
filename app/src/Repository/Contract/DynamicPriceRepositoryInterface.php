<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use DateTimeImmutable;

/**
 * Lecture des prix day-ahead consommée par CostCalculationService pour le tarif
 * dynamique. Seam de test : permet une fausse implémentation sans base de données.
 *
 * @see \App\Repository\DynamicPriceRepository implémentation concrète (PDO/MySQL).
 */
interface DynamicPriceRepositoryInterface
{
    /**
     * Prix moyen €/kWh (HTVA) par heure sur [$from, $to[.
     * Agrège les intervalles de 15 min en une moyenne horaire.
     *
     * @return array<string, float> Map 'Y-m-d H:00:00' => prix moyen €/kWh HTVA.
     */
    public function getAveragePriceByHour(DateTimeImmutable $from, DateTimeImmutable $to): array;

    /** Borne supérieure des prix disponibles (statut/affichage), ou null si vide. */
    public function latestPeriodEnd(): ?DateTimeImmutable;
}
