<?php

declare(strict_types=1);

namespace App\Infrastructure;

final class HttpClient
{
    public function postJson(string $url, array $payload, int $timeout = 15): array
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_TIMEOUT => $timeout,
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
        ];
    }
}
