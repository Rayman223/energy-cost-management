<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use PDO;

/**
 * État de synchronisation webhook (EnergyID), scopé par utilisateur :
 * horodatage du dernier envoi pour chaque flux (prelevement-jour, gas-index…).
 */
final class WebhookSyncStateRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $userId,
    ) {
    }

    public function getLastSentAt(string $source): ?DateTimeImmutable
    {
        $stmt = $this->pdo->prepare(
            'SELECT last_sent_at FROM webhook_sync_state WHERE user_id = :uid AND source_name = :source LIMIT 1'
        );
        $stmt->execute(['uid' => $this->userId, 'source' => $source]);
        $value = $stmt->fetchColumn();

        return $value ? new DateTimeImmutable((string) $value) : null;
    }

    public function saveLastSentAt(string $source, DateTimeImmutable $lastSentAt): void
    {
        $sql = <<<'SQL'
            INSERT INTO webhook_sync_state (user_id, source_name, last_sent_at)
            VALUES (:uid, :source, :last_sent_at)
            ON DUPLICATE KEY UPDATE last_sent_at = VALUES(last_sent_at)
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'uid'          => $this->userId,
            'source'       => $source,
            'last_sent_at' => $lastSentAt->format('Y-m-d H:i:s'),
        ]);
    }
}
