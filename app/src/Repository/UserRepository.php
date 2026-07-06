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
            // Le premier compte créé est l'owner de l'instance : il reçoit le rôle
            // « admin » (les suivants restent « user »). Le COUNT est dans la même
            // transaction que l'INSERT → atomique et robuste aux trous d'id.
            $countStmt = $this->pdo->query('SELECT COUNT(*) FROM users');
            $isFirstAccount = $countStmt !== false && (int) $countStmt->fetchColumn() === 0;
            $role = $isFirstAccount ? 'admin' : 'user';

            $stmt = $this->pdo->prepare(
                'INSERT INTO users (oidc_iss, oidc_sub, provider, display_name, role)
                 VALUES (:iss, :sub, :provider, :name, :role)'
            );
            $stmt->execute([
                'iss' => $iss,
                'sub' => $sub,
                'provider' => $provider,
                'name' => $displayName,
                'role' => $role,
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

    /** Persiste la langue choisie dans le profil (crée la ligne au besoin). */
    public function setLocale(int $userId, string $locale): void
    {
        $this->pdo->prepare(
            'INSERT INTO user_profiles (user_id, locale) VALUES (:uid, :locale)
             ON DUPLICATE KEY UPDATE locale = VALUES(locale)'
        )->execute(['uid' => $userId, 'locale' => $locale]);
    }

    /** Marque l'acceptation des CGU/confidentialité si ce n'est pas déjà fait. */
    public function acceptTermsIfNeeded(int $userId): void
    {
        $this->pdo->prepare('UPDATE users SET terms_accepted_at = NOW() WHERE id = :id AND terms_accepted_at IS NULL')
            ->execute(['id' => $userId]);
    }

    /**
     * Met à jour le profil de l'utilisateur (crée la ligne si absente).
     */
    public function updateProfile(
        int $userId,
        ?string $country,
        string $timezone,
        string $currency,
        ?string $biddingZone,
        string $locale,
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_profiles (user_id, country, timezone, currency, bidding_zone, locale)
             VALUES (:uid, :country, :tz, :currency, :zone, :locale)
             ON DUPLICATE KEY UPDATE
                country = VALUES(country), timezone = VALUES(timezone),
                currency = VALUES(currency), bidding_zone = VALUES(bidding_zone),
                locale = VALUES(locale)'
        );
        $stmt->execute([
            'uid'      => $userId,
            'country'  => $country,
            'tz'       => $timezone,
            'currency' => $currency,
            'zone'     => $biddingZone,
            'locale'   => $locale,
        ]);
    }

    public function touchLastLogin(int $userId): void
    {
        $this->pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')
            ->execute(['id' => $userId]);
    }

    /**
     * Liste tous les comptes pour l'administration (sans PII sensible).
     *
     * @return list<array{id: int, provider: string, display_name: string,
     *                    role: string, status: string, created_at: string,
     *                    last_login_at: ?string}>
     */
    public function listAll(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, provider, display_name, role, status, created_at, last_login_at
             FROM users ORDER BY created_at ASC, id ASC'
        );
        $rows = $stmt === false ? [] : $stmt->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'            => (int) $row['id'],
                'provider'      => (string) $row['provider'],
                'display_name'  => (string) $row['display_name'],
                'role'          => (string) $row['role'],
                'status'        => (string) $row['status'],
                'created_at'    => (string) $row['created_at'],
                'last_login_at' => $row['last_login_at'] !== null ? (string) $row['last_login_at'] : null,
            ];
        }

        return $out;
    }

    /**
     * Change le rôle d'un compte (« user » ou « admin »). Renvoie false si le
     * rôle est invalide ou si aucune ligne n'a changé.
     */
    public function setRole(int $userId, string $role): bool
    {
        if (!in_array($role, ['user', 'admin'], true)) {
            return false;
        }

        $stmt = $this->pdo->prepare('UPDATE users SET role = :role WHERE id = :id');
        $stmt->execute(['role' => $role, 'id' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Change le statut d'un compte (« active » ou « blocked »). Un compte
     * « blocked » perd l'accès (session refusée, jetons API rejetés). Renvoie
     * false si le statut est invalide ou si aucune ligne n'a changé.
     */
    public function setStatus(int $userId, string $status): bool
    {
        if (!in_array($status, ['active', 'blocked'], true)) {
            return false;
        }

        $stmt = $this->pdo->prepare('UPDATE users SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $userId]);

        return $stmt->rowCount() > 0;
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
