<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Lien d'invitation vers le serveur Discord, configurable dans `config.php`
 * (`discord.invite_url`). Source unique du prédicat, réutilisée par les pages
 * pour n'afficher le lien que lorsqu'une URL exploitable est configurée.
 */
final class DiscordLink
{
    /**
     * Renvoie l'URL d'invitation configurée, ou null si elle est absente, vide
     * ou inexploitable. Seuls `http`/`https` sont acceptés : la config ne doit
     * pas pouvoir injecter un `href` de schéma `javascript:` dans les pages.
     *
     * @param array<string, mixed> $config
     */
    public static function inviteUrl(array $config): ?string
    {
        $discord = $config['discord'] ?? [];
        if (!is_array($discord)) {
            return null;
        }

        $url = $discord['invite_url'] ?? '';
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
