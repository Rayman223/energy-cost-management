<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Domain\User;

interface UserRepositoryInterface
{
    public function findByOidc(string $iss, string $sub): ?User;

    public function findById(int $id): ?User;

    /**
     * Crée le compte (auto-inscription à la 1re connexion) et son profil par défaut.
     */
    public function create(string $iss, string $sub, string $provider, string $displayName): User;

    public function updateDisplayName(int $userId, string $displayName): void;

    public function touchLastLogin(int $userId): void;

    /**
     * @return array{country: ?string, timezone: string, currency: string, bidding_zone: ?string, locale: string}|null
     */
    public function getProfile(int $userId): ?array;
}
