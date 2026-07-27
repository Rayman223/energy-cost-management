<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\BillPeriod;
use App\Domain\SpotFormula;
use App\Domain\SpotFormulaFit;

/**
 * Retrouve le couple (coefficient, offset) d'un contrat à partir des montants réellement
 * facturés (#229).
 *
 * Chaque mois donne une équation linéaire à deux inconnues :
 *
 *     billedTtc − uncoveredTtc = coefficient × indexedBaseTtc + offset × coveredKwh
 *
 * Un seul mois ne suffit donc pas : une équation, deux inconnues, une infinité de couples
 * également valides. On ne renvoie alors PAS de couple — seulement les deux propositions
 * à une inconnue (l'offset qui corrige à coefficient figé, et réciproquement), qui sont
 * les seules réponses honnêtes dans ce cas.
 *
 * Deux mois suffisent, MAIS seulement si leurs profils de consommation diffèrent. Le
 * déterminant du système vaut A₁B₂ − A₂B₁ = B₁B₂ × (p₁ − p₂), où p = A/B est le prix
 * moyen indexé du mois : deux mois au même prix moyen donnent deux équations colinéaires,
 * et le système est dégénéré même s'il paraît formellement résoluble. C'est exactement le
 * cas « profils trop proches » que l'issue demande de signaler explicitement, d'où le
 * garde-fou sur l'écart RELATIF des prix moyens plutôt que sur le seul déterminant, dont
 * la magnitude ne dit rien par elle-même.
 *
 * Logique pure, sans I/O — testable sans base de données, à la manière de
 * {@see SpotFormulaResolver} et {@see TariffPeriodSplitter}.
 */
final class SpotFormulaFitter
{
    /**
     * Écart relatif minimal (%) entre le prix moyen indexé le plus haut et le plus bas
     * pour considérer le système séparable.
     *
     * En deçà, la résolution reste numériquement possible mais amplifie tout : une erreur
     * de quelques euros sur un montant saisi ferait bondir le coefficient de plusieurs
     * dizaines de pourcents, et l'offset partirait en compensation. Mieux vaut annoncer
     * un système mal conditionné et proposer les deux corrections à une inconnue.
     *
     * 2 % correspond à l'ordre de grandeur du bruit de saisie sur une facture : en dessous,
     * la différence de profil entre les deux mois n'est pas distinguable de l'arrondi.
     */
    public const PRICE_SPREAD_MIN_PCT = 2.0;

    /**
     * Garde numérique du déterminant des équations normales, en RELATIF : le produit
     * ΣA²·ΣB² donne l'échelle attendue, sous 1e-9 de celle-ci le système est numériquement
     * singulier. Sécurise la division même quand le contrôle de conditionnement est passé.
     */
    private const DETERMINANT_EPSILON = 1.0e-9;

    /**
     * @param list<BillPeriod> $periods  Mois rapprochés ; ceux sans heure couverte sont ignorés.
     * @param SpotFormula      $current  Formule actuellement appliquée, base des propositions à une inconnue.
     */
    public function fit(array $periods, SpotFormula $current): SpotFormulaFit
    {
        $usable = array_values(array_filter($periods, static fn (BillPeriod $p): bool => $p->isUsable()));

        if ($usable === []) {
            return new SpotFormulaFit(SpotFormulaFit::MODE_UNDETERMINED);
        }

        // Agrégat toutes périodes confondues : la seule équation dont on dispose quand le
        // système ne peut pas être séparé. Le terme constant (heures non couvertes) est
        // retiré du montant facturé, sinon il serait absorbé par le coefficient.
        $sumA      = 0.0;
        $sumB      = 0.0;
        $sumTarget = 0.0;
        foreach ($usable as $period) {
            $sumA      += $period->indexedBaseTtc;
            $sumB      += $period->coveredKwh;
            $sumTarget += $period->billedTtc - $period->uncoveredTtc;
        }

        $offsetAtCurrentCoefficient = $sumB > 0.0
            ? ($sumTarget - $current->coefficient * $sumA) / $sumB
            : null;
        $coefficientAtCurrentOffset = $sumA > 0.0
            ? ($sumTarget - $current->offsetTtc * $sumB) / $sumA
            : null;

        $spread = $this->priceSpreadPct($usable);

        if (count($usable) < 2) {
            return new SpotFormulaFit(
                mode:                       SpotFormulaFit::MODE_SINGLE_PERIOD,
                offsetAtCurrentCoefficient: $offsetAtCurrentCoefficient,
                coefficientAtCurrentOffset: $coefficientAtCurrentOffset,
                priceSpreadPct:             $spread,
            );
        }

        if ($spread < self::PRICE_SPREAD_MIN_PCT) {
            return new SpotFormulaFit(
                mode:                       SpotFormulaFit::MODE_ILL_CONDITIONED,
                offsetAtCurrentCoefficient: $offsetAtCurrentCoefficient,
                coefficientAtCurrentOffset: $coefficientAtCurrentOffset,
                priceSpreadPct:             $spread,
            );
        }

        $solved = $this->solveLeastSquares($usable);
        if ($solved === null) {
            return new SpotFormulaFit(
                mode:                       SpotFormulaFit::MODE_ILL_CONDITIONED,
                offsetAtCurrentCoefficient: $offsetAtCurrentCoefficient,
                coefficientAtCurrentOffset: $coefficientAtCurrentOffset,
                priceSpreadPct:             $spread,
            );
        }

        [$coefficient, $offsetTtc] = $solved;

        $residual = 0.0;
        foreach ($usable as $period) {
            $residual += abs($period->billedTtc - $period->costFor($coefficient, $offsetTtc));
        }

        return new SpotFormulaFit(
            // Deux périodes : les moindres carrés retombent sur la solution exacte du
            // système 2×2 (résidu nul). Au-delà, le résidu devient informatif.
            mode:                       count($usable) === 2 ? SpotFormulaFit::MODE_EXACT : SpotFormulaFit::MODE_LEAST_SQUARES,
            coefficient:                $coefficient,
            offsetTtc:                  $offsetTtc,
            offsetAtCurrentCoefficient: $offsetAtCurrentCoefficient,
            coefficientAtCurrentOffset: $coefficientAtCurrentOffset,
            priceSpreadPct:             $spread,
            residualTtc:                $residual,
            coefficientOutOfBounds:     $coefficient <= SpotFormulaResolver::COEFFICIENT_MIN
                                        || $coefficient > SpotFormulaResolver::COEFFICIENT_MAX,
        );
    }

    /**
     * Écart relatif (%) entre le plus haut et le plus bas prix moyen indexé des périodes.
     * Rapporté au plus bas : c'est l'amplitude de variation vue par le système, et elle
     * reste comparable d'un jeu de données à l'autre malgré des prix absolus différents.
     *
     * @param list<BillPeriod> $periods Périodes exploitables (coveredKwh > 0).
     */
    private function priceSpreadPct(array $periods): float
    {
        $prices = [];
        foreach ($periods as $period) {
            $price = $period->averageIndexedPrice();
            if ($price !== null) {
                $prices[] = $price;
            }
        }

        if (count($prices) < 2) {
            return 0.0;
        }

        $min = min($prices);
        $max = max($prices);

        return $min > 0.0 ? ($max - $min) / $min * 100.0 : 0.0;
    }

    /**
     * Résout min Σ (c·Aᵢ + o·Bᵢ − tᵢ)² par les équations normales 2×2. Exact pour deux
     * périodes, ajustement au sens des moindres carrés au-delà.
     *
     * @param list<BillPeriod> $periods
     * @return array{float, float}|null [coefficient, offset], ou null si le système est singulier.
     */
    private function solveLeastSquares(array $periods): ?array
    {
        $sumAA = 0.0;
        $sumAB = 0.0;
        $sumBB = 0.0;
        $sumAt = 0.0;
        $sumBt = 0.0;

        foreach ($periods as $period) {
            $a = $period->indexedBaseTtc;
            $b = $period->coveredKwh;
            $t = $period->billedTtc - $period->uncoveredTtc;

            $sumAA += $a * $a;
            $sumAB += $a * $b;
            $sumBB += $b * $b;
            $sumAt += $a * $t;
            $sumBt += $b * $t;
        }

        $determinant = $sumAA * $sumBB - $sumAB * $sumAB;
        if (abs($determinant) < self::DETERMINANT_EPSILON * $sumAA * $sumBB) {
            return null;
        }

        return [
            ($sumBB * $sumAt - $sumAB * $sumBt) / $determinant,
            ($sumAA * $sumBt - $sumAB * $sumAt) / $determinant,
        ];
    }
}
