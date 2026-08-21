<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\UserIdentityRepository;
use App\Repository\UserRepository;

/**
 * Identités OIDC multiples par compte (#137) contre une vraie base :
 * provisioning (identité primaire semée), liaison, résolution via JOIN,
 * déliaison et promotion de la primaire. S'auto-skippe sans base de test joignable.
 */
final class UserIdentityRepositoryDbTest extends DatabaseTestCase
{
    protected function clean(): void
    {
        // users est supprimée en dernier : la FK ON DELETE CASCADE purge
        // user_identities/user_profiles, mais on les liste pour rester explicite.
        foreach (['user_identities', 'user_profiles', 'users'] as $table) {
            $this->pdo()->exec('DELETE FROM ' . $table);
        }
    }

    public function testCreateSeedsPrimaryIdentity(): void
    {
        $users = new UserRepository($this->pdo());
        $identities = new UserIdentityRepository($this->pdo());

        $user = $users->create('https://accounts.google.com', 'sub-G', 'google', 'Alice');

        $rows = $identities->listForUser($user->id);
        self::assertCount(1, $rows);
        self::assertSame('https://accounts.google.com', $rows[0]['oidc_iss']);
        self::assertSame('sub-G', $rows[0]['oidc_sub']);
        self::assertSame('google', $rows[0]['provider']);
        // La résolution de connexion passe par user_identities.
        self::assertSame($user->id, $users->findByOidc('https://accounts.google.com', 'sub-G')?->id);
    }

    public function testLinkSecondIdentityResolvesToSameAccount(): void
    {
        $users = new UserRepository($this->pdo());
        $identities = new UserIdentityRepository($this->pdo());

        $user = $users->create('https://accounts.google.com', 'sub-G', 'google', 'Alice');
        $identities->link($user->id, 'https://login.microsoftonline.com/common/v2.0', 'sub-M', 'microsoft');

        self::assertSame(2, $identities->countForUser($user->id));
        // Connexion via l'un OU l'autre fournisseur → même compte.
        self::assertSame($user->id, $users->findByOidc('https://accounts.google.com', 'sub-G')?->id);
        self::assertSame($user->id, $users->findByOidc('https://login.microsoftonline.com/common/v2.0', 'sub-M')?->id);
    }

    public function testUnlinkPrimaryPromotesRemainingIdentity(): void
    {
        $users = new UserRepository($this->pdo());
        $identities = new UserIdentityRepository($this->pdo());

        $user = $users->create('https://accounts.google.com', 'sub-G', 'google', 'Alice');
        $identities->link($user->id, 'https://login.microsoftonline.com/common/v2.0', 'sub-M', 'microsoft');

        // Retrait de l'identité primaire (google) puis promotion de microsoft.
        $primaryRow = null;
        foreach ($identities->listForUser($user->id) as $row) {
            if ($row['oidc_sub'] === 'sub-G') {
                $primaryRow = $row;
            }
        }
        self::assertNotNull($primaryRow);
        self::assertTrue($identities->delete($primaryRow['id'], $user->id));

        $remaining = $identities->listForUser($user->id);
        self::assertCount(1, $remaining);
        $users->setPrimaryIdentity($user->id, $remaining[0]['oidc_iss'], $remaining[0]['oidc_sub'], $remaining[0]['provider']);

        // users pointe désormais vers microsoft ; l'ancienne identité google est libre.
        $refreshed = $users->findById($user->id);
        self::assertSame('sub-M', $refreshed?->oidcSub);
        self::assertSame('microsoft', $refreshed?->provider);
        self::assertNull($identities->findUserIdByOidc('https://accounts.google.com', 'sub-G'));

        // google/sub-G réutilisable pour un nouveau compte, sans collision uq_users_oidc.
        $other = $users->create('https://accounts.google.com', 'sub-G', 'google', 'Bob');
        self::assertNotSame($user->id, $other->id);
    }

    public function testFindByOidcFallsBackToLegacyPrimaryColumns(): void
    {
        // Simule un déploiement où le backfill #137 n'a pas encore tourné : la
        // ligne user_identities est absente mais users.oidc_iss/sub existe.
        $users = new UserRepository($this->pdo());
        $user = $users->create('https://accounts.google.com', 'sub-legacy', 'google', 'Alice');
        $this->pdo()->exec('DELETE FROM user_identities WHERE user_id = ' . $user->id);

        // Sans filet, le JOIN ne trouverait rien → l'utilisateur ne pourrait plus
        // se connecter. Le repli sur les colonnes primaires le retrouve.
        $found = $users->findByOidc('https://accounts.google.com', 'sub-legacy');
        self::assertSame($user->id, $found?->id);
    }

    public function testDeleteScopedToOwningAccount(): void
    {
        $users = new UserRepository($this->pdo());
        $identities = new UserIdentityRepository($this->pdo());

        $a = $users->create('https://accounts.google.com', 'sub-A', 'google', 'Alice');
        $b = $users->create('https://accounts.google.com', 'sub-B', 'google', 'Bob');

        $bRow = $identities->listForUser($b->id)[0];
        // Le compte A ne peut pas délier l'identité de B.
        self::assertFalse($identities->delete($bRow['id'], $a->id));
        self::assertSame(1, $identities->countForUser($b->id));
    }
}
