<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Prix unitaire de marché d'UN mois, et la façon dont il a été obtenu (#93).
 *
 * C'est l'équivalent calculé du paramètre d'indexation `Belpex_RLP_M` des fiches
 * tarifaires belges : la moyenne pondérée des cotations day-ahead du mois, HORS TVA et
 * hors formule. Le coefficient et l'offset du contrat s'y appliquent ensuite via
 * {@see SpotFormula::rateTtc()}.
 *
 * Les métadonnées ne sont pas décoratives : un même prix obtenu par pondération réelle
 * ou par baseload n'a pas la même valeur probante, et le rapprochement facture doit
 * pouvoir écarter les mois estimés ({@see \App\Service\BillReconciliationService}).
 */
final class MonthlyIndexedPrice
{
    /**
     * @param string $month            Mois civil 'Y-m' (UTC, comme les cotations).
     * @param float  $priceHtva        Prix unitaire €/kWh HTVA, moyenne pondérée du mois.
     * @param LoadWeighting $weighting Provenance de la PONDÉRATION.
     * @param string $priceSource      Série de cotations retenue : 'native_quarter',
     *                                 'native_hourly' ou 'avg_hourly'.
     * @param int    $resolutionMin    Résolution de cette série (15 ou 60).
     * @param float  $weightCoveragePct Part (%) de la consommation réellement mesurée au
     *                                 pas de la pondération retenue.
     * @param float  $priceCoveragePct Part (%) des créneaux du mois disposant d'une cotation.
     * @param bool   $partial          Mois incomplet : fenêtre partielle ou mois non clos.
     *                                 Belpex_RLP_M n'étant connu qu'en fin de mois, un
     *                                 prix provisoire doit être annoncé comme tel.
     * @param ?string $profileCode     Code du profil standard employé, le cas échéant.
     */
    public function __construct(
        public readonly string $month,
        public readonly float $priceHtva,
        public readonly LoadWeighting $weighting,
        public readonly string $priceSource,
        public readonly int $resolutionMin,
        public readonly float $weightCoveragePct,
        public readonly float $priceCoveragePct,
        public readonly bool $partial,
        public readonly ?string $profileCode = null,
    ) {
    }

    /**
     * Ce prix reproduit-il la consommation réelle, ou est-il estimé ?
     *
     * Un prix estimé reste parfaitement utilisable pour afficher un coût ; il ne l'est
     * pas pour DÉDUIRE les paramètres d'un contrat, car l'erreur de pondération serait
     * alors absorbée par le coefficient déduit.
     */
    public function isMeasured(): bool
    {
        return $this->weighting === LoadWeighting::ActualLoad && $this->partial === false;
    }

    /** @return array<string, mixed> Vue sérialisable pour l'UI. */
    public function toArray(): array
    {
        return [
            'month'              => $this->month,
            'price_htva'         => round($this->priceHtva, 6),
            'weighting'          => $this->weighting->value,
            'price_source'       => $this->priceSource,
            'resolution_min'     => $this->resolutionMin,
            'weight_coverage_pct' => round($this->weightCoveragePct, 1),
            'price_coverage_pct' => round($this->priceCoveragePct, 1),
            'partial'            => $this->partial,
            'profile_code'       => $this->profileCode,
        ];
    }
}
