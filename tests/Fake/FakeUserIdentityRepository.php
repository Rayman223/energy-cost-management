<?php

declare(strict_types=1);

namespace Tests\Fake;

use App\Repository\Contract\UserIdentityRepositoryInterface;

final class FakeUserIdentityRepository implements UserIdentityRepositoryInterface
{
    /** @var list<array{id: int, user_id: int, oidc_iss: string, oidc_sub: string, provider: string, created_at: string}> */
    public array $rows = [];

    private int $autoId = 0;

    public function listForUser(int $userId): array
    {
        $out = [];
        foreach ($this->rows as $row) {
            if ($row['user_id'] === $userId) {
                $out[] = [
                    'id'         => $row['id'],
                    'oidc_iss'   => $row['oidc_iss'],
                    'oidc_sub'   => $row['oidc_sub'],
                    'provider'   => $row['provider'],
                    'created_at' => $row['created_at'],
                ];
            }
        }

        return $out;
    }

    public function listForUserForUpdate(int $userId): array
    {
        // En mémoire, aucun verrou à poser : même résultat que listForUser().
        return $this->listForUser($userId);
    }

    public function findUserIdByOidc(string $iss, string $sub): ?int
    {
        foreach ($this->rows as $row) {
            if ($row['oidc_iss'] === $iss && $row['oidc_sub'] === $sub) {
                return $row['user_id'];
            }
        }

        return null;
    }

    public function link(int $userId, string $iss, string $sub, string $provider): void
    {
        if ($this->findUserIdByOidc($iss, $sub) !== null) {
            throw new \RuntimeException('Duplicate identity (uq_user_identities).');
        }
        $this->rows[] = [
            'id'         => ++$this->autoId,
            'user_id'    => $userId,
            'oidc_iss'   => $iss,
            'oidc_sub'   => $sub,
            'provider'   => $provider,
            'created_at' => '2026-07-14 00:00:00',
        ];
    }

    public function countForUser(int $userId): int
    {
        return count($this->listForUser($userId));
    }

    public function delete(int $identityId, int $userId): bool
    {
        foreach ($this->rows as $i => $row) {
            if ($row['id'] === $identityId && $row['user_id'] === $userId) {
                unset($this->rows[$i]);
                $this->rows = array_values($this->rows);

                return true;
            }
        }

        return false;
    }
}
