<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Contract\ReleaseAnnouncementRepositoryInterface;
use PDO;
use RuntimeException;
use Throwable;

final class ReleaseAnnouncementRepository implements ReleaseAnnouncementRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function sentVersions(): array
    {
        $stmt = $this->pdo->query("SELECT version FROM release_announcements WHERE lang = 'en'");
        if ($stmt === false) {
            throw new RuntimeException('Cannot read release receipts.');
        }

        return array_map(static fn (mixed $version): string => (string) $version, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function markSent(array $versions): void
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("INSERT INTO release_announcements (version, lang, sent_at) VALUES (:version, 'en', UTC_TIMESTAMP())");
            foreach ($versions as $version) {
                $stmt->execute(['version' => $version]);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function withPublishLock(callable $publish): array
    {
        // Advisory locks are server-wide: include the database to isolate instances.
        $database = $this->pdo->query('SELECT DATABASE()');
        if ($database === false) {
            throw new RuntimeException('Cannot identify the release database.');
        }
        $name = 'energy-release-' . substr(hash('sha256', (string) $database->fetchColumn()), 0, 40);
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(:name, 0)');
        $stmt->execute(['name' => $name]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new RuntimeException('Another release publisher is running. Retry later.');
        }
        try {
            return $publish();
        } finally {
            $this->pdo->prepare('SELECT RELEASE_LOCK(:name)')->execute(['name' => $name]);
        }
    }
}
