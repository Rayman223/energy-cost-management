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
        // On ne décode le corps que pour les méthodes qui en portent un
        // (POST/PUT/PATCH/DELETE — corrige B7 : PUT/PATCH étaient ignorés), et
        // seulement si le client déclare `application/json`. Ce gating ferme un
        // vecteur CSRF (C3) : un <form enctype="text/plain"> cross-site ne peut
        // pas forger un corps JSON, et un vrai `application/json` force un
        // préflight CORS — impossible à émettre en navigation cross-site simple.
        //
        // Exception : les requêtes portant un jeton Bearer (agents machine
        // d'ingestion) ne sont pas exploitables en CSRF — aucun identifiant
        // ambiant, un formulaire cross-site ne peut pas poser d'en-tête
        // Authorization. On tolère donc leur corps JSON sans Content-Type strict
        // pour préserver la compat des agents ; le gating reste requis pour les
        // mutations authentifiées par session/Basic (navigateur).
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        $carriesBody = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
        $authHeader  = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        $viaBearer   = stripos($authHeader, 'bearer ') === 0;
        if ($carriesBody && (str_starts_with($contentType, 'application/json') || $viaBearer)) {
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
     *
     * Exige une date calendaire réelle. Sont donc refusées, en plus des valeurs
     * non parsables :
     * - les dates impossibles (2026-02-31, 2026-02-29 hors année bissextile),
     *   que le parseur décale sinon en silence ;
     * - les entrées qui ne portent pas de date et se résoudraient sur l'horloge
     *   courante (`2026`, `12:00`, `now`, `tomorrow`).
     */
    public static function parseDate(mixed $value, string $field): DateTimeImmutable
    {
        // Valeur absente/vide : refus explicite. Sans cette garde, le cast en
        // string faisait construire un DateTimeImmutable('') — soit « maintenant »,
        // en silence. Message volontairement identique au cas « non parsable » :
        // stabilité du contrat 422 pour les clients existants.
        if (self::isBlank($value)) {
            throw new ValidationException(sprintf('Invalid %s date format', $field));
        }

        // Inspection avant construction, comme le fait déjà ReadingParser à
        // l'ingestion : `date_parse` décrit ce que le parseur a réellement lu, sans
        // l'état global de `DateTimeImmutable::getLastErrors()`.
        $parsed = date_parse($value);

        // Une date calendaire impossible ne lève pas d'exception : le parseur la
        // décale silencieusement (2026-02-31 -> 2026-03-03) en ne signalant qu'un
        // warning. Sans cette garde, une typo côté client devient une donnée fausse
        // et durable — grille active deux jours trop tard, relevé horodaté au
        // mauvais jour.
        if ($parsed['error_count'] > 0 || $parsed['warning_count'] > 0) {
            throw new ValidationException(sprintf('Invalid %s date format', $field));
        }

        // Une entrée qui ne porte aucune date se résout sur l'horloge courante :
        // « 2026 » est lu comme l'heure 20:26 d'aujourd'hui, « 0731 » comme 07:31,
        // « now » et « tomorrow » comme le moment de la requête. Même classe de bug
        // que la chaîne vide traitée en #246 : un 200 et une donnée fausse. La voie
        // documentée pour horodater à l'instant courant reste l'absence du champ
        // (cf. app/docs/api-contract.md), pas une valeur relative.
        if (!is_int($parsed['year']) || !is_int($parsed['month']) || !is_int($parsed['day'])) {
            throw new ValidationException(sprintf('Invalid %s date format', $field));
        }

        try {
            return new DateTimeImmutable($value);
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
        if (self::isBlank($value)) {
            return null;
        }

        return self::parseDate($value, $field);
    }

    /**
     * Vrai si la valeur ne porte aucune date exploitable (non-string, vide, blancs).
     * Prédicat partagé : ce que `optionalDate` traite comme « absent »,
     * `parseDate` traite comme « requis manquant ».
     *
     * @phpstan-assert-if-false string $value
     */
    private static function isBlank(mixed $value): bool
    {
        return !is_string($value) || trim($value) === '';
    }
}
