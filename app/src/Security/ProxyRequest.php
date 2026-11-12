<?php

declare(strict_types=1);

namespace App\Security;

/** SWAG replaces forwarded headers; trust only its explicitly configured IPs. */
final class ProxyRequest
{
    /** @var list<string> */
    private static array $proxies = [];

    /** @param array<string, mixed> $security */
    public static function configure(array $security): void
    {
        self::$proxies = array_values(array_filter(
            is_array($security['trusted_proxies'] ?? null) ? $security['trusted_proxies'] : [],
            static fn (mixed $ip): bool => is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false,
        ));
    }

    public static function isHttps(): bool
    {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }
        return self::fromProxy() && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    public static function clientIp(): ?string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        // A chain is rejected: SWAG must overwrite, never append user input.
        if (self::fromProxy() && filter_var($forwarded, FILTER_VALIDATE_IP) !== false) {
            return $forwarded;
        }
        return filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : null;
    }

    private static function fromProxy(): bool
    {
        $remote = @inet_pton((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($remote === false) {
            return false;
        }
        foreach (self::$proxies as $proxy) {
            if (inet_pton($proxy) === $remote) {
                return true;
            }
        }
        return false;
    }
}
