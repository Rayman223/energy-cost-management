<?php

declare(strict_types=1);

namespace App\Service;

use PDO;

/**
 * Effacement RGPD (droit à l'effacement) : supprime le compte et TOUTES ses
 * données, en transaction. La plupart des tables cascadent via FK ; celles sans
 * FK vers users (tariff_grids personnelles, webhook_sync_state) sont supprimées
 * explicitement avant le compte.
 */
final class AccountEraser
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function erase(int $userId): void
    {
        $this->pdo->beginTransaction();
        try {
            // Sans FK cascade vers users → suppression explicite.
            $this->delete('DELETE FROM tariff_grids WHERE user_id = :uid', $userId);
            $this->delete('DELETE FROM webhook_sync_state WHERE user_id = :uid', $userId);

            // Cascade : user_profiles, meters→registers→readings, utility_readings,
            // api_tokens, energyid_integrations.
            $this->delete('DELETE FROM users WHERE id = :uid', $userId);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function delete(string $sql, int $userId): void
    {
        $this->pdo->prepare($sql)->execute(['uid' => $userId]);
    }
}
