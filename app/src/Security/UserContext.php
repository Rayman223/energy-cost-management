<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\UserRepository;
use PDO;
use RuntimeException;

/**
 * Résolution du tenant (utilisateur) courant pour scoper les repositories.
 *
 * - Web + OIDC activé : l'utilisateur de la session (AuthGuard a déjà exigé la
 *   connexion en amont).
 * - Web + OIDC désactivé (mode Basic Auth historique) : installation mono-tenant ;
 *   on réutilise le compte technique « local/owner » (créé au premier besoin),
 *   sinon le premier compte existant. Quand l'owner active OIDC ensuite, son
 *   compte OIDC devient le tenant de référence (cf. backfill --user).
 * - CLI (crons, imports) : --user=<id> explicite, sinon premier compte.
 */
final class UserContext
{
    /** @param array<string, mixed> $config */
    public static function currentWebUserId(PDO $pdo, array $config): int
    {
        $oidc = $config['oidc'] ?? [];
        $oidcEnabled = is_array($oidc) && ($oidc['enabled'] ?? false) === true;

        if ($oidcEnabled) {
            $userId = AuthSession::userId();
            if ($userId === null) {
                // AuthGuard aurait dû rediriger : garde-fou défensif.
                throw new RuntimeException('Aucune session authentifiée.');
            }

            return $userId;
        }

        $users = new UserRepository($pdo);

        // Mode mono-tenant : compte technique local, sinon premier compte.
        $local = $users->findByOidc('local', 'owner');
        if ($local !== null) {
            return $local->id;
        }

        $firstId = self::firstUserId($pdo);
        if ($firstId !== null) {
            return $firstId;
        }

        return $users->create('local', 'owner', 'local', 'Owner')->id;
    }

    public static function cliUserId(PDO $pdo, ?int $explicit): int
    {
        if ($explicit !== null) {
            if ((new UserRepository($pdo))->findById($explicit) === null) {
                throw new RuntimeException('Utilisateur #' . $explicit . ' introuvable.');
            }

            return $explicit;
        }

        $firstId = self::firstUserId($pdo);
        if ($firstId === null) {
            throw new RuntimeException(
                'Aucun compte utilisateur : connecte-toi d\'abord (ou crée le compte), puis relance (ou passe --user=<id>).'
            );
        }

        return $firstId;
    }

    /** Extrait la valeur de --user=<id> des arguments CLI (null si absente). */
    public static function parseCliUserArg(): ?int
    {
        global $argv;
        foreach ($argv as $arg) {
            if (str_starts_with((string) $arg, '--user=')) {
                return (int) substr((string) $arg, 7);
            }
        }

        return null;
    }

    private static function firstUserId(PDO $pdo): ?int
    {
        $stmt = $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1');
        if ($stmt === false) {
            return null;
        }

        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }
}
