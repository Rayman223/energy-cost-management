<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\User;
use App\Repository\Contract\UserRepositoryInterface;
use PDO;
use RuntimeException;

final class UserRepository implements UserRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findByOidc(string $iss, string $sub): ?User
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, oidc_iss, oidc_sub, provider, display_name, role, status
             FROM users WHERE oidc_iss = :iss AND oidc_sub = :sub LIMIT 1'
        );
        $stmt->execute(['iss' => $iss, 'sub' => $sub]);
        $row = $stmt->fetch();

        return is_array($row) ? self::mapRow($row) : null;
    }

    public function findById(int $id): ?User
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, oidc_iss, oidc_sub, provider, display_name, role, status
             FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? self::mapRow($row) : null;
    }

    public function create(string $iss, string $sub, string $provider, string $displayName): User
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO users (oidc_iss, oidc_sub, provider, display_name)
                 VALUES (:iss, :sub, :provider, :name)'
            );
            $stmt->execute([
                'iss' => $iss,
                'sub' => $sub,
                'provider' => $provider,
                'name' => $displayName,
            ]);

            $id = (int) $this->pdo->lastInsertId();

            $this->pdo->prepare('INSERT INTO user_profiles (user_id) VALUES (:id)')
                ->execute(['id' => $id]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $user = $this->findById($id);
        if ($user === null) {
            throw new RuntimeException('Utilisateur introuvable après création.');
        }

        return $user;
    }

    public function updateDisplayName(int $userId, string $displayName): void
    {
        $this->pdo->prepare('UPDATE users SET display_name = :name WHERE id = :id')
            ->execute(['name' => $displayName, 'id' => $userId]);
    }

    public function touchLastLogin(int $userId): void
    {
        $this->pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')
            ->execute(['id' => $userId]);
    }

    public function getProfile(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT country, timezone, currency, bidding_zone, locale
             FROM user_profiles WHERE user_id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return null;
        }

        return [
            'country' => $row['country'] !== null ? (string) $row['country'] : null,
            'timezone' => (string) $row['timezone'],
            'currency' => (string) $row['currency'],
            'bidding_zone' => $row['bidding_zone'] !== null ? (string) $row['bidding_zone'] : null,
            'locale' => (string) $row['locale'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function mapRow(array $row): User
    {
        return new User(
            (int) $row['id'],
            (string) $row['oidc_iss'],
            (string) $row['oidc_sub'],
            (string) $row['provider'],
            (string) $row['display_name'],
            (string) $row['role'],
            (string) $row['status'],
        );
    }
}
