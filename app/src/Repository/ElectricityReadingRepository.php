<?php

declare(strict_types=1);

namespace App\Repository;

use App\Infrastructure\MeterTopology;
use App\Repository\Contract\ElectricityIngestionInterface;
use App\Repository\Contract\LegacyDailyRepositoryInterface;
use DateTimeImmutable;
use PDO;

/**
 * Relevés électricité/solaire sur le modèle à registres (meters →
 * meter_registers → meter_readings), scopés par utilisateur. Reprend la
 * sémantique de l'ancien LegacyDailyRepository (interpolation à minuit des
 * bornes de mois, deltas journaliers/horaires) — un registre remplace une
 * colonne de l'ancienne table.
 *
 * Le contrat JSON du dashboard (clés Prelev_jour/…) est conservé : seul le
 * stockage change ; le renommage du contrat front viendra avec l'API publique.
 */
final class ElectricityReadingRepository implements LegacyDailyRepositoryInterface, ElectricityIngestionInterface
{
    private const IMPORT_KEYS = ['import_t1', 'import_t2'];

    /** Correspondance registre → clé du contrat JSON existant. */
    private const JSON_KEYS = [
        'import_t1' => 'Prelev_jour',
        'import_t2' => 'Prelev_nuit',
        'export_t1' => 'Injec_jour',
        'export_t2' => 'Injec_nuit',
    ];

    /** @var array<string, int>|null Cache register_key => register_id. */
    private ?array $registerMap = null;

    /** @var array<string, mixed> Cache requête-scopé des deltas du mois courant. */
    private array $monthlyDeltasCache = [];

    private bool $monthlyDeltasComputed = false;

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $userId,
    ) {
    }

    // -------------------------------------------------------------------------
    // Ingestion (cron horaire / agent)
    // -------------------------------------------------------------------------

    /**
     * Insère un jeu d'index au même horodatage. Crée compteur/registres au
     * besoin. INSERT IGNORE : idempotent en cas de relance ou de renvoi.
     *
     * @param array<string, float> $indexByRegister register_key => index cumulé
     * @return int Nombre de lignes réellement insérées (doublons exclus).
     */
    public function insertIndexes(DateTimeImmutable $timestamp, array $indexByRegister): int
    {
        $topology = new MeterTopology($this->pdo);
        $meterId = $topology->ensureElectricityMeter($this->userId);
        $map = $topology->ensureRegisters($meterId);
        $this->registerMap = $map;

        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO meter_readings (register_id, reading_at, index_value) VALUES (:rid, :at, :val)'
        );

        // Atomicité du jeu de registres au même horodatage : sans transaction, un
        // échec en cours de boucle laisserait un relevé partiel (fausse
        // getHourlyImportDeltas). Garde inTransaction() : l'import en masse
        // (ImportRunner) ouvre déjà une transaction englobante — ne pas imbriquer.
        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $inserted = 0;
            foreach ($indexByRegister as $key => $value) {
                if (!isset($map[$key])) {
                    continue;
                }
                $stmt->execute([
                    'rid' => $map[$key],
                    'at'  => $timestamp->format('Y-m-d H:i:s'),
                    'val' => $value,
                ]);
                $inserted += $stmt->rowCount();
            }
        } catch (\Throwable $e) {
            // Seul un échec de la boucle est rattrapé ici (transaction encore
            // active). Le commit reste HORS du try : un échec de COMMIT ne doit
            // pas déclencher un rollBack sur une transaction déjà close (qui
            // masquerait l'exception d'origine).
            if ($ownTransaction) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        if ($ownTransaction) {
            $this->pdo->commit();
        }

        return $inserted;
    }

    /**
     * @param list<string> $registerKeys
     * @return array<string, array{min: float|null, max: float|null, exists: bool}>
     */
    public function readingBounds(DateTimeImmutable $timestamp, array $registerKeys): array
    {
        $at = $timestamp->format('Y-m-d H:i:s');

        // Un seul aller-retour par registre : les trois sous-requêtes scalaires
        // (relevé antérieur, postérieur, exact) sont évaluées ensemble. Chaque
        // placeholder est distinct — PDO natif interdit la réutilisation d'un nom.
        $stmt = $this->pdo->prepare(
            'SELECT
                (SELECT index_value FROM meter_readings
                   WHERE register_id = :rid_b AND reading_at < :at_b ORDER BY reading_at DESC LIMIT 1) AS min_v,
                (SELECT index_value FROM meter_readings
                   WHERE register_id = :rid_a AND reading_at > :at_a ORDER BY reading_at ASC LIMIT 1) AS max_v,
                EXISTS(SELECT 1 FROM meter_readings
                   WHERE register_id = :rid_e AND reading_at = :at_e) AS exists_v'
        );

        $bounds = [];
        foreach ($registerKeys as $key) {
            $rid = $this->registerId($key);
            if ($rid === null) {
                $bounds[$key] = ['min' => null, 'max' => null, 'exists' => false];
                continue;
            }

            $stmt->execute([
                'rid_b' => $rid, 'at_b' => $at,
                'rid_a' => $rid, 'at_a' => $at,
                'rid_e' => $rid, 'at_e' => $at,
            ]);
            /** @var array{min_v: string|null, max_v: string|null, exists_v: int|string} $row */
            $row = $stmt->fetch();

            $bounds[$key] = [
                'min'    => $row['min_v'] === null ? null : (float) $row['min_v'],
                'max'    => $row['max_v'] === null ? null : (float) $row['max_v'],
                'exists' => (bool) $row['exists_v'],
            ];
        }

        return $bounds;
    }

    // -------------------------------------------------------------------------
    // Dashboard : index courants
    // -------------------------------------------------------------------------

    /**
     * Derniers index du jour (sinon derniers disponibles), au format JSON
     * historique du dashboard.
     *
     * @return array<string, mixed>
     */
    public function getTodayIndexValues(): array
    {
        $dries = null;
        foreach (self::JSON_KEYS as $registerKey => $jsonKey) {
            $row = $this->latestReadingToday($registerKey);
            if ($row === null) {
                continue;
            }
            if ($dries === null) {
                $dries = ['timestamp' => $row['reading_at']];
            }
            $dries[$jsonKey] = $row['index_value'];
        }

        $solar = null;
        $solarRow = $this->latestReadingToday('production');
        if ($solarRow !== null) {
            $solar = ['timestamp' => $solarRow['reading_at'], 'production' => $solarRow['index_value']];
        }

        return [
            'dries'        => $dries,
            'solar'        => $solar,
            'solar_source' => 'meter_readings',
        ];
    }

    // -------------------------------------------------------------------------
    // Saisie manuelle : historique des relevés
    // -------------------------------------------------------------------------

    /**
     * Historique des relevés électricité : les $limit horodatages distincts les
     * plus récents, chacun avec ses index par registre (null si le registre n'a
     * pas de valeur à cet instant). DESC (plus récent d'abord).
     *
     * Borné par nombre d'horodatages distincts (et non par fenêtre temporelle)
     * afin qu'une saisie manuelle — même antidatée de plusieurs mois — reste
     * visible, tout en plafonnant le volume pour les comptes alimentés par le
     * cron horaire.
     *
     * @return list<array{reading_at: string, import_t1: float|null, import_t2: float|null, export_t1: float|null, export_t2: float|null, production: float|null}>
     */
    public function getHistory(int $limit = 100): array
    {
        $limit = max(1, $limit);

        $idToKey = [];
        foreach (ElectricityIngestionInterface::REGISTERS as $key) {
            $rid = $this->registerId($key);
            if ($rid !== null) {
                $idToKey[$rid] = $key;
            }
        }
        if ($idToKey === []) {
            return [];
        }

        $ids = array_keys($idToKey);
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        // $limit casté en int et interpolé : sûr (entier), et évite la limitation
        // MySQL « LIMIT paramétré dans une sous-requête IN ». Le sous-SELECT
        // dérivé « recent » contourne « LIMIT dans une sous-requête IN ».
        $stmt = $this->pdo->prepare(
            "SELECT reading_at, register_id, index_value
             FROM meter_readings
             WHERE register_id IN ($placeholders)
               AND reading_at IN (
                   SELECT reading_at FROM (
                       SELECT DISTINCT reading_at
                       FROM meter_readings
                       WHERE register_id IN ($placeholders)
                       ORDER BY reading_at DESC
                       LIMIT $limit
                   ) recent
               )
             ORDER BY reading_at DESC"
        );
        $stmt->execute([...$ids, ...$ids]);

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $at = (string) $row['reading_at'];
            $rows[$at] ??= [
                'reading_at' => $at,
                'import_t1'  => null,
                'import_t2'  => null,
                'export_t1'  => null,
                'export_t2'  => null,
                'production' => null,
            ];
            $key = $idToKey[(int) $row['register_id']] ?? null;
            if ($key !== null) {
                $rows[$at][$key] = (float) $row['index_value'];
            }
        }

        return array_values($rows);
    }

    // -------------------------------------------------------------------------
    // Dashboard : deltas mensuels (interpolation à minuit)
    // -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function getMonthlyDeltas(): array
    {
        if (!$this->monthlyDeltasComputed) {
            $this->monthlyDeltasCache    = $this->interpolatedMonthlyDeltas((int) date('Y'), (int) date('n'));
            $this->monthlyDeltasComputed = true;
        }

        return $this->monthlyDeltasCache;
    }

    /** @return array<string, mixed> */
    public function getMonthlyDeltasForMonth(int $year, int $month): array
    {
        return $this->interpolatedMonthlyDeltas($year, $month);
    }

    /**
     * Deltas d'un mois avec interpolation à minuit des bornes, par registre.
     * Même sémantique que l'ancienne implémentation : mois vide → [] ; mois en
     * cours → borne de fin clampée sur le dernier relevé.
     *
     * @return array<string, mixed>
     */
    private function interpolatedMonthlyDeltas(int $year, int $month): array
    {
        $monthStart = sprintf('%04d-%02d-01 00:00:00', $year, $month);

        $nextYear       = $month === 12 ? $year + 1 : $year;
        $nextMonth      = $month === 12 ? 1         : $month + 1;
        $nextMonthStart = sprintf('%04d-%02d-01 00:00:00', $nextYear, $nextMonth);

        $refId = $this->registerId('import_t1');
        if ($refId === null || $this->hasReadingInRange($refId, $monthStart, $nextMonthStart) === false) {
            return [];
        }

        $result = ['from' => $monthStart, 'to' => $nextMonthStart];
        $outKeys = ['import_t1' => 'prelev_jour', 'import_t2' => 'prelev_nuit', 'export_t1' => 'injec_jour', 'export_t2' => 'injec_nuit'];

        foreach ($outKeys as $registerKey => $outKey) {
            $rid = $this->registerId($registerKey);
            if ($rid === null) {
                $result[$outKey] = 0.0;
                continue;
            }

            $start = $this->interpolatedValueAt($rid, $monthStart);
            $end   = $this->interpolatedValueAt($rid, $nextMonthStart);
            if ($start === null || $end === null) {
                return [];
            }

            $result[$outKey] = max(0.0, round($end['value'] - $start['value'], 3));

            // Borne de fin réelle (mois en cours → timestamp du dernier relevé).
            if ($registerKey === 'import_t1') {
                $result['to'] = $end['timestamp'];
            }
        }

        // Solaire : interpolé aux mêmes instants (fin = borne 'to' résolue).
        $solarId = $this->registerId('production');
        $result['solar']      = null;
        $result['solar_unit'] = null;
        if ($solarId !== null) {
            $sStart = $this->interpolatedValueAt($solarId, $monthStart);
            $sEnd   = $this->interpolatedValueAt($solarId, (string) $result['to']);
            if ($sStart !== null && $sEnd !== null) {
                $result['solar']      = round(max(0.0, $sEnd['value'] - $sStart['value']), 3);
                $result['solar_unit'] = 'kwh';
            }
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Dashboard : séries journalières (graphique)
    // -------------------------------------------------------------------------

    /**
     * Deltas journaliers des N derniers jours (premier relevé du jour J+1 −
     * premier relevé du jour J), même forme de sortie qu'historiquement.
     *
     * @return array<int, array{day:string, import_t1:float, import_t2:float, export_t1:float, export_t2:float, solar:float|null}>
     */
    public function getDailyDeltasForChart(int $days = 30): array
    {
        $series = [];
        foreach (['import_t1', 'import_t2', 'export_t1', 'export_t2', 'production'] as $key) {
            $rid = $this->registerId($key);
            $series[$key] = $rid === null ? [] : $this->dailyFirstValuesSince($rid, $days + 1);
        }

        $deltas = [];
        $rows = $series['import_t1'];
        for ($i = 1, $iMax = count($rows); $i < $iMax; $i++) {
            $day = substr($rows[$i]['reading_at'], 0, 10);
            $deltas[$day] = [
                'day'       => $day,
                'import_t1' => self::consecutiveDelta($rows, $i),
                'import_t2' => 0.0,
                'export_t1' => 0.0,
                'export_t2' => 0.0,
                'solar'     => null,
            ];
        }

        foreach (['import_t2', 'export_t1', 'export_t2'] as $key) {
            $rows = $series[$key];
            for ($i = 1, $iMax = count($rows); $i < $iMax; $i++) {
                $day = substr($rows[$i]['reading_at'], 0, 10);
                if (isset($deltas[$day])) {
                    $deltas[$day][$key] = self::consecutiveDelta($rows, $i);
                }
            }
        }

        $rows = $series['production'];
        for ($i = 1, $iMax = count($rows); $i < $iMax; $i++) {
            $day = substr($rows[$i]['reading_at'], 0, 10);
            if (isset($deltas[$day])) {
                $deltas[$day]['solar'] = self::consecutiveDelta($rows, $i);
            }
        }

        return array_values($deltas);
    }

    // -------------------------------------------------------------------------
    // Tarif dynamique : conso import horaire
    // -------------------------------------------------------------------------

    /**
     * Conso IMPORT (T1+T2) ventilée par heure sur [$from, $to] : deltas entre
     * relevés consécutifs, imputés à l'heure de début d'intervalle.
     *
     * @return array<int, array{hour: string, import_kwh: float}>
     */
    public function getHourlyImportDeltas(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        // Fusion par horodatage : les registres T1/T2 sont relevés au même instant
        // (même trame du compteur) ; on ne somme que les instants présents des deux côtés.
        $byTs = [];
        foreach (self::IMPORT_KEYS as $key) {
            $rid = $this->registerId($key);
            if ($rid === null) {
                return [];
            }
            $stmt = $this->pdo->prepare(
                'SELECT reading_at, index_value FROM meter_readings
                 WHERE register_id = :rid AND reading_at >= :from AND reading_at <= :to
                 ORDER BY reading_at ASC'
            );
            $stmt->execute([
                'rid'  => $rid,
                'from' => $from->format('Y-m-d H:i:s'),
                'to'   => $to->format('Y-m-d H:i:s'),
            ]);
            foreach ($stmt->fetchAll() as $row) {
                $ts = (string) $row['reading_at'];
                $byTs[$ts][$key] = (float) $row['index_value'];
            }
        }

        $rows = [];
        ksort($byTs);
        foreach ($byTs as $ts => $vals) {
            if (count($vals) === count(self::IMPORT_KEYS)) {
                $rows[] = ['ts' => $ts, 'total' => array_sum($vals)];
            }
        }

        $buckets = [];
        for ($i = 1, $iMax = count($rows); $i < $iMax; $i++) {
            $delta          = $rows[$i]['total'] - $rows[$i - 1]['total'];
            $hour           = substr($rows[$i - 1]['ts'], 0, 13) . ':00:00';
            $buckets[$hour] = ($buckets[$hour] ?? 0.0) + max(0.0, $delta);
        }

        $out = [];
        foreach ($buckets as $hour => $kwh) {
            $out[] = ['hour' => $hour, 'import_kwh' => round($kwh, 3)];
        }

        return $out;
    }

    // -------------------------------------------------------------------------
    // Webhook quotidien : premières valeurs du jour par registre
    // -------------------------------------------------------------------------

    /** @return array<int,array{timestamp:string,value:string}> */
    public function fetchDailyFirstValues(
        string $registerKey,
        ?DateTimeImmutable $fromExclusive,
        DateTimeImmutable $toInclusive
    ): array {
        $rid = $this->registerId($registerKey);
        if ($rid === null) {
            return [];
        }

        $boundsWhere = 'register_id = :rid AND reading_at <= :to';
        $params      = ['rid' => $rid, 'to' => $toInclusive->format('Y-m-d H:i:s')];

        if ($fromExclusive !== null) {
            $boundsWhere       .= ' AND DATE(reading_at) > :from_day';
            $params['from_day'] = $fromExclusive->format('Y-m-d');
        }

        $sql = 'SELECT r.reading_at AS timestamp, r.index_value AS value
                FROM meter_readings r
                INNER JOIN (
                    SELECT MIN(reading_at) AS min_at
                    FROM meter_readings
                    WHERE ' . $boundsWhere . '
                    GROUP BY DATE(reading_at)
                ) f ON f.min_at = r.reading_at
                WHERE r.register_id = :rid2
                ORDER BY r.reading_at ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params + ['rid2' => $rid]);

        return $stmt->fetchAll();
    }

    // -------------------------------------------------------------------------
    // Privé
    // -------------------------------------------------------------------------

    private function registerId(string $key): ?int
    {
        if ($this->registerMap === null) {
            $this->registerMap = (new MeterTopology($this->pdo))->registerMapForUser($this->userId);
        }

        return $this->registerMap[$key] ?? null;
    }

    /** @return array{reading_at: string, index_value: float}|null */
    private function latestReadingToday(string $registerKey): ?array
    {
        $rid = $this->registerId($registerKey);
        if ($rid === null) {
            return null;
        }

        foreach ([
            'SELECT reading_at, index_value FROM meter_readings
             WHERE register_id = :rid AND reading_at >= CURDATE() AND reading_at < CURDATE() + INTERVAL 1 DAY
             ORDER BY reading_at DESC LIMIT 1',
            'SELECT reading_at, index_value FROM meter_readings
             WHERE register_id = :rid ORDER BY reading_at DESC LIMIT 1',
        ] as $sql) {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['rid' => $rid]);
            $row = $stmt->fetch();
            if (is_array($row)) {
                return ['reading_at' => (string) $row['reading_at'], 'index_value' => (float) $row['index_value']];
            }
        }

        return null;
    }

    private function hasReadingInRange(int $registerId, string $from, string $to): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM meter_readings WHERE register_id = :rid AND reading_at >= :from AND reading_at < :to LIMIT 1'
        );
        $stmt->execute(['rid' => $registerId, 'from' => $from, 'to' => $to]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Index interpolé linéairement à un instant (clamp sur le relevé le plus
     * proche si l'instant est hors plage). null si le registre est vide.
     *
     * @return array{value: float, timestamp: string}|null
     */
    private function interpolatedValueAt(int $registerId, string $instant): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT reading_at, index_value FROM meter_readings
             WHERE register_id = :rid AND reading_at <= :i ORDER BY reading_at DESC LIMIT 1'
        );
        $stmt->execute(['rid' => $registerId, 'i' => $instant]);
        $before = $stmt->fetch() ?: null;

        $stmt = $this->pdo->prepare(
            'SELECT reading_at, index_value FROM meter_readings
             WHERE register_id = :rid AND reading_at >= :i ORDER BY reading_at ASC LIMIT 1'
        );
        $stmt->execute(['rid' => $registerId, 'i' => $instant]);
        $after = $stmt->fetch() ?: null;

        if ($before === null && $after === null) {
            return null;
        }
        if ($before === null) {
            return ['value' => (float) $after['index_value'], 'timestamp' => (string) $after['reading_at']];
        }
        if ($after === null || (string) $before['reading_at'] === $instant) {
            return ['value' => (float) $before['index_value'], 'timestamp' => (string) $before['reading_at']];
        }
        if ((string) $after['reading_at'] === $instant) {
            return ['value' => (float) $after['index_value'], 'timestamp' => (string) $after['reading_at']];
        }

        $aTs  = (int) strtotime((string) $before['reading_at']);
        $bTs  = (int) strtotime((string) $after['reading_at']);
        $iTs  = (int) strtotime($instant);
        $span = $bTs - $aTs;
        $frac = $span > 0 ? ($iTs - $aTs) / $span : 0.0;

        $a = (float) $before['index_value'];
        $b = (float) $after['index_value'];

        return ['value' => $a + ($b - $a) * $frac, 'timestamp' => $instant];
    }

    /**
     * Premier relevé de chaque jour sur les N derniers jours, ASC.
     *
     * @return list<array{reading_at: string, index_value: float}>
     */
    private function dailyFirstValuesSince(int $registerId, int $days): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.reading_at, r.index_value
             FROM meter_readings r
             INNER JOIN (
                 SELECT MIN(reading_at) AS min_at
                 FROM meter_readings
                 WHERE register_id = :rid AND reading_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
                 GROUP BY DATE(reading_at)
             ) f ON f.min_at = r.reading_at
             WHERE r.register_id = :rid2
             ORDER BY r.reading_at ASC'
        );
        $stmt->execute(['rid' => $registerId, 'days' => $days, 'rid2' => $registerId]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = ['reading_at' => (string) $row['reading_at'], 'index_value' => (float) $row['index_value']];
        }

        return $out;
    }

    /** @param list<array{reading_at: string, index_value: float}> $rows */
    private static function consecutiveDelta(array $rows, int $i): float
    {
        return max(0.0, round($rows[$i]['index_value'] - $rows[$i - 1]['index_value'], 3));
    }
}
