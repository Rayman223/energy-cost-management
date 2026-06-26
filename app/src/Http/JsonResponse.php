<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Réponse JSON de l'API : un statut HTTP + une charge utile sérialisée avec les
 * mêmes drapeaux que l'ancien `jsonOut()` (UNESCAPED_UNICODE | UNESCAPED_SLASHES).
 */
final class JsonResponse
{
    public function __construct(
        public readonly mixed $data,
        public readonly int $status = 200,
    ) {
    }

    public static function ok(mixed $data, int $status = 200): self
    {
        return new self($data, $status);
    }

    /** Réponse d'erreur normalisée `{ ok: false, error }`. */
    public static function error(string $message, int $status): self
    {
        return new self(['ok' => false, 'error' => $message], $status);
    }

    public function send(): void
    {
        http_response_code($this->status);
        echo json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
