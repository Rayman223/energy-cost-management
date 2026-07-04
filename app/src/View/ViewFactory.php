<?php

declare(strict_types=1);

namespace App\View;

use App\I18n\Formatter;
use App\I18n\Translator;

/**
 * Construit une View configurée (traduction + formatage) pour une locale donnée.
 * Les catalogues sont dans `<app>/translations`, dérivé du dossier de templates.
 */
final class ViewFactory
{
    public static function create(string $templateDir, string $locale, string $defaultLocale = 'fr'): View
    {
        $translationsDir = dirname($templateDir) . '/translations';

        return new View(
            $templateDir,
            new Translator($translationsDir, $locale, $defaultLocale),
            new Formatter($locale),
        );
    }
}
