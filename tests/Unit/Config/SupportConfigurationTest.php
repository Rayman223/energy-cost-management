<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Config\ConfigIssue;
use App\Config\ConfigValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SupportConfigurationTest extends TestCase
{
    /** @return array<string, mixed> */
    private function config(): array
    {
        $config = require __DIR__ . '/../../../app/config/config.example.php';
        $config['oidc']['enabled'] = true;
        $config['support'] = [
            'enabled' => true, 'stripe_mode' => 'test',
            'stripe_payment_link' => 'https://buy.stripe.com/test_fixture',
            'stripe_payment_link_id' => 'plink_fixture',
            'stripe_webhook_secret' => 'whsec_fixture_secret',
            'reference_signing_key' => str_repeat('a', 64),
        ];
        return $config;
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidStripeSettings(): iterable
    {
        yield 'invalid mode' => ['stripe_mode', 'livve'];
        yield 'insecure URL' => ['stripe_payment_link', 'http://buy.stripe.com/fixture'];
        yield 'missing link identifier' => ['stripe_payment_link_id', ''];
        yield 'wrong link identifier' => ['stripe_payment_link_id', 'https://buy.stripe.com/fixture'];
        yield 'missing webhook secret' => ['stripe_webhook_secret', ''];
        yield 'wrong webhook secret' => ['stripe_webhook_secret', 'private_fixture_value_never_log'];
        yield 'missing account signing key' => ['reference_signing_key', ''];
    }

    #[DataProvider('invalidStripeSettings')]
    public function testInvalidActiveSettingsAreReportedWithoutExposingValues(string $key, string $value): void
    {
        $config = $this->config();
        $config['support'][$key] = $value;
        $issues = $this->supportIssues($config);
        self::assertContains('support.' . $key, array_column($issues, 'path'));
        foreach ($issues as $issue) {
            self::assertFalse($issue->isError());
            self::assertSame(ConfigIssue::KIND_INVALID_VALUE, $issue->kind);
            self::assertTrue($issue->isRuntimeSignal());
            if ($value !== '') self::assertStringNotContainsString($value, $issue->message);
        }
    }

    public function testWebhookAndAccountSigningKeysMustBeDistinct(): void
    {
        $config = $this->config();
        $config['support']['reference_signing_key'] = $config['support']['stripe_webhook_secret'];
        self::assertContains('support.reference_signing_key', array_column($this->supportIssues($config), 'path'));
    }

    public function testEnabledSupportOnAPrivateInstanceIsReported(): void
    {
        $config = $this->config();
        $config['oidc']['enabled'] = false;
        self::assertContains('support.enabled', array_column($this->supportIssues($config), 'path'));
    }

    public function testDisabledSupportAndGithubOnlySupportNeedNoStripeSecrets(): void
    {
        $config = $this->config();
        $config['support'] = ['enabled' => false, 'stripe_mode' => 'invalid'];
        self::assertSame([], $this->supportIssues($config));
        $config['support'] = ['enabled' => true, 'github_url' => 'https://github.com/sponsors/example'];
        self::assertSame([], $this->supportIssues($config));
        self::assertSame([], $this->supportIssues($this->config()));
    }

    /** @param array<string, mixed> $config @return list<ConfigIssue> */
    private function supportIssues(array $config): array
    {
        return array_values(array_filter(
            ConfigValidator::validate($config, false),
            static fn (ConfigIssue $issue): bool => str_starts_with($issue->path, 'support.'),
        ));
    }
}
