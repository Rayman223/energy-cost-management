<?php

declare(strict_types=1);

namespace App\Repository;

use App\Infrastructure\MeterTopology;
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
 *
 * Depuis #55 chaque relevé porte un `meter_id`, qui est la source de vérité de
 * son unicité. `user_id` et `energy_type` restent dénormalisés sur la table —
 * ils gardent la frontière multi-tenant sous forme de prédicat de colonne — mais
 * les écritures les DÉRIVENT du compteur résolu, jamais du constructeur : c'est
 * ce qui empêche les deux sources de diverger.
 */
final class UtilityReadingRepository implements GasReadingRepositoryInterface, MeterReadingRepositoryInterface, UtilityIngestionInterface
{
    /** Compteur d'écriture, résolu paresseusement (créé au premier relevé). */
    private ?int $meterId = null;

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
            'INSERT INTO utility_readings (user_id, meter_id, energy_type, reading_at, counter_m3)
             VALUES (:uid, :mid, :etype, :reading_at, :counter_m3)'
        );
        $stmt->execute([
            'uid'        => $this->userId,
            'mid'        => $this->writeMeterId(),
            'etype'      => $this->energyType,
            'reading_at' => Dates::toDbString($readingAt),
            'counter_m3' => $counterM3,
        ]);
    }

    /**
     * Variante idempotente pour l'ingestion API/batch : INSERT IGNORE sur
     * l'unicité (compteur, horodatage). Contrairement à save(), n'échoue pas
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
            ? 'INSERT INTO utility_readings (user_id, meter_id, energy_type, reading_at, counter_m3)
               VALUES (:uid, :mid, :etype, :reading_at, :counter_m3)
               ON DUPLICATE KEY UPDATE counter_m3 = VALUES(counter_m3)'
            : 'INSERT IGNORE INTO utility_readings (user_id, meter_id, energy_type, reading_at, counter_m3)
               VALUES (:uid, :mid, :etype, :reading_at, :counter_m3)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'uid'        => $this->userId,
            'mid'        => $this->writeMeterId(),
            'etype'      => $this->energyType,
            'reading_at' => Dates::toDbString($readingAt),
            'counter_m3' => $counterM3,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Compteur visé par les écritures : le compteur par défaut de l'utilisateur
     * pour ce fluide, créé s'il n'en possède pas encore.
     *
     * Résolution PARESSEUSE, et non dans le constructeur : le repository est
     * instancié à chaque requête sur les pages de lecture (dashboard, stats),
     * où créer un compteur à vide polluerait la table et fausserait le décompte
     * de la limite par énergie.
     */
    private function writeMeterId(): int
    {
        return $this->meterId ??= (new MeterTopology($this->pdo))->ensureMeter($this->userId, $this->energyType);
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

    /**
     * Les deux relevés les plus récents du parc, AGRÉGÉS (#55).
     *
     * Prendre les deux dernières LIGNES ne marche plus dès qu'un foyer a deux
     * compteurs : elles peuvent appartenir à des compteurs différents, et leur
     * différence ne serait alors la consommation de rien du tout. On prend donc
     * les deux derniers horodatages du parc, et à chacun la somme des index de
     * tous les compteurs — la même série que {@see getReadingsForRange()}.
     *
     * `id` ne figure plus dans les lignes rendues : une somme de compteurs n'a
     * pas d'identifiant de relevé. Aucun appelant ne le lisait.
     *
     * @return array{from: array<string, mixed>|null, to: array<string, mixed>|null}
     */
    public function getLastTwoReadings(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT reading_at FROM utility_readings
             WHERE user_id = :uid AND energy_type = :etype
             ORDER BY reading_at DESC LIMIT 2'
        );
        $stmt->execute(['uid' => $this->userId, 'etype' => $this->energyType]);
        $instants = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (count($instants) < 2) {
            return ['from' => null, 'to' => null];
        }

        $to   = (string) $instants[0];
        $from = (string) $instants[1];

        // Borne haute EXCLUE dans windowRows : `$to` revient par le relevé
        // d'encadrement « premier >= $to », qui est exactement lui.
        $byTs = [];
        foreach (self::aggregate($this->windowRows($from, $to)) as $row) {
            $byTs[$row['reading_at']] = $row;
        }

        return [
            'from' => $byTs[$from] ?? null,
            'to'   => $byTs[$to] ?? null,
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
        return self::aggregate($this->windowRows($from, $to));
    }

    /**
     * Fenêtre brute, PAR COMPTEUR : pour chacun, son dernier relevé avant $from,
     * ses relevés de [$from, $to[ et son premier relevé à/après $to.
     *
     * Les relevés d'encadrement sont pris par compteur (`MAX`/`MIN … GROUP BY
     * meter_id`) et non globalement : un seul relevé d'encadrement pour tout le
     * parc laisserait les autres compteurs sans borne, et leur index à cette
     * borne serait alors extrapolé au lieu d'être lu.
     *
     * L'agrégat borné se résout par seek sur `uq_utility_readings_meter
     * (meter_id, reading_at)`, puis la jointure sur cette même clé unique
     * récupère `counter_m3` — pas de balayage de l'historique.
     *
     * @return list<array{meter_id: int, reading_at: string, counter_m3: float}>
     */
    private function windowRows(string $from, string $to): array
    {
        // Placeholders positionnels : PDO (mode natif) n'autorise pas la
        // réutilisation d'un placeholder nommé dans une même requête.
        $sql = '(SELECT ur.meter_id, ur.reading_at, ur.counter_m3 FROM utility_readings ur'
            . '   JOIN (SELECT meter_id, MAX(reading_at) AS bound_at FROM utility_readings'
            . '          WHERE user_id = ? AND energy_type = ? AND reading_at < ?'
            . '          GROUP BY meter_id) b'
            . '     ON b.meter_id = ur.meter_id AND b.bound_at = ur.reading_at)'
            . ' UNION ALL '
            . '(SELECT meter_id, reading_at, counter_m3 FROM utility_readings'
            . '   WHERE user_id = ? AND energy_type = ? AND reading_at >= ? AND reading_at < ?)'
            . ' UNION ALL '
            . '(SELECT ur.meter_id, ur.reading_at, ur.counter_m3 FROM utility_readings ur'
            . '   JOIN (SELECT meter_id, MIN(reading_at) AS bound_at FROM utility_readings'
            . '          WHERE user_id = ? AND energy_type = ? AND reading_at >= ?'
            . '          GROUP BY meter_id) b'
            . '     ON b.meter_id = ur.meter_id AND b.bound_at = ur.reading_at)'
            . ' ORDER BY reading_at ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $this->userId, $this->energyType, $from,
            $this->userId, $this->energyType, $from, $to,
            $this->userId, $this->energyType, $to,
        ]);

        return array_map(
            static fn (array $row): array => [
                'meter_id'   => (int) $row['meter_id'],
                'reading_at' => (string) $row['reading_at'],
                'counter_m3' => (float) $row['counter_m3'],
            ],
            $stmt->fetchAll(),
        );
    }

    /**
     * Somme les compteurs en UNE série : union de leurs horodatages, et à chaque
     * instant la somme de leurs index (#55).
     *
     * L'index d'un compteur à un instant qu'il n'a pas relevé est INTERPOLÉ entre
     * ses deux relevés encadrants, et CLAMPÉ hors de sa plage — constante avant
     * son premier relevé, constante après son dernier. C'est le clamp qui rend
     * l'opération sûre : un compteur posé en cours d'année apporte une constante
     * jusqu'à sa mise en service, donc un delta nul, et non un saut d'index égal
     * à tout son odomètre. Extrapoler la pente, comme le fait le service
     * d'interpolation mensuelle sur la série déjà agrégée, inventerait au
     * contraire de la consommation avant l'existence du compteur.
     *
     * Avec un seul compteur, l'union est sa propre liste d'horodatages et chaque
     * valeur est lue telle quelle : sortie identique à celle d'avant #55.
     *
     * Balayage à curseurs : les horodatages sont parcourus dans l'ordre et le
     * curseur de chaque compteur n'avance jamais en arrière — coût linéaire, là
     * où une recherche du segment encadrant à chaque instant serait quadratique.
     *
     * @param  list<array{meter_id: int, reading_at: string, counter_m3: float}> $rows
     * @return list<array{reading_at: string, counter_m3: float}>
     */
    private static function aggregate(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        /** @var array<int, list<array{ts: int, value: float}>> $series */
        $series = [];
        /** @var array<int, string> $labels horodatage unix => forme 'Y-m-d H:i:s' */
        $labels = [];
        foreach ($rows as $row) {
            $ts = Dates::fromDbString($row['reading_at'])->getTimestamp();
            $labels[$ts] = $row['reading_at'];
            $series[$row['meter_id']][] = ['ts' => $ts, 'value' => $row['counter_m3']];
        }

        // Un seul compteur : rien à interpoler, la série EST le résultat. Chemin
        // court volontaire — il garantit l'identité bit à bit du cas courant.
        if (count($series) === 1) {
            return array_map(
                static fn (array $row): array => [
                    'reading_at' => $row['reading_at'],
                    'counter_m3' => $row['counter_m3'],
                ],
                $rows,
            );
        }

        foreach ($series as &$points) {
            usort($points, static fn (array $a, array $b): int => $a['ts'] <=> $b['ts']);
        }
        unset($points);

        $instants = array_keys($labels);
        sort($instants);

        $cursors = array_fill_keys(array_keys($series), 0);
        $out     = [];
        foreach ($instants as $ts) {
            $sum = 0.0;
            foreach ($series as $meterId => $points) {
                $i = $cursors[$meterId];
                $n = count($points);
                while ($i + 1 < $n && $points[$i + 1]['ts'] <= $ts) {
                    ++$i;
                }
                $cursors[$meterId] = $i;

                $a = $points[$i];
                // Avant/sur le point courant, ou après le dernier : clamp.
                if ($ts <= $a['ts'] || $i + 1 >= $n) {
                    $sum += $a['value'];
                    continue;
                }

                $b    = $points[$i + 1];
                $span = $b['ts'] - $a['ts'];
                $sum += $span > 0
                    ? $a['value'] + ($b['value'] - $a['value']) * (($ts - $a['ts']) / $span)
                    : $a['value'];
            }

            $out[] = ['reading_at' => $labels[$ts], 'counter_m3' => round($sum, 3)];
        }

        return $out;
    }
}
