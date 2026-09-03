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
     * ou inexploitable — règles de validation dans {@see ConfigUrl::httpUrl()}.
     *
     * @param array<string, mixed> $config
     */
    public static function inviteUrl(array $config): ?string
    {
        return ConfigUrl::httpUrl($config, 'discord', 'invite_url');
    }
}
