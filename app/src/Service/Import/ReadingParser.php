<?php

declare(strict_types=1);

namespace App\Service\Import;

use DateTimeImmutable;

/**
 * Prédicats **purs** de validation des relevés, partagés entre l'import en masse
 * ({@see \App\Service\BulkImportService}, stratégie « collecter ») et l'API
 * d'ingestion ({@see \App\Http\Controller\IngestController}, stratégie « lever »).
 *
 * Chaque prédicat renvoie la valeur normalisée ou `null` si invalide — l'appelant
 * décide quoi faire d'un `null` (compter et poursuivre, ou lever). Centraliser la
 * règle ici évite la divergence entre les deux chemins (racine d'un bug corrigé
 * en P8).
 */
final class ReadingParser
{
    /**
     * Formats d'horodatage acceptés (stricts). On refuse tout ce que
     * `new DateTimeImmutable()` accepterait de trop souple (« now », « +1 day »,
     * « 2026 »…) : ces valeurs seraient datées à l'instant de l'import et
     * casseraient l'idempotence (un réimport tomberait sur un nouvel horodatage).
     */
    public const TIMESTAMP_FORMATS = ['Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d'];

    /** Valide un index/valeur : float ≥ 0, ou null si invalide. */
    public static function parseValue(mixed $raw): ?float
    {
        $value = filter_var($raw, FILTER_VALIDATE_FLOAT);
        if ($value === false || $value < 0) {
            return null;
        }

        return $value;
    }

    /**
     * Valide un horodatage strict (cf. {@see self::TIMESTAMP_FORMATS}) : renvoie
     * le `DateTimeImmutable` correspondant, ou null si le format ne correspond pas
     * exactement (aller-retour).
     */
    public static function parseTimestampStrict(string $raw): ?DateTimeImmutable
    {
        foreach (self::TIMESTAMP_FORMATS as $format) {
            // `!` réinitialise les champs non fournis (Y-m-d → minuit) ; l'aller-
            // retour garantit une correspondance exacte (rejette « now », « 2026 »…).
            $dt = DateTimeImmutable::createFromFormat('!' . $format, $raw);
            if ($dt !== false && $dt->format($format) === $raw) {
                return $dt;
            }
        }

        return null;
    }
}
