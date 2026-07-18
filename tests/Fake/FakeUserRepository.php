<?php

declare(strict_types=1);

namespace Tests\Fake;

use App\Domain\User;
use App\Repository\Contract\UserRepositoryInterface;

final class FakeUserRepository implements UserRepositoryInterface
{
    /** @var array<int, User> */
    public array $users = [];

    /** @var list<int> */
    public array $loginTouches = [];

    /** Simule une création concurrente (compte semé) suivie d'une violation d'unicité. */
    public bool $throwRaceOnCreate = false;

    /** Simule un échec de création sans création effective (vraie erreur). */
    public bool $failCreate = false;

    private int $autoId = 0;

    public function findByOidc(string $iss, string $sub): ?User
    {
        foreach ($this->users as $user) {
            if ($user->oidcIss === $iss && $user->oidcSub === $sub) {
                return $user;
            }
        }

        return null;
    }

    public function findById(int $id): ?User
    {
        return $this->users[$id] ?? null;
    }

    public function create(string $iss, string $sub, string $provider, string $displayName): User
    {
        if ($this->failCreate) {
            throw new \RuntimeException('Échec de création (simulé).');
        }

        $id = ++$this->autoId;
        // Le premier compte (owner) est admin ; les suivants sont de simples users.
        $role = $this->users === [] ? 'admin' : 'user';
        $user = new User($id, $iss, $sub, $provider, $displayName, $role, 'active');
        $this->users[$id] = $user;

        if ($this->throwRaceOnCreate) {
            throw new \RuntimeException('Duplicate entry (course simulée).');
        }

        return $user;
    }

    public function updateDisplayName(int $userId, string $displayName): void
    {
        if (isset($this->users[$userId])) {
            $this->users[$userId] = $this->users[$userId]->withDisplayName($displayName);
        }
    }

    public function setPrimaryIdentity(int $userId, string $iss, string $sub, string $provider): void
    {
        $existing = $this->users[$userId] ?? null;
        if ($existing !== null) {
            $this->users[$userId] = new User(
                $existing->id,
                $iss,
                $sub,
                $provider,
                $existing->displayName,
                $existing->role,
                $existing->status,
            );
        }
    }

    /** @var list<int> */
    public array $termsAccepted = [];

    public function acceptTermsIfNeeded(int $userId): void
    {
        $this->termsAccepted[] = $userId;
    }

    public function touchLastLogin(int $userId): void
    {
        $this->loginTouches[] = $userId;
    }

    public function getProfile(int $userId): ?array
    {
        if (!isset($this->users[$userId])) {
            return null;
        }

        return [
            'country' => null,
            'timezone' => 'Europe/Brussels',
            'currency' => 'EUR',
            'bidding_zone' => null,
            'pricing_mode' => 'fixed',
            'vat_rate' => 21.0,
            'supplier_markup_per_kwh' => 0.0,
            'locale' => 'fr',
        ];
    }
}
