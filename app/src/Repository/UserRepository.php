<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\User;
use App\Domain\UserProfile;
use App\Repository\Contract\UserRepositoryInterface;
use PDO;
use RuntimeException;

final class UserRepository implements UserRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findByOidc(string $iss, string $sub): ?User
    {
        // Résolution via user_identities (source de vérité #137) : une identité
        // liée pointe vers son compte, qui peut en porter plusieurs. La ligne
        // users renvoyée reste l'identité primaire du compte.
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.oidc_iss, u.oidc_sub, u.provider, u.display_name, u.role, u.status
             FROM users u
             JOIN user_identities ui ON ui.user_id = u.id
             WHERE ui.oidc_iss = :iss AND ui.oidc_sub = :sub LIMIT 1'
        );
        $stmt->execute(['iss' => $iss, 'sub' => $sub]);
        $row = $stmt->fetch();
        if (is_array($row)) {
            return self::mapRow($row);
        }

        // Filet de transition : si le backfill #137 n'a pas encore peuplé
        // user_identities (code déployé avant `migrate.php`), on retombe sur les
        // colonnes primaires de `users`. Sans ça, tous les comptes existants
        // échoueraient à se connecter tant que la migration n'est pas jouée.
        // Post-backfill, ce second appel ne renvoie jamais rien de plus (toute
        // identité primaire est aussi dans user_identities) : filet inoffensif.
        $legacy = $this->pdo->prepare(
            'SELECT id, oidc_iss, oidc_sub, provider, display_name, role, status
             FROM users WHERE oidc_iss = :iss AND oidc_sub = :sub LIMIT 1'
        );
        $legacy->execute(['iss' => $iss, 'sub' => $sub]);
        $row = $legacy->fetch();

        return is_array($row) ? self::mapRow($row) : null;
    }

    public function findById(int $id): ?User
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, oidc_iss, oidc_sub, provider, display_name, role, status
             FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? self::mapRow($row) : null;
    }

    public function create(string $iss, string $sub, string $provider, string $displayName): User
    {
        $this->pdo->beginTransaction();
        try {
            // Le premier compte créé est l'owner de l'instance : il reçoit le rôle
            // « admin » (les suivants restent « user »). Le COUNT est dans la même
            // transaction que l'INSERT → atomique et robuste aux trous d'id.
            $countStmt = $this->pdo->query('SELECT COUNT(*) FROM users');
            $isFirstAccount = $countStmt !== false && (int) $countStmt->fetchColumn() === 0;
            $role = $isFirstAccount ? 'admin' : 'user';

            $stmt = $this->pdo->prepare(
                'INSERT INTO users (oidc_iss, oidc_sub, provider, display_name, role)
                 VALUES (:iss, :sub, :provider, :name, :role)'
            );
            $stmt->execute([
                'iss' => $iss,
                'sub' => $sub,
                'provider' => $provider,
                'name' => $displayName,
                'role' => $role,
            ]);

            $id = (int) $this->pdo->lastInsertId();

            $this->pdo->prepare('INSERT INTO user_profiles (user_id) VALUES (:id)')
                ->execute(['id' => $id]);

            // Identité primaire (#137) : chaque compte a au moins une ligne dans
            // user_identities, source de vérité de la recherche (iss, sub). Même
            // transaction → une course (unicité) fait rollback, relue par
            // AccountProvisioner via findByOidc().
            $this->pdo->prepare(
                'INSERT INTO user_identities (user_id, oidc_iss, oidc_sub, provider)
                 VALUES (:uid, :iss, :sub, :provider)'
            )->execute([
                'uid'      => $id,
                'iss'      => $iss,
                'sub'      => $sub,
                'provider' => $provider,
            ]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $user = $this->findById($id);
        if ($user === null) {
            throw new RuntimeException('Utilisateur introuvable après création.');
        }

        return $user;
    }

    public function updateDisplayName(int $userId, string $displayName): void
    {
        $this->pdo->prepare('UPDATE users SET display_name = :name WHERE id = :id')
            ->execute(['name' => $displayName, 'id' => $userId]);
    }

    /**
     * Repointe l'identité primaire du compte (#137). Appelé lors de la déliaison
     * de l'identité primaire : `users.oidc_iss/oidc_sub` doit toujours
     * correspondre à une ligne réelle de `user_identities`, sinon un futur
     * provisioning du même `(iss, sub)` violerait `uq_users_oidc`.
     */
    public function setPrimaryIdentity(int $userId, string $iss, string $sub, string $provider): void
    {
        $this->pdo->prepare(
            'UPDATE users SET oidc_iss = :iss, oidc_sub = :sub, provider = :provider WHERE id = :id'
        )->execute([
            'iss'      => $iss,
            'sub'      => $sub,
            'provider' => $provider,
            'id'       => $userId,
        ]);
    }

    /** Persiste la langue choisie dans le profil (crée la ligne au besoin). */
    public function setLocale(int $userId, string $locale): void
    {
        $this->pdo->prepare(
            'INSERT INTO user_profiles (user_id, locale) VALUES (:uid, :locale)
             ON DUPLICATE KEY UPDATE locale = VALUES(locale)'
        )->execute(['uid' => $userId, 'locale' => $locale]);
    }

    /**
     * Mémorise la période du bilan d'acomptes (#241), pour la restituer au retour.
     *
     * Écriture ciblée, comme {@see setLocale()} : passer par `updateProfile()`
     * obligerait la page /advances à relire puis réécrire tout le profil, et une
     * consultation de bilan pourrait alors écraser un réglage modifié entre-temps
     * dans un autre onglet.
     *
     * @param string $from Date 'Y-m-d' de début.
     * @param string $to   Date 'Y-m-d' de fin, exclue de la période.
     */
    public function setAdvancesPeriod(int $userId, string $from, string $to): void
    {
        $this->pdo->prepare(
            'INSERT INTO user_profiles (user_id, advances_period_from, advances_period_to)
             VALUES (:uid, :from, :to)
             ON DUPLICATE KEY UPDATE
                advances_period_from = VALUES(advances_period_from),
                advances_period_to   = VALUES(advances_period_to)'
        )->execute(['uid' => $userId, 'from' => $from, 'to' => $to]);
    }

    /** Marque l'acceptation des CGU/confidentialité si ce n'est pas déjà fait. */
    public function acceptTermsIfNeeded(int $userId): void
    {
        $this->pdo->prepare('UPDATE users SET terms_accepted_at = NOW() WHERE id = :id AND terms_accepted_at IS NULL')
            ->execute(['id' => $userId]);
    }

    /**
     * Met à jour le profil de l'utilisateur (crée la ligne si absente).
     *
     * NOTE d'asymétrie assumée : le bornage de `supplierMarkupPerKwh` vit ici, côté
     * écriture — dernière ligne de défense avant l'INSERT. Le DTO `UserProfile`
     * reste un simple porteur de données, et `getProfile` ne borne PAS la marge en
     * lecture : déplacer ce garde dans le constructeur changerait le comportement
     * de lecture. Le mode de tarification, lui, a quitté le profil pour la grille
     * (#245) : sa normalisation vit désormais dans `TariffRepository`.
     */
    public function updateProfile(int $userId, UserProfile $profile): void
    {
        // Bornage côté repository : marge ∈ [-1,1] €/kWh (négatif = remise), pour
        // qu'aucun appelant ne puisse déborder DECIMAL(12,7).
        $supplierMarkupPerKwh = max(-1.0, min(1.0, $profile->supplierMarkupPerKwh));

        $stmt = $this->pdo->prepare(
            'INSERT INTO user_profiles (user_id, country, timezone, currency, bidding_zone, supplier_markup_per_kwh, locale)
             VALUES (:uid, :country, :tz, :currency, :zone, :markup, :locale)
             ON DUPLICATE KEY UPDATE
                country = VALUES(country), timezone = VALUES(timezone),
                currency = VALUES(currency), bidding_zone = VALUES(bidding_zone),
                supplier_markup_per_kwh = VALUES(supplier_markup_per_kwh), locale = VALUES(locale)'
        );
        $stmt->execute([
            'uid'      => $userId,
            'country'  => $profile->country,
            'tz'       => $profile->timezone,
            'currency' => $profile->currency,
            'zone'     => $profile->biddingZone,
            'markup'   => $supplierMarkupPerKwh,
            'locale'   => $profile->locale,
        ]);
    }

    public function touchLastLogin(int $userId): void
    {
        $this->pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')
            ->execute(['id' => $userId]);
    }

    /**
     * Liste tous les comptes pour l'administration (sans PII sensible).
     *
     * @return list<array{id: int, provider: string, display_name: string,
     *                    role: string, status: string, created_at: string,
     *                    last_login_at: ?string}>
     */
    public function listAll(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, provider, display_name, role, status, created_at, last_login_at
             FROM users ORDER BY created_at ASC, id ASC'
        );
        $rows = $stmt === false ? [] : $stmt->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'            => (int) $row['id'],
                'provider'      => (string) $row['provider'],
                'display_name'  => (string) $row['display_name'],
                'role'          => (string) $row['role'],
                'status'        => (string) $row['status'],
                'created_at'    => (string) $row['created_at'],
                'last_login_at' => $row['last_login_at'] !== null ? (string) $row['last_login_at'] : null,
            ];
        }

        return $out;
    }

    /**
     * Change le rôle d'un compte (« user » ou « admin »). Renvoie false si le
     * rôle est invalide ou si aucune ligne n'a changé.
     */
    public function setRole(int $userId, string $role): bool
    {
        if (!in_array($role, ['user', 'admin'], true)) {
            return false;
        }

        $stmt = $this->pdo->prepare('UPDATE users SET role = :role WHERE id = :id');
        $stmt->execute(['role' => $role, 'id' => $userId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Change le statut d'un compte (« active » ou « blocked »). Un compte
     * « blocked » perd l'accès (session refusée, jetons API rejetés). Renvoie
     * false si le statut est invalide ou si aucune ligne n'a changé.
     */
    public function setStatus(int $userId, string $status): bool
    {
        if (!in_array($status, ['active', 'blocked'], true)) {
            return false;
        }

        $stmt = $this->pdo->prepare('UPDATE users SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $userId]);

        return $stmt->rowCount() > 0;
    }

    public function getProfile(int $userId): ?UserProfile
    {
        $stmt = $this->pdo->prepare(
            'SELECT country, timezone, currency, bidding_zone, supplier_markup_per_kwh, locale,
                    advances_period_from, advances_period_to
             FROM user_profiles WHERE user_id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return null;
        }

        return new UserProfile(
            country: $row['country'] !== null ? (string) $row['country'] : null,
            timezone: (string) $row['timezone'],
            currency: (string) $row['currency'],
            biddingZone: $row['bidding_zone'] !== null ? (string) $row['bidding_zone'] : null,
            supplierMarkupPerKwh: (float) ($row['supplier_markup_per_kwh'] ?? 0.0),
            locale: (string) $row['locale'],
            advancesPeriodFrom: isset($row['advances_period_from']) ? (string) $row['advances_period_from'] : null,
            advancesPeriodTo:   isset($row['advances_period_to'])   ? (string) $row['advances_period_to']   : null,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function mapRow(array $row): User
    {
        return new User(
            (int) $row['id'],
            (string) $row['oidc_iss'],
            (string) $row['oidc_sub'],
            (string) $row['provider'],
            (string) $row['display_name'],
            (string) $row['role'],
            (string) $row['status'],
        );
    }
}
