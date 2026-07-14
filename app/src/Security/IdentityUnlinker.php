<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\Contract\UserIdentityRepositoryInterface;
use App\Repository\Contract\UserRepositoryInterface;
use PDO;

/**
 * Retire une identité OIDC d'un compte (Issue #137), de façon atomique.
 *
 * Tout se joue dans une transaction avec verrou de lignes (`FOR UPDATE`) :
 *  - refus de retirer la **dernière** identité (auto-verrouillage) ;
 *  - si l'identité retirée est la **primaire** (celle reflétée dans
 *    `users.oidc_iss/oidc_sub`), une autre est **promue** pour préserver
 *    l'invariant « users pointe toujours vers une identité réelle ».
 *
 * Le verrou sérialise deux déliaisons concurrentes du même compte (p. ex. deux
 * appareils) : sans lui, chacune pourrait passer le contrôle « il en reste ≥ 2 »
 * puis supprimer une ligne différente, laissant le compte sans identité.
 */
final class IdentityUnlinker
{
    /** Identité retirée avec succès. */
    public const UNLINKED = 'unlinked';

    /** Refus : c'était la dernière identité du compte. */
    public const LAST = 'last';

    /** Identité introuvable ou n'appartenant pas au compte. */
    public const NOT_FOUND = 'not_found';

    public function __construct(
        private readonly PDO $pdo,
        private readonly UserIdentityRepositoryInterface $identities,
        private readonly UserRepositoryInterface $users,
    ) {
    }

    /**
     * @return self::UNLINKED|self::LAST|self::NOT_FOUND
     */
    public function unlink(int $userId, int $identityId): string
    {
        $this->pdo->beginTransaction();
        try {
            // Verrouille les identités du compte : une déliaison concurrente
            // bloquera ici jusqu'au commit et verra donc l'état à jour.
            $rows = $this->identities->listForUserForUpdate($userId);

            if (count($rows) <= 1) {
                $this->pdo->commit(); // rien écrit : libère juste le verrou
                return self::LAST;
            }

            $removed = null;
            foreach ($rows as $row) {
                if ($row['id'] === $identityId) {
                    $removed = $row;
                    break;
                }
            }
            if ($removed === null || $this->identities->delete($identityId, $userId) === false) {
                $this->pdo->commit();
                return self::NOT_FOUND;
            }

            // Promotion si l'identité retirée était la primaire du compte.
            $current = $this->users->findById($userId);
            if ($current !== null && $current->oidcIss === $removed['oidc_iss'] && $current->oidcSub === $removed['oidc_sub']) {
                foreach ($rows as $row) {
                    if ($row['id'] === $identityId) {
                        continue;
                    }
                    $this->users->setPrimaryIdentity($userId, $row['oidc_iss'], $row['oidc_sub'], $row['provider']);
                    break;
                }
            }

            $this->pdo->commit();

            return self::UNLINKED;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
