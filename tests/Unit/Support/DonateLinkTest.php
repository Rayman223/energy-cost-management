<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\DonateLink;
use App\Support\SupportLink;
use PHPUnit\Framework\TestCase;

final class DonateLinkTest extends TestCase
{
    public function testReturnsSupportPageOnlyWhenEnabled(): void
    {
        self::assertStringEndsWith('/support', SupportLink::url(['support' => ['enabled' => true]]));
        self::assertStringEndsWith('/support', DonateLink::url(['support' => ['enabled' => true]]));
    }

    public function testReturnsNullWhenSupportIsDisabledEvenWhenLegacyDonationUrlExists(): void
    {
        self::assertNull(DonateLink::url([]));
        self::assertNull(DonateLink::url(['support' => ['enabled' => false], 'donate' => ['url' => 'https://old.example'] ]));
    }
}
