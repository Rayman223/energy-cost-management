<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Type de composante d'une ligne tarifaire pour le moteur de calcul générique.
 *
 * Chaque ligne d'une grille porte un kind qui détermine sa formule de calcul
 * (quantité multipliée, proratisation) et son groupe d'affichage. Cela rend le
 * calcul indépendant des clés belges historiques : n'importe quelle taxe de
 * n'importe quel pays se calcule via son kind.
 *
 * Formules (cf. TariffCalculatorService) :
 *   energy_flat   €/kWh × total kWh          (énergie fournisseur, remplacée en dynamique)
 *   energy_t1/t2  €/kWh × kwhT1 / kwhT2       (énergie bihoraire, remplacée en dynamique)
 *   per_kwh       €/kWh × total kWh           (transport, accises, contributions)
 *   per_kwh_t1/t2 €/kWh × kwhT1 / kwhT2       (distribution bihoraire)
 *   per_m3        €/m³ × m³                   (eau)
 *   fixed_monthly €/mois × mois entiers       (abonnements)
 *   fixed_annual  €/an proratisé sur la période
 *   injection_t1/t2  −(€/kWh × export T1/T2)  (crédits d'injection)
 *
 * Cas à part — paramètres de la formule dynamique (#228), qui ne produisent AUCUN
 * montant : ils décrivent comment indexer le prix de marché, pas une composante
 * facturée. Cf. {@see self::isSpotFormula()}.
 *   spot_coefficient  multiplicateur sans dimension appliqué au prix spot
 *   spot_offset       €/kWh TTC ajoutés au prix spot indexé
 */
enum ComponentKind: string
{
    case EnergyFlat  = 'energy_flat';
    case EnergyT1    = 'energy_t1';
    case EnergyT2    = 'energy_t2';
    case PerKwh      = 'per_kwh';
    case PerKwhT1    = 'per_kwh_t1';
    case PerKwhT2    = 'per_kwh_t2';
    case PerM3       = 'per_m3';
    case FixedMonthly = 'fixed_monthly';
    case FixedAnnual = 'fixed_annual';
    case InjectionT1 = 'injection_t1';
    case InjectionT2 = 'injection_t2';
    case SpotCoefficient = 'spot_coefficient';
    case SpotOffset      = 'spot_offset';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $k): string => $k->value, self::cases());
    }

    /**
     * Kinds proposables dans le formulaire pour un type d'énergie (issue #191).
     * Ne restreint PAS la validation à la soumission : des grilles existantes
     * peuvent porter des kinds hors liste (ex. per_kwh sur une grille eau).
     *
     * @return list<self>
     */
    public static function forEnergy(string $energyType): array
    {
        return match ($energyType) {
            'gas'   => [self::EnergyFlat, self::PerKwh, self::FixedMonthly, self::FixedAnnual],
            'water' => [self::PerM3, self::FixedMonthly, self::FixedAnnual],
            default => array_values(array_filter(
                self::cases(),
                static fn (self $k): bool => $k !== self::PerM3,
            )),
        };
    }

    /** Repli sûr : une valeur inconnue devient une taxe €/kWh. */
    public static function fromStringOrDefault(string $value): self
    {
        return self::tryFrom($value) ?? self::PerKwh;
    }

    /**
     * La part énergie fournisseur est remplacée par le prix de marché en tarif
     * dynamique (ENTSO-E) ; ces kinds sont donc ignorés dans ce mode.
     */
    public function isSupplierEnergy(): bool
    {
        return match ($this) {
            self::EnergyFlat, self::EnergyT1, self::EnergyT2 => true,
            default => false,
        };
    }

    public function isInjection(): bool
    {
        return $this === self::InjectionT1 || $this === self::InjectionT2;
    }

    /**
     * Paramètre de la formule d'indexation dynamique, et non une composante de coût (#228).
     *
     * Ces lignes portent un multiplicateur ou un offset €/kWh appliqués au prix spot ;
     * elles ne doivent JAMAIS être facturées comme un poste. Un coefficient 1,08 ajouté
     * au total coûterait 1,08 €/kWh. Le moteur de calcul les saute donc dans TOUS les
     * modes — y compris en tarif fixe, où elles sont simplement inertes.
     *
     * Volontairement distinct de {@see self::isSupplierEnergy()} : ces kinds ne sont pas
     * « remplacés » par le prix de marché, ils le paramètrent.
     */
    public function isSpotFormula(): bool
    {
        return match ($this) {
            self::SpotCoefficient, self::SpotOffset => true,
            default => false,
        };
    }

    /** Groupe d'affichage du formulaire et du détail de coût. */
    public function group(): string
    {
        return match ($this) {
            self::EnergyFlat, self::EnergyT1, self::EnergyT2,
            self::SpotCoefficient, self::SpotOffset          => 'energy',
            self::FixedMonthly, self::FixedAnnual           => 'fixed',
            self::InjectionT1, self::InjectionT2            => 'injection',
            default                                          => 'taxes',
        };
    }

    /**
     * Unité affichée : dépend de l'énergie pour la part variable, et de la devise
     * de la grille pour le numérateur. Le symbole n'est jamais codé en dur —
     * facturer en CHF et afficher « €/kWh » induirait en erreur.
     *
     * @param string $currency Code ISO de la grille (`tariff_grids.currency`).
     */
    public function unit(string $energyType, string $currency): string
    {
        // Multiplicateur sans dimension : ni une devise, ni une quantité.
        if ($this === self::SpotCoefficient) {
            return '×';
        }

        $symbol = Currency::symbol($currency);

        return $symbol . match ($this) {
            self::FixedMonthly => '/mois',
            self::FixedAnnual  => '/an',
            self::PerM3        => '/m³',
            default            => $energyType === 'water' ? '/m³' : '/kWh',
        };
    }
}
