<?php

declare(strict_types=1);

namespace App\Infrastructure;

use PDO;

/**
 * Topologie des compteurs du modèle à registres : résolution (et création à la
 * volée) du compteur électricité d'un utilisateur et de ses registres.
 * Source de vérité unique partagée par la lecture (dashboard), l'ingestion
 * (cron) et la migration (backfill).
 */
final class MeterTopology
{
    /** Registres électricité connus (jusqu'à 5 index par compteur). */
    public const ELECTRICITY_REGISTERS = ['import_t1', 'import_t2', 'export_t1', 'export_t2', 'production'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Compteur électricité de l'utilisateur, créé s'il n'existe pas. */
    public function ensureElectricityMeter(int $userId): int
    {
        $existing = $this->findElectricityMeter($userId);
        if ($existing !== null) {
            return $existing;
        }

        $this->pdo->prepare(
            "INSERT INTO meters (user_id, energy_type, label) VALUES (:uid, 'electricity', 'Compteur électrique')"
        )->execute(['uid' => $userId]);

        return (int) $this->pdo->lastInsertId();
    }

    /** Compteur électricité de l'utilisateur, null s'il n'existe pas. */
    public function findElectricityMeter(int $userId): ?int
    {
        $stmt = $this->pdo->prepare(
            "SELECT id FROM meters WHERE user_id = :uid AND energy_type = 'electricity' ORDER BY id LIMIT 1"
        );
        $stmt->execute(['uid' => $userId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Crée les registres manquants et retourne la carte complète.
     *
     * @param list<string> $keys
     * @return array<string, int> register_key => id
     */
    public function ensureRegisters(int $meterId, array $keys = self::ELECTRICITY_REGISTERS): array
    {
        $insert = $this->pdo->prepare(
            "INSERT IGNORE INTO meter_registers (meter_id, register_key, unit) VALUES (:mid, :key, 'kWh')"
        );
        foreach ($keys as $key) {
            $insert->execute(['mid' => $meterId, 'key' => $key]);
        }

        return $this->registerMap($meterId);
    }

    /** @return array<string, int> register_key => id (vide si compteur inconnu) */
    public function registerMap(int $meterId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, register_key FROM meter_registers WHERE meter_id = :mid');
        $stmt->execute(['mid' => $meterId]);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            if (is_array($row)) {
                $map[(string) $row['register_key']] = (int) $row['id'];
            }
        }

        return $map;
    }

    /**
     * Carte des registres de l'utilisateur SANS création (lecture seule).
     *
     * @return array<string, int> register_key => id (vide si aucun compteur)
     */
    public function registerMapForUser(int $userId): array
    {
        $meterId = $this->findElectricityMeter($userId);

        return $meterId === null ? [] : $this->registerMap($meterId);
    }
}
