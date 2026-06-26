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
    /** @return array<string,array{label:string,unit:string}> */
    public static function electricity(): array
    {
        return [
            'energy_simple'         => ['label' => 'Énergie simple (monohoraire)',     'unit' => '€/kWh'],
            'energy_t1'             => ['label' => 'Énergie T1 (jour)',                'unit' => '€/kWh'],
            'energy_t2'             => ['label' => 'Énergie T2 (nuit)',                'unit' => '€/kWh'],
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

    /**
     * Définitions pour un type d'énergie ('gas' → gaz, sinon électricité).
     *
     * @return array<string,array{label:string,unit:string}>
     */
    public static function forType(string $energyType): array
    {
        return $energyType === 'gas' ? self::gas() : self::electricity();
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
