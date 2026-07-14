<?php

declare(strict_types=1);

namespace App\Repository\Contract;

/**
 * Identités OpenID Connect rattachées à un compte (Issue #137) : source de
 * vérité de la recherche `(iss, sub)`. Un compte peut en porter plusieurs.
 */
interface UserIdentityRepositoryInterface
{
    /**
     * @return list<array{id: int, oidc_iss: string, oidc_sub: string, provider: string, created_at: string}>
     */
    public function listForUser(int $userId): array;

    /**
     * Comme {@see self::listForUser()}, mais verrouille les lignes (`FOR UPDATE`)
     * pour sérialiser les déliaisons concurrentes — à appeler dans une transaction.
     *
     * @return list<array{id: int, oidc_iss: string, oidc_sub: string, provider: string, created_at: string}>
     */
    public function listForUserForUpdate(int $userId): array;

    /** Identifiant du compte propriétaire de l'identité `(iss, sub)`, ou null si libre. */
    public function findUserIdByOidc(string $iss, string $sub): ?int;

    /** Rattache une identité `(iss, sub)` au compte (laisse remonter la violation d'unicité). */
    public function link(int $userId, string $iss, string $sub, string $provider): void;

    /** Nombre d'identités rattachées au compte. */
    public function countForUser(int $userId): int;

    /** Retire une identité du compte ; false si absente ou non rattachée à ce compte. */
    public function delete(int $identityId, int $userId): bool;
}
