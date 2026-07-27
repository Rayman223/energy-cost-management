<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\EnergyBill;
use App\Repository\Contract\EnergyBillRepositoryInterface;
use PDO;

/**
 * Accès à `energy_bills` : les montants énergie facturés saisis par l'utilisateur (#229).
 *
 * Scope tenant dur en constructeur, comme TariffRepository : `user_id` n'apparaît jamais
 * dans une signature publique, il est injecté une fois et appliqué à chaque requête —
 * y compris au DELETE, pour qu'un identifiant deviné ne supprime pas la ligne d'autrui.
 */
final class EnergyBillRepository implements EnergyBillRepositoryInterface
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $userId,
    ) {
    }

    /** @return list<EnergyBill> */
    public function listFor(string $energyType, int $limit, int $offset = 0): array
    {
        // LIMIT/OFFSET interpolés après cast entier : MySQL refuse un paramètre lié à
        // cet emplacement en mode requête préparée émulée désactivé.
        $limit  = max(1, $limit);
        $offset = max(0, $offset);

        $stmt = $this->pdo->prepare(
            'SELECT id, energy_type, period_year, period_month, amount_energy_htva, amount_energy_ttc, note
               FROM energy_bills
              WHERE user_id = :uid AND energy_type = :type
              ORDER BY period_year DESC, period_month DESC
              LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $stmt->execute(['uid' => $this->userId, 'type' => $energyType]);

        /** @var array<int, array{id: string, energy_type: string, period_year: string, period_month: string, amount_energy_htva: ?string, amount_energy_ttc: ?string, note: string}> $rows */
        $rows = $stmt->fetchAll();

        return array_map(
            static fn (array $row): EnergyBill => new EnergyBill(
                id:         (int) $row['id'],
                energyType: $row['energy_type'],
                year:       (int) $row['period_year'],
                month:      (int) $row['period_month'],
                // Les DECIMAL nullables reviennent en string ou null : ne caster qu'un
                // montant réellement saisi, (float) null valant 0.0 — un « 0 € facturé »
                // et un « pas encore renseigné » ne veulent pas dire la même chose.
                amountHtva: $row['amount_energy_htva'] !== null ? (float) $row['amount_energy_htva'] : null,
                amountTtc:  $row['amount_energy_ttc'] !== null ? (float) $row['amount_energy_ttc'] : null,
                note:       $row['note'],
            ),
            $rows
        );
    }

    public function countFor(string $energyType): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM energy_bills WHERE user_id = :uid AND energy_type = :type'
        );
        $stmt->execute(['uid' => $this->userId, 'type' => $energyType]);

        return (int) $stmt->fetchColumn();
    }

    public function upsert(
        string $energyType,
        int $year,
        int $month,
        ?float $amountHtva,
        ?float $amountTtc,
        string $note = '',
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO energy_bills (user_id, energy_type, period_year, period_month, amount_energy_htva, amount_energy_ttc, note)
             VALUES (:uid, :type, :year, :month, :htva, :ttc, :note)
             ON DUPLICATE KEY UPDATE
                 amount_energy_htva = VALUES(amount_energy_htva),
                 amount_energy_ttc  = VALUES(amount_energy_ttc),
                 note               = VALUES(note)'
        );

        $stmt->execute([
            'uid'   => $this->userId,
            'type'  => $energyType,
            'year'  => $year,
            'month' => $month,
            'htva'  => $amountHtva,
            'ttc'   => $amountTtc,
            'note'  => $note,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM energy_bills WHERE id = :id AND user_id = :uid');
        $stmt->execute(['id' => $id, 'uid' => $this->userId]);
    }
}
