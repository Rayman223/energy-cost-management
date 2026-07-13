<?php

declare(strict_types=1);

namespace App\Service\Import;

use DateTimeImmutable;

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

    /**
     * Sous-ensemble de {@see $errors} imputable à une défaillance base/infra
     * (écriture échouée), par opposition aux lignes simplement ignorées
     * (validation). Sert à distinguer « lignes ignorées » d'un « échec réel »
     * pour le code de sortie CLI.
     */
    private int $writeErrors = 0;

    /** Vrai si l'import a été tronqué (plafond de lignes atteint). */
    private bool $truncated = false;

    /** @var list<string> */
    private array $errorSamples = [];

    /**
     * Horodatage le plus ancien effectivement inséré (nouvelle ligne, hors
     * doublon). Permet à l'orchestrateur de reculer le watermark de synchro
     * EnergyID quand l'import contient des relevés antidatés (#130 B2).
     */
    private ?DateTimeImmutable $earliestImportedAt = null;

    public function addImported(int $n = 1): void
    {
        $this->imported += $n;
    }

    /** Enregistre l'horodatage d'une ligne réellement insérée (garde le minimum). */
    public function noteImportedAt(DateTimeImmutable $ts): void
    {
        if ($this->earliestImportedAt === null || $ts < $this->earliestImportedAt) {
            $this->earliestImportedAt = $ts;
        }
    }

    public function earliestImportedAt(): ?DateTimeImmutable
    {
        return $this->earliestImportedAt;
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

    /**
     * Erreur d'écriture base/infra : comptée comme erreur **et** comme échec réel
     * (contrairement à une ligne ignorée pour cause de validation).
     */
    public function addWriteError(string $message): void
    {
        $this->writeErrors++;
        $this->addError($message);
    }

    /** Marque le rapport comme tronqué (plafond de lignes atteint). */
    public function markTruncated(): void
    {
        $this->truncated = true;
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

    /** Nombre d'erreurs imputables à une défaillance base/infra (échec réel). */
    public function writeErrors(): int
    {
        return $this->writeErrors;
    }

    public function truncated(): bool
    {
        return $this->truncated;
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

    /** @return array{imported: int, duplicates: int, errors: int, write_errors: int, truncated: bool, total: int, error_samples: list<string>} */
    public function toArray(): array
    {
        return [
            'imported'      => $this->imported,
            'duplicates'    => $this->duplicates,
            'errors'        => $this->errors,
            'write_errors'  => $this->writeErrors,
            'truncated'     => $this->truncated,
            'total'         => $this->total(),
            'error_samples' => $this->errorSamples,
        ];
    }
}
