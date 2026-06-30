<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Infrastructure\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class MigrationRunnerTest extends TestCase
{
    public function testComputePendingReturnsOnlyUnapplied(): void
    {
        $available = ['001_a.sql', '002_b.sql', '003_c.sql'];
        $applied = ['001_a.sql', '003_c.sql'];

        self::assertSame(['002_b.sql'], MigrationRunner::computePending($available, $applied));
    }

    public function testComputePendingPreservesOrderAndHandlesEmpty(): void
    {
        self::assertSame(['a.sql', 'b.sql'], MigrationRunner::computePending(['a.sql', 'b.sql'], []));
        self::assertSame([], MigrationRunner::computePending(['a.sql'], ['a.sql']));
        self::assertSame([], MigrationRunner::computePending([], ['a.sql']));
    }

    public function testScanVersionsListsSortedSqlFilesOnly(): void
    {
        $dir = sys_get_temp_dir() . '/migtest_' . uniqid('', true);
        mkdir($dir);

        try {
            file_put_contents($dir . '/002_second.sql', 'SELECT 1;');
            file_put_contents($dir . '/001_first.sql', 'SELECT 1;');
            file_put_contents($dir . '/readme.txt', 'ignore');

            self::assertSame(['001_first.sql', '002_second.sql'], MigrationRunner::scanVersions($dir));
        } finally {
            @unlink($dir . '/002_second.sql');
            @unlink($dir . '/001_first.sql');
            @unlink($dir . '/readme.txt');
            @rmdir($dir);
        }
    }

    public function testSplitStatementsDropsCommentsAndSplitsOnSemicolon(): void
    {
        $sql = "-- commentaire\nALTER TABLE a DROP INDEX i;\nALTER TABLE b DROP INDEX j;\n";

        self::assertSame(
            ['ALTER TABLE a DROP INDEX i', 'ALTER TABLE b DROP INDEX j'],
            MigrationRunner::splitStatements($sql)
        );
    }

    public function testSplitStatementsKeepsSingleCreateTableIntact(): void
    {
        $sql = "CREATE TABLE x (\n  id INT,\n  name VARCHAR(10)\n);\n";

        $statements = MigrationRunner::splitStatements($sql);

        self::assertCount(1, $statements);
        self::assertStringStartsWith('CREATE TABLE x', $statements[0]);
    }

    public function testSplitStatementsKeepsSemicolonInsideStringLiteral(): void
    {
        $sql = "INSERT INTO t (c) VALUES ('a;b');";

        self::assertSame(["INSERT INTO t (c) VALUES ('a;b')"], MigrationRunner::splitStatements($sql));
    }

    public function testSplitStatementsKeepsSemicolonInCommentClause(): void
    {
        $sql = "CREATE TABLE t (id INT COMMENT 'x; y');";

        $statements = MigrationRunner::splitStatements($sql);

        self::assertCount(1, $statements);
        self::assertStringContainsString("COMMENT 'x; y'", $statements[0]);
    }

    public function testSplitStatementsDropsTrailingInlineComment(): void
    {
        $sql = "ALTER TABLE a ADD x INT; -- note\n";

        self::assertSame(['ALTER TABLE a ADD x INT'], MigrationRunner::splitStatements($sql));
    }

    public function testSplitStatementsHandlesBlockComments(): void
    {
        $sql = '/* entête */ SELECT 1; SELECT 2;';

        self::assertSame(['SELECT 1', 'SELECT 2'], MigrationRunner::splitStatements($sql));
    }

    public function testSplitStatementsKeepsBackslashEscapedQuote(): void
    {
        $sql = "INSERT INTO t (c) VALUES ('a\\';b');";

        self::assertSame(["INSERT INTO t (c) VALUES ('a\\';b')"], MigrationRunner::splitStatements($sql));
    }
}
