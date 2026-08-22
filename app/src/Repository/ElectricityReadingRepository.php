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

    /** Durée d'un MTU quart-horaire, en secondes. */
    private const QUARTER_SECONDS = 900;

    /**
     * Marge admise sur l'écart entre deux relevés avant de cesser de considérer la
     * cadence comme quart-horaire. `reading_at` est stocké tel que l'émetteur l'a
     * envoyé (aucun alignement à l'ingestion) : un compteur ou un poller qui vise
     * les 15 min dérive de quelques secondes à chaque trame. Sans marge, un flux
     * réellement quart-horaire serait classé « reconstruit » et n'accéderait jamais
     * à la facturation au quart d'heure. 90 s reste très en dessous de la cadence
     * suivante plausible (20 ou 30 min).
     *
     * La cadence jugée est celle du STOCKAGE, pas celle de l'émetteur : l'ingestion
     * ne retient qu'un relevé par créneau de 15 min ({@see ReadingGranularity}). Une
     * source cadencée plus finement mais ne divisant pas 900 s — un cron toutes les
     * 10 min, dont les relevés retenus tombent à :00, :20, :30, :50 — laisse donc des
     * intervalles de 20 min, classés non natifs. C'est voulu : sur 20 min il n'existe
     * aucune mesure intra-MTU, l'énergie ne peut qu'être étalée, et la facturer au
     * quart d'heure serait la présenter comme mesurée. Rendre ce cas natif suppose
     * d'aligner l'ingestion sur les MTU, pas d'élargir cette tolérance.
     */
    private const QUARTER_JITTER_SECONDS = 90;

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

    /** @var array<string, array<string, mixed>> Cache requête-scopé des deltas par intervalle libre (clé "from|to"). */
    private array $rangeDeltasCache = [];

    /**
     * Cache requête-scopé des deltas par bornes de sous-périodes (#2), clefé sur la
     * liste de bornes : la vue d'un mois appelle estimateMonthElectricity() PUIS
     * estimateMonthElectricityDynamic(), qui découpent la même période aux mêmes
     * bornes tarifaires.
     *
     * @var array<string, list<array{prelev_jour: float, prelev_nuit: float, injec_jour: float, injec_nuit: float, solar: float}>>
     */
    private array $boundaryDeltasCache = [];

    /** Cache requête-scopé du total d'horodatages de l'historique (pagination, #257). */
    private ?int $historyCount = null;

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
     * Invalide les deltas mémoïsés (mensuels et par intervalle libre) : une
     * suppression rend obsolètes les agrégats calculés (comme une insertion).
     * Cf. insertIndexes. Le total d'horodatages de l'historique (#257) suit la
     * même règle : insérer ou supprimer change le nombre de pages.
     */
    private function invalidateMonthlyDeltaCaches(): void
    {
        $this->monthlyDeltasComputed      = false;
        $this->monthlyDeltasCache         = [];
        $this->monthlyDeltasForMonthCache = [];
        $this->rangeDeltasCache           = [];
        $this->boundaryDeltasCache        = [];
        $this->historyCount               = null;
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
        return $this->getHistoryPage($limit, 0)['items'];
    }

    /**
     * Nombre d'horodatages distincts de l'historique — dénominateur de la
     * pagination (#257). 0 si l'utilisateur n'a aucun registre connu.
     *
     * Le dédoublonnage passe par une sous-requête `SELECT DISTINCT` plutôt que
     * par `COUNT(DISTINCT …)` : c'est la forme que l'optimiseur sait résoudre en
     * balayage d'index seul (« Using index for group-by ») sur l'unique
     * uq_meter_readings (register_id, reading_at), qui couvre exactement le
     * prédicat et la colonne dédoublonnée — aucune lecture de table.
     *
     * Le coût reste proportionnel à la profondeur d'historique : c'est le prix
     * d'un compteur de pages exact (« Page 2 / 14 »). Mémoïsé pour la requête,
     * l'historique et son total étant servis par le même appel HTTP.
     */
    public function countHistory(): int
    {
        if ($this->historyCount !== null) {
            return $this->historyCount;
        }

        $ids = array_keys($this->historyRegisterMap());
        if ($ids === []) {
            return $this->historyCount = 0;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM (
                 SELECT DISTINCT reading_at FROM meter_readings WHERE register_id IN ($placeholders)
             ) distinct_readings"
        );
        $stmt->execute($ids);

        return $this->historyCount = (int) $stmt->fetchColumn();
    }

    /**
     * Une page d'historique (#257) : $perPage horodatages distincts à partir de
     * $offset, du plus récent au plus ancien.
     *
     * `previous` porte le relevé immédiatement plus ancien que la page (null s'il
     * n'y en a pas) : les deltas par registre sont calculés côté client, qui a
     * besoin de cette ligne pour la première ligne affichée d'une page suivante.
     *
     * @return array{items: list<array{reading_at: string, import_t1: float|null, import_t2: float|null, export_t1: float|null, export_t2: float|null, production: float|null}>, previous: array{reading_at: string, import_t1: float|null, import_t2: float|null, export_t1: float|null, export_t2: float|null, production: float|null}|null}
     */
    public function getHistoryPage(int $perPage, int $offset): array
    {
        $perPage = max(1, $perPage);
        $offset  = max(0, $offset);

        $idToKey = $this->historyRegisterMap();
        if ($idToKey === []) {
            return ['items' => [], 'previous' => null];
        }

        $ids = array_keys($idToKey);
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        // Un horodatage de plus que la page : il sert de base au delta de la
        // dernière ligne affichée et n'est pas rendu comme une ligne.
        $limit = $perPage + 1;

        // $limit / $offset castés en int et interpolés : sûr (entiers), et évite
        // la limitation MySQL « LIMIT paramétré dans une sous-requête IN ». Le
        // sous-SELECT dérivé « recent » contourne « LIMIT dans une sous-requête IN ».
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
                       LIMIT $limit OFFSET $offset
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

        $items    = array_values($rows);
        $previous = count($items) > $perPage ? array_pop($items) : null;

        return ['items' => $items, 'previous' => $previous];
    }

    /**
     * Registres de l'utilisateur exposés par l'historique, indexés par id.
     *
     * @return array<int, string>
     */
    private function historyRegisterMap(): array
    {
        $idToKey = [];
        foreach (ElectricityIngestionInterface::REGISTERS as $key) {
            $rid = $this->registerId($key);
            if ($rid !== null) {
                $idToKey[$rid] = $key;
            }
        }

        return $idToKey;
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
     * Deltas entre deux instants QUELCONQUES, avec interpolation des bornes (#241).
     *
     * Mémoïsation requête-scopée clefée sur le couple de bornes : la page des
     * acomptes demande le même intervalle une fois par énergie, et le bilan élec
     * peut rejouer le calcul en classique puis en dynamique.
     *
     * @param string $from Borne de début, format DB 'Y-m-d H:i:s' (UTC).
     * @param string $to   Borne de fin, même format.
     * @return array<string, mixed>
     */
    public function getDeltasBetween(string $from, string $to): array
    {
        return $this->rangeDeltasCache[$from . '|' . $to] ??= $this->interpolatedDeltasBetween($from, $to);
    }

    /**
     * Deltas par registre entre bornes consécutives (#2).
     *
     * Même mécanique que {@see getMonthlyDeltaSeries()}, dont la série mensuelle
     * n'est qu'un cas particulier : chaque instant n'est interpolé qu'UNE fois
     * (2 requêtes indexées), la fin d'un intervalle étant le début du suivant.
     * Un découpage en N sous-périodes coûte donc 2(N+1) requêtes, et non 2N appels
     * complets à {@see getDeltasBetween()}.
     *
     * @param  list<string> $boundaries
     * @return list<array{prelev_jour: float, prelev_nuit: float, injec_jour: float, injec_nuit: float, solar: float}>
     */
    public function getDeltasByBoundaries(array $boundaries): array
    {
        if (count($boundaries) < 2) {
            return [];
        }

        return $this->boundaryDeltasCache[implode('|', $boundaries)] ??= $this->interpolatedBoundaryDeltas($boundaries);
    }

    /**
     * Corps de {@see getDeltasByBoundaries()}.
     *
     * @param  list<string> $boundaries
     * @return list<array{prelev_jour: float, prelev_nuit: float, injec_jour: float, injec_nuit: float, solar: float}>
     */
    private function interpolatedBoundaryDeltas(array $boundaries): array
    {
        // Mêmes clés de sortie que interpolatedDeltasBetween() : l'appelant compare
        // ces deltas au total de la période, qui vient de là.
        $outKeys = ['import_t1' => 'prelev_jour', 'import_t2' => 'prelev_nuit', 'export_t1' => 'injec_jour', 'export_t2' => 'injec_nuit', 'production' => 'solar'];

        $rids   = [];
        $allIds = [];
        foreach (array_keys($outKeys) as $registerKey) {
            $rid                = $this->registerId($registerKey);
            $rids[$registerKey] = $rid;
            if ($rid !== null) {
                $allIds[] = $rid;
            }
        }

        if ($allIds === []) {
            return [];
        }

        /** @var array<string, array<int, array{value: float, timestamp: string}|null>> $valuesAt */
        $valuesAt = [];
        $out      = [];

        for ($i = 0, $n = count($boundaries) - 1; $i < $n; $i++) {
            $start = $boundaries[$i];
            $end   = $boundaries[$i + 1];

            $valuesAt[$start] ??= $this->interpolatedValuesAt($allIds, $start);
            $valuesAt[$end]   ??= $this->interpolatedValuesAt($allIds, $end);

            $row = [];
            foreach ($outKeys as $registerKey => $outKey) {
                $rid = $rids[$registerKey];
                // boundedDelta() plafonne déjà à 0 : un compteur qui recule sur une
                // sous-période ne contamine pas ses voisines.
                $row[$outKey] = $rid === null
                    ? 0.0
                    : self::boundedDelta($valuesAt[$start][$rid] ?? null, $valuesAt[$end][$rid] ?? null);
            }

            /** @var array{prelev_jour: float, prelev_nuit: float, injec_jour: float, injec_nuit: float, solar: float} $row */
            $out[] = $row;
        }

        return $out;
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

        return $this->interpolatedDeltasBetween($monthStart, $nextMonthStart);
    }

    /**
     * Deltas entre deux bornes, par registre, avec interpolation à l'instant exact
     * de chaque borne. Généralisation du calcul mensuel : le mois n'était qu'un
     * couple de bornes à minuit.
     *
     * Sémantique conservée : aucun relevé dans l'intervalle → [] ; période encore
     * en cours → borne de fin clampée sur le dernier relevé disponible, de sorte
     * que `to` reflète la fin RÉELLE des données et non une projection.
     *
     * @return array<string, mixed>
     */
    private function interpolatedDeltasBetween(string $from, string $to): array
    {
        $refId = $this->registerId('import_t1');
        if ($refId === null || $this->hasReadingInRange($refId, $from, $to) === false) {
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
        $startValues = $this->interpolatedValuesAt($startIds, $from);
        $endValues   = $this->interpolatedValuesAt($elecIds, $to);

        $result = ['from' => $from, 'to' => $to];

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

            // Borne de fin réelle (période en cours → timestamp du dernier relevé).
            if ($registerKey === 'import_t1') {
                $result['to'] = $end['timestamp'];

                // Fenêtre réellement COUVERTE par des relevés, exposée à part de
                // `from`/`to` pour ne rien changer aux appelants historiques (#241).
                // Les bornes sont clampées sur le relevé le plus proche quand
                // l'instant demandé sort de la plage : les comparer à la période
                // demandée dit si les données la couvrent vraiment, ou si le calcul
                // ne porte que sur une fraction — un flux de relevés arrêté ne doit
                // pas produire un coût partiel présenté comme complet.
                $result['data_from'] = $start['timestamp'];
                $result['data_to']   = $end['timestamp'];
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

    /**
     * Consommation MENSUELLE des $months derniers mois, par registre (#238).
     *
     * La vue « 1 an » du graphique traçait 365 barres journalières : illisible.
     * On agrège donc par mois calendaire, sur la CONSOMMATION (différence des
     * index interpolés aux bornes de mois) et non sur la lecture d'index brute —
     * même sémantique que {@see interpolatedMonthlyDeltas} qui alimente les cards,
     * pour que graphe et cards concordent.
     *
     * Les bornes sont résolues une seule fois chacune ($months + 1 instants, 2
     * requêtes indexées par instant) : la fin du mois M est le début du mois M+1.
     * {@see interpolatedValuesAt} clampe déjà sur le relevé le plus proche quand
     * l'instant sort de la plage, ce qui donne au mois en cours la consommation
     * réelle à ce jour (`partial => true`) plutôt qu'une projection.
     *
     * @param DateTimeImmutable|null $now Instant de référence (tests) ; « maintenant » par défaut.
     * @return list<array{month:string, import_t1:float, import_t2:float, export_t1:float, export_t2:float, solar:float|null, partial:bool}>
     */
    public function getMonthlyDeltaSeries(int $months = 12, ?DateTimeImmutable $now = null): array
    {
        $months = max(1, min(60, $months));

        $registerKeys = ['import_t1', 'import_t2', 'export_t1', 'export_t2'];

        $rids    = [];
        $elecIds = [];
        foreach ($registerKeys as $registerKey) {
            $rid                = $this->registerId($registerKey);
            $rids[$registerKey] = $rid;
            if ($rid !== null) {
                $elecIds[] = $rid;
            }
        }
        $solarId = $this->registerId('production');

        $allIds = $solarId === null ? $elecIds : [...$elecIds, $solarId];
        if ($allIds === []) {
            return [];
        }

        $bounds = $this->readingRange($allIds);
        if ($bounds === null) {
            return [];
        }
        $firstAt = $bounds['first'];
        $lastAt  = $bounds['last'];

        // Fenêtre : le mois courant et les ($months - 1) précédents, en UTC
        // (fuseau de stockage) comme toutes les bornes calendaires du projet.
        $currentMonth = ($now ?? new DateTimeImmutable('now'))
            ->setTimezone(Dates::utc())
            ->modify('first day of this month')
            ->setTime(0, 0, 0);
        $windowStart = $currentMonth->modify('-' . ($months - 1) . ' months');

        // Instants de bornes retenus : ceux qui délimitent un mois recoupant la
        // plage des relevés. Chaque instant n'est interpolé qu'une fois.
        /** @var array<string, array<int, array{value: float, timestamp: string}|null>> $valuesAt */
        $valuesAt = [];
        $series   = [];

        for ($i = 0; $i < $months; $i++) {
            $monthStartDt = $windowStart->modify('+' . $i . ' months');
            $monthEndDt   = $monthStartDt->modify('+1 month');

            $monthStart = $monthStartDt->format('Y-m-d H:i:s');
            $monthEnd   = $monthEndDt->format('Y-m-d H:i:s');

            // Mois entièrement hors de la plage des relevés → aucune barre.
            if ($monthEnd <= $firstAt || $monthStart >= $lastAt) {
                continue;
            }

            // Bornes clampées sur les relevés : pas de conso inventée avant le
            // premier relevé, pas de projection après le dernier.
            $partial  = $monthEnd > $lastAt;
            $effStart = max($monthStart, $firstAt);
            $effEnd   = min($monthEnd, $lastAt);

            $valuesAt[$effStart] ??= $this->interpolatedValuesAt($allIds, $effStart);
            $valuesAt[$effEnd]   ??= $this->interpolatedValuesAt($allIds, $effEnd);

            $row = [
                'month'     => $monthStartDt->format('Y-m'),
                'import_t1' => 0.0,
                'import_t2' => 0.0,
                'export_t1' => 0.0,
                'export_t2' => 0.0,
                'solar'     => null,
                'partial'   => $partial || $effStart !== $monthStart,
            ];

            foreach ($registerKeys as $registerKey) {
                $rid = $rids[$registerKey];
                if ($rid === null) {
                    continue;
                }
                $row[$registerKey] = self::boundedDelta($valuesAt[$effStart][$rid] ?? null, $valuesAt[$effEnd][$rid] ?? null);
            }

            // Solaire : null (et non 0) quand le registre existe mais n'a aucun
            // relevé sur la période — la courbe PV ne doit pas tomber à zéro,
            // même sémantique que getDailyDeltasForChart.
            $solarStart = $solarId === null ? null : ($valuesAt[$effStart][$solarId] ?? null);
            $solarEnd   = $solarId === null ? null : ($valuesAt[$effEnd][$solarId] ?? null);
            if ($solarStart !== null && $solarEnd !== null) {
                $row['solar'] = self::boundedDelta($solarStart, $solarEnd);
            }

            $series[] = $row;
        }

        return $series;
    }

    /**
     * Delta entre deux index interpolés (0.0 si l'un des deux manque : registre
     * vide sur la période, donc rien à imputer au mois).
     *
     * @param array{value: float, timestamp: string}|null $start
     * @param array{value: float, timestamp: string}|null $end
     */
    private static function boundedDelta(?array $start, ?array $end): float
    {
        if ($start === null || $end === null) {
            return 0.0;
        }

        return round(max(0.0, $end['value'] - $start['value']), 3);
    }

    /**
     * Premier et dernier horodatage de relevé, tous registres confondus.
     *
     * @param list<int> $registerIds
     * @return array{first: string, last: string}|null  null si aucun relevé.
     */
    private function readingRange(array $registerIds): ?array
    {
        $placeholders = implode(', ', array_fill(0, count($registerIds), '?'));

        $stmt = $this->pdo->prepare(
            "SELECT MIN(reading_at) AS first_at, MAX(reading_at) AS last_at
             FROM meter_readings WHERE register_id IN ($placeholders)"
        );
        $stmt->execute($registerIds);
        $row = $stmt->fetch();

        if (!is_array($row) || $row['first_at'] === null || $row['last_at'] === null) {
            return null;
        }

        return ['first' => (string) $row['first_at'], 'last' => (string) $row['last_at']];
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
        $rows = $this->importIndexTotals($from, $to);

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

    /**
     * Conso IMPORT (T1+T2) ventilée par quart d'heure aligné (:00/:15/:30/:45 UTC)
     * sur [$from, $to], pour le tarif dynamique quart-horaire (#230).
     *
     * Deux questions distinctes, volontairement traitées séparément :
     *
     * - COMBIEN dans chaque créneau : la consommation d'un intervalle est toujours
     *   étalée au prorata du temps passé dans chaque quart qu'il couvre. Un intervalle
     *   aligné sur un MTU alimente donc un seul créneau (résultat exact), tandis qu'un
     *   relevé horaire, ou un relevé cadencé mais décalé (poller à :07/:22/:37/:52),
     *   voit sa consommation répartie au lieu d'être facturée en entier au prix du
     *   seul quart où l'intervalle commence.
     * - `native` : la CADENCE des relevés est-elle quart-horaire ? Vrai dès que
     *   l'intervalle ne dépasse pas 15 min à la tolérance près — le jitter de quelques
     *   secondes d'un compteur ou d'un poller ne doit pas faire passer un flux
     *   réellement quart-horaire pour une reconstruction. Un créneau reste natif tant
     *   que tout son kWh vient d'intervalles de cette cadence.
     *
     * L'appelant se sert du drapeau pour refuser de présenter comme quart-horaire un
     * calcul qui ne l'est pas ; l'étalement, lui, vaut dans les deux cas.
     *
     * @return array<int, array{quarter: string, import_kwh: float, native: bool}>
     */
    public function getQuarterImportDeltas(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = $this->importIndexTotals($from, $to);
        $utc  = Dates::utc();

        /** @var array<string, array{kwh: float, native: bool}> $buckets */
        $buckets = [];
        $add = static function (string $slot, float $kwh, bool $native) use (&$buckets): void {
            $current        = $buckets[$slot] ?? ['kwh' => 0.0, 'native' => true];
            $buckets[$slot] = [
                'kwh'    => $current['kwh'] + $kwh,
                // Un créneau n'est natif que si TOUT son kWh vient de relevés au pas
                // de 15 min : une seule part étalée suffit à le disqualifier.
                'native' => $current['native'] && $native,
            ];
        };

        for ($i = 1, $iMax = count($rows); $i < $iMax; $i++) {
            $delta  = max(0.0, $rows[$i]['total'] - $rows[$i - 1]['total']);
            $start  = Dates::fromDbString($rows[$i - 1]['ts']);
            $end    = Dates::fromDbString($rows[$i]['ts']);
            $span   = $end->getTimestamp() - $start->getTimestamp();
            $native = $span <= self::QUARTER_SECONDS + self::QUARTER_JITTER_SECONDS;

            $cursor = $start;
            while ($cursor < $end) {
                [$slotStart, $slotEnd] = ReadingGranularity::QuarterHour->bucket($cursor, $utc);
                $sliceEnd = $slotEnd < $end ? $slotEnd : $end;
                $share    = (float) ($sliceEnd->getTimestamp() - $cursor->getTimestamp()) / (float) $span;
                $add(Dates::toDbString($slotStart), $delta * $share, $native);
                $cursor = $sliceEnd;
            }
        }

        ksort($buckets);

        $out = [];
        foreach ($buckets as $slot => $bucket) {
            $out[] = [
                'quarter'    => $slot,
                'import_kwh' => round($bucket['kwh'], 3),
                'native'     => $bucket['native'],
            ];
        }

        return $out;
    }

    /**
     * Index IMPORT (T1+T2) cumulés par horodatage sur [$from, $to], triés.
     *
     * Fusion par horodatage : les registres T1/T2 sont relevés au même instant
     * (même trame du compteur) ; on ne somme que les instants présents des deux côtés.
     *
     * @return list<array{ts: string, total: float}>
     */
    private function importIndexTotals(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
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

        return $rows;
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
