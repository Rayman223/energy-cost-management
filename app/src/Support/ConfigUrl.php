<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Lecture validée d'une URL déclarée dans `config.php`.
 *
 * Implémentation unique du garde-fou partagé par les liens configurables
 * ({@see DiscordLink}, {@see DonateLink}) : une valeur de config finit dans un
 * `href` rendu, elle ne doit donc jamais pouvoir y injecter un schéma
 * `javascript:`. Tout durcissement ultérieur de cette validation (refus des URL
 * protocole-relatives, normalisation IDN, blocage des hôtes internes) s'écrit
 * ici, et une seule fois.
 *
 * Ne couvre que les entrées de config *de type URL* : {@see Adsense} et
 * {@see DynamicPricing} partagent le motif « lire la config, renvoyer un
 * prédicat » mais pas ces règles-là.
 */
final class ConfigUrl
{
    /**
     * Renvoie `$config[$section][$key]` si c'est une URL http(s) exploitable,
     * sinon null.
     *
     * Rejette (→ null) : section absente ou non-tableau ; clé absente ou de
     * valeur non-`string` ; chaîne vide une fois `trim` appliqué ; URL
     * malformée au sens de `FILTER_VALIDATE_URL` ; schéma autre que `http` ou
     * `https`. La valeur renvoyée est celle d'origine, débarrassée de ses
     * espaces de bordure.
     *
     * @param array<string, mixed> $config
     */
    public static function httpUrl(array $config, string $section, string $key): ?string
    {
        $values = $config[$section] ?? [];
        if (!is_array($values)) {
            return null;
        }

        $url = $values[$key] ?? '';
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
