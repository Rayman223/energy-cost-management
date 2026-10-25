<?php

declare(strict_types=1);

namespace App\Support;

/** Référence de compte signée compatible avec client_reference_id des Payment Links. */
final class SupportReference
{
    public static function forUser(int $userId, string $key): ?string
    {
        if ($userId <= 0 || $key === '') return null;

        return $userId . '_' . hash_hmac('sha256', (string) $userId, $key);
    }

    public static function userId(string $reference, string $key): ?int
    {
        if ($key === '' || strlen($reference) > 200
            || preg_match('/^([1-9][0-9]*)_([a-f0-9]{64})$/D', $reference, $matches) !== 1) return null;

        $id = filter_var($matches[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || !hash_equals(hash_hmac('sha256', $matches[1], $key), $matches[2])) return null;

        return $id;
    }
}
