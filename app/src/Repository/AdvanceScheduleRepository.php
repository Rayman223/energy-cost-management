<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\AdvanceSchedule;
use App\Repository\Contract\AdvanceScheduleRepositoryInterface;
use DateTimeImmutable;
use PDO;

/**
 * Accès à `energy_advances` : les barèmes d'acomptes mensuels saisis par
 * l'utilisateur (#241).
 *
 * Scope tenant dur en constructeur, comme {@see EnergyBillRepository} : `user_id`
 * n'apparaît jamais dans une signature publique, il est injecté une fois et
 * appliqué à chaque requête — y compris à l'UPDATE et au DELETE, pour qu'un
 * identifiant deviné ne touche pas la ligne d'autrui.
 */
final class AdvanceScheduleRepository implements AdvanceScheduleRepositoryInterface
{
    private const COLUMNS = 'id, energy_type, amount_monthly, valid_from, valid_to, due_day, note';

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $userId,
    ) {
    }

    /** @return list<AdvanceSchedule> */
    public function listFor(?string $energyType = null): array
    {
        $sql    = 'SELECT ' . self::COLUMNS . ' FROM energy_advances WHERE user_id = :uid';
        $params = ['uid' => $this->userId];

        if ($energyType !== null) {
            $sql .= ' AND energy_type = :type';
            $params['type'] = $energyType;
        }

        $sql .= ' ORDER BY energy_type ASC, valid_from DESC, id DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $this->hydrate($stmt->fetchAll());
    }

    /** @return list<AdvanceSchedule> */
    public function findOverlapping(
        string $energyType,
        DateTimeImmutable $validFrom,
        ?DateTimeImmutable $validTo,
        ?int $exceptId = null,
    ): array {
        // Chevauchement de deux intervalles, bornes de fin ouvertes gérées en SQL :
        // `valid_to IS NULL` court indéfiniment, tout comme un $validTo absent.
        $sql = 'SELECT ' . self::COLUMNS . ' FROM energy_advances
                 WHERE user_id = :uid
                   AND energy_type = :type
                   AND (:to IS NULL OR valid_from <= :to2)
                   AND (valid_to IS NULL OR valid_to >= :from)';

        $params = [
            'uid'  => $this->userId,
            'type' => $energyType,
            // Placeholders dupliqués : PDO en mode natif n'autorise pas la
            // réutilisation d'un placeholder nommé dans une même requête.
            'to'   => $validTo?->format('Y-m-d'),
            'to2'  => $validTo?->format('Y-m-d'),
            'from' => $validFrom->format('Y-m-d'),
        ];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :except';
            $params['except'] = $exceptId;
        }

        $stmt = $this->pdo->prepare($sql . ' ORDER BY valid_from ASC');
        $stmt->execute($params);

        return $this->hydrate($stmt->fetchAll());
    }

    public function insert(
        string $energyType,
        float $amountMonthly,
        DateTimeImmutable $validFrom,
        ?DateTimeImmutable $validTo,
        int $dueDay,
        string $note = '',
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO energy_advances (user_id, energy_type, amount_monthly, valid_from, valid_to, due_day, note)
             VALUES (:uid, :type, :amount, :from, :to, :due, :note)'
        );

        $stmt->execute([
            'uid'    => $this->userId,
            'type'   => $energyType,
            'amount' => $amountMonthly,
            'from'   => $validFrom->format('Y-m-d'),
            'to'     => $validTo?->format('Y-m-d'),
            'due'    => $dueDay,
            'note'   => $note,
        ]);
    }

    public function update(
        int $id,
        string $energyType,
        float $amountMonthly,
        DateTimeImmutable $validFrom,
        ?DateTimeImmutable $validTo,
        int $dueDay,
        string $note = '',
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE energy_advances
                SET energy_type = :type, amount_monthly = :amount, valid_from = :from,
                    valid_to = :to, due_day = :due, note = :note
              WHERE id = :id AND user_id = :uid'
        );

        $stmt->execute([
            'id'     => $id,
            'uid'    => $this->userId,
            'type'   => $energyType,
            'amount' => $amountMonthly,
            'from'   => $validFrom->format('Y-m-d'),
            'to'     => $validTo?->format('Y-m-d'),
            'due'    => $dueDay,
            'note'   => $note,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM energy_advances WHERE id = :id AND user_id = :uid');
        $stmt->execute(['id' => $id, 'uid' => $this->userId]);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return list<AdvanceSchedule>
     */
    private function hydrate(array $rows): array
    {
        return array_map(
            static fn (array $row): AdvanceSchedule => AdvanceSchedule::fromRow([
                'id'             => (int) $row['id'],
                'energy_type'    => (string) $row['energy_type'],
                'amount_monthly' => (string) $row['amount_monthly'],
                'valid_from'     => (string) $row['valid_from'],
                'valid_to'       => $row['valid_to'] !== null ? (string) $row['valid_to'] : null,
                'due_day'        => (int) $row['due_day'],
                'note'           => (string) $row['note'],
            ]),
            $rows
        );
    }
}
