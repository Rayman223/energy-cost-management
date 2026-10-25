<?php

declare(strict_types=1);

namespace Tests\Unit\View;

use App\Seo\PageMeta;
use App\View\ViewFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SupportPageTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function locales(): iterable
    {
        foreach (['fr', 'en', 'nl', 'de'] as $locale) yield $locale => [$locale];
    }

    #[DataProvider('locales')]
    public function testPageRendersTranslatedEscapedContentAndAnExplicitConsentForm(string $locale): void
    {
        $html = $this->render($locale, [
            'member' => ['badge' => true, 'public' => false],
            'supporters' => [['name' => '<script>Private name</script>']],
        ]);
        self::assertStringContainsString('<html lang="' . $locale . '">', $html);
        self::assertStringNotContainsString('support.title', $html);
        self::assertStringNotContainsString('support.wall_consent', $html);
        self::assertStringContainsString('&lt;script&gt;Private name&lt;/script&gt;', $html);
        self::assertStringContainsString('name="_csrf" value="test-token"', $html);
        self::assertStringContainsString('name="public_opt_in" value="1">', $html);
        self::assertStringContainsString('<progress ', $html);
        self::assertStringContainsString('assets/js/header.js', $html);
        self::assertStringNotContainsString('name="admin_user_id"', $html);
    }

    public function testNoGoalAndDatabaseFailureNeverRenderAFalseCollectedAmount(): void
    {
        self::assertStringNotContainsString('<progress ', $this->render('fr', ['goal' => 0]));
        $html = $this->render('fr', ['dbAvailable' => false]);
        self::assertStringNotContainsString('<progress ', $html);
        self::assertStringNotContainsString('support-numbers', $html);
        self::assertStringContainsString('role="status"', $html);
    }

    /** @param array<string, mixed> $overrides */
    private function render(string $locale, array $overrides = []): string
    {
        $config = ['seo' => ['base_url' => 'https://example.test'], 'i18n' => ['available' => ['fr', 'en', 'nl', 'de']]];
        return ViewFactory::create(__DIR__ . '/../../../app/templates', $locale)->render('support', array_replace([
            'title' => 'Support', 'available' => ['fr', 'en', 'nl', 'de'],
            'discordUrl' => null, 'donateUrl' => '/support',
            'meta' => PageMeta::indexable($config, 'support', $locale, 'Support'),
            'raised' => 12345, 'goal' => 20000, 'stripeCents' => 12345,
            'githubCents' => 0, 'year' => 2026, 'supporters' => [], 'dbAvailable' => true,
            'member' => ['badge' => false, 'public' => false], 'loggedIn' => false, 'isAdmin' => false,
            'stripeUrl' => 'https://buy.stripe.com/test_example', 'githubUrl' => 'https://github.com/sponsors/example',
            'csrfField' => '<input type="hidden" name="_csrf" value="test-token">',
        ], $overrides));
    }
}
