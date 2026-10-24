<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/** Persistes les dons, corrections de paiement et choix de publication. */
final class SupportRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array{stripe_cents:int, supporters:list<array{name:string}>} */
    public function publicSummary(int $year): array
    {
        $stmt = $this->pdo->prepare("SELECT COALESCE(SUM(d.amount_cents + COALESCE(a.adjustment_cents, 0)), 0)
            FROM support_donations d LEFT JOIN (SELECT payment_intent, SUM(adjustment_cents) adjustment_cents
                FROM support_adjustments GROUP BY payment_intent) a ON a.payment_intent = d.payment_intent
            WHERE d.donation_year = :year AND d.currency = 'eur'");
        $stmt->execute(['year' => $year]);
        $amount = (int) $stmt->fetchColumn();
        $rows = $this->pdo->query("SELECT u.display_name AS name FROM support_members m
            JOIN users u ON u.id = m.user_id AND u.status = 'active'
            WHERE m.badge_enabled = 1 AND m.public_opt_in = 1 AND TRIM(u.display_name) <> ''
            ORDER BY m.public_opted_in_at ASC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
        return ['stripe_cents' => $amount, 'supporters' => array_map(
            static fn (array $r): array => ['name' => (string) $r['name']], $rows,
        )];
    }

    /** @return array{badge:bool, public:bool} */
    public function member(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT badge_enabled, public_opt_in FROM support_members WHERE user_id = :uid');
        $stmt->execute(['uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return ['badge' => (bool) ($row['badge_enabled'] ?? false), 'public' => (bool) ($row['public_opt_in'] ?? false)];
    }

    public function setVisibility(int $userId, bool $visible): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO support_members (user_id, badge_enabled, public_opt_in, public_opted_in_at, manual_override)
            VALUES (:uid, 0, :visible, IF(:visible_time = 1, UTC_TIMESTAMP(), NULL), 0)
            ON DUPLICATE KEY UPDATE public_opted_in_at = IF(VALUES(public_opt_in) = 1 AND public_opt_in = 0, UTC_TIMESTAMP(), IF(VALUES(public_opt_in) = 0, NULL, public_opted_in_at)),
            public_opt_in = VALUES(public_opt_in), updated_at = UTC_TIMESTAMP()");
        $stmt->execute(['uid' => $userId, 'visible' => $visible ? 1 : 0, 'visible_time' => $visible ? 1 : 0]);
    }

    /** Événement déjà vérifié et filtré par le service. */
    public function recordPayment(string $sessionId, string $paymentIntent, ?int $userId, int $amount, string $currency, int $year): void
    {
        $this->pdo->beginTransaction();
        try {
            if ($userId !== null) {
                $active = $this->pdo->prepare("SELECT 1 FROM users WHERE id = :uid AND status = 'active'");
                $active->execute(['uid' => $userId]);
                if ($active->fetchColumn() === false) $userId = null;
            }
            $stmt = $this->pdo->prepare("INSERT IGNORE INTO support_donations
                (checkout_session_id, payment_intent, user_id, amount_cents, currency, donation_year)
                VALUES (:session, :intent, :uid, :amount, :currency, :year)");
            $stmt->execute(['session' => $sessionId, 'intent' => $paymentIntent, 'uid' => $userId, 'amount' => $amount, 'currency' => strtolower($currency), 'year' => $year]);
            if ($stmt->rowCount() === 1 && $userId !== null) {
                $badge = $this->pdo->prepare("INSERT INTO support_members (user_id, badge_enabled, public_opt_in, manual_override)
                    VALUES (:uid, 1, 0, 0) ON DUPLICATE KEY UPDATE
                    badge_enabled = IF(manual_override = 1, badge_enabled, 1), updated_at = UTC_TIMESTAMP()");
                $badge->execute(['uid' => $userId]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function recordAdjustment(string $eventId, int $eventCreated, string $paymentIntent, string $reference, int $amount, string $state): void
    {
        $this->pdo->prepare("INSERT INTO support_adjustments (event_id, event_created, payment_intent, adjustment_ref, adjustment_cents, state)
            VALUES (:event, :created, :intent, :ref, :amount, :state)
            ON DUPLICATE KEY UPDATE
              event_id = IF(VALUES(event_created) >= event_created, VALUES(event_id), event_id),
              payment_intent = IF(VALUES(event_created) >= event_created, VALUES(payment_intent), payment_intent),
              adjustment_cents = IF(VALUES(event_created) >= event_created, VALUES(adjustment_cents), adjustment_cents),
              state = IF(VALUES(event_created) >= event_created, VALUES(state), state),
              event_created = GREATEST(event_created, VALUES(event_created))")
            ->execute(['event' => $eventId, 'created' => $eventCreated, 'intent' => $paymentIntent, 'ref' => $reference, 'amount' => $amount, 'state' => $state]);
    }

    public function adminSetBadge(int $userId, bool $enabled): void
    {
        $this->pdo->prepare("INSERT INTO support_members (user_id, badge_enabled, public_opt_in, manual_override)
            VALUES (:uid, :enabled, 0, 1) ON DUPLICATE KEY UPDATE badge_enabled = VALUES(badge_enabled), manual_override = 1, updated_at = UTC_TIMESTAMP()")
            ->execute(['uid' => $userId, 'enabled' => $enabled ? 1 : 0]);
    }
}
