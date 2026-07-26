<?php

declare(strict_types=1);

namespace App\I18n;

/**
 * Service de traduction minimal basé sur des catalogues plats (clé => texte)
 * par locale, dans `app/translations/<locale>.php`. Remplace les paramètres
 * `{name}` et retombe sur la locale par défaut puis sur la clé brute.
 */
final class Translator
{
    /** @var array<string, string> */
    private array $messages;

    /** @var array<string, string> */
    private array $fallback;

    public function __construct(string $translationsDir, string $locale, string $fallbackLocale = 'fr')
    {
        $this->messages = self::loadCatalog($translationsDir, $locale);
        $this->fallback = $locale === $fallbackLocale
            ? $this->messages
            : self::loadCatalog($translationsDir, $fallbackLocale);
    }

    /**
     * Substitution en une seule passe, même sémantique que `tr()` dans
     * dashboard.js : une valeur déjà insérée qui contiendrait « {autre} » (un nom
     * de grille tarifaire saisi par l'utilisateur, p. ex.) ne doit pas être
     * resubstituée par le paramètre suivant. Un `{name}` sans paramètre
     * correspondant est laissé intact.
     *
     * @param array<string, string|int|float> $params
     */
    public function t(string $key, array $params = []): string
    {
        $message = $this->messages[$key] ?? $this->fallback[$key] ?? $key;
        if ($params === []) {
            return $message;
        }

        return (string) preg_replace_callback(
            '/\{(\w+)\}/',
            static fn (array $m): string => array_key_exists($m[1], $params) ? (string) $params[$m[1]] : $m[0],
            $message,
        );
    }

    /**
     * Sous-catalogue de toutes les clés commençant par `$prefix`, avec repli clé
     * par clé sur la locale par défaut (même sémantique que t()).
     *
     * Destiné aux scripts qui rendent du contenu côté client : le template
     * sérialise le sous-catalogue dans son data block JSON plutôt que d'énumérer
     * les clés une à une, ce qui rendrait une désynchronisation possible.
     *
     * @return array<string, string>
     */
    public function allWithPrefix(string $prefix): array
    {
        $filter = static function (array $messages) use ($prefix): array {
            return array_filter(
                $messages,
                static fn (string $key): bool => str_starts_with($key, $prefix),
                ARRAY_FILTER_USE_KEY,
            );
        };

        return array_merge($filter($this->fallback), $filter($this->messages));
    }

    /**
     * @return array<string, string>
     */
    private static function loadCatalog(string $translationsDir, string $locale): array
    {
        $file = rtrim($translationsDir, '/') . '/' . $locale . '.php';
        if (!is_file($file)) {
            return [];
        }

        $data = require $file;
        if (!is_array($data)) {
            return [];
        }

        $messages = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $messages[$key] = $value;
            }
        }

        return $messages;
    }
}
