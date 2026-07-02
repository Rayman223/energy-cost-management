<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\TariffGrid;
use App\Repository\Contract\TariffRepositoryInterface;
use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Grilles tarifaires : catalogue communautaire partagé (user_id NULL, géré par
 * un admin) + surcharges personnelles (user_id renseigné). La résolution de la
 * grille active privilégie la surcharge personnelle sur le catalogue.
 */
final class TariffRepository implements TariffRepositoryInterface
{
    private const COLUMNS = 'id, user_id, energy_type, country, currency, name, valid_from, valid_to, pcs_coefficient';

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $userId,
        private readonly bool $isAdmin = false,
    ) {
    }

    /**
     * Grille active pour un type d'énergie à une date : la surcharge
     * personnelle prime sur le catalogue partagé ; à priorité égale, la grille
     * démarrée le plus récemment gagne.
     */
    public function findActiveGrid(string $energyType, ?DateTimeImmutable $on = null): ?TariffGrid
    {
        $on ??= new DateTimeImmutable('now');
        $date = $on->format('Y-m-d');

        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM tariff_grids
             WHERE energy_type = :type
               AND (user_id = :uid OR user_id IS NULL)
               AND valid_from <= :date
               AND (valid_to IS NULL OR valid_to >= :date)
             ORDER BY (user_id IS NOT NULL) DESC, valid_from DESC
             LIMIT 1'
        );
        $stmt->execute(['type' => $energyType, 'uid' => $this->userId, 'date' => $date]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    public function findById(int $id): ?TariffGrid
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM tariff_grids
             WHERE id = :id AND (user_id = :uid OR user_id IS NULL)'
        );
        $stmt->execute(['id' => $id, 'uid' => $this->userId]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    /** @return TariffGrid[] */
    public function findAll(string $energyType): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM tariff_grids
             WHERE energy_type = :type AND (user_id = :uid OR user_id IS NULL)
             ORDER BY valid_from DESC'
        );
        $stmt->execute(['type' => $energyType, 'uid' => $this->userId]);
        $rows = $stmt->fetchAll();

        if ($rows === []) {
            return [];
        }

        // Chargement de toutes les lignes en une seule requête (évite le N+1).
        $ids      = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $linesMap = $this->fetchLinesForIds($ids);

        return array_map(
            fn (array $row): TariffGrid => $this->hydrate($row, $linesMap[(int) $row['id']] ?? []),
            $rows
        );
    }

    /** @param array<string, mixed> $lines */
    public function saveGrid(
        string $energyType,
        string $name,
        DateTimeImmutable $validFrom,
        ?DateTimeImmutable $validTo,
        array $lines,
        ?float $pcsCoefficient = null,
        ?string $country = null,
        string $currency = 'EUR',
        bool $shared = false,
    ): int {
        $this->assertCanManageShared($shared);

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO tariff_grids (user_id, energy_type, country, currency, name, valid_from, valid_to, pcs_coefficient)
                 VALUES (:user_id, :type, :country, :currency, :name, :from, :to, :pcs)'
            );
            $stmt->execute([
                'user_id'  => $shared ? null : $this->userId,
                'type'     => $energyType,
                'country'  => $country,
                'currency' => $currency,
                'name'     => $name,
                'from'     => $validFrom->format('Y-m-d'),
                'to'       => $validTo?->format('Y-m-d'),
                'pcs'      => $pcsCoefficient,
            ]);
            $id = (int) $this->pdo->lastInsertId();

            $lineStmt = $this->pdo->prepare(
                'INSERT INTO tariff_grid_lines (tariff_grid_id, line_key, amount_per_kwh)
                 VALUES (:grid_id, :key, :amount)'
            );
            foreach ($lines as $key => $amount) {
                $lineStmt->execute(['grid_id' => $id, 'key' => $key, 'amount' => $amount]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $id;
    }

    /** @param array<string, mixed> $lines */
    public function updateGrid(
        int $id,
        string $energyType,
        string $name,
        DateTimeImmutable $validFrom,
        ?DateTimeImmutable $validTo,
        array $lines,
        ?float $pcsCoefficient = null,
        ?string $country = null,
        string $currency = 'EUR',
    ): void {
        $this->assertCanModify($id);

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE tariff_grids
                 SET energy_type = :type,
                     country = :country,
                     currency = :currency,
                     name = :name,
                     valid_from = :from,
                     valid_to = :to,
                     pcs_coefficient = :pcs
                 WHERE id = :id'
            );
            $stmt->execute([
                'id'       => $id,
                'type'     => $energyType,
                'country'  => $country,
                'currency' => $currency,
                'name'     => $name,
                'from'     => $validFrom->format('Y-m-d'),
                'to'       => $validTo?->format('Y-m-d'),
                'pcs'      => $pcsCoefficient,
            ]);

            $deleteStmt = $this->pdo->prepare('DELETE FROM tariff_grid_lines WHERE tariff_grid_id = :grid_id');
            $deleteStmt->execute(['grid_id' => $id]);

            $lineStmt = $this->pdo->prepare(
                'INSERT INTO tariff_grid_lines (tariff_grid_id, line_key, amount_per_kwh)
                 VALUES (:grid_id, :key, :amount)'
            );
            foreach ($lines as $key => $amount) {
                $lineStmt->execute(['grid_id' => $id, 'key' => $key, 'amount' => $amount]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function closeGrid(int $id, DateTimeImmutable $validTo): void
    {
        $this->assertCanModify($id);

        $stmt = $this->pdo->prepare(
            'UPDATE tariff_grids SET valid_to = :valid_to WHERE id = :id'
        );
        $stmt->execute(['valid_to' => $validTo->format('Y-m-d'), 'id' => $id]);
    }

    public function deleteGrid(int $id): void
    {
        $this->assertCanModify($id);

        $stmt = $this->pdo->prepare('DELETE FROM tariff_grids WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * Coefficient PCS le plus récent visible (surcharges perso + catalogue).
     */
    public function findMostRecentPcs(string $energyType, DateTimeImmutable $before): ?float
    {
        $stmt = $this->pdo->prepare(
            'SELECT pcs_coefficient
             FROM tariff_grids
             WHERE energy_type     = :type
               AND (user_id = :uid OR user_id IS NULL)
               AND pcs_coefficient IS NOT NULL
               AND valid_from      <= :date
             ORDER BY (user_id IS NOT NULL) DESC, valid_from DESC
             LIMIT 1'
        );
        $stmt->execute([
            'type' => $energyType,
            'uid'  => $this->userId,
            'date' => $before->format('Y-m-d'),
        ]);

        $val = $stmt->fetchColumn();

        return $val !== false ? (float) $val : null;
    }

    // -------------------------------------------------------------------------
    // Privé
    // -------------------------------------------------------------------------

    private function assertCanManageShared(bool $shared): void
    {
        if ($shared && $this->isAdmin === false) {
            throw new RuntimeException('Seul un administrateur peut gérer le catalogue partagé.');
        }
    }

    /**
     * Une grille n'est modifiable que par son propriétaire ; une grille du
     * catalogue partagé n'est modifiable que par un admin.
     */
    private function assertCanModify(int $id): void
    {
        $stmt = $this->pdo->prepare('SELECT user_id FROM tariff_grids WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $owner = $stmt->fetch();

        if ($owner === false) {
            throw new RuntimeException('Tarif introuvable.');
        }

        $ownerId = $owner['user_id'] !== null ? (int) $owner['user_id'] : null;

        if ($ownerId === null) {
            $this->assertCanManageShared(true);

            return;
        }

        if ($ownerId !== $this->userId) {
            throw new RuntimeException('Tarif introuvable.'); // pas de fuite d'existence cross-tenant
        }
    }

    /** @return array<string,float> */
    private function fetchLines(int $gridId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT line_key, amount_per_kwh FROM tariff_grid_lines WHERE tariff_grid_id = :id'
        );
        $stmt->execute(['id' => $gridId]);

        $lines = [];
        foreach ($stmt->fetchAll() as $row) {
            $lines[$row['line_key']] = (float) $row['amount_per_kwh'];
        }

        return $lines;
    }

    /**
     * Charge toutes les lignes pour une liste d'ids en une seule requête.
     * Utilisé par findAll() pour éviter le pattern N+1.
     *
     * @param  int[]                           $ids
     * @return array<int,array<string,float>>  Indexé par tariff_grid_id
     */
    private function fetchLinesForIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT tariff_grid_id, line_key, amount_per_kwh
             FROM tariff_grid_lines
             WHERE tariff_grid_id IN ($placeholders)"
        );
        $stmt->execute(array_values($ids));

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['tariff_grid_id']][$row['line_key']] = (float) $row['amount_per_kwh'];
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string,float>|null $preloadedLines  Lignes déjà chargées (évite un SELECT supplémentaire).
     */
    private function hydrate(array $row, ?array $preloadedLines = null): TariffGrid
    {
        $id    = (int) $row['id'];
        $lines = $preloadedLines ?? $this->fetchLines($id);

        return new TariffGrid(
            id: $id,
            energyType: $row['energy_type'],
            name: $row['name'],
            validFrom: new DateTimeImmutable($row['valid_from']),
            validTo: $row['valid_to'] ? new DateTimeImmutable($row['valid_to']) : null,
            lines: $lines,
            pcsCoefficient: isset($row['pcs_coefficient']) ? (float) $row['pcs_coefficient'] : null,
            userId: $row['user_id'] !== null ? (int) $row['user_id'] : null,
            country: $row['country'] !== null ? (string) $row['country'] : null,
            currency: (string) $row['currency'],
        );
    }
}
