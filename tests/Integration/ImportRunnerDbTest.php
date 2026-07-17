<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\UserRepository;
use App\Service\BulkImportService;
use App\Service\Import\ImportMapping;
use App\Service\Import\ImportRunner;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Orchestration transactionnelle {@see ImportRunner::run()} contre une vraie
 * base : plafond stop-and-report + commit/rollback (dry-run).
 *
 * S'AUTO-SKIPPE sans base joignable. Garde anti-destruction : refuse toute base
 * dont le nom ne contient pas « test ».
 */
final class ImportRunnerDbTest extends TestCase
{
    private ?PDO $pdo = null;

    /** @var list<string> Fichiers CSV temporaires à purger. */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $configPath = __DIR__ . '/../../app/config/config.php';
        if (!is_file($configPath)) {
            self::markTestSkipped('app/config/config.php absent — test BDD ignoré.');
        }

        /** @var array{database: array<string, mixed>} $config */
        $config = require $configPath;
        $db = $config['database'];

        if (!str_contains((string) $db['name'], 'test')) {
            self::markTestSkipped('Base "' . $db['name'] . '" non-test — seed destructif refusé.');
        }

        try {
            $this->pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $db['host'], $db['port'], $db['name'], $db['charset'] ?? 'utf8mb4'),
                (string) $db['user'],
                (string) $db['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
            );
        } catch (\Throwable $e) {
            self::markTestSkipped('Base injoignable — test BDD ignoré : ' . $e->getMessage());
        }

        $this->clean();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        $this->tempFiles = [];

        if ($this->pdo !== null) {
            $this->clean();
        }
    }

    public function testCapStopsAndReportsWithoutLosingImportedRows(): void
    {
        $userId = (new UserRepository($this->pdo()))->create('https://iss.example', 'cap-owner', 'example', 'Cap')->id;

        $rows = [];
        for ($day = 1; $day <= 5; $day++) {
            $rows[$day + 1] = ['timestamp' => sprintf('2026-01-%02d 08:00:00', $day), 'value' =>(string) (100 + $day)];
        }

        // Plafond volontairement bas (2 lignes) → tronqué mais N premières gardées.
        $runner = new ImportRunner(new BulkImportService(), 8_388_608, 2);
        $report = $runner->run($this->pdo(), ImportMapping::preset('gas'), $rows, $userId, 'gas', false);

        self::assertTrue($report->truncated());
        self::assertSame(2, $report->imported());
        self::assertSame(2, $this->countRows('utility_readings', $userId), 'les 2 lignes sont bien committées');
    }

    public function testDryRunRollsBackWhileReportingCounts(): void
    {
        $userId = (new UserRepository($this->pdo()))->create('https://iss.example', 'dry-owner', 'example', 'Dry')->id;

        $rows = [
            2 => ['timestamp' => '2026-02-01 08:00:00', 'value' =>'10'],
            3 => ['timestamp' => '2026-02-02 08:00:00', 'value' =>'11'],
        ];

        $report = (new ImportRunner())->run($this->pdo(), ImportMapping::preset('gas'), $rows, $userId, 'gas', true);

        self::assertSame(2, $report->imported());
        self::assertFalse($report->truncated());
        self::assertSame(0, $this->countRows('utility_readings', $userId), 'dry-run : rien en base');
    }

    /**
     * Scénario de l'issue #134, de bout en bout par la voie web : un seul CSV
     * d'export tableur (séparateur « ; », colonnes aux noms du fournisseur) dont
     * **tous** les index sont importés en une passe, via le mapping du formulaire.
     */
    public function testWebRequestImportsSeveralRegistersFromOneCsv(): void
    {
        $userId = (new UserRepository($this->pdo()))->create('https://iss.example', 'map-owner', 'example', 'Map')->id;

        $path = $this->tempCsv(
            "Date;HP_Jour;HP_Nuit;Inj_Jour\n"
            . "2026-03-01 08:00:00;1000;2000;50\n"
            . "2026-03-02 08:00:00;1010;2020;55\n"
        );

        $post = [
            'energy_type' => 'electricity',
            'unit'        => 'kwh',
            'ts_col'      => 'Date',
            'registers'   => [
                'import_t1' => 'HP_Jour',
                'import_t2' => 'HP_Nuit',
                'export_t1' => 'Inj_Jour',
                'export_t2' => '',       // non présent dans le fichier → ignoré
                'production' => '',
            ],
        ];

        $report = (new ImportRunner())->runFromRequest($this->pdo(), $userId, $post, $this->uploadedFile($path));

        self::assertSame(0, $report->errors());
        self::assertSame(6, $report->imported(), '2 lignes × 3 index mappés');
        self::assertSame(1000.0, $this->indexValue($userId, 'import_t1', '2026-03-01 08:00:00'));
        self::assertSame(2000.0, $this->indexValue($userId, 'import_t2', '2026-03-01 08:00:00'));
        self::assertSame(50.0, $this->indexValue($userId, 'export_t1', '2026-03-01 08:00:00'));

        // Idempotence : le même POST rejoué ne crée aucun doublon.
        $again = (new ImportRunner())->runFromRequest($this->pdo(), $userId, $post, $this->uploadedFile($this->tempCsv(
            "Date;HP_Jour;HP_Nuit;Inj_Jour\n2026-03-01 08:00:00;1000;2000;50\n"
        )));
        self::assertSame(0, $again->imported());
        self::assertSame(3, $again->duplicates());
    }

    /** Index d'un registre à un horodatage donné (null si absent). */
    private function indexValue(int $userId, string $registerKey, string $readingAt): ?float
    {
        $stmt = $this->pdo()->prepare(
            'SELECT mr.index_value FROM meter_readings mr
               JOIN meter_registers reg ON reg.id = mr.register_id
               JOIN meters m ON m.id = reg.meter_id
              WHERE m.user_id = :uid AND reg.register_key = :key AND mr.reading_at = :at'
        );
        $stmt->execute(['uid' => $userId, 'key' => $registerKey, 'at' => $readingAt]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (float) $value;
    }

    /** @return array{import_file: array{name: string, tmp_name: string, error: int, size: int}} */
    private function uploadedFile(string $path): array
    {
        // is_uploaded_file() est court-circuité hors SAPI web (cf. runUploaded).
        return ['import_file' => [
            'name'     => 'export.csv',
            'tmp_name' => $path,
            'error'    => UPLOAD_ERR_OK,
            'size'     => (int) filesize($path),
        ]];
    }

    private function tempCsv(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'import-test-');
        self::assertNotFalse($path);
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function countRows(string $table, int $userId): int
    {
        $stmt = $this->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE user_id = :uid");
        $stmt->execute(['uid' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    private function clean(): void
    {
        $pdo = $this->pdo();
        $pdo->exec('DELETE FROM meter_readings');
        $pdo->exec('DELETE FROM meter_registers');
        $pdo->exec('DELETE FROM meters');
        $pdo->exec('DELETE FROM utility_readings');
        $pdo->exec('DELETE FROM user_profiles');
        $pdo->exec('DELETE FROM users');
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            self::fail('PDO non initialisé.');
        }

        return $this->pdo;
    }
}
