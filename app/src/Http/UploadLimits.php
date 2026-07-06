<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Détection d'un POST tronqué par PHP faute de place (au-delà de `post_max_size`).
 *
 * Dans ce cas PHP **vide** `$_POST` et `$_FILES` alors que le corps a bien été
 * envoyé (`CONTENT_LENGTH > 0`). Sans garde, la validation CSRF (jeton absent)
 * échoue en premier et l'utilisateur voit « Jeton CSRF invalide » au lieu de
 * « fichier trop volumineux ». À tester **avant** la validation CSRF.
 */
final class UploadLimits
{
    /**
     * Vrai si la requête POST courante a été tronquée par `post_max_size`
     * (corps non vide mais `$_POST`/`$_FILES` vidés par PHP).
     *
     * @param array<string, mixed> $server Typiquement `$_SERVER`.
     * @param array<string, mixed> $post   Typiquement `$_POST`.
     * @param array<string, mixed> $files  Typiquement `$_FILES`.
     */
    public static function postExceededLimit(array $server, array $post, array $files): bool
    {
        if (($server['REQUEST_METHOD'] ?? '') !== 'POST') {
            return false;
        }

        $contentLength = (int) ($server['CONTENT_LENGTH'] ?? 0);

        return $contentLength > 0 && $post === [] && $files === [];
    }
}
