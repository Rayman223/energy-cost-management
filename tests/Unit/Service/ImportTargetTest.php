<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Service\Import\ImportTarget;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ImportTargetTest extends TestCase
{
    public function testSelfImportAllowedForAnyone(): void
    {
        self::assertSame(7, ImportTarget::resolve(7, false, null));
        self::assertSame(7, ImportTarget::resolve(7, false, 7));
    }

    public function testAdminCanTargetAnotherUser(): void
    {
        self::assertSame(9, ImportTarget::resolve(7, true, 9));
    }

    public function testNonAdminCannotTargetAnotherUser(): void
    {
        $this->expectException(RuntimeException::class);
        ImportTarget::resolve(7, false, 9);
    }
}
