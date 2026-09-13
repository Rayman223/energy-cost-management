<?php

declare(strict_types=1);

namespace App\Repository\Exception;

use RuntimeException;

/**
 * Plafond anti-abus atteint (#55) : compteurs d'une énergie, ou parc de
 * batteries.
 *
 * Porte les DONNÉES du refus — ce qui était plafonné, et à combien —, pas son
 * message : le repository ne connaît ni la langue du lecteur ni le catalogue de
 * traductions. C'est l'appelant qui compose le message à partir de `$limit`.
 *
 * Une seule classe pour les deux cas : le refus est le même événement, seule
 * change la phrase affichée. Deux classes jumelles obligeraient chaque nouveau
 * point d'appel à choisir entre elles, et un `catch` à les énumérer.
 *
 * `RuntimeException` et non `App\Http\ValidationException` : la garde vit dans la
 * couche d'accès aux données, qui ne dépend pas de `App\Http`. Le jour où l'API
 * créera des compteurs, elle traduira cette exception en 422 chez elle.
 */
final class LimitReachedException extends RuntimeException
{
    private function __construct(
        public readonly int $limit,
        public readonly ?string $energyType,
        string $message,
    ) {
        parent::__construct($message);
    }

    /** Plafond de compteurs atteint pour cette énergie. */
    public static function meters(string $energyType, int $limit): self
    {
        return new self($limit, $energyType, sprintf('Meter limit reached for %s (%d).', $energyType, $limit));
    }

    /** Plafond du parc de batteries atteint (pas d'énergie associée). */
    public static function batteries(int $limit): self
    {
        return new self($limit, null, sprintf('Battery limit reached (%d).', $limit));
    }
}
