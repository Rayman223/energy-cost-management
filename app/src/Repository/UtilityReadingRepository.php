<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Contract\GasReadingRepositoryInterface;
use App\Repository\Contract\MeterReadingRepositoryInterface;
use App\Repository\Contract\UtilityIngestionInterface;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

/**
 * Relevés gaz/eau unifiés (table utility_readings), scopés par utilisateur.
 * Un seul repository pour les deux fluides : la structure est identique
 * (un index m³ par relevé) — l'energy_type distingue les flux.
 */
final class UtilityReadingRepository implements GasReadingRepositoryInterface, MeterReadingRepositoryInterface, UtilityIngestionInterface
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $userId,
        private readonly string $energyType,
    ) {
        if (!in_array($energyType, ['gas', 'water'], true)) {
            throw new InvalidArgumentException('energy_type invalide : ' . $energyType);
        }
    }

    public function save(DateTimeImmutable $readingAt, float $counterM3): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO utility_readings (user_id, energy_type, reading_at, counter_m3)
             VALUES (:uid, :etype, :reading_at, :counter_m3)'
        );
        $stmt->execute([
            'uid'        => $this->userId,
            'etype'      => $this->energyType,
            'reading_at' => $readingAt->format('Y-m-d H:i:s'),
            'counter_m3' => $counterM3,
        ]);
    }

    /**
     * Variante idempotente pour l'ingestion API/batch : INSERT IGNORE sur
     * l'unicité (user, type, horodatage). Contrairement à save(), n'échoue pas
     * sur un renvoi du même relevé.
     */
    public function saveIgnore(DateTimeImmutable $readingAt, float $counterM3): bool
    {
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO utility_readings (user_id, energy_type, reading_at, counter_m3)
             VALUES (:uid, :etype, :reading_at, :counter_m3)'
        );
        $stmt->execute([
            'uid'        => $this->userId,
            'etype'      => $this->energyType,
            'reading_at' => $readingAt->format('Y-m-d H:i:s'),
            'counter_m3' => $counterM3,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Tous les relevés, DESC (le plus récent d'abord), chacun avec son delta
     * par rapport au relevé précédent (null pour le plus ancien).
     *
     * @return array<int,array{id:int,reading_at:string,counter_m3:float,delta_m3:float|null}>
     */
    public function getAllReadings(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, reading_at, counter_m3 FROM utility_readings
             WHERE user_id = :uid AND energy_type = :etype
             ORDER BY reading_at ASC'
        );
        $stmt->execute(['uid' => $this->userId, 'etype' => $this->energyType]);
        $rowsAsc = $stmt->fetchAll();

        if (empty($rowsAsc)) {
            return [];
        }

        $result = [];
        $prev   = null;

        foreach ($rowsAsc as $row) {
            $delta = $prev !== null
                ? round((float) $row['counter_m3'] - (float) $prev['counter_m3'], 3)
                : null;

            $result[] = [
                'id'         => (int) $row['id'],
                'reading_at' => $row['reading_at'],
                'counter_m3' => (float) $row['counter_m3'],
                'delta_m3'   => $delta,
            ];
            $prev = $row;
        }

        return array_reverse($result);
    }

    /** @return array<string, mixed>|null */
    public function getLatest(): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, reading_at, counter_m3 FROM utility_readings
             WHERE user_id = :uid AND energy_type = :etype
             ORDER BY reading_at DESC LIMIT 1'
        );
        $stmt->execute(['uid' => $this->userId, 'etype' => $this->energyType]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return array{from: array<string, mixed>|null, to: array<string, mixed>|null} */
    public function getLastTwoReadings(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, reading_at, counter_m3 FROM utility_readings
             WHERE user_id = :uid AND energy_type = :etype
             ORDER BY reading_at DESC LIMIT 2'
        );
        $stmt->execute(['uid' => $this->userId, 'etype' => $this->energyType]);
        $rows = $stmt->fetchAll();

        return [
            'from' => $rows[1] ?? null,
            'to'   => $rows[0] ?? null,
        ];
    }

    /**
     * Fenêtre de relevés pour l'interpolation à minuit d'un mois : dernier
     * relevé avant le mois ∪ relevés du mois ∪ premier relevé après le mois.
     *
     * @return list<array{reading_at: string, counter_m3: float}>
     */
    public function getReadingsForInterpolation(int $year, int $month): array
    {
        $firstOfMonth = sprintf('%04d-%02d-01 00:00:00', $year, $month);

        $nextYear    = $month === 12 ? $year + 1 : $year;
        $nextMonth   = $month === 12 ? 1         : $month + 1;
        $firstOfNext = sprintf('%04d-%02d-01 00:00:00', $nextYear, $nextMonth);

        // Placeholders suffixés : PDO (mode natif) n'autorise pas la
        // réutilisation d'un placeholder nommé dans une même requête.
        $sql = '(SELECT reading_at, counter_m3 FROM utility_readings'
            . '   WHERE user_id = :uid AND energy_type = :etype AND reading_at < :start'
            . '   ORDER BY reading_at DESC LIMIT 1)'
            . ' UNION ALL '
            . '(SELECT reading_at, counter_m3 FROM utility_readings'
            . '   WHERE user_id = :uid2 AND energy_type = :etype2 AND reading_at >= :start2 AND reading_at < :next'
            . '   ORDER BY reading_at ASC)'
            . ' UNION ALL '
            . '(SELECT reading_at, counter_m3 FROM utility_readings'
            . '   WHERE user_id = :uid3 AND energy_type = :etype3 AND reading_at >= :next2'
            . '   ORDER BY reading_at ASC LIMIT 1)'
            . ' ORDER BY reading_at ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'uid'    => $this->userId,
            'etype'  => $this->energyType,
            'uid2'   => $this->userId,
            'etype2' => $this->energyType,
            'uid3'   => $this->userId,
            'etype3' => $this->energyType,
            'start'  => $firstOfMonth,
            'start2' => $firstOfMonth,
            'next'   => $firstOfNext,
            'next2'  => $firstOfNext,
        ]);

        return array_map(
            static fn (array $row): array => [
                'reading_at' => (string) $row['reading_at'],
                'counter_m3' => (float) $row['counter_m3'],
            ],
            $stmt->fetchAll(),
        );
    }

    /**
     * Relevés strictement après $fromExclusive et jusqu'à $toInclusive,
     * au format {timestamp, value} attendu par le service de sync.
     *
     * @return array<int, array{timestamp: string, value: string}>
     */
    public function fetchReadingsSince(
        ?DateTimeImmutable $fromExclusive,
        DateTimeImmutable $toInclusive
    ): array {
        $sql    = 'SELECT reading_at AS timestamp, counter_m3 AS value
                FROM utility_readings
                WHERE user_id = :uid AND energy_type = :etype AND reading_at <= :to';
        $params = [
            'uid'   => $this->userId,
            'etype' => $this->energyType,
            'to'    => $toInclusive->format('Y-m-d H:i:s'),
        ];

        if ($fromExclusive !== null) {
            $sql           .= ' AND reading_at > :from';
            $params['from'] = $fromExclusive->format('Y-m-d H:i:s');
        }

        $sql .= ' ORDER BY reading_at ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }
}
