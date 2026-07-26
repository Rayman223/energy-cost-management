<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\ComponentKind;
use App\Domain\SpotFormula;
use App\Domain\TariffGrid;

/**
 * Extrait la formule d'indexation dynamique (#228) des lignes d'une grille tarifaire.
 *
 * Les paramètres vivent dans la grille et non dans le profil utilisateur : un coefficient
 * change à chaque renouvellement de contrat, et `valid_from`/`valid_to` conservent alors
 * l'historique — les mois passés restent calculés avec les paramètres de l'époque.
 *
 * Règle de précédence, PAR COMPOSANTE : seule une ligne `spot_offset` écarte le champ
 * historique `user_profiles.supplier_markup_per_kwh`, puisque eux seuls feraient double
 * emploi. Un `spot_coefficient` saisi seul laisse donc la marge du profil intacte — la
 * faire disparaître serait une perte silencieuse sur chaque heure, invisible dans
 * `/account` qui continue d'afficher le champ. Sans aucune ligne spot, la formule
 * reproduit à l'identique le comportement d'avant #228.
 *
 * Logique pure, sans I/O — testable sans base de données, à la manière de
 * {@see MonthlyConsumptionInterpolator} et {@see TariffPeriodSplitter}.
 */
final class SpotFormulaResolver
{
    /**
     * Bornes de sécurité du coefficient. Un coefficient nul ou négatif annulerait — voire
     * inverserait — tout le coût énergie ; au-delà de MAX il s'agit d'une saisie erronée
     * (un pourcentage 108 au lieu de 1,08, par exemple). Dans les deux cas on neutralise
     * à 1,0 plutôt que d'afficher un montant absurde, en le SIGNALANT
     * (SpotFormula::$coefficientRejected) : contrairement aux replis muets du projet, la
     * formule appliquée ne correspondrait sinon à rien de ce que l'utilisateur a saisi.
     * La saisie est par ailleurs validée en amont dans app/routes/tariffs.php — ceci est
     * la dernière ligne de défense, qui couvre aussi les grilles importées par l'API
     * (délibérément non validées sur ce point, cf. app/docs/api-contract.md).
     */
    public const COEFFICIENT_MIN = 0.0;
    public const COEFFICIENT_MAX = 5.0;

    /**
     * @param float $profileMarkupTtc Repli : `user_profiles.supplier_markup_per_kwh` (€/kWh TTC),
     *                                utilisé uniquement si la grille ne porte aucune ligne spot.
     */
    public function resolve(TariffGrid $grid, float $profileMarkupTtc = 0.0): SpotFormula
    {
        $coefficient = 1.0;
        $offsetTtc   = 0.0;
        $hasOffset   = false;
        $rejected    = false;

        foreach ($grid->lines as $line) {
            if ($line->kind === ComponentKind::SpotCoefficient) {
                // Produit : deux coefficients se composent (ex. pertes réseau × équilibrage).
                // Une valeur hors bornes est écartée plutôt qu'appliquée de travers, et
                // signalée — sinon la formule affichée ne correspondrait à rien de saisi.
                if ($this->isValidCoefficient($line->amount)) {
                    $coefficient *= $line->amount;
                } else {
                    $rejected = true;
                }

                continue;
            }

            if ($line->kind === ComponentKind::SpotOffset) {
                // Somme : marge fournisseur, coût d'équilibrage, coût de profil… autant de
                // lignes que le contrat en distingue.
                $offsetTtc += $line->amount;
                $hasOffset  = true;
            }
        }

        // Le produit lui-même peut sortir des bornes (plusieurs coefficients cumulés).
        if (!$this->isValidCoefficient($coefficient)) {
            $coefficient = 1.0;
            $rejected    = true;
        }

        // Précédence PAR COMPOSANTE : seul un spot_offset en grille écarte la marge du
        // profil, car eux seuls feraient double emploi. Un coefficient saisi sans ligne
        // de marge ne doit pas faire disparaître silencieusement celle du profil.
        return $hasOffset
            ? new SpotFormula($coefficient, $offsetTtc, SpotFormula::SOURCE_GRID, $rejected)
            : new SpotFormula($coefficient, $profileMarkupTtc, SpotFormula::SOURCE_PROFILE, $rejected);
    }

    private function isValidCoefficient(float $value): bool
    {
        return $value > self::COEFFICIENT_MIN && $value <= self::COEFFICIENT_MAX;
    }
}
