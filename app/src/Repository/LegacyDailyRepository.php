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
     * Compute current-month consumption/injection deltas for electricity and solar.
     * Delta = latest available reading − first reading of the current calendar month.
     *
     * @return array<string, mixed>
     */
    private function computeMonthlyDeltas(): array
    {
        // First reading of the current month
        $stmt = $this->pdo->query(
            'SELECT d.timestamp, d.Prelev_jour, d.Prelev_nuit, d.Injec_jour, d.Injec_nuit
             FROM Data_Dries d
             INNER JOIN (
                 SELECT MIN(timestamp) AS min_ts
                 FROM Data_Dries
                 WHERE timestamp >= DATE_FORMAT(CURDATE(), \'%Y-%m-01\')
                   AND timestamp < DATE_FORMAT(CURDATE(), \'%Y-%m-01\') + INTERVAL 1 MONTH
             ) f ON d.timestamp = f.min_ts
             LIMIT 1'
        );
        $first = $stmt->fetch() ?: null;

        if ($first === null) {
            return [];
        }

        // Latest available reading
        $stmt   = $this->pdo->query(
            'SELECT timestamp, Prelev_jour, Prelev_nuit, Injec_jour, Injec_nuit
             FROM Data_Dries ORDER BY timestamp DESC LIMIT 1'
        );
        $latest = $stmt->fetch() ?: null;

        if ($latest === null) {
            return [];
        }

        $result = [
            'from'        => $first['timestamp'],
            'to'          => $latest['timestamp'],
            'prelev_jour' => max(0.0, round((float) $latest['Prelev_jour'] - (float) $first['Prelev_jour'], 3)),
            'prelev_nuit' => max(0.0, round((float) $latest['Prelev_nuit'] - (float) $first['Prelev_nuit'], 3)),
            'injec_jour'  => max(0.0, round((float) $latest['Injec_jour']  - (float) $first['Injec_jour'],  3)),
            'injec_nuit'  => max(0.0, round((float) $latest['Injec_nuit']  - (float) $first['Injec_nuit'],  3)),
        ];

        // Solar delta (cumulative source: Data_Solaire stores cumulative kWh after migration)
        $table = $this->solarTable();
        $stmt  = $this->pdo->query(
            "SELECT s.timestamp, s.production
             FROM {$table} s
             INNER JOIN (
                 SELECT MIN(timestamp) AS min_ts
                 FROM {$table}
                 WHERE timestamp >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                   AND timestamp < DATE_FORMAT(CURDATE(), '%Y-%m-01') + INTERVAL 1 MONTH
             ) f ON s.timestamp = f.min_ts
             LIMIT 1"
        );
        $firstSolar = $stmt->fetch() ?: null;

        $stmt       = $this->pdo->query("SELECT production FROM {$table} ORDER BY timestamp DESC LIMIT 1");
        $latestSolar = $stmt->fetchColumn();

        if ($firstSolar !== null && $latestSolar !== false) {
            $unit = ($table === 'Data_Solaire') ? 'kwh' : 'wh';
            $raw  = max(0.0, (float) $latestSolar - (float) $firstSolar['production']);
            $result['solar']      = round($unit === 'kwh' ? $raw : $raw / 1000, 3);
            $result['solar_unit'] = 'kwh';
        } else {
            $result['solar']      = null;
            $result['solar_unit'] = null;
        }

        return $result;
    }

    /**
     * Compute electricity/solar deltas for a specific calendar month.
     * Returns [] if no data is available for that month.
     *
     * @return array<string, mixed>
     */
    public function getMonthlyDeltasForMonth(int $year, int $month): array
    {
        $monthStart = sprintf('%04d-%02d-01 00:00:00', $year, $month);

        // First reading of the given month
        $stmt = $this->pdo->prepare(
            'SELECT d.timestamp, d.Prelev_jour, d.Prelev_nuit, d.Injec_jour, d.Injec_nuit
             FROM Data_Dries d
             INNER JOIN (
                 SELECT MIN(timestamp) AS min_ts
                 FROM Data_Dries
                 WHERE timestamp >= :month_start AND timestamp < :month_start + INTERVAL 1 MONTH
             ) f ON d.timestamp = f.min_ts
             LIMIT 1'
        );
        $stmt->execute(['month_start' => $monthStart]);
        $first = $stmt->fetch() ?: null;

        if ($first === null) {
            return [];
        }

        // Upper bound: first reading of the next calendar month.
        // This is the most accurate boundary — it captures the full month including
        // the last hour, exactly like daily deltas use "first of day N+1".
        // Falls back to the last reading of the requested month when next month
        // has no data yet (i.e. the current ongoing month).
        $nextYear  = $month === 12 ? $year + 1 : $year;
        $nextMonth = $month === 12 ? 1 : $month + 1;
        $nextMonthStart = sprintf('%04d-%02d-01 00:00:00', $nextYear, $nextMonth);

        $stmt = $this->pdo->prepare(
            'SELECT d.timestamp, d.Prelev_jour, d.Prelev_nuit, d.Injec_jour, d.Injec_nuit
             FROM Data_Dries d
             INNER JOIN (
                 SELECT MIN(timestamp) AS min_ts
                 FROM Data_Dries
                 WHERE timestamp >= :month_start AND timestamp < :month_start + INTERVAL 1 MONTH
             ) f ON d.timestamp = f.min_ts
             LIMIT 1'
        );
        $stmt->execute(['month_start' => $nextMonthStart]);
        $latest = $stmt->fetch() ?: null;

        // No first reading of next month → use last reading of the requested month
        if ($latest === null) {
            $stmt = $this->pdo->prepare(
                'SELECT timestamp, Prelev_jour, Prelev_nuit, Injec_jour, Injec_nuit
                 FROM Data_Dries
                 WHERE timestamp >= :month_start AND timestamp < :month_start + INTERVAL 1 MONTH
                 ORDER BY timestamp DESC LIMIT 1'
            );
            $stmt->execute(['month_start' => $monthStart]);
            $latest = $stmt->fetch() ?: null;
        }

        if ($latest === null) {
            return [];
        }

        $result = [
            'from'        => $first['timestamp'],
            'to'          => $latest['timestamp'],
            'prelev_jour' => max(0.0, round((float) $latest['Prelev_jour'] - (float) $first['Prelev_jour'], 3)),
            'prelev_nuit' => max(0.0, round((float) $latest['Prelev_nuit'] - (float) $first['Prelev_nuit'], 3)),
            'injec_jour'  => max(0.0, round((float) $latest['Injec_jour']  - (float) $first['Injec_jour'],  3)),
            'injec_nuit'  => max(0.0, round((float) $latest['Injec_nuit']  - (float) $first['Injec_nuit'],  3)),
        ];

        // Solar delta — same boundary logic
        $table = $this->solarTable();
        $stmt  = $this->pdo->prepare(
            "SELECT s.timestamp, s.production
             FROM {$table} s
             INNER JOIN (
                 SELECT MIN(timestamp) AS min_ts
                 FROM {$table}
                 WHERE timestamp >= :month_start AND timestamp < :month_start + INTERVAL 1 MONTH
             ) f ON s.timestamp = f.min_ts
             LIMIT 1"
        );
        $stmt->execute(['month_start' => $monthStart]);
        $firstSolar = $stmt->fetch() ?: null;

        // First reading of next month for solar (same boundary logic as electricity)
        $stmt = $this->pdo->prepare(
            "SELECT s.production
             FROM {$table} s
             INNER JOIN (
                 SELECT MIN(timestamp) AS min_ts
                 FROM {$table}
                 WHERE timestamp >= :month_start AND timestamp < :month_start + INTERVAL 1 MONTH
             ) f ON s.timestamp = f.min_ts
             LIMIT 1"
        );
        $stmt->execute(['month_start' => $nextMonthStart]);
        $latestSolar = $stmt->fetchColumn();

        // Fallback to last reading of the month
        if ($latestSolar === false) {
            $stmt = $this->pdo->prepare(
                "SELECT production FROM {$table}
                 WHERE timestamp >= :month_start AND timestamp < :month_start + INTERVAL 1 MONTH
                 ORDER BY timestamp DESC LIMIT 1"
            );
            $stmt->execute(['month_start' => $monthStart]);
            $latestSolar = $stmt->fetchColumn();
        }

        if ($firstSolar !== null && $latestSolar !== false) {
            $unit                 = ($table === 'Data_Solaire') ? 'kwh' : 'wh';
            $raw                  = max(0.0, (float) $latestSolar - (float) $firstSolar['production']);
            $result['solar']      = round($unit === 'kwh' ? $raw : $raw / 1000, 3);
            $result['solar_unit'] = 'kwh';
        } else {
            $result['solar']      = null;
            $result['solar_unit'] = null;
        }

        return $result;
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