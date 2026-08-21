<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\UserProfile;
use App\Repository\UserRepository;

/**
 * Test d'intégration de UserRepository contre une vraie base MySQL/MariaDB.
 *
 * S'AUTO-SKIPPE sans base de test joignable.
 */
final class UserRepositoryDbTest extends DatabaseTestCase
{
    /**
     * Nettoyage direct (pas d'isolation par transaction : UserRepository::create()
     * ouvre la sienne).
     */
    protected function clean(): void
    {
        $this->pdo()->exec('DELETE FROM user_profiles');
        $this->pdo()->exec('DELETE FROM users');
    }

    public function testCreateFindAndProfileDefaults(): void
    {
        $repo = new UserRepository($this->pdo());

        self::assertNull($repo->findByOidc('https://iss.example', 'sub-1'));

        $user = $repo->create('https://iss.example', 'sub-1', 'example', 'Bob');
        self::assertGreaterThan(0, $user->id);
        self::assertSame('Bob', $user->displayName);
        self::assertSame('admin', $user->role); // 1er compte (owner) → admin
        self::assertTrue($user->isActive());

        $found = $repo->findByOidc('https://iss.example', 'sub-1');
        self::assertNotNull($found);
        self::assertSame($user->id, $found->id);

        $profile = $repo->getProfile($user->id);
        self::assertNotNull($profile);
        self::assertSame('EUR', $profile->currency);
        self::assertSame('fr', $profile->locale);
        self::assertSame('UTC', $profile->timezone);
        // Défaut de la marge fournisseur par utilisateur (#153) ; la TVA a quitté le
        // profil pour la grille tarifaire (#232).
        self::assertSame(0.0, $profile->supplierMarkupPerKwh);
    }

    public function testUpdateProfilePersistsMarkup(): void
    {
        $repo = new UserRepository($this->pdo());
        $user = $repo->create('https://iss.example', 'sub-vat', 'example', 'Vera');

        // Valeurs volontairement TOUTES distinctes des défauts (et de longueurs
        // différentes pour country/currency) : garantit que l'aller-retour DTO
        // mappe chaque colonne au bon champ — une inversion d'arguments dans
        // getProfile/updateProfile ou un retour systématique aux défauts échouerait.
        $repo->updateProfile($user->id, new UserProfile(
            country: 'FR',
            timezone: 'Europe/Paris',
            currency: 'SEK',
            biddingZone: '10YFR-RTE------C',
            supplierMarkupPerKwh: 0.0123456,
            locale: 'en',
        ));

        $profile = $repo->getProfile($user->id);
        self::assertNotNull($profile);
        self::assertSame('FR', $profile->country);
        self::assertSame('Europe/Paris', $profile->timezone);
        self::assertSame('SEK', $profile->currency);
        self::assertSame('10YFR-RTE------C', $profile->biddingZone);
        self::assertSame('en', $profile->locale);
        self::assertEqualsWithDelta(0.0123456, $profile->supplierMarkupPerKwh, 0.0000001);

        // supplier_markup ∈ [-1,1] borné côté repository.
        $repo->updateProfile($user->id, new UserProfile(
            country: 'FR',
            timezone: 'Europe/Paris',
            currency: 'EUR',
            biddingZone: null,
            supplierMarkupPerKwh: 5.0,
            locale: 'fr',
        ));
        $clamped = $repo->getProfile($user->id);
        self::assertNotNull($clamped);
        self::assertSame(1.0, $clamped->supplierMarkupPerKwh);

        $repo->updateProfile($user->id, new UserProfile(
            country: 'FR',
            timezone: 'Europe/Paris',
            currency: 'EUR',
            biddingZone: null,
            supplierMarkupPerKwh: -5.0,
            locale: 'fr',
        ));
        $clampedLow = $repo->getProfile($user->id);
        self::assertNotNull($clampedLow);
        self::assertSame(-1.0, $clampedLow->supplierMarkupPerKwh);
    }

    public function testUpdateDisplayName(): void
    {
        $repo = new UserRepository($this->pdo());

        $user = $repo->create('https://iss.example', 'sub-2', 'example', 'Bob');
        $repo->updateDisplayName($user->id, 'Bobby');

        $reloaded = $repo->findById($user->id);
        self::assertNotNull($reloaded);
        self::assertSame('Bobby', $reloaded->displayName);
    }

    public function testListAllReturnsCreatedAccounts(): void
    {
        $repo = new UserRepository($this->pdo());

        self::assertSame([], $repo->listAll());

        $a = $repo->create('https://iss.example', 'sub-a', 'example', 'Alice');
        $b = $repo->create('https://iss.example', 'sub-b', 'example', 'Bob');

        $all = $repo->listAll();
        self::assertCount(2, $all);
        $ids = array_column($all, 'id');
        self::assertContains($a->id, $ids);
        self::assertContains($b->id, $ids);
        // Ordonné par created_at ASC : Alice (1re, owner) est admin ; Bob reste user.
        self::assertSame('admin', $all[0]['role']);
        self::assertSame('user', $all[1]['role']);
        self::assertSame('active', $all[0]['status']);
    }

    public function testSetRoleAndStatus(): void
    {
        $repo = new UserRepository($this->pdo());
        // Le 1er compte est promu admin d'office ; on teste setRole sur un 2e
        // compte (rôle « user »), sinon setRole('admin') serait un no-op (rowCount 0).
        $repo->create('https://iss.example', 'sub-owner', 'example', 'Owner');
        $user = $repo->create('https://iss.example', 'sub-c', 'example', 'Carol');
        self::assertSame('user', $user->role);

        self::assertTrue($repo->setRole($user->id, 'admin'));
        self::assertTrue($repo->findById($user->id)?->isAdmin());

        self::assertTrue($repo->setStatus($user->id, 'blocked'));
        self::assertFalse($repo->findById($user->id)?->isActive());

        // Valeurs invalides rejetées, sans lever d'exception.
        self::assertFalse($repo->setRole($user->id, 'superuser'));
        self::assertFalse($repo->setStatus($user->id, 'paused'));

        // Compte inconnu : aucune ligne affectée.
        self::assertFalse($repo->setRole(999999, 'admin'));
    }

    /**
     * Période du bilan d'acomptes (#241) : mémorisée par une écriture CIBLÉE, qui
     * ne doit toucher qu'elle — et survivre à un enregistrement du profil depuis
     * /account, lequel ne connaît pas cette préférence.
     */
    public function testAdvancesPeriodIsStoredAndSurvivesAProfileUpdate(): void
    {
        $repo = new UserRepository($this->pdo());
        $user = $repo->create('https://iss.example', 'sub-advances', 'example', 'Ada');

        self::assertNull($repo->getProfile($user->id)?->advancesPeriodFrom);

        $repo->setAdvancesPeriod($user->id, '2026-01-01', '2026-07-01');

        $profile = $repo->getProfile($user->id);
        self::assertNotNull($profile);
        self::assertSame('2026-01-01', $profile->advancesPeriodFrom);
        self::assertSame('2026-07-01', $profile->advancesPeriodTo);

        // Enregistrement du profil « classique » : les dates ne sont pas dans son
        // formulaire, elles ne doivent pas être remises à NULL au passage.
        $repo->updateProfile($user->id, new UserProfile(
            country: 'BE',
            timezone: 'Europe/Brussels',
            currency: 'EUR',
            biddingZone: null,
            supplierMarkupPerKwh: 0.0,
            locale: 'fr',
        ));

        $after = $repo->getProfile($user->id);
        self::assertSame('2026-01-01', $after?->advancesPeriodFrom);
        self::assertSame('2026-07-01', $after?->advancesPeriodTo);

        // Nouvelle période choisie : elle remplace la précédente.
        $repo->setAdvancesPeriod($user->id, '2025-06-01', '2026-06-01');
        self::assertSame('2025-06-01', $repo->getProfile($user->id)?->advancesPeriodFrom);
    }
}
