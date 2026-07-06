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

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $k): string => $k->value, self::cases());
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

    /** Groupe d'affichage du formulaire et du détail de coût. */
    public function group(): string
    {
        return match ($this) {
            self::EnergyFlat, self::EnergyT1, self::EnergyT2 => 'energy',
            self::FixedMonthly, self::FixedAnnual           => 'fixed',
            self::InjectionT1, self::InjectionT2            => 'injection',
            default                                          => 'taxes',
        };
    }

    /** Unité affichée (dépend de l'énergie pour la part variable). */
    public function unit(string $energyType): string
    {
        return match ($this) {
            self::FixedMonthly => '€/mois',
            self::FixedAnnual  => '€/an',
            self::PerM3        => '€/m³',
            default            => $energyType === 'water' ? '€/m³' : '€/kWh',
        };
    }
}
