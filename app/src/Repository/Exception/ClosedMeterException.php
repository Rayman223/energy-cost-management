<?php

declare(strict_types=1);

namespace App\Repository\Exception;

use RuntimeException;

/**
 * Écriture refusée sur un compteur fermé, ou sur une batterie déposée (#55).
 *
 * La règle porte sur `reading_at`, PAS sur l'horloge : un relevé daté d'avant la
 * fermeture reste acceptable longtemps après elle — c'est le cas d'un carnet
 * recopié, ou d'un import de l'historique du fournisseur. Seule la date du
 * relevé décide.
 *
 * L'exception vit dans la couche d'accès aux données parce que c'est là que vit
 * l'invariant : il y a quatre points d'entrée en écriture (saisie manuelle,
 * ingestion d'agent, import de fichier, scripts CLI) et le repository est le seul
 * qui leur soit commun. Une garde posée en contrôleur laisserait l'import
 * ouvert — or c'est justement le chemin le plus susceptible de porter des lignes
 * anciennes, donc postérieures à une fermeture.
 *
 * `RuntimeException` et non `App\Http\ValidationException` : le repository ne
 * dépend pas de `App\Http`. C'est {@see \App\Http\Router} qui la traduit en 422,
 * en un seul endroit plutôt qu'à chaque contrôleur.
 *
 * **Jamais un `return 0` silencieux** : un relevé refusé doit se voir. Le
 * confondre avec un doublon ignoré ferait croire à un agent que ses envois
 * passent, alors qu'ils tombent tous.
 */
final class ClosedMeterException extends RuntimeException
{
    private function __construct(
        public readonly string $closedOn,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function meter(string $closedOn): self
    {
        return new self($closedOn, 'Meter closed on ' . $closedOn . ' — no reading accepted from that date on');
    }

    public static function battery(string $decommissionedOn): self
    {
        return new self(
            $decommissionedOn,
            'Battery decommissioned on ' . $decommissionedOn . ' — no reading accepted from that date on',
        );
    }
}
