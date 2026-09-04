<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Contract\GasReadingRepositoryInterface;
use App\Repository\Contract\MeterReadingRepositoryInterface;
use App\Repository\Contract\UtilityIngestionInterface;
use App\Support\Dates;
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
            'reading_at' => Dates::toDbString($readingAt),
            'counter_m3' => $counterM3,
        ]);
    }

    /**
     * Variante idempotente pour l'ingestion API/batch : INSERT IGNORE sur
     * l'unicité (user, type, horodatage). Contrairement à save(), n'échoue pas
     * sur un renvoi du même relevé.
     *
     * En mode $replace (ré-import « écraser »), un relevé déjà présent au même
     * horodatage est mis à jour (ON DUPLICATE KEY UPDATE) — pour corriger un
     * import fautif sans passer par la suppression. rowCount() vaut alors 2 pour
     * une mise à jour, 1 pour une insertion, 0 si la valeur était identique : on
     * renvoie true dès qu'une ligne a été insérée ou modifiée.
     */
    public function saveIgnore(DateTimeImmutable $readingAt, float $counterM3, bool $replace = false): bool
    {
        $sql = $replace
            ? 'INSERT INTO utility_readings (user_id, energy_type, reading_at, counter_m3)
               VALUES (:uid, :etype, :reading_at, :counter_m3)
               ON DUPLICATE KEY UPDATE counter_m3 = VALUES(counter_m3)'
            : 'INSERT IGNORE INTO utility_readings (user_id, energy_type, reading_at, counter_m3)
               VALUES (:uid, :etype, :reading_at, :counter_m3)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'uid'        => $this->userId,
            'etype'      => $this->energyType,
            'reading_at' => Dates::toDbString($readingAt),
            'counter_m3' => $counterM3,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Supprime un relevé précis, scopé sur l'utilisateur ET le type de fluide :
     * un id appartenant à un autre utilisateur (ou à l'autre fluide) n'est jamais
     * touché.
     *
     * @return bool true si une ligne a été supprimée.
     */
    public function deleteReading(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM utility_readings
             WHERE id = :id AND user_id = :uid AND energy_type = :etype'
        );
        $stmt->execute(['id' => $id, 'uid' => $this->userId, 'etype' => $this->energyType]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Supprime tous les relevés du fluide pour l'utilisateur (pour repartir
     * propre après un import raté).
     *
     * @return int Nombre de relevés supprimés.
     */
    public function deleteAll(): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM utility_readings WHERE user_id = :uid AND energy_type = :etype'
        );
        $stmt->execute(['uid' => $this->userId, 'etype' => $this->energyType]);

        return $stmt->rowCount();
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

        return array_reverse($this->withDeltas($stmt->fetchAll()));
    }

    /** Nombre total de relevés du fluide (dénominateur de la pagination, #257). */
    public function countReadings(): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM utility_readings WHERE user_id = :uid AND energy_type = :etype'
        );
        $stmt->execute(['uid' => $this->userId, 'etype' => $this->energyType]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Une page de relevés, DESC (le plus récent d'abord), même forme que
     * getAllReadings() (#257).
     *
     * On lit $perPage + 1 lignes : la ligne excédentaire est le relevé
     * immédiatement plus ancien que la page, nécessaire au delta de la dernière
     * ligne — sans elle, la ligne de frontière afficherait « — » alors qu'un
     * précédent existe. Elle est écartée du résultat. `delta_m3` ne reste donc
     * null que pour le tout premier relevé de l'historique.
     *
     * @return list<array{id:int,reading_at:string,counter_m3:float,delta_m3:float|null}>
     */
    public function getReadingsPage(int $perPage, int $offset): array
    {
        $perPage = max(1, $perPage);
        $offset  = max(0, $offset);
        // Interpolés (entiers castés) plutôt que liés : PDO en mode émulation
        // désactivée passerait LIMIT/OFFSET en chaînes, que MySQL rejette.
        $limit = $perPage + 1;

        $stmt = $this->pdo->prepare(
            "SELECT id, reading_at, counter_m3 FROM utility_readings
             WHERE user_id = :uid AND energy_type = :etype
             ORDER BY reading_at DESC, id DESC
             LIMIT $limit OFFSET $offset"
        );
        $stmt->execute(['uid' => $this->userId, 'etype' => $this->energyType]);
        $rowsDesc = $stmt->fetchAll();

        // withDeltas() attend un ordre croissant : on lui donne la page renversée
        // (la ligne excédentaire, la plus ancienne, se retrouve en tête et sert
        // de base au delta suivant), puis on la retire et on repasse en DESC.
        $withDeltas = $this->withDeltas(array_reverse($rowsDesc));
        if (count($rowsDesc) > $perPage) {
            array_shift($withDeltas);
        }

        return array_reverse($withDeltas);
    }

    /**
     * Ajoute à chaque relevé son delta par rapport au précédent. Attend des
     * lignes triées par horodatage CROISSANT ; renvoie le même ordre.
     *
     * @param  list<array<string,mixed>> $rowsAsc
     * @return list<array{id:int,reading_at:string,counter_m3:float,delta_m3:float|null}>
     */
    private function withDeltas(array $rowsAsc): array
    {
        $result = [];
        $prev   = null;

        foreach ($rowsAsc as $row) {
            $counter = (float) $row['counter_m3'];
            $result[] = [
                'id'         => (int) $row['id'],
                'reading_at' => (string) $row['reading_at'],
                'counter_m3' => $counter,
                'delta_m3'   => $prev !== null ? round($counter - $prev, 3) : null,
            ];
            $prev = $counter;
        }

        return $result;
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

    /** @return array<string, mixed>|null */
    public function getReadingBefore(DateTimeImmutable $ts): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, reading_at, counter_m3 FROM utility_readings
             WHERE user_id = :uid AND energy_type = :etype AND reading_at <= :ts
             ORDER BY reading_at DESC LIMIT 1'
        );
        $stmt->execute(['uid' => $this->userId, 'etype' => $this->energyType, 'ts' => Dates::toDbString($ts)]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    public function getReadingAfter(DateTimeImmutable $ts): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, reading_at, counter_m3 FROM utility_readings
             WHERE user_id = :uid AND energy_type = :etype AND reading_at >= :ts
             ORDER BY reading_at ASC LIMIT 1'
        );
        $stmt->execute(['uid' => $this->userId, 'etype' => $this->energyType, 'ts' => Dates::toDbString($ts)]);
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
     * Enveloppe de {@see getReadingsForRange()} sur les bornes du mois.
     *
     * @return list<array{reading_at: string, counter_m3: float}>
     */
    public function getReadingsForInterpolation(int $year, int $month): array
    {
        $firstOfMonth = sprintf('%04d-%02d-01 00:00:00', $year, $month);

        $nextYear    = $month === 12 ? $year + 1 : $year;
        $nextMonth   = $month === 12 ? 1         : $month + 1;
        $firstOfNext = sprintf('%04d-%02d-01 00:00:00', $nextYear, $nextMonth);

        return $this->getReadingsForRange($firstOfMonth, $firstOfNext);
    }

    /**
     * Même fenêtre d'interpolation, sur des bornes QUELCONQUES (#241) : dernier
     * relevé avant $from ∪ relevés de [$from, $to[ ∪ premier relevé à/après $to.
     *
     * Les deux relevés d'encadrement sont indispensables : sans eux, une borne
     * tombant entre deux relevés serait extrapolée avec la pente d'un segment de
     * bord au lieu d'être interpolée sur le segment qui la contient réellement.
     *
     * @param string $from Borne de début incluse, format DB 'Y-m-d H:i:s' (UTC).
     * @param string $to   Borne de fin exclue, même format.
     * @return list<array{reading_at: string, counter_m3: float}>
     */
    public function getReadingsForRange(string $from, string $to): array
    {
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
            'start'  => $from,
            'start2' => $from,
            'next'   => $to,
            'next2'  => $to,
        ]);

        return array_map(
            static fn (array $row): array => [
                'reading_at' => (string) $row['reading_at'],
                'counter_m3' => (float) $row['counter_m3'],
            ],
            $stmt->fetchAll(),
        );
    }
}
