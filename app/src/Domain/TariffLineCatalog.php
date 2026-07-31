<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Source unique des composantes tarifaires (lignes) par type d'énergie :
 * clé technique → libellé + unité affichés.
 *
 * Évite de dupliquer la liste des clés entre la validation du formulaire
 * (quelles lignes lire dans la requête) et l'affichage (libellés/unités).
 */
final class TariffLineCatalog
{
    /**
     * Format d'une clé technique de ligne (`tariff_lines.line_key`) : minuscule
     * initiale, puis minuscules/chiffres/underscores, 100 caractères au plus.
     *
     * Contrainte partagée par les deux portes d'entrée — formulaire web
     * (app/routes/tariffs.php) et API `save_tariff`
     * (App\Http\Controller\TariffController::normalizeLines) : une clé hors
     * format n'est reconnue par aucun kind du catalogue, retombe sur per_kwh,
     * et rend ensuite la grille non réenregistrable depuis le formulaire (#265).
     * Les clés `custom_*` du slug automatique du formulaire la respectent par
     * construction.
     *
     * Le modificateur `D` est indispensable : sans lui, `$` matche aussi juste
     * avant un `\n` final, et « energy_t1\n » franchissait la garde. Le chemin
     * API ne trime pas la clé (contrairement au formulaire), la clé polluée
     * n'était donc reconnue par aucun kind — le bug même que ce format ferme —
     * et 100 caractères suivis d'un `\n` débordaient le VARCHAR(100) de
     * `tariff_grid_lines.line_key`.
     */
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,99}$/D';

    /** @return array<string,array{label:string,unit:string}> */
    public static function electricity(): array
    {
        return [
            'energy_simple'         => ['label' => 'Énergie simple (monohoraire)',     'unit' => '€/kWh'],
            'energy_t1'             => ['label' => 'Énergie T1 (jour)',                'unit' => '€/kWh'],
            'energy_t2'             => ['label' => 'Énergie T2 (nuit)',                'unit' => '€/kWh'],
            'spot_coefficient'      => ['label' => 'Coefficient sur le prix de marché','unit' => '×'],
            'spot_offset'           => ['label' => 'Marge fournisseur sur le marché (TTC)', 'unit' => '€/kWh'],
            'subscription'          => ['label' => 'Abonnement fournisseur',           'unit' => '€/mois'],
            'distribution_t1'       => ['label' => 'Distribution T1 (jour)',           'unit' => '€/kWh'],
            'distribution_t2'       => ['label' => 'Distribution T2 (nuit)',           'unit' => '€/kWh'],
            'transport'             => ['label' => 'Transport',                        'unit' => '€/kWh'],
            'management_annual'     => ['label' => 'Gestion (fixe annuel)',            'unit' => '€/an'],
            'prosumer_annual'       => ['label' => 'Taxe prosumer BRUGEL',             'unit' => '€/an'],
            'excise_duty'           => ['label' => "Droit d'accise spécial",           'unit' => '€/kWh'],
            'energy_contribution'   => ['label' => 'Contribution énergie',             'unit' => '€/kWh'],
            'green_contribution'    => ['label' => 'Contribution verte & cogénération','unit' => '€/kWh'],
            'public_service_annual' => ['label' => 'Obligations de service public',    'unit' => '€/an'],
            'injection_t1'          => ['label' => 'Crédit injection T1',              'unit' => '€/kWh'],
            'injection_t2'          => ['label' => 'Crédit injection T2',              'unit' => '€/kWh'],
        ];
    }

    /** @return array<string,array{label:string,unit:string}> */
    public static function gas(): array
    {
        return [
            'energy'                => ['label' => 'Énergie fournisseur',               'unit' => '€/kWh'],
            'subscription'          => ['label' => 'Abonnement fournisseur',            'unit' => '€/mois'],
            'energy_contribution'   => ['label' => 'Contribution énergie',              'unit' => '€/kWh'],
            'federal_excise'        => ['label' => 'Accise fédérale',                   'unit' => '€/kWh'],
            'distribution'          => ['label' => 'Distribution (variable)',           'unit' => '€/kWh'],
            'distribution_fixed'    => ['label' => 'Distribution (fixe)',               'unit' => '€/an'],
            'transport'             => ['label' => 'Transport',                         'unit' => '€/kWh'],
            'meter_reading_annual'  => ['label' => 'Relevé de compteur',                'unit' => '€/an'],
            'connection_fee_kwh'    => ['label' => 'Redevance de raccordement',         'unit' => '€/kWh'],
            'public_service_annual' => ['label' => 'Obligations de service public',     'unit' => '€/an'],
        ];
    }

    /** @return array<string,array{label:string,unit:string}> */
    public static function water(): array
    {
        return [
            'water_supply'         => ['label' => 'Consommation — fourniture',   'unit' => '€/m³'],
            'sanitation_communal'  => ['label' => 'Assainissement communal',     'unit' => '€/m³'],
            'sanitation_regional'  => ['label' => 'Assainissement régional',     'unit' => '€/m³'],
            'social_fund'          => ['label' => 'Fonds social de l\'eau',      'unit' => '€/m³'],
            'meter_rental_annual'  => ['label' => 'Redevance compteur',          'unit' => '€/an'],
        ];
    }

    /**
     * Définitions pour un type d'énergie ('gas' → gaz, 'water' → eau, sinon électricité).
     *
     * @return array<string,array{label:string,unit:string}>
     */
    public static function forType(string $energyType): array
    {
        return match ($energyType) {
            'gas'   => self::gas(),
            'water' => self::water(),
            default => self::electricity(),
        };
    }

    /**
     * Type de composante (kind) d'une clé du catalogue. Source unique alignée sur
     * le backfill SQL (migration 2026-07-06) et le mapping des lignes plates de
     * l'API. Toute clé inconnue retombe sur per_kwh (une taxe €/kWh).
     */
    public static function kindFor(string $energyType, string $key): ComponentKind
    {
        return match (true) {
            $key === 'energy_simple', $key === 'energy'          => ComponentKind::EnergyFlat,
            $energyType === 'electricity' && $key === 'energy_t1' => ComponentKind::EnergyT1,
            $energyType === 'electricity' && $key === 'energy_t2' => ComponentKind::EnergyT2,
            // Volontairement NON restreint à l'électricité, contrairement aux clés
            // bihoraires : le repli par défaut est per_kwh, qui FACTURERAIT un
            // coefficient 1,08 comme 1,08 €/kWh sur une grille gaz reçue par l'API
            // (les lignes plates sont typées ici, cf. TariffController::normalizeLines).
            // Mappées correctement, ces clés restent inertes hors tarif dynamique.
            $key === 'spot_coefficient' => ComponentKind::SpotCoefficient,
            $key === 'spot_offset'      => ComponentKind::SpotOffset,
            $key === 'subscription'                              => ComponentKind::FixedMonthly,
            $energyType === 'electricity' && $key === 'distribution_t1' => ComponentKind::PerKwhT1,
            $energyType === 'electricity' && $key === 'distribution_t2' => ComponentKind::PerKwhT2,
            $key === 'injection_t1'                              => ComponentKind::InjectionT1,
            $key === 'injection_t2'                              => ComponentKind::InjectionT2,
            $key === 'distribution_fixed', str_ends_with($key, '_annual') => ComponentKind::FixedAnnual,
            str_starts_with($key, 'sanitation_'), $key === 'water_supply', $key === 'social_fund' => ComponentKind::PerM3,
            default                                              => ComponentKind::PerKwh,
        };
    }

    /**
     * Clés techniques attendues pour un type d'énergie (validation du formulaire).
     *
     * @return list<string>
     */
    public static function keysFor(string $energyType): array
    {
        return array_keys(self::forType($energyType));
    }
}
