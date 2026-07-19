<?php

declare(strict_types=1);

namespace App\Integration;

/**
 * Registre des connecteurs d'export. Liste manuelle (pas de conteneur DI ni de
 * découverte automatique dans ce projet) : ajouter un site externe = créer son
 * dossier sous app/src/Integration/ puis l'ajouter à {@see self::all()}.
 *
 * Les modules sont instanciables à partir de la seule configuration (le PDO est
 * fourni à {@see ExportModuleInterface::syncUser()}), ce qui permet de les lister
 * et de tester leur kill-switch sans ouvrir de connexion base de données.
 */
final class ModuleRegistry
{
    /**
     * @param array<string, mixed> $config
     * @return list<ExportModuleInterface>
     */
    public static function all(array $config): array
    {
        return [
            new EnergyId\EnergyIdModule($config),
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function find(string $key, array $config): ?ExportModuleInterface
    {
        foreach (self::all($config) as $module) {
            if ($module->key() === $key) {
                return $module;
            }
        }

        return null;
    }
}
