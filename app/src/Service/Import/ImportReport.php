<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * Bilan d'un import en masse : compteurs cumulés + échantillon borné des
 * erreurs ligne à ligne (pour ne pas exploser la mémoire sur un gros fichier).
 */
final class ImportReport
{
    /** Nombre maximal de messages d'erreur conservés (les suivants sont comptés mais non détaillés). */
    private const MAX_SAMPLES = 50;

    private int $imported = 0;
    private int $duplicates = 0;
    private int $errors = 0;

    /** @var list<string> */
    private array $errorSamples = [];

    public function addImported(int $n = 1): void
    {
        $this->imported += $n;
    }

    public function addDuplicate(int $n = 1): void
    {
        $this->duplicates += $n;
    }

    public function addError(string $message): void
    {
        $this->errors++;
        if (count($this->errorSamples) < self::MAX_SAMPLES) {
            $this->errorSamples[] = $message;
        }
    }

    public function imported(): int
    {
        return $this->imported;
    }

    public function duplicates(): int
    {
        return $this->duplicates;
    }

    public function errors(): int
    {
        return $this->errors;
    }

    /** @return list<string> */
    public function errorSamples(): array
    {
        return $this->errorSamples;
    }

    /** Total de lignes traitées (importées + doublons + erreurs). */
    public function total(): int
    {
        return $this->imported + $this->duplicates + $this->errors;
    }

    /** @return array{imported: int, duplicates: int, errors: int, total: int, error_samples: list<string>} */
    public function toArray(): array
    {
        return [
            'imported'      => $this->imported,
            'duplicates'    => $this->duplicates,
            'errors'        => $this->errors,
            'total'         => $this->total(),
            'error_samples' => $this->errorSamples,
        ];
    }
}
