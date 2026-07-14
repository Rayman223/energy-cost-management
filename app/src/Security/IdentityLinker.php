<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\Contract\UserIdentityRepositoryInterface;

/**
 * Rattache une identité OIDC `(iss, sub)` au compte courant (Issue #137).
 *
 * Distinct de {@see AccountProvisioner} (qui provisionne/résout un compte à la
 * connexion) : ici l'utilisateur est déjà connecté et souhaite lier un second
 * fournisseur pour éviter les doublons. La liaison n'aboutit que si l'identité
 * est libre — une identité déjà rattachée à un AUTRE compte est un cas de fusion,
 * hors périmètre, et fait l'objet d'un refus explicite.
 */
final class IdentityLinker
{
    /** Identité libre : rattachée au compte courant. */
    public const LINKED = 'linked';

    /** Identité déjà rattachée à CE compte : aucune action (idempotent). */
    public const ALREADY_LINKED_SELF = 'already_linked_self';

    /** Identité rattachée à un AUTRE compte : refus (cas de fusion). */
    public const TAKEN_BY_OTHER = 'taken_by_other';

    public function __construct(private readonly UserIdentityRepositoryInterface $identities)
    {
    }

    /**
     * @return self::LINKED|self::ALREADY_LINKED_SELF|self::TAKEN_BY_OTHER
     */
    public function link(int $currentUserId, string $iss, string $sub, string $provider): string
    {
        $ownerId = $this->identities->findUserIdByOidc($iss, $sub);

        if ($ownerId === $currentUserId) {
            return self::ALREADY_LINKED_SELF;
        }
        if ($ownerId !== null) {
            return self::TAKEN_BY_OTHER;
        }

        $this->identities->link($currentUserId, $iss, $sub, $provider);

        return self::LINKED;
    }
}
