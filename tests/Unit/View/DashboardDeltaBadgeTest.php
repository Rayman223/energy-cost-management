<?php

declare(strict_types=1);

namespace Tests\Unit\View;

use App\I18n\Formatter;
use App\Service\CostCalculationService;
use App\Service\DashboardCardsService;
use App\Service\TariffCalculatorService;
use App\View\ViewFactory;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fake\FakeGasReadingRepository;
use Tests\Fake\FakeLegacyDailyRepository;
use Tests\Fake\FakeTariffRepository;

final class DashboardDeltaBadgeTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function locales(): iterable
    {
        foreach (['fr', 'en', 'de', 'nl'] as $locale) {
            yield $locale => [$locale];
        }
    }

    #[DataProvider('locales')]
    public function testBadgeDisplaysBoundedAndOrdinaryVariations(string $locale): void
    {
        $formatter = new Formatter($locale);
        $cases = [
            // Courant, précédent, card, valeur attendue, couleur.
            [250.0, 0.001, 'import_t1', '▲ &gt; +999 %', 'bad'],
            [250.0, 0.001, 'export_t1', '▲ &gt; +999 %', 'good'],
            [-899.1, 100.0, 'import_t1', '▼ &lt; -999 %', 'good'],
            [-899.1, 100.0, 'export_t1', '▼ &lt; -999 %', 'bad'],
            [1099.0, 100.0, 'import_t1', '▲ +' . $formatter->number(999.0, 1) . ' %', 'bad'],
            [1099.1, 100.0, 'import_t1', '▲ &gt; +999 %', 'bad'],
            [125.0, 100.0, 'import_t1', '▲ +' . $formatter->number(25.0, 1) . ' %', 'bad'],
            [50.0, 100.0, 'import_t1', '▼ ' . $formatter->number(-50.0, 1) . ' %', 'good'],
            [100.0, 100.0, 'import_t1', '= ' . $formatter->number(0.0, 1) . ' %', 'flat'],
        ];

        foreach ($cases as [$current, $previous, $key, $expectedValue, $tone]) {
            $html = $this->dashboard($locale, $current, $previous, $key);

            self::assertStringContainsString(
                '<div class="card-delta card-delta--' . $tone . '"><span class="card-delta-val">'
                    . $expectedValue . '</span> <span class="card-delta-ref">',
                $html,
            );
            $catalog = require dirname(__DIR__, 3) . '/app/translations/' . $locale . '.php';
            self::assertStringContainsString(htmlspecialchars($catalog['dash.card.vs_prev_period'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $html);
            self::assertSame(1, substr_count($html, 'class="card-delta-val"'));
            self::assertStringNotContainsString($formatter->number(24999900.0, 1) . ' %', $html);
        }
    }

    #[DataProvider('locales')]
    public function testNewAndUnavailableReferencesKeepTheirExistingBadges(string $locale): void
    {
        $html = $this->dashboard($locale, 250.0, 0.0);
        $catalog = require dirname(__DIR__, 3) . '/app/translations/' . $locale . '.php';

        self::assertStringContainsString(
            '<div class="card-delta card-delta--bad"><span class="card-delta-val">▲</span> '
                . '<span class="card-delta-ref">'
                . htmlspecialchars($catalog['dash.card.new'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>',
            $html,
        );

        foreach ([[250.0, null], [null, 100.0], [0.0, 0.0]] as [$current, $previous]) {
            self::assertStringNotContainsString('class="card-delta ', $this->dashboard($locale, $current, $previous));
        }
    }

    private function dashboard(string $locale, ?float $current, ?float $previous, string $key = 'import_t1'): string
    {
        $deltaKey = $key === 'import_t1' ? 'prelev_jour' : 'injec_jour';
        $legacy = new FakeLegacyDailyRepository(deltasBetween: [$deltaKey => $previous]);
        $cost = new CostCalculationService(
            legacyRepo: $legacy,
            tariffRepo: new FakeTariffRepository(grid: null),
            gasRepo: new FakeGasReadingRepository(),
            calculator: new TariffCalculatorService(),
        );
        $cards = (new DashboardCardsService($legacy, $cost))->build(
            [$deltaKey => $current, 'to' => '2026-07-01 00:00:00'],
            2026,
            6,
            new DateTimeImmutable('2026-07-01 00:00:00', new DateTimeZone('UTC')),
        );

        return ViewFactory::create(dirname(__DIR__, 3) . '/app/templates', $locale)->render('dashboard', [
            'cards'          => array_values(array_filter($cards, static fn (array $card): bool => $card['key'] === $key)),
            'dbError'        => null,
            'deltas'         => null,
            'available'      => ['fr', 'en', 'de', 'nl'],
            'initYear'       => 2026,
            'initMonth'      => 6,
            'gasInitYear'    => 2026,
            'gasInitMonth'   => 6,
            'waterInitYear'  => 2026,
            'waterInitMonth' => 6,
        ]);
    }
}
