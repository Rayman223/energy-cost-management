<?php

declare(strict_types=1);

namespace App\Security;

use App\Domain\User;
use App\Repository\Contract\UserRepositoryInterface;

/**
 * Auto-inscription ouverte : à la 1re connexion OIDC, crée le compte ; sinon le
 * réutilise et rafraîchit le nom d'affichage. Met à jour la dernière connexion.
 */
final class AccountProvisioner
{
    public function __construct(private readonly UserRepositoryInterface $users)
    {
    }

    public function provision(string $iss, string $sub, string $provider, string $displayName): User
    {
        $user = $this->users->findByOidc($iss, $sub);

        if ($user === null) {
            try {
                $user = $this->users->create($iss, $sub, $provider, $displayName);
            } catch (\Throwable $e) {
                // Course possible : un autre process a créé ce compte entre le
                // findByOidc() et le create() (violation d'unicité). On relit.
                $user = $this->users->findByOidc($iss, $sub);
                if ($user === null) {
                    throw $e;
                }
            }
        } elseif ($displayName !== '' && $displayName !== $user->displayName) {
            $this->users->updateDisplayName($user->id, $displayName);
            $user = $user->withDisplayName($displayName);
        }

        $this->users->touchLastLogin($user->id);

        return $user;
    }
}
