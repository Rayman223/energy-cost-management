<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use PDO;

final class LegacyDailyRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function getLastSentAt(string $source): ?DateTimeImmutable
    {
        $stmt = $this->pdo->prepare('SELECT last_sent_at FROM webhook_sync_state WHERE source_name = :source LIMIT 1');
        $stmt->execute(['source' => $source]);
        $value = $stmt->fetchColumn();

        return $value ? new DateTimeImmutable((string) $value) : null;
    }

    public function saveLastSentAt(string $source, DateTimeImmutable $lastSentAt): void
    {
        $sql = <<<'SQL'
            INSERT INTO webhook_sync_state (source_name, last_sent_at)
            VALUES (:source, :last_sent_at)
            ON DUPLICATE KEY UPDATE last_sent_at = VALUES(last_sent_at)
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'source' => $source,
            'last_sent_at' => $lastSentAt->format('Y-m-d H:i:s'),
        ]);
    }

    /** @return array<int,array{timestamp:string,value:string}> */
    public function fetchDriesDailyFirstValues(
        string $column,
        ?DateTimeImmutable $fromExclusive,
        DateTimeImmutable $toInclusive
    ): array {
        $allowedColumns = ['Prelev_jour', 'Prelev_nuit', 'Injec_jour', 'Injec_nuit'];
        if (!in_array($column, $allowedColumns, true)) {
            throw new \InvalidArgumentException('Invalid Data_Dries column: ' . $column);
        }

        $boundsWhere = 'timestamp <= :to';
        $params = ['to' => $toInclusive->format('Y-m-d H:i:s')];

        // Important: sync state stores the last sent point for a given day.
        // We explicitly exclude that full day (DATE > last_sent_day) to avoid duplicates,
        // without relying on an artificial "next day 00:00" timestamp boundary.
        if ($fromExclusive !== null) {
            $boundsWhere .= ' AND DATE(timestamp) > :from_day';
            $params['from_day'] = $fromExclusive->format('Y-m-d');
        }

        $sql = sprintf(
            "SELECT d.timestamp, d.%s AS value\n"
            . "FROM Data_Dries d\n"
            . "INNER JOIN (\n"
            . "    SELECT DATE(timestamp) AS day_key, MIN(timestamp) AS min_timestamp\n"
            . "    FROM Data_Dries\n"
            . "    WHERE %s\n"
            . "    GROUP BY DATE(timestamp)\n"
            . ") first_per_day ON first_per_day.min_timestamp = d.timestamp\n"
            . "ORDER BY d.timestamp ASC",
            $column,
            $boundsWhere
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @return array<int,array{timestamp:string,value:string}> */
    public function fetchSolaireDailyFirstValues(?DateTimeImmutable $fromExclusive, DateTimeImmutable $toInclusive): array
    {
        $table = $this->tableExists('Data_Solaire') ? 'Data_Solaire' : 'Data_Brusol';

        $boundsWhere = 'timestamp <= :to';
        $params = ['to' => $toInclusive->format('Y-m-d H:i:s')];

        if ($fromExclusive !== null) {
            $boundsWhere .= ' AND DATE(timestamp) > :from_day';
            $params['from_day'] = $fromExclusive->format('Y-m-d');
        }

        $sql = sprintf(
            "SELECT s.timestamp, s.production AS value\n"
            . "FROM %s s\n"
            . "INNER JOIN (\n"
            . "    SELECT DATE(timestamp) AS day_key, MIN(timestamp) AS min_timestamp\n"
            . "    FROM %s\n"
            . "    WHERE %s\n"
            . "    GROUP BY DATE(timestamp)\n"
            . ") first_per_day ON first_per_day.min_timestamp = s.timestamp\n"
            . "ORDER BY s.timestamp ASC",
            $table,
            $table,
            $boundsWhere
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare('SHOW TABLES LIKE :table_name');
        $stmt->execute(['table_name' => $table]);

        return (bool) $stmt->fetchColumn();
    }
}
