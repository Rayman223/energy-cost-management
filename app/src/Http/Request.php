<?php

declare(strict_types=1);

namespace App\Http;

use DateTimeImmutable;

/**
 * Requête HTTP entrante (méthode, paramètres de requête, corps JSON décodé).
 *
 * Le constructeur prend des valeurs explicites (testable) ; `fromGlobals()`
 * lit les superglobales et le corps `php://input` pour les requêtes POST.
 */
final class Request
{
    /**
     * @param array<string,mixed> $query Paramètres de l'URL ($_GET).
     * @param array<string,mixed> $body  Corps JSON décodé (POST), sinon vide.
     */
    public function __construct(
        private readonly string $method,
        private readonly array $query,
        private readonly array $body,
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        $body = [];
        if ($method === 'POST') {
            $decoded = json_decode((string) (file_get_contents('php://input') ?: '{}'), true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        return new self($method, $_GET, $body);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function action(): string
    {
        return (string) ($this->query['action'] ?? '');
    }

    public function queryInt(string $key, int $default): int
    {
        $value = $this->query[$key] ?? null;

        return $value === null ? $default : (int) $value;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    /**
     * Parse une valeur en date, ou lève une ValidationException (-> 422).
     * Message identique à l'ancien parseDateTimeOr422.
     */
    public static function parseDate(mixed $value, string $field): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable((string) $value);
        } catch (\Exception) {
            throw new ValidationException(sprintf('Invalid %s date format', $field));
        }
    }

    /**
     * Date optionnelle : null si absente/vide, sinon parsée (peut lever 422).
     * Reproduit l'ancien parseOptionalDateTimeOr422.
     */
    public static function optionalDate(mixed $value, string $field): ?DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return self::parseDate($value, $field);
    }
}
