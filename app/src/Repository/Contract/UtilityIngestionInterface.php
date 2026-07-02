<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use DateTimeImmutable;

/**
 * Écriture idempotente de relevés gaz/eau consommée par l'API d'ingestion
 * (INSERT IGNORE sur l'unicité (user, type, horodatage)). Seam de test.
 *
 * @see \App\Repository\UtilityReadingRepository implémentation concrète.
 */
interface UtilityIngestionInterface
{
    /** @return bool true si le relevé a été inséré, false si doublon ignoré. */
    public function saveIgnore(DateTimeImmutable $readingAt, float $counterM3): bool;
}
