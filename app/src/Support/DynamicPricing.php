<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Prédicat unique du tarif électricité dynamique, piloté par `config.php`
 * (`dynamic_prices.enabled`). Frère de {@see DiscordLink} : source unique
 * réutilisée par les pages pour n'exposer le tarif dynamique (choix de mode,
 * zone de marché, section dashboard, grisage de la grille) que lorsque la
 * fonctionnalité est réellement activée côté serveur.
 *
 * Neutralisation à la lecture : quand la fonctionnalité est désactivée, l'app
 * se comporte comme si chaque utilisateur était en tarif fixe, sans toucher la
 * base (le `pricing_mode` d'un profil est préservé et ressort si le flag revient).
 */
final class DynamicPricing
{
    /**
     * Vrai uniquement si `dynamic_prices.enabled` vaut strictement `true`.
     * Bloc absent, non-tableau ou `enabled` non-`true` ⇒ désactivé.
     *
     * @param array<string, mixed> $config
     */
    public static function isEnabled(array $config): bool
    {
        $dynamic = $config['dynamic_prices'] ?? [];
        if (!is_array($dynamic)) {
            return false;
        }

        return ($dynamic['enabled'] ?? false) === true;
    }
}
