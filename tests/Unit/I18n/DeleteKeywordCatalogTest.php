<?php

declare(strict_types=1);

namespace Tests\Unit\I18n;

use App\I18n\Translator;
use PHPUnit\Framework\TestCase;

/**
 * Le mot-clé de confirmation de suppression RGPD est localisé (#73) : chaque
 * catalogue doit fournir `account.delete_keyword`, et les libellés d'instruction
 * doivent l'interpoler (plus de « SUPPRIMER » codé en dur toutes langues).
 */
final class DeleteKeywordCatalogTest extends TestCase
{
    private const LOCALES = ['fr', 'en', 'de', 'nl'];

    private function translationsDir(): string
    {
        return dirname(__DIR__, 3) . '/app/translations';
    }

    public function testEveryCatalogDefinesDeleteKeyword(): void
    {
        foreach (self::LOCALES as $locale) {
            $t       = new Translator($this->translationsDir(), $locale, 'fr');
            $keyword = $t->t('account.delete_keyword');

            self::assertNotSame('account.delete_keyword', $keyword, "Clé manquante pour $locale.");
            self::assertNotSame('', trim($keyword), "Mot-clé vide pour $locale.");
        }
    }

    public function testConfirmLabelInterpolatesKeyword(): void
    {
        foreach (self::LOCALES as $locale) {
            $t       = new Translator($this->translationsDir(), $locale, 'fr');
            $keyword = $t->t('account.delete_keyword');

            foreach (['account.delete_confirm_label', 'account.delete_need_confirm'] as $key) {
                $text = $t->t($key, ['keyword' => $keyword]);
                self::assertStringNotContainsString('{keyword}', $text, "Placeholder non résolu ($locale/$key).");
                self::assertStringContainsString($keyword, $text, "Mot-clé absent ($locale/$key).");
            }
        }
    }
}
