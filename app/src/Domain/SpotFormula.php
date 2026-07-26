<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Formule d'indexation du prix de marché en tarif dynamique (#228).
 *
 * ENTSO-E fournit l'index brut (day-ahead, €/kWh HTVA) — c'est bien celui auquel les
 * fournisseurs se réfèrent, mais aucun ne le facture tel quel. Le contrat le transforme :
 *
 *     rate TTC = spot × coefficient × (1 + TVA/100) + offset TTC
 *
 * Le `coefficient` (typiquement 1,00 à 1,10) couvre les pertes réseau et les coûts de
 * profil/équilibrage ; l'`offset` regroupe marge fournisseur et frais fixes au kWh.
 *
 * L'offset est exprimé TTC, cohérent avec la convention des grilles tarifaires (montants
 * saisis TTC, cf. TariffCalculatorService) et avec le champ historique
 * `user_profiles.supplier_markup_per_kwh` qu'il complète : à coefficient 1,0 et sans
 * ligne spot_offset, la formule reproduit exactement le calcul d'avant #228.
 *
 * Les deux termes sont résolus INDÉPENDAMMENT (cf. SpotFormulaResolver) : un coefficient
 * saisi seul ne doit pas faire disparaître la marge du profil.
 *
 * Value object immuable, sans dépendance : la formule est ainsi testable seule.
 */
final class SpotFormula
{
    public const SOURCE_GRID    = 'grid';
    public const SOURCE_PROFILE = 'profile';

    /**
     * @param float  $coefficient  Multiplicateur du prix spot ; 1.0 = neutre.
     * @param float  $offsetTtc    €/kWh TTC ajoutés au prix indexé.
     * @param string $offsetSource Provenance du SEUL terme qui a un repli :
     *        self::SOURCE_GRID (lignes spot_offset de la grille) ou
     *        self::SOURCE_PROFILE (repli sur user_profiles.supplier_markup_per_kwh).
     *        Le coefficient, lui, n'a pas de repli — son absence vaut 1.0.
     * @param bool $coefficientRejected Un coefficient hors bornes a été neutralisé à
     *        1.0. À exposer : la formule appliquée ne correspond alors NI à la grille
     *        saisie, ni au repli profil, et l'utilisateur doit pouvoir le voir.
     */
    public function __construct(
        public readonly float $coefficient = 1.0,
        public readonly float $offsetTtc = 0.0,
        public readonly string $offsetSource = self::SOURCE_PROFILE,
        public readonly bool $coefficientRejected = false,
    ) {
    }

    /**
     * Prix TTC du kWh pour un prix spot HTVA donné.
     *
     * La TVA s'applique au spot indexé, pas à l'offset : celui-ci est déjà TTC.
     */
    public function rateTtc(float $spotHtva, float $vatRatePercent): float
    {
        return $spotHtva * $this->coefficient * (1.0 + $vatRatePercent / 100.0) + $this->offsetTtc;
    }

    /** Formule sans effet : le prix spot passe tel quel (hors TVA). */
    public function isNeutral(): bool
    {
        return $this->coefficient === 1.0 && $this->offsetTtc === 0.0;
    }
}
