<?php

declare(strict_types=1);

namespace App\Security;

final class WebAccessGuard
{
    public static function protect(array $security, bool $jsonResponse = false): void
    {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            return;
        }

        $enabled = (bool) ($security['enabled'] ?? false);
        if ($enabled === false) {
            return;
        }

        $lang = self::resolveLanguage();

        if (self::isIpDenied($security) === true) {
            self::deny(403, self::message($lang, 'forbidden'), $jsonResponse);
        }

        $basicEnabled = (bool) ($security['basic_auth']['enabled'] ?? false);
        if ($basicEnabled === false) {
            return;
        }

        $expectedUser = (string) ($security['basic_auth']['username'] ?? '');
        $expectedPass = (string) ($security['basic_auth']['password'] ?? '');

        if ($expectedUser === '' || $expectedPass === '') {
            self::deny(500, self::message($lang, 'invalid_config'), $jsonResponse);
        }

        $user = isset($_SERVER['PHP_AUTH_USER']) ? (string) $_SERVER['PHP_AUTH_USER'] : '';
        $pass = isset($_SERVER['PHP_AUTH_PW']) ? (string) $_SERVER['PHP_AUTH_PW'] : '';

        $validUser = hash_equals($expectedUser, $user);
        $validPass = hash_equals($expectedPass, $pass);

        if ($validUser && $validPass) {
            return;
        }

        $realm = $lang === 'fr' ? 'Accès sécurisé Manage Energy' : 'Manage Energy secure access';
        header('WWW-Authenticate: Basic realm="' . addslashes($realm) . '", charset="UTF-8"');
        self::deny(401, self::message($lang, 'auth_required'), $jsonResponse);
    }

    private static function deny(int $status, string $message, bool $jsonResponse): void
    {
        http_response_code($status);

        if ($jsonResponse === true) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
        exit;
    }

    private static function resolveLanguage(): string
    {
        $lang = isset($_GET['lang']) ? strtolower((string) $_GET['lang']) : '';
        if ($lang === 'fr' || $lang === 'en') {
            return $lang;
        }

        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
        if (str_starts_with($accept, 'fr') || str_contains($accept, ',fr')) {
            return 'fr';
        }

        return 'en';
    }

    private static function message(string $lang, string $key): string
    {
        $messages = [
            'fr' => [
                'forbidden' => 'Accès refusé: votre adresse IP n\'est pas autorisée.',
                'invalid_config' => 'Configuration de sécurité invalide: renseignez un nom d\'utilisateur et un mot de passe.',
                'auth_required' => 'Authentification requise pour accéder à cette ressource.',
            ],
            'en' => [
                'forbidden' => 'Access denied: your IP address is not allowed.',
                'invalid_config' => 'Invalid security configuration: set a username and password.',
                'auth_required' => 'Authentication is required to access this resource.',
            ],
        ];

        return $messages[$lang][$key] ?? $messages['en'][$key] ?? 'Access denied';
    }

    private static function isIpDenied(array $security): bool
    {
        $allowlist = $security['allowed_ips'] ?? [];
        if (is_array($allowlist) === false || $allowlist === []) {
            return false;
        }

        $ip = self::clientIp();
        if ($ip === null) {
            return true;
        }

        foreach ($allowlist as $entry) {
            if (is_string($entry) && self::ipMatches($ip, trim($entry))) {
                return false;
            }
        }

        return true;
    }

    private static function clientIp(): ?string
    {
        $xff = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? trim((string) $_SERVER['HTTP_X_FORWARDED_FOR']) : '';
        if ($xff !== '') {
            $parts = array_map('trim', explode(',', $xff));
            if (isset($parts[0]) && filter_var($parts[0], FILTER_VALIDATE_IP)) {
                return $parts[0];
            }
        }

        $remote = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
        if ($remote !== '' && filter_var($remote, FILTER_VALIDATE_IP)) {
            return $remote;
        }

        return null;
    }

    private static function ipMatches(string $ip, string $rule): bool
    {
        if ($rule === '') {
            return false;
        }

        if (!str_contains($rule, '/')) {
            return $ip === $rule;
        }

        [$subnet, $mask] = explode('/', $rule, 2);
        if (!is_numeric($mask)) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $maskInt = (int) $mask;
            if ($maskInt < 0 || $maskInt > 32) {
                return false;
            }

            $ipLong = ip2long($ip);
            $subnetLong = ip2long($subnet);
            if ($ipLong === false || $subnetLong === false) {
                return false;
            }

            $maskLong = $maskInt === 0 ? 0 : (-1 << (32 - $maskInt));
            return (($ipLong & $maskLong) === ($subnetLong & $maskLong));
        }

        return false;
    }
}
