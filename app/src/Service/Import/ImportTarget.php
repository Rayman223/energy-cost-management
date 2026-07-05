<?php

declare(strict_types=1);

namespace App\Service\Import;

use RuntimeException;

/**
 * Règle d'autorisation unique pour la cible d'un import.
 *
 * - Importer pour soi-même est toujours permis.
 * - Importer pour un **autre** utilisateur exige le rôle admin (onboarding /
 *   migration). Sans ce rôle, la demande est refusée.
 *
 * Centralisé ici pour être testé unitairement et partagé entre le self-service
 * (page compte) et l'import admin (page admin).
 */
final class ImportTarget
{
    /**
     * @throws RuntimeException si un non-admin tente de cibler un autre utilisateur.
     */
    public static function resolve(int $currentUserId, bool $isAdmin, ?int $requestedTargetId): int
    {
        if ($requestedTargetId === null || $requestedTargetId === $currentUserId) {
            return $currentUserId;
        }

        if ($isAdmin === false) {
            throw new RuntimeException('Import pour un autre utilisateur réservé aux administrateurs.');
        }

        return $requestedTargetId;
    }
}
