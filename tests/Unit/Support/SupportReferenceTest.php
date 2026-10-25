<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\SupportReference;
use PHPUnit\Framework\TestCase;

final class SupportReferenceTest extends TestCase
{
    public function testGeneratedReferencesFollowStripeCharacterAndLengthRestrictions(): void
    {
        foreach ([1, 42, PHP_INT_MAX] as $userId) {
            $reference = SupportReference::forUser($userId, 'instance_secret');
            self::assertNotNull($reference);
            // Stripe silently drops anything outside this alphabet or above 200 characters.
            self::assertMatchesRegularExpression('/^[a-zA-Z0-9_-]{1,200}$/D', $reference);
            self::assertSame($userId, SupportReference::userId($reference, 'instance_secret'));
        }
    }

    public function testReferencesCannotBeReassignedToAnotherAccountOrAnotherInstance(): void
    {
        $reference = SupportReference::forUser(42, 'instance_secret');
        self::assertNotNull($reference);
        self::assertNull(SupportReference::userId(str_replace('42_', '43_', $reference), 'instance_secret'));
        self::assertNull(SupportReference::userId($reference, 'another_instance_secret'));
        self::assertNull(SupportReference::userId($reference, ''));
    }

    public function testMissingInvalidAndOverflowingAccountIdsAreRejected(): void
    {
        self::assertNull(SupportReference::forUser(0, 'key'));
        self::assertNull(SupportReference::forUser(42, ''));
        foreach (['', '42.signature', '0', '042', (string) PHP_INT_MAX . '0'] as $id) {
            self::assertNull(SupportReference::userId($id . '_' . hash_hmac('sha256', $id, 'key'), 'key'));
        }
        self::assertNull(SupportReference::userId('42_' . str_repeat('0', 64), 'key'));
        self::assertNull(SupportReference::userId(str_repeat('1', 201), 'key'));
    }
}
