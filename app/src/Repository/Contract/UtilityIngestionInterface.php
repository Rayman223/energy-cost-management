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
    /**
     * Même repository, scopé sur un compteur DÉSIGNÉ (#55) ; `null` rend
     * l'instance courante.
     */
    public function forMeter(?int $meterId): self;

    /**
     * @param bool $replace Si true, un relevé déjà présent au même horodatage est
     *        écrasé (ON DUPLICATE KEY UPDATE) au lieu d'être ignoré — pour corriger
     *        un import fautif. Par défaut false (INSERT IGNORE idempotent).
     * @return bool true si le relevé a été inséré ou mis à jour, false si doublon
     *         ignoré (uniquement possible quand $replace vaut false).
     */
    public function saveIgnore(DateTimeImmutable $readingAt, float $counterM3, bool $replace = false): bool;
}
