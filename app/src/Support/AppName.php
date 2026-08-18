<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Nom du produit, affiché dans les logos, titres de page et pieds de page (#280).
 *
 * Constante et non clé de traduction : le nom est le même dans les quatre
 * langues, et le traduire ouvrirait la porte à des variantes divergentes selon
 * le catalogue. Les templates y accèdent via `$this->appName()`
 * ({@see \App\View\View::appName()}), le reste du code par la constante.
 */
final class AppName
{
    public const NAME = 'Energy cost management';
}
