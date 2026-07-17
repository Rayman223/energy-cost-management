<?php

declare(strict_types=1);

namespace App\Service\Import;

use InvalidArgumentException;

/**
 * Sources de lignes normalisées pour l'import : CSV (en flux, sans charger tout
 * le fichier) et JSON. Chaque ligne est un tableau associatif `colonne => valeur`
 * dont les clés sont normalisées (trim + minuscules), cohérent avec
 * {@see ImportMapping}. La clé du tableau itéré est le **numéro de ligne source**
 * (utile pour les messages d'erreur du {@see ImportReport}).
 */
final class RowSource
{
    /** Délimiteurs candidats pour l'auto-détection, par ordre de préférence. */
    private const DELIMITERS = [',', ';', "\t"];

    /**
     * Lit un CSV depuis une ressource ouverte, en flux. La 1ʳᵉ ligne est l'en-tête.
     *
     * Le délimiteur est **auto-détecté** depuis la ligne d'en-tête ({@see
     * self::sniffDelimiter()}) : les exports tableur FR/BE utilisent `;`, ceux des
     * outils anglo-saxons `,`. Le passer explicitement force la lecture (CLI, tests).
     *
     * L'en-tête est lu via `fgets()` (une ligne physique) pour détecter le
     * délimiteur sans dépendre d'un flux rembobinable — un nom de colonne contenant
     * un saut de ligne dans un champ quoté n'est donc pas géré (inexistant en
     * pratique). Les **lignes de données**, elles, passent par `fgetcsv()` et
     * gardent le support des champs multi-lignes.
     *
     * @param resource $handle
     * @param non-empty-string|null $delimiter null = auto-détection
     * @return iterable<int, array<string, string>>
     */
    public static function fromCsv($handle, ?string $delimiter = null): iterable
    {
        $headerLine = fgets($handle);
        if ($headerLine === false || trim($headerLine) === '') {
            throw new InvalidArgumentException('CSV vide ou en-tête manquant.');
        }

        // BOM UTF-8 des exports tableur : sans ce retrait il resterait collé au 1er
        // nom de colonne (« \xEF\xBB\xBFdate » ≠ « date »), rendant la colonne
        // d'horodatage introuvable — l'import échouerait sur chaque ligne.
        $headerLine = (string) preg_replace('/^\xEF\xBB\xBF/', '', $headerLine);

        $delimiter ??= self::sniffDelimiter($headerLine);

        // escape: '' → conforme RFC 4180 (pas d'échappement backslash) et aligné
        // sur le futur défaut de PHP ; requis explicitement depuis PHP 8.4.
        $header = str_getcsv(rtrim($headerLine, "\r\n"), $delimiter, escape: '');
        if ($header === [null]) {
            throw new InvalidArgumentException('CSV vide ou en-tête manquant.');
        }

        /** @var list<string> $columns */
        $columns = array_map(static fn($h): string => strtolower(trim((string) $h)), $header);

        $lineNo = 1;
        while (($line = fgetcsv($handle, 0, $delimiter, escape: '')) !== false) {
            $lineNo++;

            // Ligne vide (fgetcsv renvoie [null] pour une ligne blanche).
            if ($line === [null]) {
                continue;
            }

            $row = [];
            foreach ($columns as $i => $col) {
                $row[$col] = isset($line[$i]) ? trim((string) $line[$i]) : '';
            }

            yield $lineNo => $row;
        }
    }

    /**
     * Devine le délimiteur d'une ligne d'en-tête : celui qui découpe le plus de
     * colonnes l'emporte. À égalité (notamment quand aucun ne découpe rien, en-tête
     * mono-colonne), l'ordre de {@see self::DELIMITERS} tranche — donc `,`, le
     * comportement historique.
     *
     * Le découpage réel (`str_getcsv`) plutôt qu'un `substr_count` : un délimiteur
     * présent uniquement **dans** un champ quoté (`"Prélèvement, jour"`) ne gonfle
     * pas le score et ne fait pas gagner le mauvais candidat.
     *
     * @return non-empty-string
     */
    private static function sniffDelimiter(string $headerLine): string
    {
        $headerLine = rtrim($headerLine, "\r\n");

        $best  = self::DELIMITERS[0];
        $score = 0;
        foreach (self::DELIMITERS as $candidate) {
            $count = count(str_getcsv($headerLine, $candidate, escape: ''));
            if ($count > $score) {
                $best  = $candidate;
                $score = $count;
            }
        }

        return $best;
    }

    /**
     * Décode un JSON `{"readings":[...]}` ou un tableau de lignes en tête.
     *
     * @return iterable<int, array<string, string>>
     */
    public static function fromJson(string $raw): iterable
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('JSON invalide ou racine inattendue.');
        }

        $list = $decoded;
        if (isset($decoded['readings']) && is_array($decoded['readings'])) {
            $list = $decoded['readings'];
        }

        $rowNo = 0;
        foreach (array_values($list) as $entry) {
            $rowNo++;
            if (!is_array($entry)) {
                throw new InvalidArgumentException(sprintf('readings[%d] doit être un objet.', $rowNo - 1));
            }

            $row = [];
            foreach ($entry as $key => $value) {
                if (is_scalar($value)) {
                    $row[strtolower(trim((string) $key))] = trim((string) $value);
                }
            }

            yield $rowNo => $row;
        }
    }
}
