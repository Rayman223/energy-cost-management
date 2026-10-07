<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\LoadWeighting;
use App\Domain\MonthlyIndexedPrice;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Réduit les cotations day-ahead d'un mois à UN prix unitaire moyen pondéré (#93).
 *
 * C'est la réponse à la question posée par l'issue : « comment font les fournisseurs
 * pour n'avoir qu'un seul prix unitaire ? ». Une moyenne, mais **pondérée** — le
 * paramètre `Belpex_RLP_M` des contrats belges à prix variable est la moyenne des
 * cotations du mois pondérée par le profil de consommation RLP, et non la moyenne
 * arithmétique. Les deux diffèrent sensiblement : un profil résidentiel consomme
 * davantage aux heures chères, si bien que la moyenne arithmétique sous-estime le coût.
 *
 * Service **pur** : aucune E/S, tout lui est fourni. L'algèbre reste ainsi testable sans
 * base, comme {@see SpotFormulaFitter} et {@see SpotFormulaResolver}.
 *
 * Chaque mois civil est traité INDÉPENDAMMENT : une période à cheval sur deux mois donne
 * deux prix distincts, comme deux factures distinctes.
 */
final class MonthlyIndexedPriceCalculator
{
    /**
     * Part minimale (%) de la consommation devant être réellement MESURÉE au pas de
     * 15 min pour pondérer par la courbe réelle.
     *
     * Même valeur que le seuil de résolution de {@see CostCalculationService}, mais
     * sémantique différente : celui-ci décide de la provenance du POIDS, pas de la
     * résolution de facturation. Les confondre reviendrait à croire qu'un relevé
     * mensuel suffit à pondérer une courbe horaire.
     */
    public const ACTUAL_LOAD_MIN_PCT = 80.0;

    /** Couverture calendaire minimale (%) des cotations pour qu'un mois soit exploitable. */
    public const PRICE_COVERAGE_MIN_PCT = 80.0;

    /**
     * Prix unitaire de chaque mois civil couvert par la fenêtre.
     *
     * Un mois dont les cotations sont trop lacunaires est **absent** du résultat plutôt
     * que calculé sur un échantillon biaisé : l'appelant le facture alors au tarif
     * fournisseur, comme il le fait déjà pour un créneau sans prix.
     *
     * @param DateTimeImmutable $from Début de la fenêtre (UTC), inclus.
     * @param DateTimeImmutable $to   Fin de la fenêtre (UTC), exclue.
     * @param list<array{source: string, resolution_min: int, prices: array<string, float>}> $candidates
     *        Séries de cotations €/kWh HTVA, **ordonnées par préférence**. La première
     *        dont la couverture suffit est retenue pour le mois entier : mélanger deux
     *        résolutions dans une même moyenne la biaiserait, les créneaux n'ayant plus
     *        le même poids temporel.
     * @param list<array{slot: string, import_kwh: float, native: bool}> $load
     *        Courbe de charge de l'utilisateur. `native` distingue un relevé réellement
     *        pris à ce pas d'un étalement au prorata — ce dernier est plat dans la
     *        journée, donc équivalent au baseload tout en prétendant à la précision.
     * @param array<string, float> $profileWeights Profil de charge standard, mêmes clés
     *        que les cotations. Vide = indisponible, la cascade saute ce niveau.
     * @param ?string $profileCode Code du profil standard, pour l'affichage.
     * @return array<string, MonthlyIndexedPrice> 'Y-m' => prix du mois.
     */
    public function perMonth(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        array $candidates,
        array $load,
        array $profileWeights = [],
        ?string $profileCode = null,
    ): array {
        $result = [];

        foreach ($this->monthsBetween($from, $to) as $month => $window) {
            $chosen = $this->chooseSeries($month, $window, $candidates);
            if ($chosen === null) {
                continue;
            }

            [$prices, $source, $resolutionMin, $priceCoverage] = $chosen;

            $weighted = $this->weightedPrice($month, $prices, $resolutionMin, $load, $profileWeights);

            $result[$month] = new MonthlyIndexedPrice(
                month: $month,
                priceHtva: $weighted['price'],
                weighting: $weighted['weighting'],
                priceSource: $source,
                resolutionMin: $resolutionMin,
                weightCoveragePct: $weighted['coverage_pct'],
                priceCoveragePct: $priceCoverage,
                partial: $window['partial'],
                profileCode: $weighted['weighting'] === LoadWeighting::StandardProfile ? $profileCode : null,
            );
        }

        return $result;
    }

    /**
     * Mois civils intersectant la fenêtre, avec la durée réellement couverte.
     *
     * `partial` vaut vrai dès que la fenêtre ne couvre pas le mois entier — cas d'un
     * mois en cours, dont le prix est provisoire : `Belpex_RLP_M` n'est publié qu'une
     * fois le mois clos.
     *
     * @return array<string, array{minutes: float, partial: bool}>
     */
    private function monthsBetween(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        if ($to <= $from) {
            return [];
        }

        $utc    = new DateTimeZone('UTC');
        $cursor = new DateTimeImmutable($from->setTimezone($utc)->format('Y-m-01 00:00:00'), $utc);
        $end    = $to->setTimezone($utc);
        $months = [];

        while ($cursor < $end) {
            $next       = $cursor->modify('+1 month');
            $sliceStart = max($cursor, $from->setTimezone($utc));
            $sliceEnd   = min($next, $end);

            if ($sliceEnd > $sliceStart) {
                $minutes      = ($sliceEnd->getTimestamp() - $sliceStart->getTimestamp()) / 60.0;
                $fullMinutes  = ($next->getTimestamp() - $cursor->getTimestamp()) / 60.0;
                $months[$cursor->format('Y-m')] = [
                    'minutes' => $minutes,
                    'partial' => $minutes < $fullMinutes,
                ];
            }

            $cursor = $next;
        }

        return $months;
    }

    /**
     * Première série dont la couverture calendaire du mois atteint le seuil.
     *
     * La couverture se mesure sur le CALENDRIER (créneaux présents / créneaux attendus),
     * pas sur la consommation : une cotation manquante à 3 h du matin manque, que
     * l'utilisateur ait consommé ou non à cette heure-là. C'est ce qui distingue ce
     * seuil de celui de la pondération.
     *
     * @param array{minutes: float, partial: bool} $window
     * @param list<array{source: string, resolution_min: int, prices: array<string, float>}> $candidates
     * @return ?array{array<string, float>, string, int, float}
     */
    private function chooseSeries(string $month, array $window, array $candidates): ?array
    {
        foreach ($candidates as $candidate) {
            $resolution = $candidate['resolution_min'];
            if ($resolution <= 0) {
                continue;
            }

            $prices = $this->pricesOfMonth($month, $candidate['prices']);
            if ($prices === []) {
                continue;
            }

            $expected = $window['minutes'] / $resolution;
            $coverage = $expected > 0.0 ? min(100.0, count($prices) / $expected * 100.0) : 0.0;

            if ($coverage >= self::PRICE_COVERAGE_MIN_PCT) {
                return [$prices, $candidate['source'], $resolution, $coverage];
            }
        }

        return null;
    }

    /**
     * Cascade de pondération, appliquée au mois : courbe réelle → profil standard →
     * baseload. Chaque niveau n'est retenu que si ses poids recouvrent effectivement
     * des créneaux cotés ; sinon le suivant prend la main.
     *
     * @param array<string, float> $prices
     * @param list<array{slot: string, import_kwh: float, native: bool}> $load
     * @param array<string, float> $profileWeights
     * @return array{price: float, weighting: LoadWeighting, coverage_pct: float}
     */
    private function weightedPrice(string $month, array $prices, int $resolutionMin, array $load, array $profileWeights): array
    {
        $monthLoad  = array_values(array_filter($load, static fn (array $r): bool => str_starts_with($r['slot'], $month)));
        $totalKwh   = array_sum(array_column($monthLoad, 'import_kwh'));
        $nativeKwh  = array_sum(array_column(
            array_filter($monthLoad, static fn (array $r): bool => $r['native'] === true),
            'import_kwh'
        ));
        $nativeShare = $totalKwh > 0.0 ? $nativeKwh / $totalKwh * 100.0 : 0.0;

        if ($nativeShare >= self::ACTUAL_LOAD_MIN_PCT) {
            // Seuls les créneaux NATIFS pondèrent : un créneau étalé au prorata porterait
            // une forme de consommation inventée.
            $native = array_filter($monthLoad, static fn (array $r): bool => $r['native'] === true);

            $price = $this->average($prices, $this->alignWeights(
                array_combine(
                    array_column($native, 'slot'),
                    array_column($native, 'import_kwh'),
                ),
                $resolutionMin,
            ));
            if ($price !== null) {
                return ['price' => $price, 'weighting' => LoadWeighting::ActualLoad, 'coverage_pct' => $nativeShare];
            }
        }

        $monthProfile = $this->alignWeights($this->pricesOfMonth($month, $profileWeights), $resolutionMin);
        if ($monthProfile !== []) {
            $price = $this->average($prices, $monthProfile);
            if ($price !== null) {
                return [
                    'price'        => $price,
                    'weighting'    => LoadWeighting::StandardProfile,
                    'coverage_pct' => min(100.0, count(array_intersect_key($monthProfile, $prices)) / count($prices) * 100.0),
                ];
            }
        }

        return [
            'price'        => array_sum($prices) / count($prices),
            'weighting'    => LoadWeighting::Baseload,
            'coverage_pct' => 0.0,
        ];
    }

    /**
     * Moyenne de $prices pondérée par $weights, sur leurs clés COMMUNES.
     *
     * `null` si aucun poids ne tombe sur un créneau coté, ou si leur somme est nulle :
     * l'appelant passe alors au niveau suivant de la cascade plutôt que de produire une
     * division par zéro ou une moyenne portant sur une poignée de créneaux.
     *
     * @param array<string, float> $prices
     * @param array<string, float> $weights
     */
    private function average(array $prices, array $weights): ?float
    {
        $sumWeights = 0.0;
        $sumWeighted = 0.0;

        foreach ($weights as $slot => $weight) {
            if ($weight <= 0.0 || !isset($prices[$slot])) {
                continue;
            }
            $sumWeights  += $weight;
            $sumWeighted += $weight * $prices[$slot];
        }

        return $sumWeights > 0.0 ? $sumWeighted / $sumWeights : null;
    }

    /**
     * Ramène des poids à la résolution de la série de cotations, en SOMMANT ceux qui
     * retombent dans le même créneau.
     *
     * Indispensable dès que les deux granularités diffèrent, et pour les deux niveaux de
     * la cascade : un profil (ou une courbe) au pas de 15 min confronté à une série
     * horaire ne partagerait sinon qu'une clé sur quatre — celles en `:00:00` — et la
     * moyenne ne porterait que sur le premier quart de chaque heure, en ignorant
     * silencieusement les trois autres.
     *
     * @param array<string, float> $weights
     * @return array<string, float>
     */
    private function alignWeights(array $weights, int $resolutionMin): array
    {
        if ($resolutionMin < 60) {
            return $weights;
        }

        $aligned = [];
        foreach ($weights as $slot => $weight) {
            $key = substr($slot, 0, 13) . ':00:00';
            $aligned[$key] = ($aligned[$key] ?? 0.0) + $weight;
        }

        return $aligned;
    }

    /**
     * @param array<string, float> $values
     * @return array<string, float>
     */
    private function pricesOfMonth(string $month, array $values): array
    {
        $out = [];
        foreach ($values as $slot => $value) {
            if (str_starts_with($slot, $month)) {
                $out[$slot] = $value;
            }
        }

        return $out;
    }
}
