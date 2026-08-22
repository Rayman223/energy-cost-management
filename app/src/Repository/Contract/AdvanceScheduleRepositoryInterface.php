<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Domain\AdvanceSchedule;
use DateTimeImmutable;

/**
 * Accès aux barèmes d'acomptes de l'utilisateur courant (#241).
 * Seam de test de {@see \App\Service\AdvanceBalanceService} sans base de données.
 *
 * `user_id` n'apparaît dans aucune signature : il est injecté une fois dans
 * l'implémentation concrète et appliqué à chaque requête.
 *
 * @see \App\Repository\AdvanceScheduleRepository implémentation concrète (PDO/MySQL).
 */
interface AdvanceScheduleRepositoryInterface
{
    /**
     * Barèmes d'une énergie (ou de toutes si $energyType est null), du plus
     * récent au plus ancien.
     *
     * @return list<AdvanceSchedule>
     */
    public function listFor(?string $energyType = null): array;

    /**
     * Barèmes de la même énergie dont la plage recouvre `[$validFrom, $validTo[`,
     * en excluant éventuellement un identifiant (le barème en cours d'édition).
     *
     * Borne de fin EXCLUE (#1) : un barème qui s'arrête pile à `$validFrom` ne
     * chevauche pas, il se recolle.
     *
     * @param ?DateTimeImmutable $validTo Premier jour non couvert ; null = ouvert.
     * @return list<AdvanceSchedule>
     */
    public function findOverlapping(
        string $energyType,
        DateTimeImmutable $validFrom,
        ?DateTimeImmutable $validTo,
        ?int $exceptId = null,
    ): array;

    /**
     * Ce barème existe-t-il ET appartient-il à l'utilisateur courant ?
     *
     * Distinct de `rowCount()` après écriture : sans `MYSQL_ATTR_FOUND_ROWS`, un
     * UPDATE qui ne change aucune valeur rapporte zéro ligne affectée bien que la
     * cible existe — s'y fier confondrait « barème introuvable » et
     * « réenregistré à l'identique ».
     */
    public function owns(int $id): bool;

    public function insert(
        string $energyType,
        float $amountMonthly,
        DateTimeImmutable $validFrom,
        ?DateTimeImmutable $validTo,
        int $dueDay,
        string $note = '',
    ): void;

    public function update(
        int $id,
        string $energyType,
        float $amountMonthly,
        DateTimeImmutable $validFrom,
        ?DateTimeImmutable $validTo,
        int $dueDay,
        string $note = '',
    ): void;

    public function delete(int $id): void;
}
