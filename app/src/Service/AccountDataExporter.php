<?php

declare(strict_types=1);

namespace App\Service;

use PDO;

/**
 * Export RGPD : écrit en flux (streaming) toutes les données d'un utilisateur au
 * format JSON. Les tables volumineuses (relevés) sont paginées par lots pour
 * borner la mémoire, quel que soit l'historique.
 */
final class AccountDataExporter
{
    /** Taille des lots pour les tables volumineuses. */
    private const CHUNK = 5000;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Écrit le JSON complet sur la sortie standard (echo). L'appelant a déjà
     * envoyé les en-têtes (Content-Type / Content-Disposition).
     */
    public function stream(int $userId): void
    {
        $head = [
            '"exported_at":' . self::enc((new \DateTimeImmutable('now'))->format('c')),
            '"user":' . self::enc($this->one('SELECT id, oidc_iss, oidc_sub, provider, display_name, role, status, terms_accepted_at, created_at, last_login_at FROM users WHERE id = :uid', $userId)),
            '"profile":' . self::enc($this->one('SELECT country, timezone, currency, bidding_zone, pricing_mode, vat_rate, supplier_markup_per_kwh, locale FROM user_profiles WHERE user_id = :uid', $userId)),
            '"meters":' . self::enc($this->all('SELECT id, energy_type, label, country, timezone, created_at FROM meters WHERE user_id = :uid ORDER BY id', $userId)),
        ];
        echo '{' . implode(',', $head);

        // Tables volumineuses : paginées (mémoire bornée).
        echo ',"meter_readings":';
        $this->streamRows(
            'SELECT reg.register_key, mr.reading_at, mr.index_value
             FROM meter_readings mr
             INNER JOIN meter_registers reg ON reg.id = mr.register_id
             INNER JOIN meters m ON m.id = reg.meter_id
             WHERE m.user_id = :uid
             ORDER BY mr.reading_at, reg.register_key',
            $userId
        );

        echo ',"utility_readings":';
        $this->streamRows(
            'SELECT energy_type, reading_at, counter_m3 FROM utility_readings
             WHERE user_id = :uid ORDER BY energy_type, reading_at',
            $userId
        );

        $tail = [
            '"tariff_grids":' . self::enc($this->all('SELECT id, energy_type, country, currency, name, valid_from, valid_to, pcs_coefficient FROM tariff_grids WHERE user_id = :uid ORDER BY id', $userId)),
            '"api_tokens":' . self::enc($this->all('SELECT id, name, prefix, scopes, last_used_at, created_at, revoked_at FROM api_tokens WHERE user_id = :uid ORDER BY id', $userId)),
            '"integrations":' . self::enc($this->integrations($userId)),
            '"sync_state":' . self::enc($this->all('SELECT source_name, last_sent_at, updated_at FROM webhook_sync_state WHERE user_id = :uid ORDER BY source_name', $userId)),
        ];
        echo ',' . implode(',', $tail) . '}';
    }

    /** Écrit un tableau JSON de lignes, paginé par lots de self::CHUNK. */
    private function streamRows(string $baseSql, int $userId): void
    {
        echo '[';
        $offset = 0;
        $first = true;

        do {
            $stmt = $this->pdo->prepare($baseSql . ' LIMIT :lim OFFSET :off');
            $stmt->bindValue('uid', $userId, PDO::PARAM_INT);
            $stmt->bindValue('lim', self::CHUNK, PDO::PARAM_INT);
            $stmt->bindValue('off', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                echo ($first ? '' : ',') . self::enc($row);
                $first = false;
            }

            if (function_exists('flush')) {
                flush();
            }

            $count = count($rows);
            $offset += self::CHUNK;
        } while ($count === self::CHUNK);

        echo ']';
    }

    private static function enc(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Connecteurs d'export : décode la colonne JSON `settings` (renvoyée en
     * chaîne par PDO) pour qu'elle apparaisse comme objet imbriqué dans l'export,
     * et non comme chaîne JSON doublement échappée.
     *
     * @return list<array<string, mixed>>
     */
    private function integrations(int $userId): array
    {
        $rows = $this->all(
            'SELECT module_key, enabled, settings, updated_at FROM user_integrations WHERE user_id = :uid ORDER BY module_key',
            $userId
        );

        foreach ($rows as $i => $row) {
            if (is_string($row['settings'] ?? null)) {
                $decoded = json_decode($row['settings'], true);
                $rows[$i]['settings'] = is_array($decoded) ? $decoded : $row['settings'];
            }
        }

        return $rows;
    }

    /** @return array<string, mixed>|null */
    private function one(string $sql, int $userId): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return list<array<string, mixed>> */
    private function all(string $sql, int $userId): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['uid' => $userId]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }
}
