<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repository\SupportRepository;
use App\Repository\UserRepository;
use App\Service\StripeSupportWebhook;
use App\Service\AccountDataExporter;
use App\Infrastructure\MigrationRunner;
use PDOException;

/** Persistance et rapprochement des paiements liés à la collecte annuelle. */
final class SupportPaymentsDbTest extends DatabaseTestCase
{
    protected function clean(): void
    {
        $this->pdo()->exec('DELETE FROM support_adjustments');
        $this->pdo()->exec('DELETE FROM support_donations');
        $this->pdo()->exec('DELETE FROM support_members');
        $this->pdo()->exec("DELETE FROM users WHERE oidc_iss = 'https://support.test'");
    }

    public function testOutOfOrderRefundIsIdempotentAndManualRevocationSurvivesPaymentReplay(): void
    {
        $repo = new SupportRepository($this->pdo());
        $year = (int) gmdate('Y');
        $user = (new UserRepository($this->pdo()))->create('https://support.test', uniqid('support-', true), 'test', 'Support test');

        // L'événement de remboursement peut atteindre Stripe avant la session payée.
        $eventCreated = time();
        $repo->recordAdjustment('evt_refund', $eventCreated, 'pi_support', 'refund:ch_support', -250, 'refunded');
        $repo->recordPayment('cs_support', 'pi_support', $user->id, 1000, 'eur', $year);
        $repo->recordAdjustment('evt_refund_new', $eventCreated, 'pi_support', 'refund:ch_support', -400, 'refunded');
        $repo->recordAdjustment('evt_refund', $eventCreated, 'pi_support', 'refund:ch_support', -250, 'refunded');
        self::assertSame(600, $repo->publicSummary($year)['stripe_cents']);
        self::assertTrue($repo->member($user->id)['badge']);

        $repo->recordPayment('cs_dispute', 'pi_dispute', $user->id, 1000, 'eur', $year);
        $repo->recordAdjustment('evt_dispute_open', $eventCreated, 'pi_dispute', 'dispute:dp_support', -1000, 'open');
        $repo->recordAdjustment('evt_dispute_won', $eventCreated, 'pi_dispute', 'dispute:dp_support', 0, 'won');
        $repo->recordAdjustment('evt_dispute_open', $eventCreated, 'pi_dispute', 'dispute:dp_support', -1000, 'open');
        self::assertSame(1600, $repo->publicSummary($year)['stripe_cents']);

        $repo->adminSetBadge($user->id, false);
        $repo->recordPayment('cs_support', 'pi_support', $user->id, 1000, 'eur', $year);
        self::assertFalse($repo->member($user->id)['badge']);
        self::assertSame(1600, $repo->publicSummary($year)['stripe_cents']);
    }

    public function testDeletingAccountAnonymizesDonationAndRemovesPreferences(): void
    {
        $repo = new SupportRepository($this->pdo());
        $user = (new UserRepository($this->pdo()))->create('https://support.test', uniqid('support-', true), 'test', 'Support test');
        $repo->recordPayment('cs_erase', 'pi_erase', $user->id, 500, 'eur', (int) gmdate('Y'));
        $repo->setVisibility($user->id, true);
        $this->pdo()->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $user->id]);

        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM support_members')->fetchColumn());
        $statement = $this->pdo()->prepare('SELECT user_id FROM support_donations WHERE payment_intent = :intent');
        $statement->execute(['intent' => 'pi_erase']);
        self::assertNull($statement->fetchColumn());
    }

    public function testAdjustmentsCannotConsumeAnotherDonationsContribution(): void
    {
        $repo = new SupportRepository($this->pdo());
        $repo->recordPayment('cs_overlap', 'pi_overlap', null, 1000, 'eur', 2026);
        $repo->recordPayment('cs_other', 'pi_other', null, 500, 'eur', 2026);
        $repo->recordAdjustment('evt_overlap_refund', 1, 'pi_overlap', 'refund:ch_overlap', -400, 'refunded');
        $repo->recordAdjustment('evt_overlap_dispute', 2, 'pi_overlap', 'dispute:dp_overlap', -1000, 'open');
        self::assertSame(500, $repo->publicSummary(2026)['stripe_cents']);

        $repo->recordAdjustment('evt_overlap_won', 3, 'pi_overlap', 'dispute:dp_overlap', 0, 'won');
        self::assertSame(1100, $repo->publicSummary(2026)['stripe_cents']);
    }

    public function testDelayedPaymentUsesTheYearOfConfirmationInsteadOfSessionCreation(): void
    {
        $body = json_encode([
            'id' => 'evt_new_year', 'type' => 'checkout.session.async_payment_succeeded',
            'created' => strtotime('2027-01-01 00:00:01 UTC'), 'livemode' => false,
            'data' => ['object' => [
                'id' => 'cs_new_year', 'payment_intent' => 'pi_new_year', 'payment_link' => 'plink_test',
                'created' => strtotime('2026-12-31 23:59:59 UTC'),
                'payment_status' => 'paid', 'amount_total' => 700, 'currency' => 'eur',
            ]],
        ], JSON_THROW_ON_ERROR);
        $repo = new SupportRepository($this->pdo());
        $service = new StripeSupportWebhook($repo, [
            'stripe_webhook_secret' => 'whsec_support_test', 'stripe_mode' => 'test',
            'stripe_payment_link_id' => 'plink_test',
        ]);
        $timestamp = time();
        $signature = 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, 'whsec_support_test');
        self::assertTrue($service->handle($body, $signature));
        self::assertSame(0, $repo->publicSummary(2026, 'test')['stripe_cents']);
        self::assertSame(700, $repo->publicSummary(2027, 'test')['stripe_cents']);
    }

    public function testTestPaymentsBadgesConsentAndAdjustmentsStaySeparateFromLive(): void
    {
        $repo = new SupportRepository($this->pdo());
        $user = (new UserRepository($this->pdo()))->create('https://support.test', 'mode-isolation', 'test', 'Mode test');
        $repo->recordPayment('cs_modes', 'pi_modes', $user->id, 1000, 'eur', 2026, 'test');
        $repo->setVisibility($user->id, true, 'test');
        self::assertSame(0, $repo->publicSummary(2026)['stripe_cents']);
        self::assertSame([], $repo->publicSummary(2026)['supporters']);
        self::assertFalse($repo->member($user->id)['badge']);

        $repo->recordPayment('cs_modes', 'pi_modes', $user->id, 2000, 'eur', 2026);
        $repo->recordAdjustment('evt_modes', 1, 'pi_modes', 'refund:ch_modes', -100, 'refunded', 'test');
        $repo->recordAdjustment('evt_modes', 1, 'pi_modes', 'refund:ch_modes', -200, 'refunded');
        self::assertSame(900, $repo->publicSummary(2026, 'test')['stripe_cents']);
        self::assertSame(1800, $repo->publicSummary(2026)['stripe_cents']);
        self::assertFalse($repo->member($user->id)['public']);
        self::assertTrue($repo->member($user->id, 'test')['public']);
        $repo->adminSetBadge($user->id, false, 'test');
        self::assertTrue($repo->member($user->id)['badge']);
        self::assertFalse($repo->member($user->id, 'test')['badge']);
    }

    public function testSupportExportContainsConsentAndOnlyTheAccountsAdjustments(): void
    {
        $repo = new SupportRepository($this->pdo());
        $users = new UserRepository($this->pdo());
        $user = $users->create('https://support.test', 'export', 'test', 'Export');
        $other = $users->create('https://support.test', 'other-export', 'test', 'Other');
        $repo->recordPayment('cs_export', 'pi_export', $user->id, 1000, 'eur', 2026);
        $repo->setVisibility($user->id, true);
        $repo->recordAdjustment('evt_export', 1, 'pi_export', 'refund:ch_export', -100, 'refunded');
        $repo->recordPayment('cs_export', 'pi_export', $other->id, 2000, 'eur', 2026, 'test');
        $repo->recordAdjustment('evt_export_other', 1, 'pi_export', 'refund:ch_export', -200, 'refunded', 'test');

        ob_start();
        try {
            (new AccountDataExporter($this->pdo()))->stream($user->id);
            $data = json_decode((string) ob_get_contents(), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            ob_end_clean();
        }
        self::assertCount(1, $data['support']);
        self::assertNotNull($data['support'][0]['public_opted_in_at']);
        self::assertCount(1, $data['support_donations']);
        self::assertCount(1, $data['support_adjustments']);
        self::assertSame('evt_export', $data['support_adjustments'][0]['event_id']);
    }

    public function testBadgeFailureRollsBackDonationAndAllowsRetry(): void
    {
        $repo = new SupportRepository($this->pdo());
        $user = (new UserRepository($this->pdo()))->create('https://support.test', 'rollback', 'test', 'Rollback');
        $this->pdo()->exec("CREATE TRIGGER support_test_reject_badge BEFORE INSERT ON support_members FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Simulated badge failure'");
        try {
            try {
                $repo->recordPayment('cs_rollback', 'pi_rollback', $user->id, 1000, 'eur', 2026);
                self::fail('A failed badge insert must not acknowledge the donation.');
            } catch (PDOException) {
                self::assertFalse($this->pdo()->inTransaction());
                self::assertSame(0, $repo->publicSummary(2026)['stripe_cents']);
            }
        } finally {
            $this->pdo()->exec('DROP TRIGGER support_test_reject_badge');
        }
        $repo->recordPayment('cs_rollback', 'pi_rollback', $user->id, 1000, 'eur', 2026);
        self::assertSame(1000, $repo->publicSummary(2026)['stripe_cents']);
        self::assertTrue($repo->member($user->id)['badge']);
    }

    public function testWallRequiresConsentAndAnActiveBadgeAndListsAtMostOneHundredNames(): void
    {
        $repo = new SupportRepository($this->pdo());
        $users = new UserRepository($this->pdo());
        $ids = [];
        for ($i = 0; $i < 103; $i++) {
            $user = $users->create('https://support.test', 'wall-' . $i, 'test', 'Supporter ' . $i);
            $ids[] = $user->id;
            $repo->adminSetBadge($user->id, true);
            $repo->setVisibility($user->id, true);
        }
        self::assertCount(100, $repo->publicSummary(2026)['supporters']);
        self::assertSame(['name' => 'Supporter 0'], $repo->publicSummary(2026)['supporters'][0]);
        $this->pdo()->prepare("UPDATE users SET status = 'blocked' WHERE id = ?")->execute([$ids[0]]);
        $repo->adminSetBadge($ids[1], false);
        $repo->setVisibility($ids[2], false);
        $wall = $repo->publicSummary(2026)['supporters'];
        self::assertCount(100, $wall);
        self::assertSame(['name' => 'Supporter 3'], $wall[0]);
        self::assertSame(['name' => 'Supporter 102'], $wall[99]);
    }

    public function testMigrationsCanBeReplayedWithoutLosingModeSpecificData(): void
    {
        $repo = new SupportRepository($this->pdo());
        $user = (new UserRepository($this->pdo()))->create('https://support.test', 'migration', 'test', 'Migration');
        foreach (['test', 'live'] as $mode) {
            $repo->recordPayment('cs_migration', 'pi_migration', $user->id, 500, 'eur', 2026, $mode);
            $repo->setVisibility($user->id, true, $mode);
            $repo->recordAdjustment('evt_migration', 1, 'pi_migration', 'refund:ch_migration', -100, 'refunded', $mode);
        }
        for ($i = 0; $i < 2; $i++) {
            foreach (['2026-10-24_support_stripe.sql', '2026-10-25_support_stripe_modes.sql'] as $file) {
                $sql = file_get_contents(__DIR__ . '/../../app/sql/migrations/' . $file);
                self::assertIsString($sql);
                foreach (MigrationRunner::splitStatements($sql) as $statement) $this->pdo()->exec($statement);
            }
        }
        foreach (['test', 'live'] as $mode) {
            self::assertSame(400, $repo->publicSummary(2026, $mode)['stripe_cents']);
            self::assertSame(['badge' => true, 'public' => true], $repo->member($user->id, $mode));
        }
    }
}
