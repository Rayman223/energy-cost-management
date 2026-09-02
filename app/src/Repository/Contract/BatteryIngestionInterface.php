<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use DateTimeImmutable;

/**
 * Écriture des index d'une batterie (#26), consommée par l'API d'ingestion, la
 * saisie manuelle et l'import en masse. Seam de test (sans base de données).
 *
 * L'implémentation est scopée sur UNE batterie et sur son propriétaire : ni
 * `user_id` ni `battery_id` n'apparaissent dans ces signatures, ils sont injectés
 * une fois et vérifiés à chaque écriture.
 *
 * @see \App\Repository\BatteryReadingRepository implémentation concrète.
 */
interface BatteryIngestionInterface
{
    /**
     * Compteurs acceptés. Ce sont des INDEX CUMULÉS, pas des énergies de période :
     * un index perdu ne se retrouve pas, un delta se recalcule.
     *
     * Volontairement deux clés courtes et non les noms de colonnes : elles sont
     * l'interface publique (API, CSV, formulaire), le schéma reste interne.
     */
    public const KINDS = ['charge', 'discharge'];

    /**
     * Écrit les index fournis à cet horodatage. Idempotent.
     *
     * Les deux compteurs partagent une même ligne, mais chacun peut être relevé
     * SÉPARÉMENT : beaucoup d'onduleurs n'exposent que l'un des deux. Un compteur
     * encore vide à cet horodatage est donc COMPLÉTÉ par une seconde écriture ;
     * un compteur déjà renseigné n'est jamais écrasé en silence — il faut
     * `$replace`.
     *
     * @param array<string, float> $indexByKind clé de {@see self::KINDS} => index cumulé
     * @param bool $replace Écrase la valeur déjà présente au lieu de la conserver
     *        (correction d'un import fautif). Un compteur ABSENT de `$indexByKind`
     *        n'est jamais effacé, même en mode écrasement.
     * @return int Nombre de valeurs réellement écrites (0 = tout était déjà là).
     */
    public function insertIndexes(DateTimeImmutable $timestamp, array $indexByKind, bool $replace = false): int;

    /**
     * Bornes de cohérence par compteur autour de `$timestamp`, pour valider une
     * saisie éventuellement antidatée : min = dernière valeur NON NULLE de ce
     * compteur strictement avant, max = première strictement après (null si
     * absente), exists = ce compteur est DÉJÀ renseigné à cet horodatage exact.
     *
     * Les valeurs nulles sont sautées compteur par compteur : une ligne où seule
     * la charge est relevée ne doit pas borner la décharge à « rien ».
     *
     * @param list<string> $kinds
     * @return array<string, array{min: float|null, max: float|null, exists: bool}>
     */
    public function readingBounds(DateTimeImmutable $timestamp, array $kinds): array;

    /**
     * Un AUTRE relevé de cette batterie existe-t-il déjà dans le jour civil de
     * `$timestamp` (calculé dans `$timezone`) ?
     *
     * Plafond « un relevé par jour » : la valorisation étant mensuelle (#26), un
     * index par jour suffit et borne la croissance de la table. L'instant exact
     * est EXCLU pour préserver l'idempotence et permettre de compléter le second
     * compteur d'une ligne déjà écrite.
     */
    public function readingPresentInDay(DateTimeImmutable $timestamp, string $timezone): bool;
}
