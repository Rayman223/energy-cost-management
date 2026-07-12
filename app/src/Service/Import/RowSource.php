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
    /**
     * Lit un CSV depuis une ressource ouverte, en flux. La 1ʳᵉ ligne est l'en-tête.
     *
     * @param resource $handle
     * @param non-empty-string $delimiter
     * @return iterable<int, array<string, string>>
     */
    public static function fromCsv($handle, string $delimiter = ','): iterable
    {
        // escape: '' → conforme RFC 4180 (pas d'échappement backslash) et aligné
        // sur le futur défaut de PHP ; requis explicitement depuis PHP 8.4.
        $header = fgetcsv($handle, 0, $delimiter, escape: '');
        if ($header === false || $header === [null]) {
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
