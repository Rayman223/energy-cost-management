<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Security\AccountProvisioner;
use PHPUnit\Framework\TestCase;
use Tests\Fake\FakeUserRepository;

final class AccountProvisionerTest extends TestCase
{
    public function testCreatesAccountOnFirstLogin(): void
    {
        $repo = new FakeUserRepository();
        $user = (new AccountProvisioner($repo))->provision('https://iss', 'sub-1', 'google', 'Bob');

        self::assertSame('Bob', $user->displayName);
        self::assertSame('user', $user->role);
        self::assertCount(1, $repo->users);
        self::assertSame([$user->id], $repo->loginTouches);
        self::assertSame([$user->id], $repo->termsAccepted); // consentement enregistré à l'inscription
    }

    public function testReusesExistingAccountAndRefreshesName(): void
    {
        $repo = new FakeUserRepository();
        $provisioner = new AccountProvisioner($repo);

        $first = $provisioner->provision('https://iss', 'sub-1', 'google', 'Bob');
        $second = $provisioner->provision('https://iss', 'sub-1', 'google', 'Bobby');

        self::assertSame($first->id, $second->id);
        self::assertSame('Bobby', $second->displayName);
        self::assertCount(1, $repo->users);
    }

    public function testDistinctSubjectCreatesDistinctAccount(): void
    {
        $repo = new FakeUserRepository();
        $provisioner = new AccountProvisioner($repo);

        $a = $provisioner->provision('https://iss', 'sub-1', 'google', 'A');
        $b = $provisioner->provision('https://iss', 'sub-2', 'google', 'B');

        self::assertNotSame($a->id, $b->id);
        self::assertCount(2, $repo->users);
    }

    public function testEmptyNameDoesNotOverwriteExisting(): void
    {
        $repo = new FakeUserRepository();
        $provisioner = new AccountProvisioner($repo);

        $provisioner->provision('https://iss', 'sub-1', 'google', 'Bob');
        $again = $provisioner->provision('https://iss', 'sub-1', 'google', '');

        self::assertSame('Bob', $again->displayName);
    }

    public function testRecoversFromConcurrentCreationRace(): void
    {
        $repo = new FakeUserRepository();
        $repo->throwRaceOnCreate = true;

        $user = (new AccountProvisioner($repo))->provision('https://iss', 'sub-1', 'google', 'Bob');

        self::assertSame('sub-1', $user->oidcSub);
        self::assertCount(1, $repo->users);
        self::assertSame([$user->id], $repo->loginTouches);
    }

    public function testRethrowsWhenCreationFailsWithoutRace(): void
    {
        $repo = new FakeUserRepository();
        $repo->failCreate = true;

        $this->expectException(\RuntimeException::class);

        (new AccountProvisioner($repo))->provision('https://iss', 'sub-1', 'google', 'Bob');
    }
}
