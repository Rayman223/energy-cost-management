<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Domain\ReadingGranularity;
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
     * @param bool $replace Si true, un index déjà présent au même horodatage est
     *        écrasé (ON DUPLICATE KEY UPDATE) au lieu d'être ignoré — pour corriger
     *        un import fautif. Par défaut false (INSERT IGNORE).
     * @return int Nombre de lignes réellement écrites (insérées ou mises à jour).
     */
    public function insertIndexes(DateTimeImmutable $timestamp, array $indexByRegister, bool $replace = false): int;

    /**
     * Bornes de cohérence par registre autour de $timestamp, pour valider une
     * saisie manuelle (éventuellement antidatée) : min = index du relevé
     * strictement antérieur, max = index du relevé strictement postérieur
     * (null si absent), exists = un relevé existe déjà à cet horodatage exact
     * (l'INSERT IGNORE l'ignorerait silencieusement, d'où un rejet explicite).
     *
     * @param list<string> $registerKeys
     * @return array<string, array{min: float|null, max: float|null, exists: bool}>
     */
    public function readingBounds(DateTimeImmutable $timestamp, array $registerKeys): array;

    /**
     * Indique, par registre, si un relevé existe déjà dans le **créneau aligné**
     * ($granularity : jour ou quart d'heure) contenant $timestamp — calculé dans le
     * fuseau $timezone — à un instant **différent** de $timestamp. Sert à plafonner
     * l'ingestion à un index par registre et par créneau (issue #165) : un index par
     * jour en tarif fixe, un index par tranche de 15 min en tarif dynamique.
     * L'instant exact est exclu pour préserver l'idempotence : re-pousser le même
     * relevé reste un no-op (INSERT IGNORE) et n'est pas un « autre » relevé du créneau.
     *
     * @param list<string> $registerKeys
     * @return array<string, bool> register_key => un autre relevé existe dans ce créneau
     */
    public function readingsPresentInBucket(DateTimeImmutable $timestamp, string $timezone, ReadingGranularity $granularity, array $registerKeys): array;
}
