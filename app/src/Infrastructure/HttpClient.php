<?php

declare(strict_types=1);

namespace App\Infrastructure;

final class HttpClient
{
    /**
     * User-Agent envoyé par défaut. Sans en-tête `User-Agent`, cURL n'en envoie
     * aucun — et plusieurs API le refusent : Discord (protégé par Cloudflare)
     * répond 403 à une requête anonyme, ce qui faisait échouer silencieusement
     * la mise en cache de sa découverte OIDC (#25). Un appelant peut le
     * remplacer en passant sa propre en-tête `User-Agent`.
     */
    private const USER_AGENT = 'energy-cost-management (+https://github.com/Rayman223/energy-cost-management)';

    /**
     * Requête HTTP GET simple. Le corps brut est laissé tel quel (l'appelant
     * décode JSON, parse XML, etc.). Même forme de retour que postJson().
     *
     * @param array<string,string> $headers
     * @return array<string, mixed>
     */
    public function get(string $url, int $timeout = 15, array $headers = []): array
    {
        $responseHeaders = [];

        $curlHeaders = [];
        foreach ($headers as $name => $value) {
            $curlHeaders[] = $name . ': ' . $value;
        }
        if (!self::hasUserAgent($headers)) {
            $curlHeaders[] = 'User-Agent: ' . self::USER_AGENT;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET        => true,
            CURLOPT_HTTPHEADER     => $curlHeaders,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$responseHeaders): int {
                $trimmed = trim($headerLine);
                if ($trimmed === '' || str_contains($trimmed, ':') === false) {
                    return strlen($headerLine);
                }

                [$name, $value] = explode(':', $trimmed, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);

                return strlen($headerLine);
            },
        ]);

        $body   = curl_exec($ch);
        $error  = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'ok'      => $error === '' && $status >= 200 && $status < 300,
            'status'  => $status,
            'error'   => $error,
            'body'    => is_string($body) ? $body : '',
            'headers' => $responseHeaders,
        ];
    }

    /**
     * @param array<array-key, mixed> $payload  Corps JSON : objet (assoc) ou liste de mesures.
     * @param array<string,string> $headers
     * @return array<string, mixed>
     */
    public function postJson(string $url, array $payload, int $timeout = 15, array $headers = []): array
    {
        $responseHeaders = [];

        $curlHeaders = ['Content-Type: application/json'];
        foreach ($headers as $name => $value) {
            $curlHeaders[] = $name . ': ' . $value;
        }
        if (!self::hasUserAgent($headers)) {
            $curlHeaders[] = 'User-Agent: ' . self::USER_AGENT;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$responseHeaders): int {
                $trimmed = trim($headerLine);
                if ($trimmed === '' || str_contains($trimmed, ':') === false) {
                    return strlen($headerLine);
                }

                [$name, $value] = explode(':', $trimmed, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);

                return strlen($headerLine);
            },
        ]);

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'ok' => $error === '' && $status >= 200 && $status < 300,
            'status' => $status,
            'error' => $error,
            'body' => is_string($body) ? $body : '',
            'headers' => $responseHeaders,
        ];
    }

    /**
     * Requête POST `application/x-www-form-urlencoded`. Pendant de postJson()
     * pour les API qui n'acceptent pas le JSON : le token endpoint OAuth 2.0
     * (RFC 6749 §4.1.3), dont celui de GitHub (#24), attend un corps encodé en
     * formulaire. Même forme de retour que les autres méthodes.
     *
     * @param array<string, string> $fields  Paramètres du corps (encodés ici).
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public function postForm(string $url, array $fields, int $timeout = 15, array $headers = []): array
    {
        $responseHeaders = [];

        $curlHeaders = ['Content-Type: application/x-www-form-urlencoded'];
        foreach ($headers as $name => $value) {
            $curlHeaders[] = $name . ': ' . $value;
        }
        if (!self::hasUserAgent($headers)) {
            $curlHeaders[] = 'User-Agent: ' . self::USER_AGENT;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $curlHeaders,
            CURLOPT_POSTFIELDS     => http_build_query($fields, '', '&', PHP_QUERY_RFC1738),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$responseHeaders): int {
                $trimmed = trim($headerLine);
                if ($trimmed === '' || str_contains($trimmed, ':') === false) {
                    return strlen($headerLine);
                }

                [$name, $value] = explode(':', $trimmed, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);

                return strlen($headerLine);
            },
        ]);

        $body   = curl_exec($ch);
        $error  = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'ok'      => $error === '' && $status >= 200 && $status < 300,
            'status'  => $status,
            'error'   => $error,
            'body'    => is_string($body) ? $body : '',
            'headers' => $responseHeaders,
        ];
    }

    /**
     * L'appelant fournit-il déjà un User-Agent ? (nom d'en-tête insensible à la
     * casse, comme le veut HTTP.)
     *
     * @param array<string,string> $headers
     */
    private static function hasUserAgent(array $headers): bool
    {
        foreach (array_keys($headers) as $name) {
            if (strcasecmp($name, 'User-Agent') === 0) {
                return true;
            }
        }

        return false;
    }
}
