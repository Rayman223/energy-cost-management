<?php

declare(strict_types=1);

namespace App\Security;

final class WebAccessGuard
{
    /** @param array<string, mixed> $security */
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

        self::enforceIp($security, $jsonResponse);

        $basicEnabled = (bool) ($security['basic_auth']['enabled'] ?? false);
        if ($basicEnabled === false) {
            return;
        }

        if (self::isLoginPageRequest() === true) {
            return;
        }

        $expectedUser = (string) ($security['basic_auth']['username'] ?? '');
        $expectedPass = (string) ($security['basic_auth']['password'] ?? '');

        if ($expectedUser === '' || $expectedPass === '') {
            self::deny(500, self::message($lang, 'invalid_config'), $jsonResponse);
        }

        if (self::isSessionAuthenticated($expectedUser, $expectedPass)) {
            return;
        }

        [$user, $pass] = self::extractProvidedCredentials();

        $validUser = hash_equals($expectedUser, $user);
        $validPass = hash_equals($expectedPass, $pass);

        if ($validUser && $validPass) {
            if ($jsonResponse === false) {
                self::markSessionAuthenticated($expectedUser, $expectedPass);
            }

            return;
        }

        if ($jsonResponse === false && self::prefersHtml()) {
            self::redirectToLogin($lang);
        }

        $realm = $lang === 'fr' ? 'Accès sécurisé Manage Energy' : 'Manage Energy secure access';
        header('WWW-Authenticate: Basic realm="' . addslashes($realm) . '", charset="UTF-8"');
        self::deny(401, self::message($lang, 'auth_required'), $jsonResponse);
    }

    /** @param array<string, mixed> $security */
    public static function authenticateForm(array $security, string $username, string $password): bool
    {
        $expectedUser = (string) ($security['basic_auth']['username'] ?? '');
        $expectedPass = (string) ($security['basic_auth']['password'] ?? '');

        if ($expectedUser === '' || $expectedPass === '') {
            return false;
        }

        $validUser = hash_equals($expectedUser, $username);
        $validPass = hash_equals($expectedPass, $password);

        if ($validUser && $validPass) {
            self::markSessionAuthenticated($expectedUser, $expectedPass);
            return true;
        }

        return false;
    }

    /**
     * Applique uniquement l'allowlist IP (sans Basic Auth). Utilisé par AuthGuard
     * en mode OIDC, où l'authentification est portée par la session.
     *
     * @param array<string, mixed> $security
     */
    public static function enforceIp(array $security, bool $jsonResponse = false): void
    {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            return;
        }

        if ((bool) ($security['enabled'] ?? false) === false) {
            return;
        }

        if (self::isIpDenied($security) === true) {
            self::deny(403, self::message(self::resolveLanguage(), 'forbidden'), $jsonResponse);
        }
    }

    /** Préfixe de chemin de l'application (pour construire des URLs absolues). */
    public static function basePath(): string
    {
        return self::applicationBasePath();
    }

    private static function isLoginPageRequest(): bool
    {
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        return str_ends_with($script, '/login.php') || $script === 'login.php';
    }

    private static function prefersHtml(): bool
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        if ($accept === '') {
            return true;
        }

        return str_contains($accept, 'text/html') || str_contains($accept, '*/*');
    }

    private static function redirectToLogin(string $lang): void
    {
        $path = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        $next = urlencode($path);
        $langParam = urlencode($lang);
        header('Location: ' . self::buildLoginPath() . '?next=' . $next . '&lang=' . $langParam, true, 302);
        exit;
    }


    private static function buildLoginPath(): string
    {
        $base = self::applicationBasePath();
        return $base . '/login.php';
    }

    private static function applicationBasePath(): string
    {
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        if ($script === '') {
            return '';
        }

        $base = str_replace('\\', '/', dirname($script));
        if ($base === '/' || $base === '.') {
            return '';
        }

        return rtrim($base, '/');
    }

    private static function isSessionAuthenticated(string $expectedUser, string $expectedPass): bool
    {
        self::startSession();
        $token = isset($_SESSION['web_auth']) ? (string) $_SESSION['web_auth'] : '';

        if ($token === '') {
            return false;
        }

        return hash_equals($token, self::sessionToken($expectedUser, $expectedPass));
    }

    private static function markSessionAuthenticated(string $username, string $password): void
    {
        self::startSession();
        session_regenerate_id(true);
        $_SESSION['web_auth'] = self::sessionToken($username, $password);
    }

    private static function sessionToken(string $username, string $password): string
    {
        return hash('sha256', $username . ':' . $password);
    }

    private static function startSession(): void
    {
        Session::start();
    }

    /**
     * @return array{0:string,1:string}
     */
    private static function extractProvidedCredentials(): array
    {
        $user = isset($_SERVER['PHP_AUTH_USER']) ? (string) $_SERVER['PHP_AUTH_USER'] : '';
        $pass = isset($_SERVER['PHP_AUTH_PW']) ? (string) $_SERVER['PHP_AUTH_PW'] : '';

        if ($user !== '' || $pass !== '') {
            return [$user, $pass];
        }

        $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if ($authorization === '' || stripos($authorization, 'basic ') !== 0) {
            return ['', ''];
        }

        $raw = base64_decode(substr($authorization, 6), true);
        if ($raw === false || str_contains($raw, ':') === false) {
            return ['', ''];
        }

        [$decodedUser, $decodedPass] = explode(':', $raw, 2);
        return [(string) $decodedUser, (string) $decodedPass];
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

    /** @param array<string, mixed> $security */
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
