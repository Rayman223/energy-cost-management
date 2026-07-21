<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Domain\User;
use App\Domain\UserProfile;

interface UserRepositoryInterface
{
    public function findByOidc(string $iss, string $sub): ?User;

    public function findById(int $id): ?User;

    /**
     * Crée le compte (auto-inscription à la 1re connexion) et son profil par défaut.
     * Le tout premier compte (l'owner de l'instance) reçoit le rôle « admin » ;
     * les suivants sont créés avec le rôle « user ».
     */
    public function create(string $iss, string $sub, string $provider, string $displayName): User;

    public function updateDisplayName(int $userId, string $displayName): void;

    /**
     * Repointe l'identité primaire du compte (`users.oidc_iss/oidc_sub/provider`)
     * vers une identité liée existante — utilisé à la déliaison de la primaire.
     */
    public function setPrimaryIdentity(int $userId, string $iss, string $sub, string $provider): void;

    public function acceptTermsIfNeeded(int $userId): void;

    public function touchLastLogin(int $userId): void;

    /** Renvoie le profil de préférences de l'utilisateur, ou null s'il n'en a aucun. */
    public function getProfile(int $userId): ?UserProfile;

    /** Persiste le profil de l'utilisateur (crée la ligne si absente). */
    public function updateProfile(int $userId, UserProfile $profile): void;
}
