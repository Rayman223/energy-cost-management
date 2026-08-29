<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use DateTimeImmutable;

/**
 * Lecture des agrégats communautaires publiés par /stats (#8). Seam de test :
 * permet de faire tourner StatisticsService sans base de données.
 *
 * CONTRAT DE CONFIDENTIALITÉ — il porte tout le poids de cette fonctionnalité,
 * puisque c'est la première lecture inter-tenants du projet :
 *
 *   1. Aucune méthode ne renvoie une valeur attribuable à un foyer identifiable.
 *      Toute méthode « par pays » n'émet une ligne que si le pays compte au moins
 *      {@see StatisticsRepositoryInterface::MIN_HOUSEHOLDS} foyers contributeurs ;
 *      les pays sous le seuil sont fondus dans un bucket « ZZ » qui n'est
 *      lui-même publié que s'il franchit ce même seuil.
 *   2. Un foyer retiré (`user_profiles.stats_opt_out`) sort du numérateur ET du
 *      dénominateur : il ne fait plus franchir le seuil à son pays.
 *   3. Les seules méthodes acceptant un `$userId` ne renvoient QUE les données de
 *      ce foyer — l'appelant les a déjà authentifiées comme siennes.
 *
 * @see \App\Repository\StatisticsRepository implémentation concrète (PDO/MySQL).
 */
interface StatisticsRepositoryInterface
{
    /**
     * Seuil de k-anonymat. Sous ce nombre de foyers contributeurs, aucun agrégat
     * n'est publié pour le groupe.
     */
    public const MIN_HOUSEHOLDS = 5;

    /** Code réservé au regroupement des pays sous le seuil (« Autres »). */
    public const OTHER_BUCKET = 'ZZ';

    /**
     * Tarif indicatif au kWh par pays, dérivé des grilles actives (pas des relevés).
     *
     * Une ligne par couple (bucket, devise) : le projet ne convertit jamais les
     * devises, additionner des € et des zł n'aurait aucun sens. Les grilles
     * dynamiques sont exclues — leur part énergie vient du marché, les compter
     * publierait « réseau et taxes seuls » comme prix du kWh.
     *
     * @return list<array{bucket: string, currency: string, households: int,
     *     ttc_per_kwh: float, htva_per_kwh: float, fixed_year_ttc: float}>
     */
    public function unitRateByCountry(): array;

    /**
     * Même périmètre que {@see unitRateByCountry()}, ventilé par catégorie
     * tarifaire (energy / distribution / taxes …) pour la comparaison par poste.
     *
     * @return list<array{bucket: string, currency: string, category: string,
     *     households: int, ttc_per_kwh: float}>
     */
    public function unitRateByCountryAndCategory(): array;

    /**
     * Consommation électrique annualisée moyenne par foyer et par pays, en kWh.
     *
     * @return list<array{bucket: string, households: int, value: float}>
     */
    public function electricityUsageByCountry(DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * Consommation gaz ou eau annualisée moyenne par foyer et par pays, en m³.
     *
     * Le gaz reste en m³ et n'est pas converti en kWh : la conversion dépend du
     * coefficient PCS de chaque grille, souvent absent — un PCS moyen inventerait
     * une précision qui n'existe pas.
     *
     * @param string $energyType 'gas' ou 'water'.
     * @return list<array{bucket: string, households: int, value: float}>
     */
    public function utilityUsageByCountry(string $energyType, DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * Répartition des contrats électricité entre tarif fixe et tarif indexé.
     *
     * @return list<array{bucket: string, fixed: int, dynamic: int}>
     */
    public function pricingModeByCountry(): array;

    /**
     * Prix spot moyen par zone de marché et par résolution, en €/kWh HTVA.
     *
     * Aucun k-anonymat : ce sont des données publiques ENTSO-E. Et surtout aucune
     * jointure vers `user_profiles` — la répartition des foyers par zone serait
     * ré-identifiante sur les petites zones.
     *
     * La résolution fait partie de la clé : certaines zones publient PT60M ET
     * PT15M sur les mêmes heures, les mélanger compterait deux fois la même heure.
     *
     * @return list<array{zone: string, resolution_min: int, avg_price: float,
     *     points: int, first_at: string, last_at: string}>
     */
    public function spotPriceByZone(DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * Portée du corpus publié : foyers contributeurs et pays couverts.
     *
     * @return array{households: int, countries: int}
     */
    public function coverage(): array;

    /**
     * Série mensuelle de consommation électrique d'UN foyer, en kWh.
     *
     * Calculée par le même chemin SQL que {@see electricityUsageByCountry()} :
     * comparer une valeur interpolée à une moyenne chaînée produirait un écart
     * parasite de quelques pourcents impossible à expliquer.
     *
     * Ni filtre d'opt-out ni k-anonymat ici : un foyer retiré des statistiques
     * publiques continue de voir ses propres chiffres.
     *
     * @return array<string, float> Map 'Y-m' => kWh du mois.
     */
    public function householdMonthlySeries(int $userId, DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * Série mensuelle moyenne d'un pays, en kWh par foyer.
     *
     * k-anonymat appliqué MOIS PAR MOIS : un pays qui franchit le seuil sur
     * l'année peut ne pas le franchir en juillet. Les mois sous le seuil sont
     * absents de la map (trou dans la courbe, jamais un zéro).
     *
     * @return array<string, float> Map 'Y-m' => kWh moyens du mois.
     */
    public function countryMonthlySeries(string $country, DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * Position d'une consommation annualisée dans la distribution de son pays.
     *
     * null quand le pays compte moins de {@see self::MIN_HOUSEHOLDS} foyers
     * contributeurs : afficher « 100ᵉ percentile » sur deux foyers désignerait
     * l'autre.
     *
     * @return array{below: int, total: int}|null
     */
    public function usageRankInCountry(
        string $country,
        float $value,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): ?array;

    /**
     * Position d'un tarif au kWh dans la distribution des grilles de son pays.
     *
     * @return array{below: int, total: int}|null
     */
    public function rateRankInCountry(string $country, float $rate): ?array;
}
