<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\SupportConfig;
use PHPUnit\Framework\TestCase;

final class SupportConfigTest extends TestCase
{
    public function testOnlyHttpsPaymentLinksWithoutEmbeddedCredentialsAreAccepted(): void
    {
        self::assertSame('https://buy.stripe.com/link', SupportConfig::paymentLink([
            'support' => ['stripe_payment_link' => 'https://buy.stripe.com/link'],
        ]));
        self::assertNull(SupportConfig::paymentLink(['support' => ['stripe_payment_link' => 'http://buy.stripe.com/link']]));
        self::assertNull(SupportConfig::paymentLink(['support' => ['stripe_payment_link' => 'https://user:pass@buy.stripe.com/link']]));
    }

    public function testSignedReferenceReplacesAnyExistingReferenceAndKeepsOtherQueryValues(): void
    {
        $url = SupportConfig::paymentLinkForAccount(
            'https://buy.stripe.com/link?client_reference_id=stale&prefilled_email=a%40b.test',
            '42_signature',
        );
        self::assertSame('https://buy.stripe.com/link?client_reference_id=42_signature&prefilled_email=a%40b.test', $url);
    }
}
