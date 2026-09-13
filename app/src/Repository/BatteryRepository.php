<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Battery;
use App\Repository\Contract\BatteryRepositoryInterface;
use App\Repository\Exception\LimitReachedException;
use App\Support\Limits;
use PDO;

/**
 * Accès à `batteries` : le parc de batteries domestiques saisi par l'utilisateur (#26).
 *
 * Scope tenant dur en constructeur, comme {@see AdvanceScheduleRepository} :
 * `user_id` n'apparaît jamais dans une signature publique, il est injecté une fois
 * et appliqué à chaque requête — lecture comme écriture.
 */
final class BatteryRepository implements BatteryRepositoryInterface
{
    private const COLUMNS = 'id, brand, model, capacity_kwh, usable_capacity_kwh, purchase_price,
                             commissioned_on, decommissioned_on, warranty_until, rated_cycles,
                             pv_charge_share, discharge_profile, discharge_t1_share, note';

    /**
     * @param int $maxPerUser Plafond anti-abus du parc, cf. {@see Limits::metersPerEnergy()} —
     *                        une batterie pose le même problème d'exploitation qu'un
     *                        compteur, elle partage donc son plafond plutôt qu'un
     *                        second réglage à oublier de renseigner. Défaut égal au
     *                        défaut de configuration : un appelant qui oublierait de
     *                        le passer obtient la garde standard, jamais l'absence
     *                        de garde.
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $userId,
        private readonly int $maxPerUser = Limits::DEFAULT_METERS_PER_ENERGY,
    ) {
    }

    /** @return list<Battery> */
    public function listAll(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM batteries
              WHERE user_id = :uid
              ORDER BY commissioned_on DESC, id DESC'
        );
        $stmt->execute(['uid' => $this->userId]);

        return $this->hydrate($stmt->fetchAll());
    }

    public function find(int $id): ?Battery
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM batteries WHERE id = :id AND user_id = :uid LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'uid' => $this->userId]);

        $rows = $this->hydrate($stmt->fetchAll());

        return $rows[0] ?? null;
    }

    /** Taille du parc, pour la garde de plafond et pour l'afficher à l'écran. */
    public function count(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM batteries WHERE user_id = :uid');
        $stmt->execute(['uid' => $this->userId]);

        return (int) $stmt->fetchColumn();
    }

    public function owns(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM batteries WHERE id = :id AND user_id = :uid LIMIT 1');
        $stmt->execute(['id' => $id, 'uid' => $this->userId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @throws LimitReachedException si le parc a atteint son plafond
     */
    public function insert(Battery $battery): int
    {
        // Garde ici et pas seulement dans la route : le repository est le seul
        // point commun à tous les chemins de création, présents et à venir.
        // Course possible entre deux requêtes simultanées, comme pour
        // {@see ApiTokenRepository::create()} : le dépassement serait alors d'une
        // unité, sans conséquence.
        if ($this->count() >= $this->maxPerUser) {
            throw LimitReachedException::batteries($this->maxPerUser);
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO batteries
                (user_id, brand, model, capacity_kwh, usable_capacity_kwh, purchase_price,
                 commissioned_on, decommissioned_on, warranty_until, rated_cycles,
                 pv_charge_share, discharge_profile, discharge_t1_share, note)
             VALUES
                (:uid, :brand, :model, :capacity, :usable, :price,
                 :from, :to, :warranty, :cycles,
                 :pv_share, :profile, :t1_share, :note)'
        );
        $stmt->execute(['uid' => $this->userId] + $this->params($battery));

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, Battery $battery): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE batteries
                SET brand = :brand, model = :model, capacity_kwh = :capacity,
                    usable_capacity_kwh = :usable, purchase_price = :price,
                    commissioned_on = :from, decommissioned_on = :to,
                    warranty_until = :warranty, rated_cycles = :cycles,
                    pv_charge_share = :pv_share, discharge_profile = :profile,
                    discharge_t1_share = :t1_share, note = :note
              WHERE id = :id AND user_id = :uid'
        );
        $stmt->execute(['id' => $id, 'uid' => $this->userId] + $this->params($battery));
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM batteries WHERE id = :id AND user_id = :uid');
        $stmt->execute(['id' => $id, 'uid' => $this->userId]);
    }

    /**
     * Paramètres communs à l'INSERT et à l'UPDATE — les deux écrivent exactement
     * les mêmes colonnes, une seule conversion évite qu'elles divergent.
     *
     * `discharge_t1_share` n'est conservée que pour le profil qui la consomme : la
     * garder après un changement de profil laisserait en base une valeur muette,
     * qui ressortirait à l'écran comme si elle entrait dans le calcul.
     *
     * @return array<string, string|int|float|null>
     */
    private function params(Battery $battery): array
    {
        return [
            'brand'    => $battery->brand,
            'model'    => $battery->model,
            'capacity' => $battery->capacityKwh,
            'usable'   => $battery->usableCapacityKwh,
            'price'    => $battery->purchasePrice,
            'from'     => $battery->commissionedOn->format('Y-m-d'),
            'to'       => $battery->decommissionedOn?->format('Y-m-d'),
            'warranty' => $battery->warrantyUntil?->format('Y-m-d'),
            'cycles'   => $battery->ratedCycles,
            'pv_share' => $battery->pvChargeShare,
            'profile'  => $battery->dischargeProfile->value,
            't1_share' => $battery->dischargeProfile->requiresT1Share() ? $battery->dischargeT1Share : null,
            'note'     => $battery->note,
        ];
    }

    /**
     * @param array<int, mixed> $rows
     * @return list<Battery>
     */
    private function hydrate(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $out[] = Battery::fromRow([
                'id'                  => (int) $row['id'],
                'brand'               => (string) $row['brand'],
                'model'               => (string) $row['model'],
                'capacity_kwh'        => (string) $row['capacity_kwh'],
                'usable_capacity_kwh' => $row['usable_capacity_kwh'] !== null ? (string) $row['usable_capacity_kwh'] : null,
                'purchase_price'      => $row['purchase_price'] !== null ? (string) $row['purchase_price'] : null,
                'commissioned_on'     => (string) $row['commissioned_on'],
                'decommissioned_on'   => $row['decommissioned_on'] !== null ? (string) $row['decommissioned_on'] : null,
                'warranty_until'      => $row['warranty_until'] !== null ? (string) $row['warranty_until'] : null,
                'rated_cycles'        => $row['rated_cycles'] !== null ? (int) $row['rated_cycles'] : null,
                'pv_charge_share'     => (int) $row['pv_charge_share'],
                'discharge_profile'   => (string) $row['discharge_profile'],
                'discharge_t1_share'  => $row['discharge_t1_share'] !== null ? (int) $row['discharge_t1_share'] : null,
                'note'                => (string) $row['note'],
            ]);
        }

        return $out;
    }
}
