<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Security\IdentityLinker;
use PHPUnit\Framework\TestCase;
use Tests\Fake\FakeUserIdentityRepository;

final class IdentityLinkerTest extends TestCase
{
    public function testLinksFreeIdentityToCurrentAccount(): void
    {
        $repo = new FakeUserIdentityRepository();
        $status = (new IdentityLinker($repo))->link(1, 'https://accounts.google.com', 'sub-A', 'google');

        self::assertSame(IdentityLinker::LINKED, $status);
        self::assertSame(1, $repo->findUserIdByOidc('https://accounts.google.com', 'sub-A'));
        self::assertCount(1, $repo->listForUser(1));
    }

    public function testIdempotentWhenAlreadyLinkedToSameAccount(): void
    {
        $repo = new FakeUserIdentityRepository();
        $repo->link(1, 'https://login.microsoftonline.com/common/v2.0', 'sub-M', 'microsoft');

        $status = (new IdentityLinker($repo))->link(1, 'https://login.microsoftonline.com/common/v2.0', 'sub-M', 'microsoft');

        self::assertSame(IdentityLinker::ALREADY_LINKED_SELF, $status);
        self::assertCount(1, $repo->listForUser(1)); // aucune ligne dupliquée
    }

    public function testRefusesIdentityOwnedByAnotherAccount(): void
    {
        $repo = new FakeUserIdentityRepository();
        $repo->link(2, 'https://accounts.google.com', 'sub-A', 'google'); // appartient au compte 2

        $status = (new IdentityLinker($repo))->link(1, 'https://accounts.google.com', 'sub-A', 'google');

        self::assertSame(IdentityLinker::TAKEN_BY_OTHER, $status);
        self::assertSame(2, $repo->findUserIdByOidc('https://accounts.google.com', 'sub-A')); // inchangé
        self::assertCount(0, $repo->listForUser(1)); // rien rattaché au compte 1
    }
}
