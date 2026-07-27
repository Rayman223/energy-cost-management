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
     * Prix horaire NATIF €/kWh (HTVA) sur [$from, $to[ : uniquement les points de
     * résolution 60 min (PT60M) fournis par le marché, sans agrégation.
     *
     * ENTSO-E publie un prix horaire propre, distinct de la moyenne des points
     * 15 min ; ce prix doit être utilisé tel quel pour le tarif dynamique horaire.
     * Renvoie une map vide s'il n'existe pas de série horaire native (l'appelant
     * peut alors se rabattre sur {@see getAveragePriceByHour()}).
     *
     * @return array<string, float> Map 'Y-m-d H:00:00' => prix €/kWh HTVA.
     */
    public function getHourlyPrices(DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * Prix NATIF au quart d'heure €/kWh (HTVA) sur [$from, $to[ : uniquement les
     * points de résolution 15 min (PT15M), sans agrégation.
     *
     * Sert le mode de tarification 'dynamic_quarter' (#230). Renvoie une map vide
     * quand la zone ne publie pas de série 15 min sur la période : l'appelant se
     * rabat alors sur le calcul horaire ({@see getHourlyPrices()}) plutôt que
     * d'interpoler un prix intra-horaire qui n'existe pas.
     *
     * @return array<string, float> Map 'Y-m-d H:i:00' => prix €/kWh HTVA.
     */
    public function getQuarterPrices(DateTimeImmutable $from, DateTimeImmutable $to): array;

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
