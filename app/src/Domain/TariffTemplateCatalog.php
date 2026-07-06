<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Catalogue des templates de tarifs « fournis » (builtin), versionnés avec le
 * code. Un template décrit la STRUCTURE d'une grille (quelles composantes, quel
 * kind) sans montants : il sert à préremplir le formulaire de la page tarifs.
 *
 * - Templates belges (be_*) : structure complète reprise de TariffLineCatalog.
 * - Templates génériques (generic_*) : structure minimale proposée aux autres
 *   pays (l'utilisateur complète / ajoute ses propres champs).
 *
 * Les templates créés par l'utilisateur vivent en base (TariffTemplateRepository).
 * Identifiants dans les listes déroulantes : `builtin:<code>` / `user:<id>`.
 *
 * @phpstan-type TemplateField array{key: string, kind: ComponentKind, label: string}
 * @phpstan-type Template array{code: string, energy_type: string, country: ?string, name_key: string, fields: list<TemplateField>}
 */
final class TariffTemplateCatalog
{
    /** Clés (dans TariffLineCatalog) composant chaque template builtin. */
    private const DEFINITIONS = [
        'be_electricity' => [
            'energy_type' => 'electricity',
            'country'     => 'BE',
            'keys'        => [
                'energy_t1', 'energy_t2', 'subscription', 'distribution_t1', 'distribution_t2',
                'transport', 'management_annual', 'prosumer_annual', 'excise_duty',
                'energy_contribution', 'green_contribution', 'public_service_annual',
                'injection_t1', 'injection_t2',
            ],
        ],
        'be_gas' => [
            'energy_type' => 'gas',
            'country'     => 'BE',
            'keys'        => [
                'energy', 'subscription', 'energy_contribution', 'federal_excise',
                'distribution', 'distribution_fixed', 'transport', 'meter_reading_annual',
                'connection_fee_kwh', 'public_service_annual',
            ],
        ],
        'be_water' => [
            'energy_type' => 'water',
            'country'     => 'BE',
            'keys'        => [
                'water_supply', 'sanitation_communal', 'sanitation_regional',
                'social_fund', 'meter_rental_annual',
            ],
        ],
        'generic_electricity' => [
            'energy_type' => 'electricity',
            'country'     => null,
            'keys'        => ['energy_simple', 'subscription', 'transport', 'excise_duty'],
        ],
        'generic_gas' => [
            'energy_type' => 'gas',
            'country'     => null,
            'keys'        => ['energy', 'subscription', 'distribution', 'federal_excise'],
        ],
        'generic_water' => [
            'energy_type' => 'water',
            'country'     => null,
            'keys'        => ['water_supply', 'sanitation_communal', 'meter_rental_annual'],
        ],
    ];

    /**
     * @return Template
     */
    private static function build(string $code): array
    {
        $def     = self::DEFINITIONS[$code];
        $energy  = $def['energy_type'];
        $catalog = TariffLineCatalog::forType($energy);

        $fields = [];
        foreach ($def['keys'] as $key) {
            $fields[] = [
                'key'   => $key,
                'kind'  => TariffLineCatalog::kindFor($energy, $key),
                'label' => $catalog[$key]['label'] ?? $key,
            ];
        }

        return [
            'code'        => $code,
            'energy_type' => $energy,
            'country'     => $def['country'],
            'name_key'    => 'tariffs.tpl.' . $code,
            'fields'      => $fields,
        ];
    }

    /** @return list<Template> */
    public static function all(): array
    {
        return array_map(self::build(...), array_keys(self::DEFINITIONS));
    }

    /** @return Template|null */
    public static function find(string $code): ?array
    {
        return isset(self::DEFINITIONS[$code]) ? self::build($code) : null;
    }

    /** @return list<Template> */
    public static function forEnergy(string $energyType): array
    {
        return array_values(array_filter(
            self::all(),
            static fn (array $t): bool => $t['energy_type'] === $energyType,
        ));
    }

    /**
     * Template par défaut pour une énergie et un pays : la variante belge si le
     * pays est BE, sinon la variante générique.
     *
     * @return Template
     */
    public static function defaultFor(string $energyType, ?string $country): array
    {
        $prefix = strtoupper((string) $country) === 'BE' ? 'be_' : 'generic_';

        return self::build($prefix . $energyType);
    }
}
