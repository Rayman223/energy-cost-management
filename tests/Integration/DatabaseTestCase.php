<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Socle commun des tests d'intégration adossés à une vraie base MySQL/MariaDB.
 *
 * La suite ne se connecte JAMAIS à `database.name` : elle en dérive le nom de la
 * base jetable en y suffixant « _test » (energy → energy_test). La protection
 * contre un seed destructif (DELETE FROM users…) lancé sur la base de travail est
 * donc structurelle — le nom ouvert se termine toujours par « _test », et la base
 * de dev n'est pas même jointe (le DSN de sondage n'a pas de `dbname`). C'est ce
 * qui remplace l'ancien garde-fou `str_contains($db['name'], 'test')`, qui reposait
 * sur une valeur saisie à la main (« energy_prod_test » le contournait).
 *
 * Contrepartie assumée : la base de test vit sur le même serveur, avec les mêmes
 * identifiants que la base de travail.
 *
 * Les tests S'AUTO-SKIPPENT quand la config est absente, le serveur injoignable ou
 * la base dérivée introuvable.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected ?PDO $pdo = null;

    /**
     * Config `database` résolue une fois pour toute la suite : la lecture du
     * fichier et le sondage d'existence ne sont pas payés par chacun des tests.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $db = null;

    private static ?string $skipReason = null;

    protected function setUp(): void
    {
        $this->pdo = $this->connectToTestDatabase();
        $this->clean();
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->clean();
        }
    }

    /**
     * Purge les tables touchées par le cas de test. Appelée avant chaque test et
     * après, pour ne rien laisser derrière soi.
     */
    abstract protected function clean(): void;

    protected function pdo(): PDO
    {
        if ($this->pdo === null) {
            self::fail('PDO non initialisé.');
        }

        return $this->pdo;
    }

    /**
     * Nom de la base jetable, dérivé de `database.name`. Inconditionnel : c'est
     * la dérivation elle-même qui fait la garantie.
     */
    protected static function testDatabaseName(string $configuredName): string
    {
        return $configuredName . '_test';
    }

    private function connectToTestDatabase(): PDO
    {
        $db = self::databaseConfig();
        $testDb = self::testDatabaseName((string) $db['name']);

        // DSN sans `dbname` : on se connecte au SERVEUR, jamais à `database.name`.
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;charset=%s', $db['host'], $db['port'], $db['charset'] ?? 'utf8mb4'),
                (string) $db['user'],
                (string) $db['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'"],
            );
        } catch (\Throwable $e) {
            self::markTestSkipped('Base injoignable — test BDD ignoré : ' . $e->getMessage());
        }

        // information_schema.SCHEMATA ne liste que les bases sur lesquelles
        // l'utilisateur a des droits : base absente et base sans GRANT sont
        // indiscernables ici, le message couvre donc les deux.
        $stmt = $pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $stmt->execute([$testDb]);

        if ($stmt->fetchColumn() === false) {
            self::markTestSkipped(
                "Base « {$testDb} » introuvable (ou droits manquants pour l'utilisateur "
                . "« {$db['user']} ») — voir app/docs/installation.md pour la créer."
            );
        }

        $pdo->exec('USE `' . str_replace('`', '``', $testDb) . '`');

        return $pdo;
    }

    /** @return array<string, mixed> */
    private static function databaseConfig(): array
    {
        if (self::$skipReason !== null) {
            self::markTestSkipped(self::$skipReason);
        }

        if (self::$db === null) {
            $configPath = __DIR__ . '/../../app/config/config.php';
            if (!is_file($configPath)) {
                self::$skipReason = 'app/config/config.php absent — test BDD ignoré.';
                self::markTestSkipped(self::$skipReason);
            }

            /** @var array{database: array<string, mixed>} $config */
            $config = require $configPath;
            self::$db = $config['database'];
        }

        return self::$db;
    }
}
