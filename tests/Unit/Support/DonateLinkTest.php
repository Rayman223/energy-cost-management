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
        $config = ['oidc' => ['enabled' => true], 'support' => ['enabled' => true]];
        self::assertStringEndsWith('/support', SupportLink::url($config));
        self::assertStringEndsWith('/support', DonateLink::url($config));
    }

    public function testReturnsNullWhenSupportIsDisabledEvenWhenLegacyDonationUrlExists(): void
    {
        self::assertNull(DonateLink::url([]));
        self::assertNull(DonateLink::url(['support' => ['enabled' => true], 'oidc' => ['enabled' => false]]));
        self::assertNull(DonateLink::url(['support' => ['enabled' => false], 'donate' => ['url' => 'https://old.example'] ]));
    }

    public function testSupportLinkPreservesTheDeploymentPrefix(): void
    {
        $server = $_SERVER;
        try {
            $_SERVER['SCRIPT_NAME'] = '/energy/index.php';
            self::assertSame('/energy/support', SupportLink::url(['oidc' => ['enabled' => true], 'support' => ['enabled' => true]]));
        } finally {
            $_SERVER = $server;
        }
    }
}
