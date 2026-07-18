<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\User;
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

    /** Marque l'acceptation des CGU/confidentialité si ce n'est pas déjà fait. */
    public function acceptTermsIfNeeded(int $userId): void
    {
        $this->pdo->prepare('UPDATE users SET terms_accepted_at = NOW() WHERE id = :id AND terms_accepted_at IS NULL')
            ->execute(['id' => $userId]);
    }

    /**
     * Met à jour le profil de l'utilisateur (crée la ligne si absente).
     */
    /**
     * Modes de tarification électricité valides (colonne user_profiles.pricing_mode).
     *
     * @var list<string>
     */
    public const PRICING_MODES = ['fixed', 'dynamic_hourly', 'dynamic_quarter'];

    /**
     * NOTE : signature volontairement longue (9 paramètres positionnels), assumée
     * transitoire — `updateProfile` n'est pas dans l'interface et n'a qu'un seul
     * appelant (routes/account.php). Un DTO `App\Domain\UserProfile` couvrant
     * lecture et écriture est prévu (issue de suivi #160). Les 2 nouveaux `float`
     * sont type-distincts des `string` qui précèdent : pas de risque d'inversion.
     */
    public function updateProfile(
        int $userId,
        ?string $country,
        string $timezone,
        string $currency,
        ?string $biddingZone,
        string $locale,
        string $pricingMode = 'fixed',
        float $vatRate = 21.0,
        float $supplierMarkupPerKwh = 0.0,
    ): void {
        if (!in_array($pricingMode, self::PRICING_MODES, true)) {
            $pricingMode = 'fixed';
        }
        // Bornage côté repository (dernière ligne de défense, comme PRICING_MODES
        // ci-dessus) : TVA en % ∈ [0,100] (unité de tariff_grids.vat_rate), marge
        // ∈ [-1,1] €/kWh (négatif = remise). Garde symétrique aux deux champs pour
        // qu'aucun appelant ne puisse déborder DECIMAL(5,2) / DECIMAL(12,7).
        $vatRate              = max(0.0, min(100.0, $vatRate));
        $supplierMarkupPerKwh = max(-1.0, min(1.0, $supplierMarkupPerKwh));

        $stmt = $this->pdo->prepare(
            'INSERT INTO user_profiles (user_id, country, timezone, currency, bidding_zone, pricing_mode, vat_rate, supplier_markup_per_kwh, locale)
             VALUES (:uid, :country, :tz, :currency, :zone, :pricing_mode, :vat, :markup, :locale)
             ON DUPLICATE KEY UPDATE
                country = VALUES(country), timezone = VALUES(timezone),
                currency = VALUES(currency), bidding_zone = VALUES(bidding_zone),
                pricing_mode = VALUES(pricing_mode), vat_rate = VALUES(vat_rate),
                supplier_markup_per_kwh = VALUES(supplier_markup_per_kwh), locale = VALUES(locale)'
        );
        $stmt->execute([
            'uid'          => $userId,
            'country'      => $country,
            'tz'           => $timezone,
            'currency'     => $currency,
            'zone'         => $biddingZone,
            'pricing_mode' => $pricingMode,
            'vat'          => $vatRate,
            'markup'       => $supplierMarkupPerKwh,
            'locale'       => $locale,
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

    public function getProfile(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT country, timezone, currency, bidding_zone, pricing_mode, vat_rate, supplier_markup_per_kwh, locale
             FROM user_profiles WHERE user_id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return null;
        }

        $pricingMode = (string) ($row['pricing_mode'] ?? 'fixed');
        if (!in_array($pricingMode, self::PRICING_MODES, true)) {
            $pricingMode = 'fixed';
        }

        return [
            'country' => $row['country'] !== null ? (string) $row['country'] : null,
            'timezone' => (string) $row['timezone'],
            'currency' => (string) $row['currency'],
            'bidding_zone' => $row['bidding_zone'] !== null ? (string) $row['bidding_zone'] : null,
            'pricing_mode' => $pricingMode,
            'vat_rate' => (float) ($row['vat_rate'] ?? 21.0),
            'supplier_markup_per_kwh' => (float) ($row['supplier_markup_per_kwh'] ?? 0.0),
            'locale' => (string) $row['locale'],
        ];
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
