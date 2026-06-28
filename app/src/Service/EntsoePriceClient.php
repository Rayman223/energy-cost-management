<?php

declare(strict_types=1);

namespace App\Service;

use App\Infrastructure\HttpClient;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Client ENTSO-E Transparency Platform — prix day-ahead (document A44).
 *
 * Effectue l'appel HTTP puis délègue le parsing à EntsoePriceParser (logique pure).
 * Doc : https://transparency.entsoe.eu/ (token gratuit sur inscription).
 */
final class EntsoePriceClient
{
    private const DOCUMENT_TYPE = 'A44';

    public function __construct(
        private readonly HttpClient $http,
        private readonly EntsoePriceParser $parser,
        private readonly string $apiUrl,
        private readonly string $securityToken,
        private readonly string $biddingZone,
        private readonly int $timeout = 30,
    ) {
    }

    /**
     * Récupère les prix day-ahead pour la fenêtre [$from, $to[.
     *
     * @return array<int, array{period_start: DateTimeImmutable, resolution_min: int, price_eur_kwh: float}>
     *
     * @throws RuntimeException En cas d'erreur HTTP, de token invalide ou de réponse illisible.
     */
    public function fetchDayAheadPrices(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $utc   = new DateTimeZone('UTC');
        $query = http_build_query([
            'securityToken' => $this->securityToken,
            'documentType'  => self::DOCUMENT_TYPE,
            'in_Domain'     => $this->biddingZone,
            'out_Domain'    => $this->biddingZone,
            'periodStart'   => $from->setTimezone($utc)->format('YmdHi'),
            'periodEnd'     => $to->setTimezone($utc)->format('YmdHi'),
        ]);

        $url    = $this->apiUrl . (str_contains($this->apiUrl, '?') ? '&' : '?') . $query;
        $result = $this->http->get($url, $this->timeout);

        $body = is_string($result['body'] ?? null) ? $result['body'] : '';

        if (($result['ok'] ?? false) !== true) {
            $status = (int) ($result['status'] ?? 0);
            $error  = is_string($result['error'] ?? null) ? $result['error'] : '';
            $reason = $this->parser->errorReason($body) ?? ($error !== '' ? $error : 'réponse non-2xx');

            throw new RuntimeException(sprintf('Erreur API ENTSO-E (HTTP %d): %s', $status, $reason));
        }

        return $this->parser->parse($body);
    }
}
