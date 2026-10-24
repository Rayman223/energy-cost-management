<?php

declare(strict_types=1);

namespace App\Support;

/** Configuration active du financement volontaire, désactivé par défaut. */
final class SupportConfig
{
    /** @param array<string, mixed> $config */
    public static function enabled(array $config): bool
    {
        return ($config['support']['enabled'] ?? false) === true;
    }

    /** @param array<string, mixed> $config */
    public static function paymentLink(array $config): ?string
    {
        $url = ConfigUrl::httpUrl($config, 'support', 'stripe_payment_link');
        $parts = $url !== null ? parse_url($url) : false;
        return $url !== null && is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && !isset($parts['user']) && !isset($parts['pass']) ? $url : null;
    }

    public static function paymentLinkForAccount(string $url, string $reference): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) return $url;
        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        $query['client_reference_id'] = $reference;
        $host = (string) ($parts['scheme'] ?? 'https') . '://' . (string) ($parts['host'] ?? '');
        if (isset($parts['port'])) $host .= ':' . $parts['port'];
        $path = (string) ($parts['path'] ?? '');
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
        return $host . $path . '?' . http_build_query($query) . $fragment;
    }

    /** @param array<string, mixed> $config */
    public static function githubUrl(array $config): ?string
    {
        return ConfigUrl::httpUrl($config, 'support', 'github_url');
    }
}
