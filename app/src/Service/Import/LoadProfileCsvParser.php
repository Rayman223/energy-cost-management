<?php

declare(strict_types=1);

namespace App\Service\Import;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/**
 * Lecture d'un CSV de profil de charge (RLP Synergrid et équivalents) — #93, #101.
 *
 * Cœur partagé des deux voies d'import : le script CLI
 * (`app/scripts/import_load_profile.php`) et la page d'administration
 * (`/admin/load-profiles`). Il ne touche pas à la base : il rend des poids prêts à
 * passer à {@see \App\Repository\LoadProfileRepository::upsertWeights()}, ce qui
 * permet à chaque voie de proposer une vérification sans écriture.
 *
 * FORMAT — deux colonnes, en-tête obligatoire, délimiteur et virgule décimale
 * détectés par {@see RowSource::fromCsv()} :
 *
 *     timestamp;fraction
 *     2026-01-01 00:00;0.000021
 *
 * L'échelle des valeurs est indifférente (fractions, pourcentages, kWh bruts) : le
 * calcul fait une moyenne pondérée, qui divise par la somme des poids.
 *
 * Les horodatages nus sont lus dans le fuseau du profil (heure locale belge pour
 * Synergrid) puis convertis en UTC, convention de `dynamic_prices`. Un horodatage
 * portant un offset est respecté tel quel.
 */
final class LoadProfileCsvParser
{
    /** Les deux résolutions que le calcul sait joindre aux cotations. */
    public const RESOLUTIONS = [15, 60];

    public const DEFAULT_TIMEZONE = 'Europe/Brussels';

    public const DEFAULT_TS_COL = 'timestamp';

    public const DEFAULT_VALUE_COL = 'fraction';

    /** Un an au pas de 15 min tient en ~1 Mo : 8 Mo laisse une large marge. */
    public const MAX_UPLOAD_BYTES = 8_388_608;

    /**
     * Code de profil normalisé (majuscules, 1 à 32 caractères alphanumériques).
     *
     * @throws InvalidArgumentException
     */
    public static function normalizeCode(string $code): string
    {
        $code = strtoupper(trim($code));
        if (!preg_match('/^[A-Z0-9_-]{1,32}$/', $code)) {
            throw new InvalidArgumentException('Code de profil invalide : 1 à 32 caractères (lettres, chiffres, « _ » ou « - »).');
        }

        return $code;
    }

    /**
     * Pays normalisé (ISO 3166-1 alpha-2).
     *
     * @throws InvalidArgumentException
     */
    public static function normalizeCountry(string $country): string
    {
        $country = strtoupper(trim($country));
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            throw new InvalidArgumentException('Pays invalide : code ISO 3166-1 alpha-2 attendu (ex. BE).');
        }

        return $country;
    }

    /**
     * 15 et 60 seulement : un pas arbitraire produirait des poids qui ne tomberaient
     * sur aucun créneau coté, donc une pondération silencieusement ignorée.
     *
     * @throws InvalidArgumentException
     */
    public static function checkResolution(int $resolution): int
    {
        if (!in_array($resolution, self::RESOLUTIONS, true)) {
            throw new InvalidArgumentException('Résolution invalide : 15 ou 60 minutes.');
        }

        return $resolution;
    }

    /** @throws InvalidArgumentException */
    public static function timezone(string $timezone): DateTimeZone
    {
        try {
            return new DateTimeZone(trim($timezone));
        } catch (\Throwable $e) {
            throw new InvalidArgumentException('Fuseau inconnu : ' . $timezone, 0, $e);
        }
    }

    /**
     * Ouvre un fichier téléversé, après les contrôles de la voie web d'import des
     * relevés ({@see ImportRunner::runUploaded()}) : code d'erreur, origine
     * (`is_uploaded_file`), taille et extension.
     *
     * @param array<string, mixed> $file Entrée $_FILES (name, tmp_name, error, size).
     * @return resource
     * @throws RuntimeException Message sûr à afficher.
     */
    public static function openUploaded(array $file, int $maxBytes = self::MAX_UPLOAD_BYTES)
    {
        $tmp  = is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '';
        $name = is_string($file['name'] ?? null) ? $file['name'] : '';
        $err  = is_int($file['error'] ?? null) ? $file['error'] : UPLOAD_ERR_NO_FILE;
        $size = is_int($file['size'] ?? null) ? $file['size'] : 0;

        if ($err === UPLOAD_ERR_NO_FILE || $tmp === '') {
            throw new RuntimeException('Aucun fichier fourni.');
        }
        if ($err !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Échec du téléversement (code ' . $err . ').');
        }
        if (PHP_SAPI !== 'cli' && !is_uploaded_file($tmp)) {
            throw new RuntimeException('Fichier non valide (téléversement attendu).');
        }
        if ($size > $maxBytes) {
            throw new RuntimeException(sprintf('Fichier trop volumineux (max %d Mo).', intdiv($maxBytes, 1_048_576)));
        }
        if (strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) !== 'csv') {
            throw new RuntimeException('Format non supporté : CSV attendu. Convertissez la feuille Synergrid en CSV.');
        }

        $handle = fopen($tmp, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Ouverture du fichier impossible.');
        }

        return $handle;
    }

    /**
     * Lit le CSV et rend les poids par créneau UTC aligné sur $resolution.
     *
     * Plusieurs lignes dans un même créneau voient leurs poids SOMMÉS, pas écrasés :
     * c'est le cas d'un CSV quart-horaire importé à 60 min, où les quatre quarts
     * forment le poids de l'heure. Même agrégation que côté calcul
     * ({@see \App\Service\MonthlyIndexedPriceCalculator}).
     *
     * @param resource $handle Fermé par l'appelant.
     * @throws InvalidArgumentException Fichier illisible, ou aucun point exploitable.
     */
    public function parse($handle, string $tsCol, string $valueCol, int $resolution, DateTimeZone $tz): LoadProfileParseResult
    {
        self::checkResolution($resolution);
        $tsCol    = strtolower(trim($tsCol));
        $valueCol = strtolower(trim($valueCol));
        $utc      = new DateTimeZone('UTC');

        /** @var array<string, array{slot_start: DateTimeImmutable, fraction: float}> $weights */
        $weights  = [];
        $rejected = 0;
        $merged   = 0;

        foreach (RowSource::fromCsv($handle) as $row) {
            $rawTs    = trim((string) ($row[$tsCol] ?? ''));
            $rawValue = (string) ($row[$valueCol] ?? '');

            // Un pourcentage reste un poids : seule la pondération relative compte,
            // donc le signe « % » est retiré sans conversion. La virgule décimale est
            // convertie ICI : RowSource ne la normalise que sur une valeur purement
            // numérique, et « 0,25 % » d'un export tableur lui échappait — la ligne
            // était rejetée. La notation scientifique (« 2,85123E-05 », forme que prend
            // un petit coefficient RLP dans un export tableur) suit la même règle.
            $rawValue = str_replace(['%', ' ', "\u{A0}"], '', $rawValue);
            if (preg_match('/^\d+,\d+(?:[eE][-+]?\d+)?$/', $rawValue)) {
                $rawValue = str_replace(',', '.', $rawValue);
            }
            if ($rawTs === '' || !is_numeric($rawValue) || (float) $rawValue < 0.0) {
                $rejected++;
                continue;
            }

            $instant = self::parseInstant($rawTs, $tz);
            if ($instant === null) {
                $rejected++;
                continue;
            }
            $instant = $instant->setTimezone($utc);

            $minute = $resolution === 60 ? 0 : intdiv((int) $instant->format('i'), 15) * 15;
            $slot   = $instant->setTime((int) $instant->format('G'), $minute, 0);
            $key    = $slot->format('Y-m-d H:i:00');

            if (isset($weights[$key])) {
                $merged++;
            }
            $weights[$key] = [
                'slot_start' => $slot,
                'fraction'   => ($weights[$key]['fraction'] ?? 0.0) + (float) $rawValue,
            ];
        }

        if ($weights === []) {
            throw new InvalidArgumentException(sprintf(
                'Aucun point exploitable : vérifiez les colonnes « %s » et « %s ».',
                $tsCol,
                $valueCol,
            ));
        }

        ksort($weights);

        return new LoadProfileParseResult(array_values($weights), $resolution, $rejected, $merged);
    }

    /**
     * Horodatage d'une ligne, ou null s'il est illisible.
     *
     * Deux formes seulement :
     *  - ISO (`AAAA-MM-JJ[ HH:MM[:SS]]`, offset facultatif) ;
     *  - `JJ/MM/AAAA[ HH:MM[:SS]]`, l'export CSV d'un tableur belge. Lu JOUR/MOIS
     *    explicitement : `DateTimeImmutable` y verrait un `m/d/Y` américain, et
     *    « 1/09/2026 0:15 » deviendrait le 9 janvier — des poids écrits en silence
     *    dans le mauvais mois.
     *
     * Tout le reste est rejeté, y compris les formes souples de `DateTimeImmutable`
     * (« now », « +1 day ») et les dates calendairement invalides (« 2026-02-30 »),
     * qu'il reporterait en silence au mois suivant ({@see ReadingParser}).
     */
    private static function parseInstant(string $raw, DateTimeZone $tz): ?DateTimeImmutable
    {
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?)?$#', $raw, $m) === 1) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            [$hour, $minute, $second] = [(int) ($m[4] ?? 0), (int) ($m[5] ?? 0), (int) ($m[6] ?? 0)];
            if (!checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
                return null;
            }

            return (new DateTimeImmutable('now', $tz))
                ->setDate($year, $month, $day)
                ->setTime($hour, $minute, $second);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ]\d{1,2}:\d{2}(?::\d{2}(?:\.\d+)?)?)?(?:Z|[+-]\d{2}:?\d{2})?$/', $raw) !== 1) {
            return null;
        }
        $parsed = date_parse($raw);
        if ($parsed['error_count'] > 0 || $parsed['warning_count'] > 0) {
            return null;
        }

        try {
            return new DateTimeImmutable($raw, $tz);
        } catch (\Exception) {
            return null;
        }
    }
}
