<?php

declare(strict_types=1);

namespace App\Infrastructure;

use PDO;
use RuntimeException;

/**
 * Applique les migrations SQL versionnées de app/sql/migrations/ de façon
 * idempotente : chaque fichier déjà appliqué est tracé dans la table
 * `schema_migrations` et n'est jamais rejoué.
 *
 * Les migrations antérieures à l'introduction du runner sont « baselinées »
 * directement dans schema.sql (INSERT IGNORE) : elles sont déjà reflétées dans
 * le schéma de référence et ne doivent pas être réexécutées.
 */
final class MigrationRunner
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationsDir,
    ) {
    }

    public function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations ('
            . ' version VARCHAR(255) NOT NULL PRIMARY KEY,'
            . ' applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /** @return list<string> */
    public function appliedVersions(): array
    {
        $stmt = $this->pdo->query('SELECT version FROM schema_migrations ORDER BY version');
        if ($stmt === false) {
            return [];
        }

        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_map(static fn (mixed $v): string => (string) $v, $rows));
    }

    /** @return list<string> noms des fichiers .sql triés par ordre lexical */
    public function availableVersions(): array
    {
        return self::scanVersions($this->migrationsDir);
    }

    /** @return list<string> migrations en attente, dans l'ordre */
    public function pendingVersions(): array
    {
        return self::computePending($this->availableVersions(), $this->appliedVersions());
    }

    /**
     * Applique les migrations en attente. En dry-run, retourne la liste sans
     * rien exécuter ni tracer.
     *
     * @return list<string> versions appliquées (ou en attente si dry-run)
     */
    public function migrate(bool $dryRun = false): array
    {
        $this->ensureTable();
        $pending = $this->pendingVersions();

        if ($dryRun) {
            return $pending;
        }

        foreach ($pending as $version) {
            $this->apply($version);
        }

        return $pending;
    }

    private function apply(string $version): void
    {
        $path = $this->migrationsDir . '/' . $version;
        $sql = is_file($path) ? file_get_contents($path) : false;
        if ($sql === false) {
            throw new RuntimeException('Migration illisible : ' . $version);
        }

        $statements = self::splitStatements($sql);

        // Transaction : rend atomiques les migrations DML + l'écriture de la
        // version (en cas d'échec, rollback complet). ATTENTION : MySQL committe
        // implicitement le DDL (CREATE / ALTER / DROP), qui ne peut donc pas être
        // annulé — les migrations DDL doivent rester idempotentes (IF [NOT]
        // EXISTS / gardes). C'est pourquoi commit() et rollBack() sont gardés par
        // inTransaction() : après un DDL, la transaction n'est plus active.
        $this->pdo->beginTransaction();
        try {
            foreach ($statements as $statement) {
                $this->pdo->exec($statement);
            }

            $stmt = $this->pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:version)');
            $stmt->execute(['version' => $version]);

            if ($this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return list<string> */
    public static function scanVersions(string $dir): array
    {
        $files = glob(rtrim($dir, '/') . '/*.sql');
        if ($files === false) {
            return [];
        }

        $versions = array_map('basename', $files);
        sort($versions, SORT_STRING);

        return $versions;
    }

    /**
     * @param list<string> $available
     * @param list<string> $applied
     * @return list<string>
     */
    public static function computePending(array $available, array $applied): array
    {
        $appliedSet = array_fill_keys($applied, true);
        $pending = [];
        foreach ($available as $version) {
            if (isset($appliedSet[$version]) === false) {
                $pending[] = $version;
            }
        }

        return $pending;
    }

    /**
     * Découpe un fichier SQL en instructions exécutables.
     *
     * Analyse caractère par caractère afin d'ignorer les `;` qui apparaissent
     * dans une chaîne (`'...'`, `"..."`), un identifiant entre backticks, ou un
     * commentaire (`-- ...` jusqu'à la fin de ligne, ou de bloc). Les
     * commentaires sont retirés du SQL renvoyé ; les chaînes (et les `;` ou
     * commentaires qu'elles contiennent) sont préservées telles quelles.
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $length = strlen($sql);

        $inSingle = false;
        $inDouble = false;
        $inBacktick = false;
        $inLineComment = false;
        $inBlockComment = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($inLineComment) {
                if ($char === "\n") {
                    $inLineComment = false;
                    $current .= $char;
                }
                continue;
            }

            if ($inBlockComment) {
                if ($char === '*' && $next === '/') {
                    $inBlockComment = false;
                    $i++;
                }
                continue;
            }

            if ($inSingle || $inDouble) {
                $current .= $char;
                if ($char === '\\' && $next !== '') {
                    $current .= $next;
                    $i++;
                    continue;
                }
                if ($inSingle && $char === "'") {
                    $inSingle = false;
                } elseif ($inDouble && $char === '"') {
                    $inDouble = false;
                }
                continue;
            }

            if ($inBacktick) {
                $current .= $char;
                if ($char === '`') {
                    $inBacktick = false;
                }
                continue;
            }

            if ($char === '-' && $next === '-') {
                $inLineComment = true;
                $i++;
                continue;
            }
            if ($char === '/' && $next === '*') {
                $inBlockComment = true;
                $i++;
                continue;
            }
            if ($char === "'") {
                $inSingle = true;
                $current .= $char;
                continue;
            }
            if ($char === '"') {
                $inDouble = true;
                $current .= $char;
                continue;
            }
            if ($char === '`') {
                $inBacktick = true;
                $current .= $char;
                continue;
            }
            if ($char === ';') {
                $trimmed = trim($current);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $trimmed = trim($current);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }
}
