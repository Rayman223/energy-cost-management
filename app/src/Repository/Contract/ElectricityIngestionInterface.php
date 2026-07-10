<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use DateTimeImmutable;

/**
 * Écriture d'index électricité/solaire (modèle à registres) consommée par
 * l'API d'ingestion et le cron. Seam de test (sans base de données).
 *
 * @see \App\Repository\ElectricityReadingRepository implémentation concrète.
 */
interface ElectricityIngestionInterface
{
    /**
     * Clés des registres électricité/solaire acceptées par insertIndexes().
     * Source de vérité unique partagée par les contrôleurs d'ingestion et de
     * saisie manuelle.
     */
    public const REGISTERS = ['import_t1', 'import_t2', 'export_t1', 'export_t2', 'production'];

    /**
     * Insère un jeu d'index au même horodatage (INSERT IGNORE : idempotent).
     *
     * @param array<string, float> $indexByRegister register_key => index cumulé
     * @return int Nombre de lignes réellement insérées (doublons exclus).
     */
    public function insertIndexes(DateTimeImmutable $timestamp, array $indexByRegister): int;
}
