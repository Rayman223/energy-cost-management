<?php

declare(strict_types=1);

namespace App\Repository;

use App\Security\ApiToken;
use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Jetons API par utilisateur : création (secret affiché une seule fois),
 * authentification par hash, révocation, rate-limit à fenêtre fixe.
 */
final class ApiTokenRepository
{
    /** Nombre maximal de jetons actifs par utilisateur. */
    private const MAX_ACTIVE_TOKENS = 20;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Crée un jeton pour l'utilisateur. Le secret retourné n'est JAMAIS
     * restituable ensuite (seul le hash est stocké).
     *
     * @return array{id: int, token: string, prefix: string}
     */
    public function create(int $userId, string $name): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM api_tokens WHERE user_id = :uid AND revoked_at IS NULL'
        );
        $stmt->execute(['uid' => $userId]);
        if ((int) $stmt->fetchColumn() >= self::MAX_ACTIVE_TOKENS) {
            throw new RuntimeException('Nombre maximal de jetons actifs atteint (' . self::MAX_ACTIVE_TOKENS . ').');
        }

        $generated = ApiToken::generate();

        $this->pdo->prepare(
            'INSERT INTO api_tokens (user_id, name, token_hash, prefix) VALUES (:uid, :name, :hash, :prefix)'
        )->execute([
            'uid'    => $userId,
            'name'   => $name,
            'hash'   => $generated['hash'],
            'prefix' => $generated['prefix'],
        ]);

        return [
            'id'     => (int) $this->pdo->lastInsertId(),
            'token'  => $generated['token'],
            'prefix' => $generated['prefix'],
        ];
    }

    /**
     * Résout un jeton présenté en ID de jeton + utilisateur (null si inconnu,
     * mal formé ou révoqué). Met à jour last_used_at.
     *
     * @return array{token_id: int, user_id: int}|null
     */
    public function authenticate(string $token): ?array
    {
        if (ApiToken::looksValid($token) === false) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT t.id, t.user_id
             FROM api_tokens t
             INNER JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = :hash AND t.revoked_at IS NULL AND u.status = \'active\'
             LIMIT 1'
        );
        $stmt->execute(['hash' => ApiToken::hash($token)]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return null;
        }

        $this->pdo->prepare('UPDATE api_tokens SET last_used_at = NOW() WHERE id = :id')
            ->execute(['id' => (int) $row['id']]);

        return ['token_id' => (int) $row['id'], 'user_id' => (int) $row['user_id']];
    }

    /**
     * Rate-limit à fenêtre fixe : true si la requête est admise, false si le
     * quota de la fenêtre est dépassé.
     */
    public function consumeRateLimit(int $tokenId, int $limit, int $windowSeconds = 3600): bool
    {
        // Nouvelle fenêtre si aucune ou expirée, sinon incrément.
        $this->pdo->prepare(
            'UPDATE api_tokens
             SET window_count = IF(window_start IS NULL OR window_start < DATE_SUB(NOW(), INTERVAL :win SECOND), 1, window_count + 1),
                 window_start = IF(window_start IS NULL OR window_start < DATE_SUB(NOW(), INTERVAL :win2 SECOND), NOW(), window_start)
             WHERE id = :id'
        )->execute(['win' => $windowSeconds, 'win2' => $windowSeconds, 'id' => $tokenId]);

        $stmt = $this->pdo->prepare('SELECT window_count FROM api_tokens WHERE id = :id');
        $stmt->execute(['id' => $tokenId]);

        return (int) $stmt->fetchColumn() <= $limit;
    }

    /**
     * @return list<array{id: int, name: string, prefix: string, scopes: string,
     *                    last_used_at: ?string, created_at: string, revoked_at: ?string}>
     */
    public function listForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, prefix, scopes, last_used_at, created_at, revoked_at
             FROM api_tokens WHERE user_id = :uid ORDER BY created_at DESC'
        );
        $stmt->execute(['uid' => $userId]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = [
                'id'           => (int) $row['id'],
                'name'         => (string) $row['name'],
                'prefix'       => (string) $row['prefix'],
                'scopes'       => (string) $row['scopes'],
                'last_used_at' => $row['last_used_at'] !== null ? (string) $row['last_used_at'] : null,
                'created_at'   => (string) $row['created_at'],
                'revoked_at'   => $row['revoked_at'] !== null ? (string) $row['revoked_at'] : null,
            ];
        }

        return $out;
    }

    /** Révoque un jeton de l'utilisateur (effet immédiat). */
    public function revoke(int $tokenId, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE api_tokens SET revoked_at = NOW()
             WHERE id = :id AND user_id = :uid AND revoked_at IS NULL'
        );
        $stmt->execute(['id' => $tokenId, 'uid' => $userId]);

        return $stmt->rowCount() > 0;
    }
}
