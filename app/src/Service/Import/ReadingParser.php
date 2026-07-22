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
     * Exige un ISO 8601 avec offset de fuseau explicite : date + heure, séparateur
     * `T` ou espace, secondes (et fraction) optionnelles, et une zone obligatoire
     * (`Z`, `±HH:MM` ou `±HHMM`). Rejette donc tout horodatage nu, un date-only, et
     * les valeurs souples de `DateTimeImmutable` (« now », « +1 day », « 2026 »…).
     */
    private const ISO8601_WITH_ZONE = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/';

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
     * Valide un horodatage ISO 8601 **avec offset de fuseau explicite**
     * (`2026-07-15T00:00:00+02:00` ou `...Z`) : renvoie l'instant absolu
     * correspondant, ou null si le format ne correspond pas.
     *
     * Le fuseau est obligatoire : un horodatage nu (`2026-07-15 00:00:00`) est
     * ambigu — son instant dépendrait du fuseau du serveur — et serait supposé
     * UTC en silence, piégeant tout intégrateur (racine du bug #175). On l'exige
     * donc explicite, aussi bien à l'API d'ingestion qu'à l'import en masse.
     *
     * La regex ne valide que la *syntaxe* : une date calendairement invalide
     * (29 février non bissextile, 31 avril, `0000-00-00`…) est ensuite rejetée
     * via `date_parse`, sans quoi `DateTimeImmutable` la normaliserait en silence
     * (report d'un ou deux jours) et stockerait un instant faux.
     */
    public static function parseTimestampWithZone(string $raw): ?DateTimeImmutable
    {
        $raw = trim($raw);
        if (preg_match(self::ISO8601_WITH_ZONE, $raw) !== 1) {
            return null;
        }

        // Rejette les dates hors-plage que DateTimeImmutable reporterait sans lever
        // (warning_count) ou qu'il ne saurait parser (error_count).
        $parsed = date_parse($raw);
        if ($parsed['error_count'] > 0 || $parsed['warning_count'] > 0) {
            return null;
        }

        try {
            return new DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }
}
