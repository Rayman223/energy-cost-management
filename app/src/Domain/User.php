<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Compte utilisateur identifié par son couple OpenID Connect (issuer + subject).
 * Aucun mot de passe ni e-mail n'est conservé.
 */
final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $oidcIss,
        public readonly string $oidcSub,
        public readonly string $provider,
        public readonly string $displayName,
        public readonly string $role,
        public readonly string $status,
    ) {
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function withDisplayName(string $displayName): self
    {
        return new self(
            $this->id,
            $this->oidcIss,
            $this->oidcSub,
            $this->provider,
            $displayName,
            $this->role,
            $this->status,
        );
    }
}
