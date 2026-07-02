<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Opt-in EnergyID par utilisateur (BE/NL). Aucun secret stocké : seulement
 * l'activation, le deviceId (dérivé, non secret) et le statut de claim.
 */
final class EnergyIdIntegrationRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{enabled: bool, device_id: string, claimed_at: ?string}|null
     */
    public function get(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT enabled, device_id, claimed_at FROM energyid_integrations WHERE user_id = :uid'
        );
        $stmt->execute(['uid' => $userId]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return null;
        }

        return [
            'enabled'    => (bool) $row['enabled'],
            'device_id'  => (string) $row['device_id'],
            'claimed_at' => $row['claimed_at'] !== null ? (string) $row['claimed_at'] : null,
        ];
    }

    /** Active l'intégration (crée la ligne au besoin) avec le deviceId fourni. */
    public function enable(int $userId, string $deviceId): void
    {
        $this->pdo->prepare(
            'INSERT INTO energyid_integrations (user_id, enabled, device_id)
             VALUES (:uid, 1, :device)
             ON DUPLICATE KEY UPDATE enabled = 1, device_id = VALUES(device_id)'
        )->execute(['uid' => $userId, 'device' => $deviceId]);
    }

    public function disable(int $userId): void
    {
        $this->pdo->prepare('UPDATE energyid_integrations SET enabled = 0 WHERE user_id = :uid')
            ->execute(['uid' => $userId]);
    }

    /** Marque le device réclamé (webhook obtenu) — statut affiché à l'utilisateur. */
    public function markClaimed(int $userId): void
    {
        $this->pdo->prepare(
            'UPDATE energyid_integrations SET claimed_at = NOW() WHERE user_id = :uid AND claimed_at IS NULL'
        )->execute(['uid' => $userId]);
    }

    /**
     * @return list<array{user_id: int, device_id: string}> utilisateurs actifs (pour le cron)
     */
    public function listEnabled(): array
    {
        $stmt = $this->pdo->query(
            'SELECT user_id, device_id FROM energyid_integrations WHERE enabled = 1 ORDER BY user_id'
        );
        if ($stmt === false) {
            return [];
        }

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = ['user_id' => (int) $row['user_id'], 'device_id' => (string) $row['device_id']];
        }

        return $out;
    }
}
