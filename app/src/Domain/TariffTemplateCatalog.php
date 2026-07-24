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
        'generic_electricity' => [
            'energy_type' => 'electricity',
            'country'     => null,
            'keys'        => ['energy_t1', 'energy_t2', 'distribution_t1', 'distribution_t2', 'subscription', 'transport'],
        ],
        'generic_gas' => [
            'energy_type' => 'gas',
            'country'     => null,
            'keys'        => ['energy', 'subscription', 'distribution', 'transport', 'federal_excise'],
        ],
        'generic_water' => [
            'energy_type' => 'water',
            'country'     => null,
            'keys'        => ['water_supply'],
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
     * Template par défaut pour une énergie : la variante générique (il n'existe
     * plus de template builtin spécifique à un pays ; ceux-ci sont désormais
     * créés par les utilisateurs). Le pays n'influe plus sur la structure.
     *
     * @return Template
     */
    public static function defaultFor(string $energyType, ?string $country): array
    {
        return self::build('generic_' . $energyType);
    }
}
