<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Erreur de validation d'entrée. Capturée par le Router et traduite en réponse
 * HTTP 422 avec le message comme corps `{ ok: false, error }`.
 */
final class ValidationException extends \RuntimeException
{
}
