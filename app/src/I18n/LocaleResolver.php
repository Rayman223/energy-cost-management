<?php

declare(strict_types=1);

namespace App\I18n;

/**
 * Résolveur générique bas-niveau : essaie les candidats dans l'ordre
 * `$userLocale` > `$queryLang` > en-tête Accept-Language > `$default`.
 * L'appelant décide de ce qu'il place dans chaque emplacement — c'est
 * {@see Locale::resolve()} qui compose l'ordre métier (`?lang` > profil >
 * cookie). Seules les locales `available` sont retenues.
 */
final class LocaleResolver
{
    /**
     * @param list<string> $available
     */
    public static function resolve(
        ?string $userLocale,
        ?string $queryLang,
        string $acceptLanguage,
        array $available,
        string $default,
    ): string {
        foreach ([$userLocale, $queryLang] as $candidate) {
            $normalized = self::normalize($candidate);
            if ($normalized !== null && in_array($normalized, $available, true)) {
                return $normalized;
            }
        }

        $fromHeader = self::fromAcceptLanguage($acceptLanguage, $available);
        if ($fromHeader !== null) {
            return $fromHeader;
        }

        return $default;
    }

    private static function normalize(?string $locale): ?string
    {
        if ($locale === null) {
            return null;
        }

        $primary = strtolower(substr(trim($locale), 0, 2));

        return $primary === '' ? null : $primary;
    }

    /**
     * @param list<string> $available
     */
    private static function fromAcceptLanguage(string $acceptLanguage, array $available): ?string
    {
        if ($acceptLanguage === '') {
            return null;
        }

        foreach (explode(',', $acceptLanguage) as $part) {
            $tag = trim(explode(';', $part)[0]);
            $normalized = self::normalize($tag);
            if ($normalized !== null && in_array($normalized, $available, true)) {
                return $normalized;
            }
        }

        return null;
    }
}
