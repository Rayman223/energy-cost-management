<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Contract\LegacyDailyRepositoryInterface;
use DateTimeImmutable;
use PDO;

final class LegacyDailyRepository implements LegacyDailyRepositoryInterface
{
    /** Cache du nom de table solaire résolu une seule fois par instance. */
    private ?string $solarTableCache = null;

    /**
     * Cache requête-scopé des deltas du mois courant. Le dashboard appelle
     * getMonthlyDeltas() directement ET via estimateCurrentMonthElectricity() :
     * sans cache, les ~4 requêtes tournent deux fois. Sûr car les relevés ne
     * changent pas au sein d'une requête HTTP (écriture = cron, pas le web).
     *
     * @var array<string, mixed>
     */
    private array $monthlyDeltasCache = [];

    private bool $monthlyDeltasComputed = false;

    public function __construct(private readonly PDO $pdo)
    {
    }

    // -------------------------------------------------------------------------
    // Webhook sync state
    // -------------------------------------------------------------------------

    public function getLastSentAt(string $source): ?DateTimeImmutable
    {
        $stmt = $this->pdo->prepare('SELECT last_sent_at FROM webhook_sync_state WHERE source_name = :source LIMIT 1');
        $stmt->execute(['source' => $source]);
        $value = $stmt->fetchColumn();

        return $value ? new DateTimeImmutable((string) $value) : null;
    }

    public function saveLastSentAt(string $source, DateTimeImmutable $lastSentAt): void
    {
        $sql = <<<'SQL'
            INSERT INTO webhook_sync_state (source_name, last_sent_at)
            VALUES (:source, :last_sent_at)
            ON DUPLICATE KEY UPDATE last_sent_at = VALUES(last_sent_at)
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'source'       => $source,
            'last_sent_at' => $lastSentAt->format('Y-m-d H:i:s'),
        ]);
    }

    // -------------------------------------------------------------------------
    // Dashboard: current index values
    // -------------------------------------------------------------------------

    /**
     * Latest electricity reading for today (or most recent available).
     * Shows the current meter index values.
     *
     * @return array<string, mixed>
     */
    public function getTodayIndexValues(): array
    {
        // Latest electricity reading for today
        $stmt = $this->pdo->query(
            'SELECT timestamp, Prelev_jour, Prelev_nuit, Injec_jour, Injec_nuit
             FROM Data_Dries
             WHERE timestamp >= CURDATE() AND timestamp < CURDATE() + INTERVAL 1 DAY
             ORDER BY timestamp DESC
             LIMIT 1'
        );
        $dries = $stmt->fetch() ?: null;

        // If no reading today, take the very latest available
        if ($dries === null) {
            $stmt  = $this->pdo->query(
                'SELECT timestamp, Prelev_jour, Prelev_nuit, Injec_jour, Injec_nuit
                 FROM Data_Dries ORDER BY timestamp DESC LIMIT 1'
            );
            $dries = $stmt->fetch() ?: null;
        }

        $table  = $this->solarTable();
        $stmt   = $this->pdo->query(
            "SELECT timestamp, production FROM {$table}
             WHERE timestamp >= CURDATE() AND timestamp < CURDATE() + INTERVAL 1 DAY
             ORDER BY timestamp DESC LIMIT 1"
        );
        $solar = $stmt->fetch() ?: null;

        if ($solar === null) {
            $stmt  = $this->pdo->query("SELECT timestamp, production FROM {$table} ORDER BY timestamp DESC LIMIT 1");
            $solar = $stmt->fetch() ?: null;
        }

        return [
            'dries'        => $dries,
            'solar'        => $solar,
            'solar_source' => $table,
        ];
    }

    // -------------------------------------------------------------------------
    // Dashboard: monthly deltas (consumption = latest - first of month)
    // -------------------------------------------------------------------------

    /**
     * Deltas du mois courant — mémoïsé par instance (cf. $monthlyDeltasCache),
     * car appelé deux fois lors du rendu du dashboard.
     *
     * @return array<string, mixed>
     */
    public function getMonthlyDeltas(): array
    {
        if (!$this->monthlyDeltasComputed) {
            $this->monthlyDeltasCache    = $this->computeMonthlyDeltas();
            $this->monthlyDeltasComputed = true;
        }

        return $this->monthlyDeltasCache;
    }

    /**
     * Deltas du mois courant — délègue à la logique d'interpolation à minuit.
     *
     * @return array<string, mixed>
     */
    private function computeMonthlyDeltas(): array
    {
        return $this->interpolatedMonthlyDeltas((int) date('Y'), (int) date('n'));
    }

    /**
     * Deltas électricité/solaire d'un mois, avec INTERPOLATION À MINUIT des bornes
     * (même méthode que le gaz/eau, cf. MonthlyConsumptionInterpolator) : on estime
     * l'index théorique à minuit le 1er de M et le 1er de M+1 par interpolation
     * linéaire entre les relevés horaires encadrants. Les index étant horaires et
     * denses, seuls les relevés qui entourent chaque borne sont nécessaires.
     *
     * Mois en cours (aucun relevé après la fin du mois) → borne de fin = dernier
     * relevé disponible (conso à ce jour, pas de projection — cohérent avec le
     * tarif dynamique horaire). Renvoie [] si le mois n'a aucun relevé.
     *
     * @return array<string, mixed>
     */
    private function interpolatedMonthlyDeltas(int $year, int $month): array
    {
        $monthStart = sprintf('%04d-%02d-01 00:00:00', $year, $month);

        $nextYear       = $month === 12 ? $year + 1 : $year;
        $nextMonth      = $month === 12 ? 1         : $month + 1;
        $nextMonthStart = sprintf('%04d-%02d-01 00:00:00', $nextYear, $nextMonth);

        $cols = ['Prelev_jour', 'Prelev_nuit', 'Injec_jour', 'Injec_nuit'];

        // Aucun relevé dans le mois → indisponible (même sémantique qu'avant).
        if ($this->firstReadingInMonth('Data_Dries', $monthStart, $nextMonthStart) === null) {
            return [];
        }

        $start = $this->interpolatedRowAt('Data_Dries', $cols, $monthStart);
        if ($start === null) {
            return [];
        }

        // Borne de fin : interpolée à minuit le 1er de M+1 si un relevé existe au-delà.
        // Sinon (mois en cours) interpolatedRowAt clampe sur le dernier relevé, et son
        // 'timestamp' de retour vaut alors l'horodatage de ce relevé → on l'utilise
        // directement comme borne de fin (conso jusqu'au dernier relevé, sans projection).
        $end = $this->interpolatedRowAt('Data_Dries', $cols, $nextMonthStart);
        if ($end === null) {
            return [];
        }
        $to = (string) $end['timestamp'];

        $result = [
            'from'        => $monthStart,
            'to'          => $to,
            'prelev_jour' => max(0.0, round((float) $end['Prelev_jour'] - (float) $start['Prelev_jour'], 3)),
            'prelev_nuit' => max(0.0, round((float) $end['Prelev_nuit'] - (float) $start['Prelev_nuit'], 3)),
            'injec_jour'  => max(0.0, round((float) $end['Injec_jour']  - (float) $start['Injec_jour'],  3)),
            'injec_nuit'  => max(0.0, round((float) $end['Injec_nuit']  - (float) $start['Injec_nuit'],  3)),
        ];

        // Solaire : interpolé aux mêmes instants (début = minuit, fin = $to).
        $table      = $this->solarTable();
        $solarStart = $this->interpolatedRowAt($table, ['production'], $monthStart);
        $solarEnd   = $this->interpolatedRowAt($table, ['production'], $to);

        if ($solarStart !== null && $solarEnd !== null) {
            $unit                 = ($table === 'Data_Solaire') ? 'kwh' : 'wh';
            $raw                  = max(0.0, (float) $solarEnd['production'] - (float) $solarStart['production']);
            $result['solar']      = round($unit === 'kwh' ? $raw : $raw / 1000, 3);
            $result['solar_unit'] = 'kwh';
        } else {
            $result['solar']      = null;
            $result['solar_unit'] = null;
        }

        return $result;
    }

    /**
     * Index interpolé à minuit (ou tout instant) : interpolation linéaire entre le
     * relevé juste avant et juste après l'instant. Si l'instant est hors de la
     * plage des relevés, on prend le relevé le plus proche (clamp). null si la
     * table ne contient aucun relevé.
     *
     * @param list<string> $columns colonnes numériques (liste contrôlée, non issue de l'utilisateur)
     * @return array<string, mixed>|null  valeurs par colonne (+ 'timestamp' du relevé de bord)
     */
    private function interpolatedRowAt(string $table, array $columns, string $instant): ?array
    {
        $cols = implode(', ', $columns);

        $stmt = $this->pdo->prepare(
            "SELECT timestamp, {$cols} FROM {$table} WHERE timestamp <= :i ORDER BY timestamp DESC LIMIT 1"
        );
        $stmt->execute(['i' => $instant]);
        $before = $stmt->fetch() ?: null;

        $stmt = $this->pdo->prepare(
            "SELECT timestamp, {$cols} FROM {$table} WHERE timestamp >= :i ORDER BY timestamp ASC LIMIT 1"
        );
        $stmt->execute(['i' => $instant]);
        $after = $stmt->fetch() ?: null;

        if ($before === null && $after === null) {
            return null;
        }
        if ($before === null) {
            return $this->rowToFloats($after, $columns);   // instant avant tout relevé → clamp
        }
        if ($after === null || $before['timestamp'] === $instant) {
            return $this->rowToFloats($before, $columns);
        }
        if ($after['timestamp'] === $instant) {
            return $this->rowToFloats($after, $columns);
        }

        // Interpolation linéaire par timestamp Unix.
        $aTs   = (int) strtotime((string) $before['timestamp']);
        $bTs   = (int) strtotime((string) $after['timestamp']);
        $iTs   = (int) strtotime($instant);
        $span  = $bTs - $aTs;
        $frac  = $span > 0 ? ($iTs - $aTs) / $span : 0.0;

        $out = ['timestamp' => $instant];
        foreach ($columns as $c) {
            $a       = (float) $before[$c];
            $b       = (float) $after[$c];
            $out[$c] = $a + ($b - $a) * $frac;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $columns
     * @return array<string, mixed>
     */
    private function rowToFloats(array $row, array $columns): array
    {
        $out = ['timestamp' => $row['timestamp']];
        foreach ($columns as $c) {
            $out[$c] = (float) $row[$c];
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function firstReadingInMonth(string $table, string $monthStart, string $nextMonthStart): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT timestamp FROM {$table} WHERE timestamp >= :start AND timestamp < :next ORDER BY timestamp ASC LIMIT 1"
        );
        $stmt->execute(['start' => $monthStart, 'next' => $nextMonthStart]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Compute electricity/solar deltas for a specific calendar month, interpolated
     * to midnight on the 1st of M and the 1st of M+1. Returns [] if no data.
     *
     * @return array<string, mixed>
     */
    public function getMonthlyDeltasForMonth(int $year, int $month): array
    {
        return $this->interpolatedMonthlyDeltas($year, $month);
    }

    // -------------------------------------------------------------------------
    // Dashboard: chart data (daily deltas for last N days)
    // -------------------------------------------------------------------------

    /**
     * Returns daily consumption/injection/solar deltas for the last N days.
     * Each entry represents the delta between the first reading of that day
     * and the first reading of the previous day.
     *
     * @return array<int, array{day:string, import_t1:float, import_t2:float, export_t1:float, export_t2:float, solar:float|null}>
     */
    public function getDailyDeltasForChart(int $days = 30): array
    {
        // Fetch one extra day for initial delta reference
        $stmt = $this->pdo->prepare(
            'SELECT d.timestamp,
                    d.Prelev_jour, d.Prelev_nuit,
                    d.Injec_jour,  d.Injec_nuit
             FROM Data_Dries d
             INNER JOIN (
                 SELECT DATE(timestamp) AS day_key, MIN(timestamp) AS min_ts
                 FROM Data_Dries
                 WHERE timestamp >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
                 GROUP BY DATE(timestamp)
             ) f ON d.timestamp = f.min_ts
             ORDER BY d.timestamp ASC'
        );
        $stmt->execute(['days' => $days + 1]);
        $rows = $stmt->fetchAll();

        $deltas = [];
        for ($i = 1, $iMax = count($rows); $i < $iMax; $i++) {
            $prev = $rows[$i - 1];
            $curr = $rows[$i];

            $deltas[substr($curr['timestamp'], 0, 10)] = [
                'day'       => substr($curr['timestamp'], 0, 10),
                'import_t1' => max(0.0, round((float) $curr['Prelev_jour'] - (float) $prev['Prelev_jour'], 3)),
                'import_t2' => max(0.0, round((float) $curr['Prelev_nuit'] - (float) $prev['Prelev_nuit'], 3)),
                'export_t1' => max(0.0, round((float) $curr['Injec_jour']  - (float) $prev['Injec_jour'],  3)),
                'export_t2' => max(0.0, round((float) $curr['Injec_nuit']  - (float) $prev['Injec_nuit'],  3)),
                'solar'     => null,
            ];
        }

        // Solar deltas
        $table = $this->solarTable();
        $stmt  = $this->pdo->prepare(
            "SELECT s.timestamp, s.production
             FROM {$table} s
             INNER JOIN (
                 SELECT DATE(timestamp) AS day_key, MIN(timestamp) AS min_ts
                 FROM {$table}
                 WHERE timestamp >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
                 GROUP BY DATE(timestamp)
             ) f ON s.timestamp = f.min_ts
             ORDER BY s.timestamp ASC"
        );
        $stmt->execute(['days' => $days + 1]);
        $solarRows = $stmt->fetchAll();

        $isKwh = ($table === 'Data_Solaire');
        for ($i = 1, $iMax = count($solarRows); $i < $iMax; $i++) {
            $prev  = $solarRows[$i - 1];
            $curr  = $solarRows[$i];
            $day   = substr($curr['timestamp'], 0, 10);
            $raw   = max(0.0, (float) $curr['production'] - (float) $prev['production']);
            $kWh   = $isKwh ? $raw : round($raw / 1000, 3);

            if (isset($deltas[$day])) {
                $deltas[$day]['solar'] = round($kWh, 3);
            }
        }

        return array_values($deltas);
    }

    // -------------------------------------------------------------------------
    // Dynamic tariff: hourly import consumption
    // -------------------------------------------------------------------------

    /**
     * Consommation IMPORT (T1+T2) ventilée par heure sur [$from, $to].
     *
     * Calque la logique de deltas consécutifs de getDailyDeltasForChart(), mais
     * au pas horaire : chaque delta entre deux relevés consécutifs est attribué
     * à l'heure de début de l'intervalle. Sert à croiser la conso avec un prix
     * dynamique horaire.
     *
     * @return array<int, array{hour: string, import_kwh: float}>
     */
    public function getHourlyImportDeltas(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT timestamp, Prelev_jour, Prelev_nuit
             FROM Data_Dries
             WHERE timestamp >= :from AND timestamp <= :to
             ORDER BY timestamp ASC'
        );
        $stmt->execute([
            'from' => $from->format('Y-m-d H:i:s'),
            'to'   => $to->format('Y-m-d H:i:s'),
        ]);
        /** @var array<int, array{timestamp: string, Prelev_jour: string, Prelev_nuit: string}> $rows */
        $rows = $stmt->fetchAll();

        $buckets = [];
        for ($i = 1, $iMax = count($rows); $i < $iMax; $i++) {
            $prev  = $rows[$i - 1];
            $curr  = $rows[$i];
            $delta = ((float) $curr['Prelev_jour'] + (float) $curr['Prelev_nuit'])
                   - ((float) $prev['Prelev_jour'] + (float) $prev['Prelev_nuit']);

            // L'index est cumulatif et croissant entre deux relevés au top de l'heure :
            // la conso de l'intervalle est imputée à l'heure de début.
            $hour            = substr($prev['timestamp'], 0, 13) . ':00:00';
            $buckets[$hour]  = ($buckets[$hour] ?? 0.0) + max(0.0, $delta);
        }

        $out = [];
        foreach ($buckets as $hour => $kwh) {
            $out[] = ['hour' => $hour, 'import_kwh' => round($kwh, 3)];
        }

        return $out;
    }

    // -------------------------------------------------------------------------
    // Batch / cron queries (used by DailyLegacyWebhookSyncService)
    // -------------------------------------------------------------------------

    /** @return array<int,array{timestamp:string,value:string}> */
    public function fetchDriesDailyFirstValues(
        string $column,
        ?DateTimeImmutable $fromExclusive,
        DateTimeImmutable $toInclusive
    ): array {
        $allowedColumns = ['Prelev_jour', 'Prelev_nuit', 'Injec_jour', 'Injec_nuit'];
        if (!in_array($column, $allowedColumns, true)) {
            throw new \InvalidArgumentException('Invalid Data_Dries column: ' . $column);
        }

        $boundsWhere = 'timestamp <= :to';
        $params      = ['to' => $toInclusive->format('Y-m-d H:i:s')];

        if ($fromExclusive !== null) {
            $boundsWhere          .= ' AND DATE(timestamp) > :from_day';
            $params['from_day']    = $fromExclusive->format('Y-m-d');
        }

        $sql = sprintf(
            "SELECT d.timestamp, d.%s AS value\n"
            . "FROM Data_Dries d\n"
            . "INNER JOIN (\n"
            . "    SELECT DATE(timestamp) AS day_key, MIN(timestamp) AS min_timestamp\n"
            . "    FROM Data_Dries\n"
            . "    WHERE %s\n"
            . "    GROUP BY DATE(timestamp)\n"
            . ") first_per_day ON first_per_day.min_timestamp = d.timestamp\n"
            . "ORDER BY d.timestamp ASC",
            $column,
            $boundsWhere
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @return array<int,array{timestamp:string,value:string}> */
    public function fetchSolaireDailyFirstValues(?DateTimeImmutable $fromExclusive, DateTimeImmutable $toInclusive): array
    {
        $table       = $this->solarTable();
        $boundsWhere = 'timestamp <= :to';
        $params      = ['to' => $toInclusive->format('Y-m-d H:i:s')];

        if ($fromExclusive !== null) {
            $boundsWhere          .= ' AND DATE(timestamp) > :from_day';
            $params['from_day']    = $fromExclusive->format('Y-m-d');
        }

        $sql = sprintf(
            "SELECT s.timestamp, s.production AS value\n"
            . "FROM %s s\n"
            . "INNER JOIN (\n"
            . "    SELECT DATE(timestamp) AS day_key, MIN(timestamp) AS min_timestamp\n"
            . "    FROM %s\n"
            . "    WHERE %s\n"
            . "    GROUP BY DATE(timestamp)\n"
            . ") first_per_day ON first_per_day.min_timestamp = s.timestamp\n"
            . "ORDER BY s.timestamp ASC",
            $table,
            $table,
            $boundsWhere
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function solarTable(): string
    {
        if ($this->solarTableCache === null) {
            $this->solarTableCache = $this->tableExists('Data_Solaire') ? 'Data_Solaire' : 'Data_Brusol';
        }

        return $this->solarTableCache;
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare('SHOW TABLES LIKE :table_name');
        $stmt->execute(['table_name' => $table]);

        return (bool) $stmt->fetchColumn();
    }
}