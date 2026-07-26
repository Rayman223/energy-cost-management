<?php

declare(strict_types=1);

namespace Tests\Unit\I18n;

use App\I18n\Translator;
use PHPUnit\Framework\TestCase;

final class TranslatorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/i18ntest_' . uniqid('', true);
        mkdir($this->dir);
        file_put_contents($this->dir . '/fr.php', "<?php return ['greet' => 'Bonjour {name}', 'only_fr' => 'Exclusif', 'dash.a' => 'A fr', 'dash.b' => 'B fr', 'other' => 'Autre'];");
        file_put_contents($this->dir . '/en.php', "<?php return ['greet' => 'Hello {name}', 'dash.a' => 'A en'];");
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/fr.php');
        @unlink($this->dir . '/en.php');
        @rmdir($this->dir);
    }

    public function testTranslatesWithParameterSubstitution(): void
    {
        $translator = new Translator($this->dir, 'en', 'fr');

        self::assertSame('Hello Bob', $translator->t('greet', ['name' => 'Bob']));
    }

    public function testFallsBackToDefaultLocaleForMissingKey(): void
    {
        $translator = new Translator($this->dir, 'en', 'fr');

        self::assertSame('Exclusif', $translator->t('only_fr'));
    }

    public function testReturnsKeyWhenMissingEverywhere(): void
    {
        $translator = new Translator($this->dir, 'en', 'fr');

        self::assertSame('unknown.key', $translator->t('unknown.key'));
    }

    public function testMissingCatalogFileYieldsKey(): void
    {
        $translator = new Translator($this->dir, 'de', 'de');

        self::assertSame('greet', $translator->t('greet'));
    }

    /**
     * Le sous-catalogue exporté vers les scripts clients (#223) doit se replier
     * clé par clé sur la locale par défaut, exactement comme t() : une clé encore
     * non traduite doit sortir en français plutôt que disparaître du bloc JSON —
     * sinon le JS afficherait la clé brute.
     */
    public function testAllWithPrefixFiltersAndFallsBackPerKey(): void
    {
        $translator = new Translator($this->dir, 'en', 'fr');

        self::assertSame(
            ['dash.a' => 'A en', 'dash.b' => 'B fr'],
            $translator->allWithPrefix('dash.'),
        );
    }

    public function testAllWithPrefixIsEmptyForAnUnknownPrefix(): void
    {
        $translator = new Translator($this->dir, 'en', 'fr');

        self::assertSame([], $translator->allWithPrefix('nope.'));
    }
}
