<?php

declare(strict_types=1);

namespace App\Service;

use Generator;
use PDO;

/**
 * Génère un dump SQL logique complet de la base via PDO — sans dépendre du
 * binaire `mysqldump` (indisponible, par ex., dans le container web Alpine).
 *
 * dump() est un générateur qui produit le script SQL par fragments : le flux
 * peut être écrit directement dans un `gzopen()` sans charger toute la base en
 * mémoire (les données de chaque table sont lues en mode non bufferisé,
 * équivalent du `--quick` de mysqldump).
 *
 * Couvre : tables (structure + données), vues, triggers, routines (procédures
 * et fonctions) et events. Les colonnes générées sont exclues des INSERT.
 */
final class DatabaseBackupService
{
    /** Nombre de lignes regroupées par INSERT étendu. */
    private const ROWS_PER_INSERT = 200;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Produit le dump SQL par fragments (en-tête, objets, pied de page).
     *
     * @return Generator<string>
     */
    public function dump(): Generator
    {
        $dbName = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();

        yield "-- Dump SQL de la base `{$dbName}`\n";
        yield '-- Généré le ' . date('Y-m-d H:i:s') . " par DatabaseBackupService\n\n";
        yield "SET NAMES utf8mb4;\n";
        yield "SET FOREIGN_KEY_CHECKS = 0;\n";
        yield "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
        yield "SET time_zone = '+00:00';\n\n";

        // SHOW FULL TABLES distingue BASE TABLE et VIEW ; on dumpe les tables
        // d'abord (structure + données), puis les vues (qui peuvent en dépendre).
        $tables = [];
        $views = [];
        $rows = $this->pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
        foreach ($rows as $row) {
            if (($row[1] ?? '') === 'VIEW') {
                $views[] = (string) $row[0];
            } else {
                $tables[] = (string) $row[0];
            }
        }

        foreach ($tables as $table) {
            yield from $this->dumpTableStructure($table);
            yield from $this->dumpTableData($table);
        }

        foreach ($views as $view) {
            yield from $this->dumpView($view);
        }

        yield from $this->dumpTriggers();
        yield from $this->dumpRoutines();
        yield from $this->dumpEvents();

        yield "\nSET FOREIGN_KEY_CHECKS = 1;\n";
        yield "-- Fin du dump\n";
    }

    /** @return Generator<string> */
    private function dumpTableStructure(string $table): Generator
    {
        $create = (string) $this->pdo->query('SHOW CREATE TABLE ' . $this->quoteId($table))
            ->fetch(PDO::FETCH_NUM)[1];

        yield "\n-- Structure de la table `{$table}`\n";
        yield 'DROP TABLE IF EXISTS ' . $this->quoteId($table) . ";\n";
        yield $create . ";\n\n";
    }

    /** @return Generator<string> */
    private function dumpTableData(string $table): Generator
    {
        // Colonnes réellement insérables : on exclut les colonnes générées
        // (VIRTUAL/STORED), qu'un INSERT ne doit pas alimenter.
        $columns = [];
        $meta = $this->pdo->query('SHOW COLUMNS FROM ' . $this->quoteId($table))
            ->fetchAll(PDO::FETCH_ASSOC);
        foreach ($meta as $col) {
            // On cible précisément 'VIRTUAL GENERATED' / 'STORED GENERATED' pour
            // ne pas confondre avec 'DEFAULT_GENERATED' (colonnes DEFAULT
            // CURRENT_TIMESTAMP sous MySQL 8), qui doivent, elles, être dumpées.
            $extra = strtoupper((string) ($col['Extra'] ?? ''));
            if (!str_contains($extra, 'VIRTUAL GENERATED') && !str_contains($extra, 'STORED GENERATED')) {
                $columns[] = (string) $col['Field'];
            }
        }

        if ($columns === []) {
            return;
        }

        $columnList = implode(', ', array_map([$this, 'quoteId'], $columns));
        $selectList = $columnList;

        // Lecture non bufferisée : streaming ligne à ligne, mémoire constante.
        $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        try {
            $stmt = $this->pdo->query(
                'SELECT ' . $selectList . ' FROM ' . $this->quoteId($table)
            );

            $insertPrefix = 'INSERT INTO ' . $this->quoteId($table)
                . ' (' . $columnList . ") VALUES\n";

            $buffer = [];
            $emittedHeader = false;
            foreach ($stmt as $row) {
                if (!$emittedHeader) {
                    yield "-- Données de la table `{$table}`\n";
                    $emittedHeader = true;
                }

                $values = array_map([$this, 'quoteValue'], array_values($row));
                $buffer[] = '(' . implode(', ', $values) . ')';

                if (count($buffer) >= self::ROWS_PER_INSERT) {
                    yield $insertPrefix . implode(",\n", $buffer) . ";\n";
                    $buffer = [];
                }
            }

            if ($buffer !== []) {
                yield $insertPrefix . implode(",\n", $buffer) . ";\n";
            }
            if ($emittedHeader) {
                yield "\n";
            }
        } finally {
            $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }
    }

    /** @return Generator<string> */
    private function dumpView(string $view): Generator
    {
        $create = (string) $this->pdo->query('SHOW CREATE VIEW ' . $this->quoteId($view))
            ->fetch(PDO::FETCH_NUM)[1];

        yield "\n-- Vue `{$view}`\n";
        yield 'DROP VIEW IF EXISTS ' . $this->quoteId($view) . ";\n";
        yield $create . ";\n";
    }

    /** @return Generator<string> */
    private function dumpTriggers(): Generator
    {
        $triggers = $this->pdo->query('SHOW TRIGGERS')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($triggers as $trigger) {
            $name = (string) $trigger['Trigger'];
            $create = (string) $this->pdo->query('SHOW CREATE TRIGGER ' . $this->quoteId($name))
                ->fetch(PDO::FETCH_ASSOC)['SQL Original Statement'];
            yield "\n-- Trigger `{$name}`\n";
            yield 'DROP TRIGGER IF EXISTS ' . $this->quoteId($name) . ";\n";
            yield from $this->wrapWithDelimiter($create);
        }
    }

    /** @return Generator<string> */
    private function dumpRoutines(): Generator
    {
        foreach (['PROCEDURE', 'FUNCTION'] as $type) {
            $routines = $this->pdo->query('SHOW ' . $type . ' STATUS WHERE Db = DATABASE()')
                ->fetchAll(PDO::FETCH_ASSOC);
            foreach ($routines as $routine) {
                $name = (string) $routine['Name'];
                $row = $this->pdo->query('SHOW CREATE ' . $type . ' ' . $this->quoteId($name))
                    ->fetch(PDO::FETCH_ASSOC);
                $create = (string) $row['Create ' . ucfirst(strtolower($type))];
                yield "\n-- {$type} `{$name}`\n";
                yield 'DROP ' . $type . ' IF EXISTS ' . $this->quoteId($name) . ";\n";
                yield from $this->wrapWithDelimiter($create);
            }
        }
    }

    /** @return Generator<string> */
    private function dumpEvents(): Generator
    {
        // Base sans event scheduler : SHOW EVENTS peut échouer selon les droits.
        try {
            $events = $this->pdo->query('SHOW EVENTS WHERE Db = DATABASE()')
                ->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return;
        }

        foreach ($events as $event) {
            $name = (string) $event['Name'];
            $create = (string) $this->pdo->query('SHOW CREATE EVENT ' . $this->quoteId($name))
                ->fetch(PDO::FETCH_ASSOC)['Create Event'];
            yield "\n-- Event `{$name}`\n";
            yield 'DROP EVENT IF EXISTS ' . $this->quoteId($name) . ";\n";
            yield from $this->wrapWithDelimiter($create);
        }
    }

    /**
     * Encadre un objet à corps multi-instructions (trigger/routine/event) par un
     * changement de DELIMITER, comme mysqldump, pour un restore via le client mysql.
     *
     * @return Generator<string>
     */
    private function wrapWithDelimiter(string $create): Generator
    {
        yield "DELIMITER ;;\n";
        yield $create . ";;\n";
        yield "DELIMITER ;\n";
    }

    private function quoteId(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function quoteValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        return $this->pdo->quote((string) $value);
    }
}
