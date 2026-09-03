<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Lien de soutien financier au projet (#3), configurable dans `config.php`
 * (`donate.url`). Frère de {@see DiscordLink} : source unique du prédicat,
 * réutilisée par les pages pour n'afficher l'icône que lorsqu'une URL
 * exploitable est configurée.
 *
 * Contrairement à {@see \App\Support\AppName} ou au partiel `github-link`, l'URL
 * n'est pas en dur : l'application est auto-hébergeable, et une instance tierce
 * ne doit pas afficher la cagnotte du mainteneur amont. Vide (le défaut) ⇒ rien
 * n'est rendu.
 */
final class DonateLink
{
    /**
     * Renvoie l'URL de don configurée, ou null si elle est absente, vide ou
     * inexploitable. Seuls `http`/`https` sont acceptés : la config ne doit pas
     * pouvoir injecter un `href` de schéma `javascript:` dans les pages.
     *
     * @param array<string, mixed> $config
     */
    public static function url(array $config): ?string
    {
        $donate = $config['donate'] ?? [];
        if (!is_array($donate)) {
            return null;
        }

        $url = $donate['url'] ?? '';
        if (!is_string($url)) {
            return null;
        }

        $url = trim($url);
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        return $url;
    }
}
