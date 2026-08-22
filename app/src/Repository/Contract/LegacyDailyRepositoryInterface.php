<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use DateTimeImmutable;

/**
 * Lecture des deltas journaliers électricité/solaire consommés par
 * CostCalculationService. Seam de test : permet de substituer une fausse
 * implémentation sans base de données.
 *
 * @see \App\Repository\ElectricityReadingRepository implémentation concrète (modèle à registres).
 */
interface LegacyDailyRepositoryInterface
{
    /**
     * Deltas électricité/solaire du mois calendaire courant.
     * Renvoie [] si aucune donnée n'est disponible.
     *
     * @return array<string, mixed>
     */
    public function getMonthlyDeltas(): array;

    /**
     * Deltas électricité/solaire pour un mois calendaire donné.
     * Renvoie [] si aucune donnée n'est disponible pour ce mois.
     *
     * @return array<string, mixed>
     */
    public function getMonthlyDeltasForMonth(int $year, int $month): array;

    /**
     * Deltas entre deux instants QUELCONQUES, bornes interpolées (#241) — même
     * forme de sortie que {@see getMonthlyDeltasForMonth()}, dont le mois n'est
     * qu'un cas particulier. `[]` si aucun relevé ne tombe dans l'intervalle.
     *
     * @param string $from Borne de début, format DB 'Y-m-d H:i:s' (UTC).
     * @param string $to   Borne de fin, même format.
     * @return array<string, mixed>
     */
    public function getDeltasBetween(string $from, string $to): array;

    /**
     * Deltas par registre entre bornes CONSÉCUTIVES, en une passe (#2).
     *
     * Sert à répartir la consommation d'une période entre ses sous-périodes
     * tarifaires selon ce qui a réellement été consommé, plutôt qu'au prorata des
     * jours — lequel suppose une consommation uniforme, hypothèse fausse dès qu'il
     * y a du chauffage électrique ou une production solaire saisonnière.
     *
     * Une seule passe plutôt que N appels à {@see getDeltasBetween()} : chaque
     * instant n'est interpolé qu'une fois, et la borne de fin d'un intervalle est
     * la borne de début du suivant.
     *
     * Les quantités rendues sont des deltas BRUTS, non renormalisés : l'appelant
     * qui connaît déjà le total de la période s'en sert comme poids relatifs, ce
     * qui absorbe l'écart d'arrondi et le clamp des bornes hors plage.
     *
     * @param list<string> $boundaries N+1 instants croissants, format DB 'Y-m-d H:i:s' (UTC).
     * @return list<array{prelev_jour: float, prelev_nuit: float, injec_jour: float, injec_nuit: float, solar: float}>
     *         N entrées, une par intervalle `[boundaries[i], boundaries[i+1]]`.
     *         `[]` si moins de deux bornes ou si aucun registre n'est exploitable.
     */
    public function getDeltasByBoundaries(array $boundaries): array;

    /**
     * Consommation IMPORT (T1+T2) ventilée par heure sur [$from, $to], pour
     * croiser avec un prix dynamique horaire (tarif dynamique).
     *
     * @return array<int, array{hour: string, import_kwh: float}>
     */
    public function getHourlyImportDeltas(DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * Consommation IMPORT (T1+T2) ventilée par quart d'heure aligné sur [$from, $to],
     * pour croiser avec un prix dynamique quart-horaire (mode 'dynamic_quarter', #230).
     *
     * `native` vaut false pour un créneau dont une part du kWh provient d'un
     * intervalle de relevés plus long que 15 min (conso étalée au prorata du temps,
     * faute de mesure intra-horaire). L'appelant s'en sert pour décider s'il peut
     * facturer au quart d'heure ou doit se replier sur le calcul horaire.
     *
     * @return array<int, array{quarter: string, import_kwh: float, native: bool}>
     */
    public function getQuarterImportDeltas(DateTimeImmutable $from, DateTimeImmutable $to): array;
}
