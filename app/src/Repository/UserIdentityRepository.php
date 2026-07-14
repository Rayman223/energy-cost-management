<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Contract\UserIdentityRepositoryInterface;
use PDO;

/**
 * Identités OpenID Connect rattachées à un compte (Issue #137).
 *
 * Source de vérité pour la recherche `(iss, sub)` : un compte peut porter
 * plusieurs identités (Google + Microsoft…). `users.oidc_iss/oidc_sub` restent
 * l'identité primaire et pointent toujours vers l'une de ces lignes.
 */
final class UserIdentityRepository implements UserIdentityRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Identités liées à un compte, de la plus ancienne à la plus récente.
     *
     * @return list<array{id: int, oidc_iss: string, oidc_sub: string, provider: string, created_at: string}>
     */
    public function listForUser(int $userId): array
    {
        return $this->fetchForUser($userId, false);
    }

    /**
     * Comme {@see self::listForUser()}, mais verrouille les lignes (`FOR UPDATE`)
     * pour sérialiser deux déliaisons concurrentes du même compte (à n'appeler
     * que dans une transaction). Voir {@see \App\Security\IdentityUnlinker}.
     *
     * @return list<array{id: int, oidc_iss: string, oidc_sub: string, provider: string, created_at: string}>
     */
    public function listForUserForUpdate(int $userId): array
    {
        return $this->fetchForUser($userId, true);
    }

    /**
     * @return list<array{id: int, oidc_iss: string, oidc_sub: string, provider: string, created_at: string}>
     */
    private function fetchForUser(int $userId, bool $forUpdate): array
    {
        $sql = 'SELECT id, oidc_iss, oidc_sub, provider, created_at
                FROM user_identities WHERE user_id = :uid ORDER BY created_at ASC, id ASC';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['uid' => $userId]);
        $rows = $stmt->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'         => (int) $row['id'],
                'oidc_iss'   => (string) $row['oidc_iss'],
                'oidc_sub'   => (string) $row['oidc_sub'],
                'provider'   => (string) $row['provider'],
                'created_at' => (string) $row['created_at'],
            ];
        }

        return $out;
    }

    /** Identifiant du compte propriétaire de l'identité `(iss, sub)`, ou null si libre. */
    public function findUserIdByOidc(string $iss, string $sub): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT user_id FROM user_identities WHERE oidc_iss = :iss AND oidc_sub = :sub LIMIT 1'
        );
        $stmt->execute(['iss' => $iss, 'sub' => $sub]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Rattache une identité `(iss, sub)` au compte. Laisse remonter la violation
     * d'unicité (identité déjà rattachée) : l'appelant l'a vérifié en amont.
     */
    public function link(int $userId, string $iss, string $sub, string $provider): void
    {
        $this->pdo->prepare(
            'INSERT INTO user_identities (user_id, oidc_iss, oidc_sub, provider)
             VALUES (:uid, :iss, :sub, :provider)'
        )->execute([
            'uid'      => $userId,
            'iss'      => $iss,
            'sub'      => $sub,
            'provider' => $provider,
        ]);
    }

    /** Nombre d'identités rattachées au compte. */
    public function countForUser(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM user_identities WHERE user_id = :uid');
        $stmt->execute(['uid' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Retire une identité du compte. Renvoie false si l'identité n'existe pas ou
     * n'appartient pas au compte (le filtre user_id empêche de délier chez autrui).
     */
    public function delete(int $identityId, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM user_identities WHERE id = :id AND user_id = :uid'
        );
        $stmt->execute(['id' => $identityId, 'uid' => $userId]);

        return $stmt->rowCount() > 0;
    }
}
