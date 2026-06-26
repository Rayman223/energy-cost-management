<?php

declare(strict_types=1);

namespace App\Service;

final class MeterApiService
{
    public function __construct(private readonly int $timeout = 10)
    {
    }

    /** @return array<string, mixed> */
    public function fetchJson(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
        ]);

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error !== '') {
            throw new \RuntimeException('Erreur API compteur: ' . $error);
        }

        if ($status < 200 || $status >= 300 || !is_string($body)) {
            throw new \RuntimeException('Réponse API compteur invalide (HTTP ' . $status . ').');
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('JSON compteur invalide.');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int,string> $paths
     */
    public function readNumericValue(array $payload, array $paths): float
    {
        foreach ($paths as $path) {
            $value = $this->readPath($payload, $path);
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        throw new \RuntimeException('Aucune valeur numérique trouvée pour les chemins: ' . implode(', ', $paths));
    }

    /** @param array<string, mixed> $payload */
    private function readPath(array $payload, string $path): mixed
    {
        $cursor = $payload;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }
}
