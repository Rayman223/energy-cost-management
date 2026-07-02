<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Repository\Contract\UserRepositoryInterface;
use PDO;
use RuntimeException;

/**
 * Migration des données historiques (tables Data_*) vers le modèle multi-tenant,
 * rattachées au compte propriétaire (« owner »).
 *
 * Le compte propriétaire doit exister AU PRÉALABLE (créer son compte via une
 * connexion OIDC, puis lancer le backfill). Résolution de l'owner :
 *   1. --user=<id> explicite s'il est fourni et existe ;
 *   2. sinon le premier utilisateur de la table `users` ;
 *   3. sinon échec explicite (aucun compte : se connecter d'abord).
 *
 * Idempotente : INSERT IGNORE sur les contraintes UNIQUE composites (réexécutable).
 * Data_Brusol n'est PAS reprise (abandonnée).
 */
final class MultiTenantBackfill
{
    /** Clé de registre électricité → [table source, colonne source]. */
    private const ELECTRICITY_MAP = [
        'import_t1'  => ['Data_Dries', 'Prelev_jour'],
        'import_t2'  => ['Data_Dries', 'Prelev_nuit'],
        'export_t1'  => ['Data_Dries', 'Injec_jour'],
        'export_t2'  => ['Data_Dries', 'Injec_nuit'],
        'production' => ['Data_Solaire', 'production'],
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly UserRepositoryInterface $users,
    ) {
    }

    /**
     * @return array<string, int> owner_user_id + nombre de lignes copiées par flux
     */
    public function run(?int $ownerUserId = null): array
    {
        $ownerId = $this->resolveOwnerId($ownerUserId);
        $topology = new MeterTopology($this->pdo);
        $meterId = $topology->ensureElectricityMeter($ownerId);
        $registers = $topology->ensureRegisters($meterId, array_keys(self::ELECTRICITY_MAP));

        $counts = ['owner_user_id' => $ownerId];

        foreach (self::ELECTRICITY_MAP as $key => [$table, $column]) {
            $registerId = $registers[$key] ?? null;
            if ($registerId === null) {
                continue;
            }
            $counts[$key] = $this->copyMeterReadings($registerId, $table, $column);
        }

        $counts['gas'] = $this->copyUtility($ownerId, 'gas', 'Data_gaz');
        $counts['water'] = $this->copyUtility($ownerId, 'water', 'Data_eau');

        return $counts;
    }

    private function resolveOwnerId(?int $explicit): int
    {
        if ($explicit !== null) {
            if ($this->users->findById($explicit) === null) {
                throw new RuntimeException('Utilisateur #' . $explicit . ' introuvable.');
            }

            return $explicit;
        }

        $firstId = $this->firstUserId();
        if ($firstId === null) {
            throw new RuntimeException(
                'Aucun compte utilisateur : connecte-toi d\'abord pour créer ton compte, '
                . 'puis relance le backfill (ou passe --user=<id>).'
            );
        }

        return $firstId;
    }

    private function firstUserId(): ?int
    {
        $stmt = $this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1');
        if ($stmt === false) {
            return null;
        }

        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private function copyMeterReadings(int $registerId, string $table, string $column): int
    {
        // $table / $column sont des constantes internes (ELECTRICITY_MAP), pas des
        // entrées utilisateur ; register_id est lié en paramètre.
        $stmt = $this->pdo->prepare(
            "INSERT IGNORE INTO meter_readings (register_id, reading_at, index_value)
             SELECT :rid, `timestamp`, `$column` FROM `$table`"
        );
        $stmt->execute(['rid' => $registerId]);

        return $stmt->rowCount();
    }

    private function copyUtility(int $ownerId, string $energyType, string $table): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT IGNORE INTO utility_readings (user_id, energy_type, reading_at, counter_m3)
             SELECT :uid, :etype, reading_at, counter_m3 FROM `$table`"
        );
        $stmt->execute(['uid' => $ownerId, 'etype' => $energyType]);

        return $stmt->rowCount();
    }
}
