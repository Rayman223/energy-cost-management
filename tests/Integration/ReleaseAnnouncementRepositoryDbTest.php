<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\Database;
use App\Infrastructure\MigrationRunner;
use App\Repository\ReleaseAnnouncementRepository;
use PDOException;
use RuntimeException;

final class ReleaseAnnouncementRepositoryDbTest extends DatabaseTestCase
{
    protected function clean(): void
    {
        $this->applyMigration();
        $this->pdo()->exec('DELETE FROM release_announcements');
    }

    public function testMigrationCanBeReplayedWithoutErasingReceipts(): void
    {
        $repository = new ReleaseAnnouncementRepository($this->pdo());
        $repository->markSent(['1.0.1', '1.0.2']);
        $this->applyMigration();
        $this->applyMigration();
        self::assertEqualsCanonicalizing(['1.0.1', '1.0.2'], $repository->sentVersions());
        $row = $this->pdo()->query('SELECT lang, sent_at FROM release_announcements LIMIT 1')->fetch();
        self::assertSame('en', $row['lang']);
        self::assertNotEmpty($row['sent_at']);
    }

    public function testBatchReceiptIsAtomicIfAnInsertFails(): void
    {
        $repository = new ReleaseAnnouncementRepository($this->pdo());
        $repository->markSent(['1.0.1']);
        try {
            $repository->markSent(['1.0.2', '1.0.1']);
            self::fail('The primary key must reject duplicate receipts.');
        } catch (PDOException) {
            self::assertSame(['1.0.1'], $repository->sentVersions());
            self::assertFalse($this->pdo()->inTransaction());
        }
    }

    public function testConcurrentPublisherIsRejectedAndLockIsReleasedAfterFailure(): void
    {
        $owner = new ReleaseAnnouncementRepository($this->pdo());
        $config = \App\Config\ConfigLoader::load(__DIR__ . '/../../app/config/config.php', __DIR__ . '/../../app/config/config.local.php');
        $databaseConfig = $config['database'];
        // Use the same structurally derived disposable database as DatabaseTestCase.
        $databaseConfig['name'] = self::testDatabaseName($databaseConfig['name']);
        $otherDatabase = new Database($databaseConfig);
        $contender = new ReleaseAnnouncementRepository($otherDatabase->pdo());
        $called = false;
        try {
            $owner->withPublishLock(static function () use ($contender, &$called): array {
                try {
                    $contender->withPublishLock(static function () use (&$called): array {
                        $called = true;
                        return [];
                    });
                    self::fail('A competing publisher must not acquire the lock.');
                } catch (RuntimeException $exception) {
                    self::assertStringContainsString('Another release publisher', $exception->getMessage());
                }
                throw new RuntimeException('Simulated failure after lock acquisition.');
            });
            self::fail('Expected callback failure.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Simulated failure', $exception->getMessage());
        }
        self::assertFalse($called);
        self::assertSame(['released' => true], $contender->withPublishLock(static fn (): array => ['released' => true]));
    }

    private function applyMigration(): void
    {
        $sql = file_get_contents(__DIR__ . '/../../app/sql/migrations/2026-10-13_release_announcements.sql');
        self::assertIsString($sql);
        foreach (MigrationRunner::splitStatements($sql) as $statement) {
            $this->pdo()->exec($statement);
        }
    }
}
