<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\ReadingGranularity;
use App\Infrastructure\MeterTopology;
use App\Repository\Contract\ElectricityIngestionInterface;
use App\Repository\Contract\LegacyDailyRepositoryInterface;
use App\Support\Dates;
use DateTimeImmutable;
use DateTimeZone;
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

    /**
     * Topologie (compteur + registres) créée/résolue une seule fois par requête.
     * Évite ~7 requêtes superflues par ligne lors d'un import en masse (O1).
     */
    private bool $topologyEnsured = false;

    /** @var array<string, mixed> Cache requête-scopé des deltas du mois courant. */
    private array $monthlyDeltasCache = [];

    private bool $monthlyDeltasComputed = false;

    /** @var array<string, array<string, mixed>> Cache requête-scopé des deltas par mois donné (clé "Y-n"). */
    private array $monthlyDeltasForMonthCache = [];

    /**
     * @param string $timezone Fuseau IANA de l'utilisateur (user_profiles.timezone).
     *        Sert à délimiter les « jours locaux » des lectures dashboard
     *        (index du jour, séries journalières). Défaut 'UTC' : les chemins
     *        d'ingestion (import, EnergyId), insensibles au fuseau, gardent le
     *        comportement historique. Cf. {@see self::latestReadingToday()} et
     *        {@see self::dailyFirstValuesSince()}.
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $userId,
        private readonly string $timezone = 'UTC',
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
     * @param bool $replace Si true, un index déjà présent au même horodatage est
     *        écrasé (ON DUPLICATE KEY UPDATE) au lieu d'être ignoré.
     * @return int Nombre de lignes réellement écrites (insérées ou mises à jour).
     */
    public function insertIndexes(DateTimeImmutable $timestamp, array $indexByRegister, bool $replace = false): int
    {
        // Topologie résolue/créée au premier appel seulement : la carte des
        // registres ne change pas au cours d'une requête HTTP. En import en masse,
        // évite ~7 requêtes (ensureElectricityMeter + ensureRegisters) par ligne.
        if (!$this->topologyEnsured) {
            $topology = new MeterTopology($this->pdo);
            $meterId = $topology->ensureElectricityMeter($this->userId);
            $this->registerMap = $topology->ensureRegisters($meterId);
            $this->topologyEnsured = true;
        }
        $map = $this->registerMap ?? [];

        // ON DUPLICATE KEY UPDATE renvoie rowCount() = 2 pour une mise à jour, 1
        // pour une insertion : on plafonne à 1 par registre écrit pour compter des
        // « lignes touchées » cohérentes avec le mode INSERT IGNORE.
        $stmt = $this->pdo->prepare(
            $replace
                ? 'INSERT INTO meter_readings (register_id, reading_at, index_value) VALUES (:rid, :at, :val)
                   ON DUPLICATE KEY UPDATE index_value = VALUES(index_value)'
                : 'INSERT IGNORE INTO meter_readings (register_id, reading_at, index_value) VALUES (:rid, :at, :val)'
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
                    'at'  => Dates::toDbString($timestamp),
                    'val' => $value,
                ]);
                // rowCount() : 0 (doublon ignoré ou valeur identique), 1 (inséré)
                // ou 2 (mis à jour en mode replace) → compter une ligne touchée.
                $inserted += $stmt->rowCount() > 0 ? 1 : 0;
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

        // De nouveaux relevés rendent obsolètes les deltas mensuels mémoïsés :
        // on invalide pour rester cohérent si la même instance écrit puis relit
        // (mois courant comme mois donné).
        if ($inserted > 0) {
            $this->invalidateMonthlyDeltaCaches();
        }

        return $inserted;
    }

    /**
     * Supprime tous les index (les 5 registres) à un horodatage donné pour le
     * compteur de l'utilisateur. La jointure sur meters.user_id garantit qu'on ne
     * touche que le compteur du bon utilisateur.
     *
     * @return int Nombre de lignes meter_readings supprimées (0 à 5).
     */
    public function deleteReadingAt(DateTimeImmutable $timestamp): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE mr FROM meter_readings mr
             JOIN meter_registers reg ON reg.id = mr.register_id
             JOIN meters m ON m.id = reg.meter_id
             WHERE m.user_id = :uid AND m.energy_type = :etype AND mr.reading_at = :at'
        );
        $stmt->execute([
            'uid'   => $this->userId,
            'etype' => 'electricity',
            'at'    => Dates::toDbString($timestamp),
        ]);

        $this->invalidateMonthlyDeltaCaches();

        return $stmt->rowCount();
    }

    /**
     * Supprime le compteur électricité de l'utilisateur ; les registres et tous
     * les relevés partent en cascade (ON DELETE CASCADE). insertIndexes recrée le
     * compteur paresseusement au prochain import/saisie.
     *
     * @return int Nombre de compteurs supprimés (0 ou 1).
     */
    public function deleteMeter(): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM meters WHERE user_id = :uid AND energy_type = :etype'
        );
        $stmt->execute(['uid' => $this->userId, 'etype' => 'electricity']);

        // La topologie mémoïsée pointe sur un compteur qui n'existe plus : la
        // réinitialiser pour qu'un insertIndexes ultérieur le recrée proprement.
        $this->topologyEnsured = false;
        $this->registerMap     = null;
        $this->invalidateMonthlyDeltaCaches();

        return $stmt->rowCount();
    }

    /**
     * Invalide les deltas mensuels mémoïsés : une suppression rend obsolètes les
     * agrégats calculés (comme une insertion). Cf. insertIndexes.
     */
    private function invalidateMonthlyDeltaCaches(): void
    {
        $this->monthlyDeltasComputed      = false;
        $this->monthlyDeltasCache         = [];
        $this->monthlyDeltasForMonthCache = [];
    }

    /**
     * @param list<string> $registerKeys
     * @return array<string, array{min: float|null, max: float|null, exists: bool}>
     */
    public function readingBounds(DateTimeImmutable $timestamp, array $registerKeys): array
    {
        $at = Dates::toDbString($timestamp);

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

    /**
     * @param list<string> $registerKeys
     * @return array<string, bool>
     */
    public function readingsPresentInBucket(DateTimeImmutable $timestamp, string $timezone, ReadingGranularity $granularity, array $registerKeys): array
    {
        // Bornes du créneau LOCAL de l'utilisateur, ramenées en UTC (colonnes UTC,
        // session MariaDB forcée +00:00 — pas de CONVERT_TZ). Le calcul du créneau
        // (jour ou quart d'heure aligné) et la gestion DST vivent dans l'enum.
        [$bucketStart, $bucketEnd] = $granularity->bucket($timestamp, new DateTimeZone($timezone));

        $start = Dates::toDbString($bucketStart);
        $end   = Dates::toDbString($bucketEnd);
        $at    = Dates::toDbString($timestamp);

        // Un relevé de CE registre existe-t-il déjà dans ce créneau à un autre
        // instant ? L'exclusion de l'instant exact (reading_at <> :at) préserve
        // l'idempotence.
        $stmt = $this->pdo->prepare(
            'SELECT EXISTS(
                SELECT 1 FROM meter_readings
                WHERE register_id = :rid
                  AND reading_at >= :start AND reading_at < :end
                  AND reading_at <> :at
             ) AS present'
        );

        $present = [];
        foreach ($registerKeys as $key) {
            $rid = $this->registerId($key);
            if ($rid === null) {
                $present[$key] = false;
                continue;
            }

            $stmt->execute(['rid' => $rid, 'start' => $start, 'end' => $end, 'at' => $at]);
            /** @var int|string $flag */
            $flag = $stmt->fetchColumn();
            $present[$key] = (bool) $flag;
        }

        return $present;
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
        // Mémoïsation requête-scopée (même motif que getMonthlyDeltas) : la vue
        // « mois donné » appelle estimateMonthElectricity PUIS
        // estimateMonthElectricityDynamic, tous deux via cette méthode — sans
        // cache le calcul mensuel (interpolation) serait exécuté 2× (O2).
        $key = $year . '-' . $month;

        return $this->monthlyDeltasForMonthCache[$key] ??= $this->interpolatedMonthlyDeltas($year, $month);
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

        $outKeys = ['import_t1' => 'prelev_jour', 'import_t2' => 'prelev_nuit', 'export_t1' => 'injec_jour', 'export_t2' => 'injec_nuit'];

        // rid par clé (null si le registre est absent) + liste des rid présents à
        // interpoler en une passe. Chaque borne est résolue par un batch (2 requêtes)
        // plutôt que 2 requêtes × registre (O3).
        $rids    = [];
        $elecIds = [];
        foreach (array_keys($outKeys) as $registerKey) {
            $rid              = $this->registerId($registerKey);
            $rids[$registerKey] = $rid;
            if ($rid !== null) {
                $elecIds[] = $rid;
            }
        }
        $solarId = $this->registerId('production');

        $startIds    = $solarId === null ? $elecIds : [...$elecIds, $solarId];
        $startValues = $this->interpolatedValuesAt($startIds, $monthStart);
        $endValues   = $this->interpolatedValuesAt($elecIds, $nextMonthStart);

        $result = ['from' => $monthStart, 'to' => $nextMonthStart];

        foreach ($outKeys as $registerKey => $outKey) {
            $rid = $rids[$registerKey];
            if ($rid === null) {
                $result[$outKey] = 0.0;
                continue;
            }

            $start = $startValues[$rid];
            $end   = $endValues[$rid];
            if ($start === null || $end === null) {
                return [];
            }

            $result[$outKey] = max(0.0, round($end['value'] - $start['value'], 3));

            // Borne de fin réelle (mois en cours → timestamp du dernier relevé).
            if ($registerKey === 'import_t1') {
                $result['to'] = $end['timestamp'];
            }
        }

        // Solaire : début déjà batché ; fin à la borne 'to' résolue (mois en cours →
        // dernier relevé), donc un appel dédié à l'instant final.
        $result['solar']      = null;
        $result['solar_unit'] = null;
        if ($solarId !== null) {
            $sStart = $startValues[$solarId];
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
        $keys = ['import_t1', 'import_t2', 'export_t1', 'export_t2', 'production'];

        // rid par clé + liste des rid présents : les premiers relevés journaliers des
        // 5 registres sont résolus en UNE requête (au lieu de 5) — cf.
        // dailyFirstValuesByRegisterSince.
        $ridByKey = [];
        foreach ($keys as $key) {
            $rid = $this->registerId($key);
            if ($rid !== null) {
                $ridByKey[$key] = $rid;
            }
        }
        $firstByRid = $this->dailyFirstValuesByRegisterSince(array_values($ridByKey), $days + 1);

        $series = [];
        foreach ($keys as $key) {
            $rid = $ridByKey[$key] ?? null;
            $series[$key] = $rid === null ? [] : ($firstByRid[$rid] ?? []);
        }

        // L'axe des jours est l'UNION des jours de toutes les séries : chaque registre
        // est relevé indépendamment et peut tomber des jours différents (#180). Bâtir
        // le squelette sur les seuls jours d'import_t1 tronquait toute série désalignée
        // (notamment la production solaire → courbe PV coupée).
        /** @var array<string, array{day:string, import_t1:float, import_t2:float, export_t1:float, export_t2:float, solar:float|null}> $deltas */
        $deltas = [];
        $ensureDay = static function (string $day) use (&$deltas): void {
            if (!isset($deltas[$day])) {
                $deltas[$day] = [
                    'day'       => $day,
                    'import_t1' => 0.0,
                    'import_t2' => 0.0,
                    'export_t1' => 0.0,
                    'export_t2' => 0.0,
                    'solar'     => null,
                ];
            }
        };

        foreach (['import_t1', 'import_t2', 'export_t1', 'export_t2'] as $key) {
            $rows = $series[$key];
            for ($i = 1, $iMax = count($rows); $i < $iMax; $i++) {
                $day = $rows[$i]['day'];
                $ensureDay($day);
                $deltas[$day][$key] = self::consecutiveDelta($rows, $i);
            }
        }

        $rows = $series['production'];
        for ($i = 1, $iMax = count($rows); $i < $iMax; $i++) {
            $day = $rows[$i]['day'];
            $ensureDay($day);
            $deltas[$day]['solar'] = self::consecutiveDelta($rows, $i);
        }

        ksort($deltas); // clés 'Y-m-d' : tri lexicographique == chronologique.

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
                'from' => Dates::toDbString($from),
                'to'   => Dates::toDbString($to),
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
        $params      = ['rid' => $rid, 'to' => Dates::toDbString($toInclusive)];

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

        // « Aujourd'hui » = jour LOCAL de l'utilisateur (#171). Les colonnes sont en
        // UTC (session MariaDB forcée +00:00, cf. Database.php) : on calcule les
        // bornes [minuit local, minuit local +1 jour) en PHP puis on les ramène en
        // UTC — même motif DST-safe que readingsPresentInBucket, sans CONVERT_TZ.
        // Le second SELECT retombe sur le dernier relevé global si le jour est vide.
        [$dayStart, $dayEnd] = ReadingGranularity::Day->bucket(
            new DateTimeImmutable('now'),
            new DateTimeZone($this->timezone),
        );

        $todaySql = 'SELECT reading_at, index_value FROM meter_readings
             WHERE register_id = :rid AND reading_at >= :start AND reading_at < :end
             ORDER BY reading_at DESC LIMIT 1';
        $latestSql = 'SELECT reading_at, index_value FROM meter_readings
             WHERE register_id = :rid ORDER BY reading_at DESC LIMIT 1';

        foreach ([
            [$todaySql, ['rid' => $rid, 'start' => Dates::toDbString($dayStart), 'end' => Dates::toDbString($dayEnd)]],
            [$latestSql, ['rid' => $rid]],
        ] as [$sql, $params]) {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
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
        return $this->interpolatedValuesAt([$registerId], $instant)[$registerId];
    }

    /**
     * Index interpolé à un instant pour PLUSIEURS registres en 2 requêtes (au
     * lieu de 2 par registre). Même sémantique que {@see interpolatedValueAt}.
     *
     * @param list<int> $registerIds
     * @return array<int, array{value: float, timestamp: string}|null> register_id => valeur interpolée (ou null si registre vide)
     */
    private function interpolatedValuesAt(array $registerIds, string $instant): array
    {
        /** @var array<int, array{value: float, timestamp: string}|null> $result */
        $result = array_fill_keys($registerIds, null);
        if ($registerIds === []) {
            return $result;
        }

        $before = $this->boundaryReadings($registerIds, $instant, false);
        $after  = $this->boundaryReadings($registerIds, $instant, true);

        foreach ($registerIds as $rid) {
            $result[$rid] = self::interpolateBetween($before[$rid] ?? null, $after[$rid] ?? null, $instant);
        }

        return $result;
    }

    /**
     * Pour chaque registre, le relevé le plus proche d'un côté de l'instant en
     * UNE requête : dernier `<=` instant si $after=false, premier `>=` si vrai.
     *
     * Greatest/least-per-groupe indexé : l'agrégat borné (`MAX`/`MIN` de
     * reading_at) se résout par seek sur `uq_meter_readings (register_id,
     * reading_at)` — pas de scan de tout l'historique — puis jointure sur cette
     * clé unique pour récupérer index_value. (Une fenêtre `ROW_NUMBER()` lirait
     * au contraire toutes les lignes de la partition avant de filtrer.)
     *
     * @param list<int> $registerIds
     * @return array<int, array{reading_at: string, index_value: float}>
     */
    private function boundaryReadings(array $registerIds, string $instant, bool $after): array
    {
        $placeholders = implode(', ', array_fill(0, count($registerIds), '?'));
        $cmp = $after ? '>=' : '<=';
        $agg = $after ? 'MIN' : 'MAX';

        $stmt = $this->pdo->prepare(
            "SELECT r.register_id, r.reading_at, r.index_value
             FROM meter_readings r
             JOIN (
                 SELECT register_id, $agg(reading_at) AS bound_at
                 FROM meter_readings
                 WHERE register_id IN ($placeholders) AND reading_at $cmp ?
                 GROUP BY register_id
             ) b ON b.register_id = r.register_id AND r.reading_at = b.bound_at"
        );
        $stmt->execute([...$registerIds, $instant]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['register_id']] = [
                'reading_at'  => (string) $row['reading_at'],
                'index_value' => (float) $row['index_value'],
            ];
        }

        return $out;
    }

    /**
     * Interpolation linéaire entre deux relevés encadrant un instant. Clamp sur
     * la borne présente si l'autre manque ; null si les deux manquent.
     *
     * @param array{reading_at: string, index_value: float}|null $before
     * @param array{reading_at: string, index_value: float}|null $after
     * @return array{value: float, timestamp: string}|null
     */
    private static function interpolateBetween(?array $before, ?array $after, string $instant): ?array
    {
        if ($before === null && $after === null) {
            return null;
        }
        if ($before === null) {
            /** @var array{reading_at: string, index_value: float} $after */
            return ['value' => $after['index_value'], 'timestamp' => $after['reading_at']];
        }
        if ($after === null || $before['reading_at'] === $instant) {
            return ['value' => $before['index_value'], 'timestamp' => $before['reading_at']];
        }
        if ($after['reading_at'] === $instant) {
            return ['value' => $after['index_value'], 'timestamp' => $after['reading_at']];
        }

        $aTs  = (int) strtotime($before['reading_at']);
        $bTs  = (int) strtotime($after['reading_at']);
        $iTs  = (int) strtotime($instant);
        $span = $bTs - $aTs;
        $frac = $span > 0 ? ($iTs - $aTs) / $span : 0.0;

        $a = $before['index_value'];
        $b = $after['index_value'];

        return ['value' => $a + ($b - $a) * $frac, 'timestamp' => $instant];
    }

    /**
     * Premier relevé de chaque jour LOCAL sur les N derniers jours, par registre,
     * en UNE requête (tous les registres du graphe résolus ensemble : 1 aller-retour
     * au lieu de 5).
     *
     * Le regroupement est fait en PHP dans le fuseau de l'utilisateur (#171) : le
     * `GROUP BY DATE(reading_at)` SQL raisonne en UTC (session MariaDB +00:00) et ne
     * peut exprimer un jour local sans CONVERT_TZ (tables IANA requises, imparfait
     * autour d'une transition DST). On balaie donc les lignes de la fenêtre triées
     * ASC (index (register_id, reading_at) — pas de filesort) et on retient la
     * première de chaque jour local, DST-safe via DateTimeZone.
     *
     * @param list<int> $registerIds
     * @return array<int, list<array{day: string, index_value: float}>> register_id => premiers relevés par jour local
     */
    private function dailyFirstValuesByRegisterSince(array $registerIds, int $days): array
    {
        if ($registerIds === []) {
            return [];
        }

        $tz = new DateTimeZone($this->timezone);

        // Borne basse : minuit local il y a $days jours (helper DST-safe partagé avec
        // latestReadingToday), ramené en UTC pour filtrer les colonnes UTC.
        [$todayStart] = ReadingGranularity::Day->bucket(new DateTimeImmutable('now'), $tz);
        $since        = $todayStart->modify("-{$days} days");

        $placeholders = implode(', ', array_fill(0, count($registerIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT register_id, reading_at, index_value
             FROM meter_readings
             WHERE register_id IN ($placeholders) AND reading_at >= ?
             ORDER BY register_id ASC, reading_at ASC"
        );
        $stmt->execute([...$registerIds, Dates::toDbString($since)]);

        /** @var array<int, list<array{day: string, index_value: float}>> $out */
        $out  = [];
        $seen = [];
        foreach ($stmt->fetchAll() as $row) {
            $rid = (int) $row['register_id'];
            $day = Dates::fromDbString((string) $row['reading_at'])->setTimezone($tz)->format('Y-m-d');
            if (isset($seen[$rid][$day])) {
                continue; // Tri ASC : la première ligne vue est le premier relevé du jour local.
            }
            $seen[$rid][$day] = true;
            $out[$rid][]      = ['day' => $day, 'index_value' => (float) $row['index_value']];
        }

        return $out;
    }

    /** @param list<array{day: string, index_value: float}> $rows */
    private static function consecutiveDelta(array $rows, int $i): float
    {
        return max(0.0, round($rows[$i]['index_value'] - $rows[$i - 1]['index_value'], 3));
    }
}
